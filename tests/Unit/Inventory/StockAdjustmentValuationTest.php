<?php

namespace Tests\Unit\Inventory;

use DomainException;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockAdjustmentService;
use Tests\ErpTestCase;

/**
 * Stock opname: surplus books in and shortage books out, both at the warehouse
 * moving average, with documented fallbacks when the warehouse has no cost
 * history for the item.
 */
class StockAdjustmentValuationTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $pusat;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
    }

    public function test_the_opname_sheet_snapshots_the_system_quantity_and_the_difference(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        $adjustment = $this->makeAdjustment($this->pusat, [[$this->semen, 110]]);
        $line = $adjustment->items()->first();

        // diff = counted - system = 110 - 100 = +10
        $this->assertSame(100.0, (float) $line->system_qty);
        $this->assertSame(110.0, (float) $line->counted_qty);
        $this->assertSame(10.0, (float) $line->diff_qty);
    }

    public function test_a_surplus_books_in_at_the_warehouse_average(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 110]])
        );

        // +10 at the warehouse average of 15.000 = 150.000 booked in.
        $this->assertSame(15000.0, (float) $adjustment->items()->first()->unit_cost);
        $this->assertSame(110.0, $this->balanceQty($this->pusat, $this->semen));
        // (100 * 15.000 + 10 * 15.000) / 110 = 15.000 — booking at the current
        // average leaves the average alone.
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertSame(1650000.0, $this->balanceValue($this->pusat, $this->semen));

        $row = $this->ledgerFor($this->pusat, $this->semen)->last();
        $this->assertSame('in', $row->direction);
        $this->assertSame(10.0, (float) $row->qty);
        $this->assertSame(15000.0, (float) $row->unit_cost);

        $this->assertNotNull($adjustment->fresh()->posted_at);
    }

    public function test_a_shortage_books_out_at_the_warehouse_average(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 90]])
        );

        // -10 at 15.000 = 150.000 written off; the average is untouched.
        $this->assertSame(15000.0, (float) $adjustment->items()->first()->unit_cost);
        $this->assertSame(90.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertSame(1350000.0, $this->balanceValue($this->pusat, $this->semen));

        $row = $this->ledgerFor($this->pusat, $this->semen)->last();
        $this->assertSame('out', $row->direction);
        $this->assertSame(10.0, (float) $row->qty);
        $this->assertSame(15000.0, (float) $row->unit_cost);
    }

    public function test_a_zero_difference_books_nothing_but_still_marks_the_document_posted(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 100]])
        );

        $this->assertSame(0.0, (float) $adjustment->items()->first()->diff_qty);
        $this->assertSame(0.0, (float) $adjustment->items()->first()->unit_cost);
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        // Only the receipt row: no ledger movement was written.
        $this->assertCount(1, $this->ledgerFor($this->pusat, $this->semen));
        $this->assertNotNull($adjustment->fresh()->posted_at);
    }

    public function test_a_surplus_falls_back_to_the_item_global_average_when_the_warehouse_has_no_history(): void
    {
        // Stock found in a warehouse that never received this item: the global
        // weighted average is the best available valuation.
        $keramik = $this->makeItem('Keramik 60x60', ['avg_cost' => 9000, 'last_price' => 7500]);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$keramik, 5]])
        );

        $this->assertSame(9000.0, (float) $adjustment->items()->first()->unit_cost);
        $this->assertSame(5.0, $this->balanceQty($this->pusat, $keramik));
        $this->assertSame(9000.0, $this->balanceAvg($this->pusat, $keramik));
        $this->assertSame(45000.0, $this->balanceValue($this->pusat, $keramik)); // 5 * 9.000
    }

    public function test_a_surplus_falls_back_to_the_last_purchase_price_when_there_is_no_average_at_all(): void
    {
        $keramik = $this->makeItem('Keramik 60x60', ['avg_cost' => 0, 'last_price' => 7500]);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$keramik, 5]])
        );

        $this->assertSame(7500.0, (float) $adjustment->items()->first()->unit_cost);
        $this->assertSame(7500.0, $this->balanceAvg($this->pusat, $keramik));
        $this->assertSame(37500.0, $this->balanceValue($this->pusat, $keramik)); // 5 * 7.500
    }

    public function test_a_surplus_of_an_item_with_no_cost_history_at_all_books_in_at_zero(): void
    {
        $keramik = $this->makeItem('Keramik 60x60', ['avg_cost' => 0, 'last_price' => 0]);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$keramik, 5]])
        );

        // Quantity is recorded, value is zero — nothing better is knowable.
        $this->assertSame(0.0, (float) $adjustment->items()->first()->unit_cost);
        $this->assertSame(5.0, $this->balanceQty($this->pusat, $keramik));
        $this->assertSame(0.0, $this->balanceAvg($this->pusat, $keramik));
    }

    public function test_surplus_and_shortage_lines_are_each_valued_at_their_own_average(): void
    {
        $besi = $this->makeItem('Besi Beton D13', ['unit' => 'btg']);

        $this->receiveStock($this->pusat, $this->semen, 100, 15000);
        $this->receiveStock($this->pusat, $besi, 50, 25000);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 110], [$besi, 44]])
        );

        $lines = $adjustment->items()->orderBy('id')->get();

        // semen: +10 * 15.000 = +150.000 ; besi: -6 * 25.000 = -150.000
        $this->assertSame(15000.0, (float) $lines[0]->unit_cost);
        $this->assertSame(25000.0, (float) $lines[1]->unit_cost);
        $this->assertSame(110.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(44.0, $this->balanceQty($this->pusat, $besi));
        // 110 * 15.000 + 44 * 25.000 = 1.650.000 + 1.100.000 = 2.750.000
        $this->assertSame(
            2750000.0,
            $this->balanceValue($this->pusat, $this->semen) + $this->balanceValue($this->pusat, $besi)
        );
    }

    public function test_an_adjustment_can_only_be_posted_once(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 110]])
        );

        try {
            $this->stock()->postAdjustment($adjustment->fresh());
            $this->fail('Expected a LogicException when re-posting an adjustment.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('has already been posted', $e->getMessage());
        }

        // 110, not 120: the +10 was applied exactly once.
        $this->assertSame(110.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertCount(2, $this->ledgerFor($this->pusat, $this->semen));
    }

    public function test_an_unapproved_adjustment_cannot_be_posted(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        $adjustment = $this->makeAdjustment($this->pusat, [[$this->semen, 110]], approve: false);

        try {
            $this->stock()->postAdjustment($adjustment);
            $this->fail('Expected a LogicException when posting a draft adjustment.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only approved adjustments can be posted', $e->getMessage());
        }

        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertNull($adjustment->fresh()->posted_at);
        $this->assertSame(DocumentStatus::Draft, $adjustment->fresh()->status);
    }

    public function test_a_shortage_larger_than_the_current_balance_rolls_back_the_approval(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        // Counted zero against a system quantity of 100 => diff -100 ...
        $adjustment = $this->makeAdjustment($this->pusat, [[$this->semen, 0]], approve: false);
        $adjustment->submit($this->warehouseUser());

        // ... but 50 zak are issued before the sheet is approved, leaving 50.
        $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 50]]));

        try {
            app(StockAdjustmentService::class)->approveAndPost($adjustment->fresh(), $this->inventoryApprover());
            $this->fail('Expected a DomainException for insufficient stock.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Stok tidak mencukupi', $e->getMessage());
        }

        // Approval and posting are one transaction: neither happened.
        $this->assertSame(DocumentStatus::Submitted, $adjustment->fresh()->status);
        $this->assertNull($adjustment->fresh()->posted_at);
        $this->assertSame(50.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertSame(2, StockLedgerEntry::query()->count()); // receipt + issue only
    }

    public function test_approve_and_post_books_the_difference_in_one_step(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000);

        $adjustment = $this->makeAdjustment($this->pusat, [[$this->semen, 90]], approve: false);
        $adjustment->submit($this->warehouseUser());

        $posted = app(StockAdjustmentService::class)
            ->approveAndPost($adjustment->fresh(), $this->inventoryApprover(), 'Selisih gudang');

        $this->assertSame(DocumentStatus::Approved, $posted->fresh()->status);
        $this->assertNotNull($posted->fresh()->posted_at);
        $this->assertSame(90.0, $this->balanceQty($this->pusat, $this->semen));
    }
}
