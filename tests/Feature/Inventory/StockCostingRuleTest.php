<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * THE COSTING RULE, measured. StockService's class docblock states it in three
 * clauses and this file is the proof of the two that decide money:
 *
 *   1. Stock is costed FORWARD, in the order movements are recorded, so a
 *      movement dated before the last one for that (warehouse, item) is refused
 *      rather than valued at today's mix.
 *   2. The stored (qty, avg_cost) balance is the authority and every GL leg is
 *      the change in it, so GL 1-1400 and sum(qty * avg_cost) agree exactly
 *      instead of approximately.
 *
 * The audit measured what their absence cost. A delivery note for 100 zak semen
 * at Rp 62.000, delivered on 12 July but keyed on 20 July — after the 15 July
 * bon for the same 100 zak had already posted at the old Rp 55.000 average —
 * understated the project's Beban Material by Rp 700.000 and left the same
 * rupiah overstated in 1-1400, with no warning and no refusal. And a receipt of
 * 3 at Rp 1.000 followed by 4 at Rp 1.001 left Rp 0,01 in 1-1400 that survived
 * the stock going to zero, because the average is stored to two decimals while
 * the ledger was debited the invoice's own arithmetic.
 */
class StockCostingRuleTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    private Warehouse $pusat;

    private Warehouse $site;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->site = $this->makeWarehouse('WH-SITE');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
    }

    // ------------------------------------------------ rule 1: movements in order

    public function test_a_receipt_dated_before_the_last_movement_of_that_stock_is_refused(): void
    {
        // 10 March: 100 zak @ 10.000. 15 March: all 100 issued at that average.
        $this->receiveStock($this->pusat, $this->semen, 100, 10000, '2026-03-10');
        $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 100]], null, '2026-03-15'));

        // The delivery note for goods that arrived on the 12th turns up late.
        $late = $this->makeGrn($this->pusat, [[$this->semen, 100, 20000]], '2026-03-12');

        try {
            $this->stock()->postReceipt($late);
            $this->fail('Expected a back-dated receipt to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2026-03-12', $e->getMessage());
            $this->assertStringContainsString('2026-03-15', $e->getMessage());
            $this->assertStringContainsString('Semen Gresik 40kg', $e->getMessage());
            $this->assertStringContainsString('WH-PUSAT', $e->getMessage());
        }

        // Refused means refused, and the numbers the audit measured never appear:
        // the issue keeps its honest 100 * 10.000 = 1.000.000 and 1-1400 is empty
        // rather than carrying 100 @ 20.000 against a 1.000.000 expense.
        $this->assertSame(StockDocumentStatus::Draft, $late->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(1000000.0, $this->accountNet('6-4100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(2, StockLedgerEntry::query()->count());
    }

    public function test_the_same_receipt_posts_once_it_is_dated_on_or_after_the_last_movement(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 10000, '2026-03-10');
        $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 100]], null, '2026-03-15'));

        // Same document, re-dated to the day the storeman actually recorded it.
        $repaired = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 20000]], '2026-03-15')
        );

        $this->assertSame(StockDocumentStatus::Posted, $repaired->fresh()->status);
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(20000.0, $this->balanceAvg($this->pusat, $this->semen));
        // 2.000.000 in, 1.000.000 already expensed: the split is now truthful.
        $this->assertSame(2000000.0, $this->accountNet('1-1400'));
    }

    public function test_a_movement_dated_on_the_very_same_day_as_the_last_one_is_allowed(): void
    {
        // Several deliveries a day is ordinary warehouse traffic; the rule is
        // about ORDER, not about one document per day.
        $this->receiveStock($this->pusat, $this->semen, 100, 10000, '2026-03-10');

        $second = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 12000]], '2026-03-10')
        );

        $this->assertSame(StockDocumentStatus::Posted, $second->fresh()->status);
        // (100 * 10.000 + 100 * 12.000) / 200 = 2.200.000 / 200 = 11.000
        $this->assertSame(200.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(11000.0, $this->balanceAvg($this->pusat, $this->semen));
    }

    public function test_the_chronology_is_kept_per_warehouse_and_item_not_across_the_company(): void
    {
        // WH-PUSAT's history says nothing about WH-SITE's average, and a bar of
        // besi says nothing about a zak of semen — so neither may block the
        // other. A guard that read one company-wide clock would stop a site
        // warehouse recording yesterday's delivery because head office booked
        // something today.
        $besi = $this->makeItem('Besi Beton D13', ['unit' => 'btg']);

        $this->receiveStock($this->pusat, $this->semen, 100, 10000, '2026-03-20');

        $otherWarehouse = $this->stock()->postReceipt(
            $this->makeGrn($this->site, [[$this->semen, 50, 11000]], '2026-03-05')
        );
        $otherItem = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$besi, 30, 90000]], '2026-03-05')
        );

        $this->assertSame(StockDocumentStatus::Posted, $otherWarehouse->fresh()->status);
        $this->assertSame(StockDocumentStatus::Posted, $otherItem->fresh()->status);
        $this->assertSame(50.0, $this->balanceQty($this->site, $this->semen));
        $this->assertSame(30.0, $this->balanceQty($this->pusat, $besi));
    }

    public function test_a_back_dated_bon_is_refused_as_well_as_a_back_dated_receipt(): void
    {
        // Every path obeys the same rule; an issue valued at an average that
        // post-dates it is exactly as wrong as a receipt.
        $this->receiveStock($this->pusat, $this->semen, 100, 10000, '2026-03-10');
        $this->receiveStock($this->pusat, $this->semen, 100, 20000, '2026-03-20');

        $backDated = $this->makeIssue($this->pusat, [[$this->semen, 40]], null, '2026-03-15');

        try {
            $this->stock()->postIssue($backDated);
            $this->fail('Expected a back-dated issue to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2026-03-20', $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Draft, $backDated->fresh()->status);
        $this->assertSame(200.0, $this->balanceQty($this->pusat, $this->semen));
    }

    // ------------------------------- rule 2: the GL leg is the sub-ledger movement

    public function test_the_ledger_is_debited_what_the_sub_ledger_gained_not_what_the_invoice_says(): void
    {
        // The audit's numbers exactly: 3 @ 1.000 then 4 @ 1.001. True purchase
        // value is 7.004,00, but the stored average is 1.000,57 (two decimals),
        // so seven units are worth 7.003,99 in the sub-ledger. Debiting 7.004,00
        // is what left a centavo of persediaan behind for ever.
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 3, 1000]], '2026-03-10'));
        $second = $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 4, 1001]], '2026-03-10'));

        $this->assertSame(1000.57, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertSame(7003.99, $this->balanceValue($this->pusat, $this->semen));

        // 7 * 1.000,57 - 3 * 1.000 = 7.003,99 - 3.000 = 4.003,99, not 4 * 1.001.
        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $second->id));
        $this->assertSame(4003.99, $lines['1-1400']['debit']);

        // And the identity the reconciliation asserts holds to the rupiah.
        $this->assertSame(7003.99, $this->accountNet('1-1400'));
        $this->assertSame($this->stockSubLedgerValue(), $this->accountNet('1-1400'));
    }

    public function test_issuing_every_last_unit_empties_the_inventory_account_completely(): void
    {
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 3, 1000]], '2026-03-10'));
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 4, 1001]], '2026-03-10'));

        $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 7]], null, '2026-03-15'));

        // Rp 0,01 used to survive here against zero stock, relievable by nothing.
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, $this->stockSubLedgerValue());
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(7003.99, $this->accountNet('6-4100'));
    }

    public function test_an_opname_surplus_books_the_value_the_balance_actually_gained(): void
    {
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 3, 1000]], '2026-03-10'));
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 4, 1001]], '2026-03-10'));

        // Counting 10 where the system holds 7: the three found zak come in at
        // the warehouse average of 1.000,57.
        $this->stock()->postAdjustment($this->makeAdjustment($this->pusat, [[$this->semen, 10]], '2026-03-25'));

        $this->assertSame(10.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(10005.7, $this->stockSubLedgerValue());
        $this->assertSame($this->stockSubLedgerValue(), $this->accountNet('1-1400'));
        // 10 * 1.000,57 - 7 * 1.000,57 = 3.001,71 of surplus against 6-4400.
        $this->assertSame(-3001.71, $this->accountNet('6-4400'));
    }

    // -------------------------------------------- the stale item-level average

    public function test_sending_a_transfer_refreshes_the_item_global_average_like_every_other_stock_out(): void
    {
        // The audit's figures. Two warehouses, 100 @ 15.000 and 20 @ 21.000:
        // (1.500.000 + 420.000) / 120 = 16.000.
        $this->receiveStock($this->pusat, $this->semen, 100, 15000, '2026-03-01');
        $this->receiveStock($this->site, $this->semen, 20, 21000, '2026-03-01');
        $this->assertSame(16000.0, (float) $this->semen->fresh()->avg_cost);

        $lain = $this->makeWarehouse('WH-LAIN');
        $transfer = $this->stock()->sendTransfer(
            $this->makeTransfer($this->pusat, $lain, [[$this->semen, 40]], '2026-03-05')
        );

        $this->assertSame(TransferStatus::InTransit, $transfer->fresh()->status);

        // What is actually ON HAND is now 60 @ 15.000 + 20 @ 21.000:
        // 1.320.000 / 80 = 16.500. The field used to sit at 16.000 for the whole
        // transit window, and postAdjustment values found stock at exactly this
        // field when the counting warehouse has no history of its own.
        $this->assertSame(16500.0, (float) $this->semen->fresh()->avg_cost);
    }

    public function test_the_stale_item_average_no_longer_misvalues_found_stock_in_a_third_warehouse(): void
    {
        $this->receiveStock($this->pusat, $this->semen, 100, 15000, '2026-03-01');
        $this->receiveStock($this->site, $this->semen, 20, 21000, '2026-03-01');

        $lain = $this->makeWarehouse('WH-LAIN');
        $this->stock()->sendTransfer($this->makeTransfer($this->pusat, $lain, [[$this->semen, 40]], '2026-03-05'));

        // An opname in a fourth warehouse that has never held the item: the
        // fallback is the item-level average, so 40 found zak are worth
        // 40 * 16.500 = 660.000 and not the 40 * 16.000 = 640.000 the stale
        // field produced — a 3% understatement of the surplus posted to 6-4400.
        $ketiga = $this->makeWarehouse('WH-KETIGA');
        $this->stock()->postAdjustment($this->makeAdjustment($ketiga, [[$this->semen, 40]], '2026-03-25'));

        $this->assertSame(16500.0, $this->balanceAvg($ketiga, $this->semen));
        $this->assertSame(660000.0, $this->balanceValue($ketiga, $this->semen));
        $this->assertSame(-660000.0, $this->accountNet('6-4400'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Signed movement of one COA account: debit - credit.
     */
    private function accountNet(string $code): float
    {
        $row = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', $code)
            ->whereNull('fin_journals.deleted_at')
            ->where('fin_journals.status', 'posted')
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) - COALESCE(SUM(fin_journal_lines.credit), 0) AS net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }

    /**
     * The whole stock sub-ledger: sum(qty * avg_cost). The figure the perpetual
     * reconciliation compares against GL 1-1400.
     */
    private function stockSubLedgerValue(): float
    {
        return round((float) DB::table('inv_stock_balances')->sum(DB::raw('qty * avg_cost')), 2);
    }
}
