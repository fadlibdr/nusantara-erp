<?php

namespace Tests\Feature\Inventory;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * The transactional guarantee: stock movement and its journal are one unit of
 * work. If the GL refuses the posting (closed or missing fiscal period), the
 * stock movement must vanish with it — a warehouse balance that no journal
 * backs would silently break the perpetual reconciliation.
 */
class FiscalPeriodRollbackTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    private const PROJECT_ID = 77;

    private Warehouse $pusat;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
    }

    private function closePeriod(int $year, int $month): void
    {
        FiscalPeriod::query()
            ->where('year', $year)
            ->where('month', $month)
            ->update(['status' => 'closed']);
    }

    public function test_a_receipt_into_a_closed_period_rolls_back_the_stock_movement(): void
    {
        $this->closePeriod(2026, 3);

        $grn = $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10');

        try {
            $this->stock()->postReceipt($grn);
            $this->fail('Expected a LogicException for a closed fiscal period.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-03 sudah ditutup', $e->getMessage());
        }

        // Nothing at all survived: no balance row, no ledger row, no journal,
        // no last_price update, document still draft.
        $this->assertNull($this->balanceOf($this->pusat, $this->semen));
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0.0, (float) $this->semen->fresh()->last_price);
        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
    }

    public function test_a_receipt_dated_outside_every_fiscal_period_rolls_back_the_stock_movement(): void
    {
        // seedLedger only opened 2026; 2027 has no periods at all.
        $grn = $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2027-03-10');

        try {
            $this->stock()->postReceipt($grn);
            $this->fail('Expected a LogicException for a missing fiscal period.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Belum ada periode fiskal untuk 2027-03-10', $e->getMessage());
        }

        $this->assertNull($this->balanceOf($this->pusat, $this->semen));
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
    }

    public function test_an_issue_into_a_closed_period_rolls_back_the_stock_movement(): void
    {
        $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-02-10')
        );

        $this->closePeriod(2026, 4);

        $issue = $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-04-05');

        try {
            $this->stock()->postIssue($issue);
            $this->fail('Expected a LogicException for a closed fiscal period.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-04 sudah ditutup', $e->getMessage());
        }

        // The balance is back at the full 100 * 15.000 = 1.500.000.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertSame(1500000.0, $this->balanceValue($this->pusat, $this->semen));

        // Only the February receipt row and its journal remain.
        $this->assertSame(1, StockLedgerEntry::query()->count());
        $this->assertSame(1, Journal::query()->count());
        $this->assertSame(0, ProjectCost::query()->count());

        $issue->refresh();
        $this->assertSame(StockDocumentStatus::Draft, $issue->status);
        $this->assertSame(0.0, (float) $issue->items()->first()->unit_cost);
        $this->assertSame(0.0, (float) $issue->items()->first()->amount);
    }

    public function test_an_adjustment_into_a_closed_period_rolls_back_and_stays_unposted(): void
    {
        $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-02-10')
        );

        $this->closePeriod(2026, 4);

        $adjustment = $this->makeAdjustment($this->pusat, [[$this->semen, 90]], '2026-04-25');

        try {
            $this->stock()->postAdjustment($adjustment);
            $this->fail('Expected a LogicException for a closed fiscal period.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-04 sudah ditutup', $e->getMessage());
        }

        $adjustment->refresh();

        // The document stays approved-but-unposted so it can be re-posted once
        // the period is reopened; the count itself is untouched.
        $this->assertSame(DocumentStatus::Approved, $adjustment->status);
        $this->assertNull($adjustment->posted_at);
        $this->assertSame(0.0, (float) $adjustment->items()->first()->unit_cost);
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(1, StockLedgerEntry::query()->count());
    }

    public function test_reopening_the_period_lets_the_same_document_post(): void
    {
        $this->closePeriod(2026, 3);

        $grn = $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10');

        try {
            $this->stock()->postReceipt($grn);
        } catch (LogicException) {
            // expected on the first attempt
        }

        $this->openFiscalYear(2026);

        $this->stock()->postReceipt($grn->fresh());

        // Exactly one application of the receipt, no leftovers from the failure.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(1, StockLedgerEntry::query()->count());
        $this->assertSame(
            1500000.0,
            $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id))['1-1400']['debit']
        );
    }

    public function test_a_closed_period_does_not_block_stock_when_perpetual_inventory_is_off(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);
        $this->closePeriod(2026, 3);

        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );

        // Periodic inventory never consults the fiscal calendar.
        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, Journal::query()->count());
    }
}
