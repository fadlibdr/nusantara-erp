<?php

namespace Tests\Feature\Inventory;

use Modules\Finance\Services\PeriodCloseService;
use Modules\Finance\Support\DanglingDocuments;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * A TRANSFER PINS ITS MONTH TWICE OVER, and the close registry did not have it.
 *
 * A DRAFT transfer is pinned the way a draft bon is: sendTransfer() asks
 * assertStockPeriodOpen() about transfer_date, so once that month closes the
 * transfer can never be sent as written.
 *
 * An IN-TRANSIT one is the only state in the whole product where the stock
 * sub-ledger provably does NOT equal GL 1-1400: the goods have left one
 * warehouse balance and not reached the other while the ledger still carries
 * them. 200 zak Semen Portland from WH-PUSAT to WH-SITE is Rp 12.400.000 the
 * closed month's stock valuation cannot show, and the arrival then lands in the
 * NEXT month — one movement split across a close nobody was told about.
 *
 * Before the fix it was worse than a reporting gap: receiveTransfer() gated the
 * fiscal period on the SEND date, so closing July over a moving truck refused
 * the arrival for ever — an in-transit transfer can be neither edited, deleted
 * nor cancelled, so the value sat in 1-1400 with nothing behind it and no
 * document able to bring it back. The arrival is now an event of the day it
 * happens; this registry entry is the close hearing about the truck in time to
 * decide.
 */
class TransferDanglingDocumentTest extends ErpTestCase
{
    use InventoryFixtures;

    private const YEAR = 2026;

    private const MONTH = 7;

    private Warehouse $pusat;

    private Warehouse $site;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->site = $this->makeWarehouse('WH-SITE');
        $this->semen = $this->makeItem('Semen Portland 50kg');

        $this->receiveStock($this->pusat, $this->semen, 200, 62000, '2026-07-01');
    }

    public function test_an_in_transit_transfer_is_a_dangling_document(): void
    {
        $transfer = $this->stock()->sendTransfer($this->inTransitDraft());

        $scan = DanglingDocuments::scan(self::YEAR, self::MONTH);

        $this->assertSame(1, DanglingDocuments::total($scan));
        $this->assertSame('inv_transfers', $scan[0]['source']);
        $this->assertSame('Transfer gudang', $scan[0]['label']);
        $this->assertSame([$transfer->code.' (dalam perjalanan)'], $scan[0]['codes']);
        $this->assertSame('r/inventory/transfers', $scan[0]['link']);

        // And the value it is standing for: neither warehouse balance holds it.
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, $this->balanceQty($this->site, $this->semen));
    }

    public function test_a_draft_transfer_pins_its_date_the_way_a_draft_bon_does(): void
    {
        $transfer = $this->inTransitDraft();

        $scan = DanglingDocuments::scan(self::YEAR, self::MONTH);

        $this->assertSame(1, DanglingDocuments::total($scan));
        $this->assertSame([$transfer->code.' (draf)'], $scan[0]['codes']);
    }

    public function test_it_blocks_the_close_of_the_month_it_is_dated_in(): void
    {
        $this->stock()->sendTransfer($this->inTransitDraft());

        $item = $this->checklistItem('dangling_documents');

        $this->assertSame(PeriodCloseService::BLOCK, $item['severity']);
        $this->assertSame(PeriodCloseService::FAIL, $item['status']);
        $this->assertStringContainsString('Juli 2026', $item['detail']);
    }

    public function test_receiving_the_goods_first_clears_the_block(): void
    {
        // The escape every entry in the registry promises, in one click: land
        // the truck, and the sub-ledger equals 1-1400 again before the month is
        // signed off.
        $transfer = $this->stock()->sendTransfer($this->inTransitDraft());

        $this->stock()->receiveTransfer($transfer->fresh());

        $this->assertSame(200.0, $this->balanceQty($this->site, $this->semen));
        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
        $this->assertSame(PeriodCloseService::OK, $this->checklistItem('dangling_documents')['status']);
    }

    public function test_a_received_transfer_in_another_month_does_not_block_this_one(): void
    {
        $this->stock()->sendTransfer($this->inTransitDraft('2026-08-04'));

        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
        $this->assertSame(1, DanglingDocuments::total(DanglingDocuments::scan(2026, 8)));
    }

    public function test_periodic_inventory_leaves_it_out_like_every_other_stock_document(): void
    {
        // Under periodic a transfer moves no ledger value and is period-gated by
        // nothing, so its date pins nothing either — the same rule the other
        // inventory sources already carry.
        $this->stock()->sendTransfer($this->inTransitDraft());

        $this->setSetting('accounting.perpetual_inventory', false);

        $this->assertSame(0, DanglingDocuments::total(DanglingDocuments::scan(self::YEAR, self::MONTH)));
    }

    /**
     * The despatch established order at the SOURCE only. If the DESTINATION
     * moved while the truck did, an arrival dated back at the despatch would
     * land behind those rows and leave balance_qty_after describing a sequence
     * that never happened. It falls forward to today — exactly as the period
     * gate learned to — rather than stranding goods that are physically there.
     */
    public function test_an_arrival_falls_forward_when_the_destination_moved_while_in_transit(): void
    {
        $transfer = $this->stock()->sendTransfer($this->inTransitDraft());

        // The destination receives the same item AFTER the despatch, so the
        // despatch date is no longer the last word in that warehouse.
        $this->receiveStock($this->site, $this->semen, 50, 60_000, now()->toDateString());

        $received = $this->stock()->receiveTransfer($transfer->fresh());

        $this->assertSame(now()->toDateString(), $received->received_date->toDateString());
        $this->assertSame(TransferStatus::Received, $received->status);
    }

    // ----------------------------------------------------------------- fixtures

    private function inTransitDraft(string $date = '2026-07-28'): Transfer
    {
        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 200]], $date);

        $this->assertSame(TransferStatus::Draft, $transfer->status);

        return $transfer;
    }

    /**
     * @return array<string, mixed>
     */
    private function checklistItem(string $key): array
    {
        foreach (app(PeriodCloseService::class)->checklist(self::YEAR, self::MONTH) as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail("Checklist item {$key} not found.");
    }
}
