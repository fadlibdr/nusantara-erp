<?php

namespace Tests\Unit\Inventory;

use DomainException;
use LogicException;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;

/**
 * Perpetual moving-average valuation on receipts and issues.
 *
 * No chart of accounts is seeded here on purpose: StockService only posts to
 * the GL when accounts exist, so these tests exercise valuation in isolation.
 * The GL behaviour lives in tests/Feature/Inventory.
 */
class MovingAverageCostTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $warehouse;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
    }

    public function test_the_first_receipt_adopts_the_purchase_price_as_the_warehouse_average(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);

        // Empty balance contributes nothing: new_avg = unit_cost = 15.000
        $this->assertSame(100.0, $this->balanceQty($this->warehouse, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->warehouse, $this->semen));

        $ledger = $this->ledgerFor($this->warehouse, $this->semen);
        $this->assertCount(1, $ledger);
        $this->assertSame('in', $ledger[0]->direction);
        $this->assertSame(100.0, (float) $ledger[0]->qty);
        $this->assertSame(15000.0, (float) $ledger[0]->unit_cost);
        $this->assertSame(100.0, (float) $ledger[0]->balance_qty_after);
    }

    public function test_a_second_receipt_at_a_different_price_moves_the_average(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);
        $this->receiveStock($this->warehouse, $this->semen, 50, 18000);

        // (100 * 15.000 + 50 * 18.000) / (100 + 50)
        //   = (1.500.000 + 900.000) / 150 = 2.400.000 / 150 = 16.000
        $this->assertSame(150.0, $this->balanceQty($this->warehouse, $this->semen));
        $this->assertSame(16000.0, $this->balanceAvg($this->warehouse, $this->semen));
        $this->assertSame(2400000.0, $this->balanceValue($this->warehouse, $this->semen));
    }

    public function test_the_moving_average_is_rounded_to_two_decimals(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 30, 10000);
        $this->receiveStock($this->warehouse, $this->semen, 7, 12500);

        // (30 * 10.000 + 7 * 12.500) / 37 = 387.500 / 37 = 10.472,972972...
        // rounded to money precision (2 dp) = 10.472,97
        $this->assertSame(37.0, $this->balanceQty($this->warehouse, $this->semen));
        $this->assertSame(10472.97, $this->balanceAvg($this->warehouse, $this->semen));
    }

    public function test_a_receipt_records_the_last_purchase_price_and_the_global_average(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 30, 10000);
        $this->receiveStock($this->warehouse, $this->semen, 7, 12500);

        $this->semen->refresh();

        // last_price is the raw price of the newest receipt, not an average.
        $this->assertSame(12500.0, (float) $this->semen->last_price);
        // Only one warehouse holds stock, so the global average equals it.
        $this->assertSame(10472.97, (float) $this->semen->avg_cost);
    }

    public function test_an_issue_is_valued_at_the_warehouse_average_and_leaves_that_average_untouched(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);
        $this->receiveStock($this->warehouse, $this->semen, 50, 18000); // avg = 16.000

        $issue = $this->stock()->postIssue($this->makeIssue($this->warehouse, [[$this->semen, 40]]));

        // 40 zak * 16.000 = 640.000 leaves the balance sheet.
        $line = $issue->items()->first();
        $this->assertSame(16000.0, (float) $line->unit_cost);
        $this->assertSame(640000.0, (float) $line->amount);

        // Quantity drops by 40, the average is NOT recomputed on the way out.
        $this->assertSame(110.0, $this->balanceQty($this->warehouse, $this->semen));
        $this->assertSame(16000.0, $this->balanceAvg($this->warehouse, $this->semen));
        // 150 * 16.000 - 640.000 = 1.760.000 = 110 * 16.000
        $this->assertSame(1760000.0, $this->balanceValue($this->warehouse, $this->semen));

        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
    }

    public function test_issuing_the_whole_balance_leaves_zero_quantity_at_the_same_average(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);
        $this->receiveStock($this->warehouse, $this->semen, 50, 18000); // avg = 16.000

        $this->stock()->postIssue($this->makeIssue($this->warehouse, [[$this->semen, 150]]));

        // Boundary: requesting exactly what is on hand is allowed.
        $this->assertSame(0.0, $this->balanceQty($this->warehouse, $this->semen));
        // Valuation continuity: the average survives a zero balance.
        $this->assertSame(16000.0, $this->balanceAvg($this->warehouse, $this->semen));
    }

    public function test_issuing_more_than_the_balance_throws_and_leaves_the_balance_untouched(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);

        $issue = $this->makeIssue($this->warehouse, [[$this->semen, 100.001]]);

        try {
            $this->stock()->postIssue($issue);
            $this->fail('Expected a DomainException for insufficient stock.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Stok tidak mencukupi', $e->getMessage());
        }

        // 100,000 available + 0,0005 tolerance < 100,001 requested => refused.
        $this->assertSame(100.0, $this->balanceQty($this->warehouse, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->warehouse, $this->semen));
        $this->assertSame(StockDocumentStatus::Draft, $issue->fresh()->status);
        $this->assertSame(0.0, (float) $issue->items()->first()->unit_cost);
        // Only the receipt row exists: the refused OUT row was rolled back.
        $this->assertCount(1, $this->ledgerFor($this->warehouse, $this->semen));
    }

    public function test_issuing_from_an_item_the_warehouse_never_stocked_throws(): void
    {
        $issue = $this->makeIssue($this->warehouse, [[$this->semen, 1]]);

        $this->expectException(DomainException::class);

        try {
            $this->stock()->postIssue($issue);
        } finally {
            $this->assertSame(0.0, $this->balanceQty($this->warehouse, $this->semen));
            $this->assertSame(StockDocumentStatus::Draft, $issue->fresh()->status);
        }
    }

    public function test_a_multi_line_issue_rolls_back_entirely_when_one_line_is_short(): void
    {
        $besi = $this->makeItem('Besi Beton D13', ['unit' => 'btg']);

        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);
        $this->receiveStock($this->warehouse, $besi, 10, 120000);

        // Line 1 (semen) is affordable, line 2 (besi) is not.
        $issue = $this->makeIssue($this->warehouse, [[$this->semen, 40], [$besi, 25]]);

        $this->expectException(DomainException::class);

        try {
            $this->stock()->postIssue($issue);
        } finally {
            // The successful first line must not survive the failed second one.
            $this->assertSame(100.0, $this->balanceQty($this->warehouse, $this->semen));
            $this->assertSame(10.0, $this->balanceQty($this->warehouse, $besi));
            $this->assertSame(StockDocumentStatus::Draft, $issue->fresh()->status);
            $this->assertSame(2, StockLedgerEntry::query()->count()); // the two receipts only
        }
    }

    public function test_a_receipt_cannot_be_posted_twice(): void
    {
        $grn = $this->receiveStock($this->warehouse, $this->semen, 100, 15000);

        try {
            $this->stock()->postReceipt($grn->fresh());
            $this->fail('Expected a LogicException when re-posting a posted GRN.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only draft GRNs can be posted', $e->getMessage());
        }

        // Guard held: stock was applied once, not twice.
        $this->assertSame(100.0, $this->balanceQty($this->warehouse, $this->semen));
        $this->assertCount(1, $this->ledgerFor($this->warehouse, $this->semen));
    }

    public function test_an_issue_cannot_be_posted_twice(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);

        $issue = $this->stock()->postIssue($this->makeIssue($this->warehouse, [[$this->semen, 40]]));

        try {
            $this->stock()->postIssue($issue->fresh());
            $this->fail('Expected a LogicException when re-posting a posted issue.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only draft issues can be posted', $e->getMessage());
        }

        // 100 - 40 once, not 100 - 40 - 40.
        $this->assertSame(60.0, $this->balanceQty($this->warehouse, $this->semen));
        $this->assertCount(2, $this->ledgerFor($this->warehouse, $this->semen));
    }

    public function test_a_receipt_without_lines_cannot_be_posted(): void
    {
        $grn = $this->makeGrn($this->warehouse, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no lines to post');

        $this->stock()->postReceipt($grn);
    }

    public function test_an_issue_without_lines_cannot_be_posted(): void
    {
        $issue = $this->makeIssue($this->warehouse, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no lines to post');

        $this->stock()->postIssue($issue);
    }

    public function test_a_zero_quantity_receipt_line_is_refused(): void
    {
        $grn = $this->makeGrn($this->warehouse, [[$this->semen, 0, 15000]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Stock-in quantity must be positive.');

        $this->stock()->postReceipt($grn);
    }

    public function test_a_zero_quantity_issue_line_is_refused(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);

        $issue = $this->makeIssue($this->warehouse, [[$this->semen, 0]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Stock-out quantity must be positive.');

        $this->stock()->postIssue($issue);
    }

    public function test_posting_a_receipt_for_a_deleted_item_fails_cleanly(): void
    {
        $grn = $this->makeGrn($this->warehouse, [[$this->semen, 10, 15000]]);

        $this->semen->delete(); // allowed: the item has no stock yet

        $this->expectException(LogicException::class);

        try {
            $this->stock()->postReceipt($grn);
        } finally {
            $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
            $this->assertNull($this->balanceOf($this->warehouse, $this->semen));
        }
    }

    public function test_the_ledger_and_the_stock_balance_always_agree(): void
    {
        $this->receiveStock($this->warehouse, $this->semen, 100, 15000);
        $this->receiveStock($this->warehouse, $this->semen, 50, 18000);
        $this->stock()->postIssue($this->makeIssue($this->warehouse, [[$this->semen, 40]]));
        $this->stock()->postIssue($this->makeIssue($this->warehouse, [[$this->semen, 25]]));

        $ledger = $this->ledgerFor($this->warehouse, $this->semen);

        // 100 + 50 - 40 - 25 = 85
        $this->assertCount(4, $ledger);
        $this->assertSame(85.0, (float) $ledger->last()->balance_qty_after);
        $this->assertSame(85.0, $this->balanceQty($this->warehouse, $this->semen));

        $signed = $ledger->sum(fn (StockLedgerEntry $row): float => $row->direction === 'in'
            ? (float) $row->qty
            : -(float) $row->qty);

        $this->assertSame(85.0, round($signed, 3));

        // Both issues were valued at the 16.000 average established by the
        // receipts, so the outgoing rows carry that cost, not the purchase price.
        $this->assertSame([15000.0, 18000.0, 16000.0, 16000.0], $ledger
            ->map(fn (StockLedgerEntry $row): float => (float) $row->unit_cost)
            ->all());
    }

    public function test_every_ledger_row_points_back_at_its_source_document(): void
    {
        $grn = $this->receiveStock($this->warehouse, $this->semen, 100, 15000);
        $issue = $this->stock()->postIssue($this->makeIssue($this->warehouse, [[$this->semen, 40]]));

        $ledger = $this->ledgerFor($this->warehouse, $this->semen);

        $this->assertSame($grn->getMorphClass(), $ledger[0]->reference_type);
        $this->assertSame((int) $grn->id, (int) $ledger[0]->reference_id);
        $this->assertSame($issue->getMorphClass(), $ledger[1]->reference_type);
        $this->assertSame((int) $issue->id, (int) $ledger[1]->reference_id);
    }
}
