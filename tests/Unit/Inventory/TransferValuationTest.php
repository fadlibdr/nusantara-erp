<?php

namespace Tests\Unit\Inventory;

use DomainException;
use LogicException;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;

/**
 * Inter-warehouse transfers must be value-neutral: the source average is frozen
 * on the line when the goods leave and applied verbatim when they arrive, so a
 * transfer can never create or destroy inventory value.
 */
class TransferValuationTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $pusat;

    private Warehouse $site;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->site = $this->makeWarehouse('WH-SITE');
        $this->semen = $this->makeItem('Semen Gresik 40kg');

        // Opening positions: 100 * 15.000 = 1.500.000 and 20 * 21.000 = 420.000
        // => 1.920.000 of inventory value across the company.
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);
        $this->receiveStock($this->site, $this->semen, 20, 21000);
    }

    public function test_sending_freezes_the_source_average_onto_the_line(): void
    {
        $transfer = $this->stock()->sendTransfer(
            $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 40]])
        );

        $this->assertSame(TransferStatus::InTransit, $transfer->fresh()->status);
        $this->assertSame(15000.0, (float) $transfer->items()->first()->unit_cost);

        // Source: quantity down 40, average unchanged (stock out never reprices).
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        // Destination untouched while the goods are on the road.
        $this->assertSame(20.0, $this->balanceQty($this->site, $this->semen));
        $this->assertSame(21000.0, $this->balanceAvg($this->site, $this->semen));

        // 60 * 15.000 + 20 * 21.000 = 900.000 + 420.000 = 1.320.000 in warehouses,
        // the remaining 40 * 15.000 = 600.000 is in transit.
        $this->assertSame(900000.0, $this->balanceValue($this->pusat, $this->semen));
        $this->assertSame(420000.0, $this->balanceValue($this->site, $this->semen));
    }

    public function test_receiving_applies_the_frozen_cost_and_conserves_total_value(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 40]]);

        $this->stock()->sendTransfer($transfer);
        $this->stock()->receiveTransfer($transfer->fresh());

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);

        // Destination moving average:
        // (20 * 21.000 + 40 * 15.000) / (20 + 40)
        //   = (420.000 + 600.000) / 60 = 1.020.000 / 60 = 17.000
        $this->assertSame(60.0, $this->balanceQty($this->site, $this->semen));
        $this->assertSame(17000.0, $this->balanceAvg($this->site, $this->semen));

        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));

        // Value conservation: 900.000 + 1.020.000 = 1.920.000, exactly the
        // 1.500.000 + 420.000 received before the transfer.
        $total = $this->balanceValue($this->pusat, $this->semen)
            + $this->balanceValue($this->site, $this->semen);

        $this->assertSame(1920000.0, $total);
        // And the item's global average is unmoved: 1.920.000 / 120 = 16.000.
        $this->assertSame(16000.0, (float) $this->semen->fresh()->avg_cost);
    }

    public function test_receiving_into_an_empty_warehouse_adopts_the_frozen_cost(): void
    {
        $baru = $this->makeWarehouse('WH-BARU');

        $transfer = $this->makeTransfer($this->pusat, $baru, [[$this->semen, 40]]);
        $this->stock()->sendTransfer($transfer);
        $this->stock()->receiveTransfer($transfer->fresh());

        // No prior balance, so new_avg = the incoming (frozen) cost.
        $this->assertSame(40.0, $this->balanceQty($baru, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($baru, $this->semen));
        $this->assertSame(600000.0, $this->balanceValue($baru, $this->semen));
    }

    public function test_a_later_purchase_does_not_reprice_goods_already_in_transit(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 40]]);
        $this->stock()->sendTransfer($transfer); // frozen at 15.000

        // Source is restocked at a much higher price while the truck is en route:
        // (60 * 15.000 + 100 * 30.000) / 160 = 3.900.000 / 160 = 24.375
        //
        // Dated AFTER the 2026-03-20 transfer, which is what "a later purchase"
        // means and what StockService::assertMovementInOrder now requires. The
        // fixture used to say 2026-03-18 — two days BEFORE the stock it is
        // restocking had left — and passed only because the engine ignored the
        // date entirely when valuing.
        $this->receiveStock($this->pusat, $this->semen, 100, 30000, '2026-03-22');
        $this->assertSame(24375.0, $this->balanceAvg($this->pusat, $this->semen));

        $this->stock()->receiveTransfer($transfer->fresh());

        // The destination still books the frozen 15.000, not 24.375:
        // (20 * 21.000 + 40 * 15.000) / 60 = 17.000
        $this->assertSame(17000.0, $this->balanceAvg($this->site, $this->semen));
    }

    public function test_transferring_more_than_the_source_holds_throws_and_moves_nothing(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 200]]);

        try {
            $this->stock()->sendTransfer($transfer);
            $this->fail('Expected a DomainException for insufficient stock.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Stok tidak mencukupi', $e->getMessage());
        }

        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(20.0, $this->balanceQty($this->site, $this->semen));
        $this->assertSame(TransferStatus::Draft, $transfer->fresh()->status);
        $this->assertSame(0.0, (float) $transfer->items()->first()->unit_cost);
    }

    public function test_a_transfer_cannot_be_sent_twice(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 40]]);
        $this->stock()->sendTransfer($transfer);

        try {
            $this->stock()->sendTransfer($transfer->fresh());
            $this->fail('Expected a LogicException when re-sending a transfer.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only draft transfers can be sent', $e->getMessage());
        }

        // 100 - 40 once, not twice.
        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_draft_transfer_cannot_be_received(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 40]]);

        try {
            $this->stock()->receiveTransfer($transfer);
            $this->fail('Expected a LogicException when receiving a draft transfer.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only in-transit transfers can be received', $e->getMessage());
        }

        // Nothing arrived: destination still at its opening position.
        $this->assertSame(20.0, $this->balanceQty($this->site, $this->semen));
        $this->assertSame(21000.0, $this->balanceAvg($this->site, $this->semen));
    }

    public function test_a_transfer_cannot_be_received_twice(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 40]]);
        $this->stock()->sendTransfer($transfer);
        $this->stock()->receiveTransfer($transfer->fresh());

        try {
            $this->stock()->receiveTransfer($transfer->fresh());
            $this->fail('Expected a LogicException when re-receiving a transfer.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only in-transit transfers can be received', $e->getMessage());
        }

        // 60, not 100: the 40 arrived exactly once.
        $this->assertSame(60.0, $this->balanceQty($this->site, $this->semen));
        $this->assertSame(17000.0, $this->balanceAvg($this->site, $this->semen));
    }

    public function test_a_transfer_without_lines_cannot_be_sent(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no lines to send');

        $this->stock()->sendTransfer($transfer);
    }

    public function test_the_transfer_writes_one_out_row_and_one_in_row_to_the_ledger(): void
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 40]]);
        $this->stock()->sendTransfer($transfer);
        $this->stock()->receiveTransfer($transfer->fresh());

        $out = $this->ledgerFor($this->pusat, $this->semen)->last();
        $in = $this->ledgerFor($this->site, $this->semen)->last();

        $this->assertSame('out', $out->direction);
        $this->assertSame(15000.0, (float) $out->unit_cost);
        $this->assertSame(60.0, (float) $out->balance_qty_after);

        $this->assertSame('in', $in->direction);
        $this->assertSame(15000.0, (float) $in->unit_cost); // the frozen cost, not 17.000
        $this->assertSame(60.0, (float) $in->balance_qty_after);

        $this->assertSame($transfer->getMorphClass(), $out->reference_type);
        $this->assertSame($transfer->getMorphClass(), $in->reference_type);
    }
}
