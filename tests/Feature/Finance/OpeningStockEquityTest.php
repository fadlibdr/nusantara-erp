<?php

namespace Tests\Feature\Finance;

use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * WHERE THE COUNTER-ENTRY OF OPENING STOCK BELONGS.
 *
 * The receipt engine has exactly three credit paths (StockService::receiptCreditLeg):
 *
 *   billable PO  Cr 2-1150 GR/IR          a liability a vendor bill clears
 *   vendor       Cr 2-1600 akrual         a liability a manual bill clears
 *   neither      Cr 3-3100 saldo awal     equity — no counterparty, no trade
 *
 * The third one used to credit 6-4400 Selisih Persediaan, which is right for an
 * opname difference and wrong for stock that simply exists: it reports the
 * company's entire starting inventory as operating income in the year it starts
 * trading. The trap is that everything still reconciles — the trial balance
 * balances and 1-1400 agrees with the sub-ledger — while the profit and loss is
 * fiction. On the shipped database that was Rp 351.250.000 of phantom income.
 *
 * The engine credits equity at source now, so new installations cannot reach
 * that state at all; these tests keep pinning the repair, because installations
 * that already carry the P&L credit still have to be corrected.
 *
 * An opening balance has no counterparty, so it raises no liability, and it is
 * not a result of trading, so it is not income. Its counter-entry is equity:
 * 3-3100 Saldo Awal, the intermediate account an accountant later closes to
 * Modal Disetor / Laba Ditahan — a split only a human can make, which is why no
 * migration makes it.
 *
 * A fresh install gets this right by construction (InventoryDatabaseSeeder posts
 * the equity leg itself). These tests pin the repair for installations that
 * already exist, which is the only place the rule can still be got wrong:
 * Modules/Finance/Database/Migrations/2026_07_25_001196_post_opening_stock_balance_to_equity.
 *
 * Every figure is hand-computed beside its assertion, on the shipped demo's own
 * opening stock: 5.000 zak @ 66.502 = 332.510.000.
 */
class OpeningStockEquityTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    /**
     * The shipped demo's opening stock, to the rupiah:
     *   5.000 zak * 66.502 = 332.510.000
     */
    private const OPENING_QTY = 5000.0;

    private const OPENING_UNIT_COST = 66502.0;

    private const OPENING_VALUE = 332510000.0;

    /** A later delivery from a real vendor: 100 * 62.000 = 6.200.000. */
    private const DELIVERY_QTY = 100.0;

    private const DELIVERY_UNIT_COST = 62000.0;

    private const DELIVERY_VALUE = 6200000.0;

    private Warehouse $pusat;

    private Item $semen;

    private Vendor $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->supplier = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ---------------------------------------------------------- no journal at all

    public function test_opening_stock_that_never_reached_the_ledger_is_booked_against_equity(): void
    {
        // Inventory seeds before Finance, so the chart of accounts was still
        // empty when the opening GRN posted and StockService wrote no journal.
        // (Turning the perpetual switch off reproduces the identical state.)
        $grn = $this->postOpeningStockWithoutJournal();

        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0.0, $this->accountNet('1-1400'));

        $this->runOpeningBalanceMigration();

        // Dr 1-1400 332.510.000 / Cr 3-3100 332.510.000 — equity, not the P&L.
        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        $this->assertSame(self::OPENING_VALUE, $lines['1-1400']['debit']);
        $this->assertSame(self::OPENING_VALUE, $lines['3-3100']['credit']);

        // Dated on the receipt, not on the day the repair happened to run.
        $this->assertPostedAndBalanced(
            $this->singleJournalFor('goods_receipt', (int) $grn->id),
            '2026-07-01'
        );

        // No P&L account is touched, and no liability is invented.
        $this->assertSame(0, $this->lineCountFor('6-4400'));
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));

        // Nothing clearable is recorded: there is no counterparty to bill.
        $this->assertNull($grn->fresh()->gl_clearing_account);
    }

    // ---------------------------------------------------------- credit landed in the P&L

    public function test_an_opening_credit_that_landed_in_the_profit_and_loss_is_reclassified_to_equity(): void
    {
        // The receipt engine's OLD no-counterparty rule ran and credited 6-4400.
        // This is the state the shipped database was actually in; the engine no
        // longer posts it (StockService credits equity at source now), so the
        // legacy journal is written here exactly as that release wrote it —
        // which is the only state this migration exists to repair.
        $grn = $this->postOpeningStockWithoutJournal();

        $original = $this->postLegacyVarianceCreditFor($grn);
        $originalLines = $this->linesByAccount($original);

        $this->assertSame(self::OPENING_VALUE, $originalLines['6-4400']['credit']);
        $this->assertSame(-self::OPENING_VALUE, $this->accountNet('6-4400'));

        $this->runOpeningBalanceMigration();

        // A posted journal is a record of what happened: it is corrected by
        // another journal, never rewritten. The original is byte-for-byte intact.
        $this->assertSame(2, $original->fresh()->lines()->count());
        $this->assertSame(
            self::OPENING_VALUE,
            $this->linesByAccount($original->fresh())['6-4400']['credit']
        );

        // Dr 6-4400 332.510.000 / Cr 3-3100 332.510.000 moves the balance off
        // the income statement and onto the balance sheet.
        $reclass = $this->linesByAccount($this->singleJournalFor('opening_stock_reclass', (int) $grn->id));

        $this->assertSame(self::OPENING_VALUE, $reclass['6-4400']['debit']);
        $this->assertSame(self::OPENING_VALUE, $reclass['3-3100']['credit']);

        // -332.510.000 raised + 332.510.000 reclassified = 0: the P&L is clean
        // and the whole opening value now sits in equity.
        $this->assertSame(0.0, $this->accountNet('6-4400'));
        $this->assertSame(-self::OPENING_VALUE, $this->accountNet('3-3100'));
        $this->assertSame(self::OPENING_VALUE, $this->accountNet('1-1400'));
    }

    public function test_the_repair_leaves_the_books_balanced_and_agreeing_with_the_stock_sub_ledger(): void
    {
        $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        $this->runOpeningBalanceMigration();

        // The three coherence checks an auditor runs, on one dataset.
        $this->assertSame(self::OPENING_VALUE, $this->accountNet('1-1400'));
        $this->assertSame(
            $this->balanceValue($this->pusat, $this->semen),
            $this->accountNet('1-1400')
        );

        $trialBalance = $this->reports()->trialBalance(2026, 7);
        $this->assertTrue($trialBalance['balanced']);

        $balanceSheet = $this->reports()->balanceSheet('2026-07-31');
        $this->assertTrue($balanceSheet['balanced']);

        // Assets 332.510.000 = Liabilities 0 + Equity 332.510.000, and the year's
        // result is ZERO — opening stock is not income. That is the whole point:
        // before the repair this reported Rp 332.510.000 of operating profit.
        $this->assertSame(self::OPENING_VALUE, $balanceSheet['assets']['total']);
        $this->assertSame(0.0, $balanceSheet['liabilities']['total']);
        $this->assertSame(self::OPENING_VALUE, $balanceSheet['equity']['total']);
        $this->assertSame(0.0, $this->currentYearResult($balanceSheet));
    }

    // ---------------------------------------------------------- idempotency

    public function test_running_the_repair_twice_books_nothing_a_second_time(): void
    {
        $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        $this->runOpeningBalanceMigration();

        $afterFirst = Journal::query()->count();

        $this->runOpeningBalanceMigration();
        $this->runOpeningBalanceMigration();

        // A redeploy must not double the equity, nor re-credit the P&L.
        $this->assertSame($afterFirst, Journal::query()->count());
        $this->assertSame(0.0, $this->accountNet('6-4400'));
        $this->assertSame(-self::OPENING_VALUE, $this->accountNet('3-3100'));
        $this->assertSame(self::OPENING_VALUE, $this->accountNet('1-1400'));
    }

    public function test_a_journal_less_receipt_repaired_once_is_not_repaired_again(): void
    {
        $this->postOpeningStockWithoutJournal();

        $this->runOpeningBalanceMigration();
        $this->runOpeningBalanceMigration();

        // The second pass sees a journal crediting equity and leaves it alone,
        // rather than reclassifying equity into equity.
        $this->assertSame(1, Journal::query()->count());
        $this->assertSame(-self::OPENING_VALUE, $this->accountNet('3-3100'));
    }

    // ---------------------------------------------------------- what is NOT opening stock

    public function test_stock_found_after_trading_has_begun_stays_in_the_profit_and_loss(): void
    {
        // Go-live stock, then consumption — trading has started.
        $grn = $this->postOpeningStockWithoutJournal();
        $this->postLegacyVarianceCreditFor($grn); // the old engine's Cr 6-4400

        $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, 1000]],
            (int) $this->project->id,
            '2026-07-10',
        ));

        // A later count turns up 100 zak nobody booked. Counting differences
        // are opname territory, and an opname surplus IS an operating gain:
        // 100 * 66.502 = 6.650.200 credited to 6-4400 Selisih Persediaan.
        $adjustment = $this->stock()->postAdjustment($this->makeAdjustment(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY - 1000 + 100]],
            '2026-07-20',
        ));

        $this->runOpeningBalanceMigration();

        // Only the leading run of the sub-ledger is opening stock: the issue
        // ends it, so nothing after it is reclassified — and an adjustment is
        // not a goods receipt, so the migration never looks at it at all. The
        // one reclassification raised is the opening receipt's own.
        $reclass = Journal::query()->where('reference_type', 'opening_stock_reclass')->get();

        $this->assertCount(1, $reclass);
        $this->assertSame((int) $grn->id, (int) $reclass->first()->reference_id);
        $this->assertSame(0, JournalLine::query()
            ->whereIn('journal_id', Journal::query()
                ->where('reference_type', 'stock_adjustment')
                ->where('reference_id', $adjustment->id)
                ->pluck('id'))
            ->where('account_id', $this->accountId('3-3100'))
            ->count());

        // 6-4400 keeps the found stock and only the found stock:
        //   -332.510.000 opening, reclassified away
        //   -  6.650.200 opname surplus, untouched
        $this->assertSame(-6650200.0, $this->accountNet('6-4400'));
        $this->assertSame(-self::OPENING_VALUE, $this->accountNet('3-3100'));
    }

    public function test_a_receipt_with_no_counterparty_after_trading_has_begun_never_reaches_the_profit_and_loss(): void
    {
        // The same event recorded as a RECEIPT rather than an opname. The
        // engine has no way to tell it from opening stock — no PO, no vendor,
        // no counterparty — so it credits equity at source and the migration
        // finds nothing left to repair. That is the conservative direction:
        // the alternative posts operating income the company never earned.
        $opening = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, 1000]],
            (int) $this->project->id,
            '2026-07-10',
        ));

        // 100 * 66.502 = 6.650.200
        $found = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, 100, self::OPENING_UNIT_COST]],
            '2026-07-20',
        ));

        $this->runOpeningBalanceMigration();

        // Both credits were already equity, so no reclassification is raised
        // for either — the migration only ever moves a P&L credit.
        $this->assertSame(0, Journal::query()->where('reference_type', 'opening_stock_reclass')->count());
        $this->assertSame(0.0, $this->accountNet('6-4400'));

        // 332.510.000 + 6.650.200 = 339.160.200, all of it in equity.
        $this->assertSame(-339160200.0, $this->accountNet('3-3100'));

        $foundLines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $found->id));

        $this->assertSame(['1-1400', '3-3100'], array_keys($foundLines));
        $this->assertSame(6650200.0, $foundLines['3-3100']['credit']);
        $this->assertNull($opening->fresh()->gl_clearing_account);
        $this->assertNull($found->fresh()->gl_clearing_account);
    }

    public function test_a_delivery_from_a_vendor_ends_the_opening_run(): void
    {
        // The very first movement is a real purchase: this installation never
        // had an opening balance, it started by buying.
        $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::DELIVERY_QTY, self::DELIVERY_UNIT_COST]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        $later = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, 100, self::DELIVERY_UNIT_COST]],
            '2026-03-06',
        ));

        $this->runOpeningBalanceMigration();

        // A counterparty stops the walk, so nothing after it is treated as
        // go-live stock. Deliberately conservative: mis-classifying a trading
        // event as equity is the error that cannot be spotted from the reports.
        $this->assertSame(0, Journal::query()->where('reference_type', 'opening_stock_reclass')->count());

        // The vendor accrual is untouched: 100 * 62.000 = 6.200.000, a real
        // liability the vendor's invoice clears.
        $this->assertSame(-self::DELIVERY_VALUE, $this->accountNet('2-1600'));

        // The later receipt has no counterparty of its own, so the engine
        // credited equity when it posted it — 6.200.000 raised at source, NOT
        // by the migration, which found nothing to move. Nothing reaches the
        // P&L either way.
        $this->assertSame(-self::DELIVERY_VALUE, $this->accountNet('3-3100'));
        $this->assertSame(0.0, $this->accountNet('6-4400'));
        $this->assertNull($later->fresh()->gl_clearing_account);
    }

    public function test_a_credit_already_sitting_in_equity_is_left_alone(): void
    {
        // The seeder (or an earlier run of this migration) already did it right.
        $grn = $this->postOpeningStockWithoutJournal();

        $this->journals()->autoPost(
            'goods_receipt',
            (int) $grn->id,
            [
                ['account_code' => '1-1400', 'debit' => self::OPENING_VALUE, 'description' => 'Stok awal'],
                ['account_code' => '3-1100', 'credit' => self::OPENING_VALUE, 'description' => 'Modal disetor'],
            ],
            '2026-07-01',
            'Saldo awal persediaan disetor sebagai modal',
        );

        $this->runOpeningBalanceMigration();

        // An accountant who already closed the opening balance to Modal Disetor
        // must not have it moved into the intermediate account behind their back.
        $this->assertSame(0, Journal::query()->where('reference_type', 'opening_stock_reclass')->count());
        $this->assertSame(-self::OPENING_VALUE, $this->accountNet('3-1100'));
        $this->assertSame(0.0, $this->accountNet('3-3100'));
    }

    public function test_a_liability_credit_is_a_real_accrual_and_is_never_moved_to_equity(): void
    {
        // A receipt from a known vendor: 2-1600 is a balance the vendor's
        // invoice will clear, not the company's opening position.
        $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::DELIVERY_QTY, self::DELIVERY_UNIT_COST]],
            '2026-07-01',
            ['vendor_id' => $this->supplier->id],
        ));

        $this->runOpeningBalanceMigration();

        $this->assertSame(0, Journal::query()->where('reference_type', 'opening_stock_reclass')->count());
        $this->assertSame(-self::DELIVERY_VALUE, $this->accountNet('2-1600'));
        $this->assertSame(0.0, $this->accountNet('3-3100'));
    }

    // ---------------------------------------------------------- helpers

    /**
     * Post the opening GRN with the GL bridge down, leaving a stock sub-ledger
     * with no matching journal — the shipped installation's exact state.
     */
    private function postOpeningStockWithoutJournal(): GoodsReceipt
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        $this->setSetting('accounting.perpetual_inventory', null);

        return $grn;
    }

    /**
     * The receipt journal the SHIPPED release wrote for a receipt with no
     * counterparty: Dr 1-1400 / Cr 6-4400 Selisih Persediaan, an expense
     * account, which is the defect this migration repairs. StockService credits
     * 3-3100 at source now, so the legacy state can only be reconstructed by
     * hand — and it still has to be repaired on installations that carry it.
     */
    private function postLegacyVarianceCreditFor(GoodsReceipt $grn): Journal
    {
        return $this->journals()->autoPost(
            'goods_receipt',
            (int) $grn->id,
            [
                ['account_code' => '1-1400', 'debit' => self::OPENING_VALUE, 'description' => 'Penerimaan barang'],
                ['account_code' => '6-4400', 'credit' => self::OPENING_VALUE, 'description' => 'Penerimaan tanpa vendor'],
            ],
            '2026-07-01',
            "GRN {$grn->code} — penerimaan persediaan",
        );
    }

    /**
     * Run the Finance data migration that moves opening stock into equity.
     * `require` (not require_once) so each call returns a fresh instance, which
     * is what makes the idempotency checks above meaningful. Located by glob so
     * renumbering the migration cannot silently skip this test.
     */
    private function runOpeningBalanceMigration(): void
    {
        $files = glob(base_path(
            'Modules/Finance/Database/Migrations/*_post_opening_stock_balance_to_equity.php'
        ));

        $this->assertIsArray($files);
        $this->assertCount(1, $files, 'The opening-stock equity migration is missing.');

        $migration = require $files[0];

        $migration->up();
    }

    /**
     * The synthetic "Laba Tahun Berjalan" row the balance sheet appends for the
     * cumulative profit and loss result (it carries no account code).
     */
    private function currentYearResult(array $balanceSheet): float
    {
        foreach ($balanceSheet['equity']['rows'] as $row) {
            if ($row['account_code'] === null) {
                return round((float) $row['balance'], 2);
            }
        }

        $this->fail('The balance sheet did not report a current-year result.');
    }

    /**
     * Signed movement of a COA account across every posted journal line:
     * debit - credit. Zero means the account has been fully cleared.
     */
    private function accountNet(string $code): float
    {
        $sums = JournalLine::query()
            ->where('account_id', $this->accountId($code))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return round((float) $sums->debit - (float) $sums->credit, 2);
    }

    private function lineCountFor(string $code): int
    {
        return JournalLine::query()->where('account_id', $this->accountId($code))->count();
    }
}
