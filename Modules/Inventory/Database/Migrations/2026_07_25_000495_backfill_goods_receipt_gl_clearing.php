<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Services\JournalService;
use Modules\Finance\Services\ProjectCostService;

/**
 * Data migration: make already-posted goods receipts carry the same clearing
 * record a receipt posted from now on writes, and bring a stock sub-ledger that
 * never reached the GL onto the GL.
 *
 * Two distinct repairs, both idempotent, both safe to skip:
 *
 * 1. BACKFILL — a receipt that already has a posted journal gets
 *    gl_clearing_account / gl_clearing_amount copied FROM THAT JOURNAL, never
 *    re-derived. Only a credit to a LIABILITY account is recorded: that is a
 *    balance a vendor bill is expected to clear. A credit to the stock variance
 *    account (opening / found stock) is closed at source and must stay
 *    unclearable, so it is deliberately left NULL. This pass always runs — it
 *    only reads the ledger and writes the receipt's own record.
 *
 * 2. BOOTSTRAP REPAIR — posted receipts and posted issues that have NO journal
 *    at all. The shipped demo database is exactly this case: Inventory seeds
 *    before Finance, so the chart of accounts was still empty when the
 *    opening-stock GRN and the first material issue posted,
 *    StockService::ledgerPostingEnabled() returned false, and the installation
 *    has carried a stock sub-ledger with no matching GL balance ever since —
 *    1-1400 at 0,00 against Rp 332.510.000 of stock on hand. The missing
 *    journals are posted now, by the same rules the service uses:
 *
 *      receipt, PO              Dr persediaan / Cr GR/IR clearing
 *      receipt, vendor no PO    Dr persediaan / Cr penerimaan accrual
 *      receipt, neither         Dr persediaan / Cr selisih persediaan
 *      issue, project           Dr 5-xxxx HPP per kategori / Cr persediaan
 *      issue, no project        Dr 6-4100 beban operasional / Cr persediaan
 *
 * The bootstrap repair is deliberately narrow. It runs ONLY when the inventory
 * account has never carried a single journal line — the signature of "this
 * installation's stock has never been on the ledger", which is the situation it
 * exists to fix. An installation that ran periodic inventory and later switched
 * to perpetual HAS postings against that account, and its historical gaps are
 * deliberate (cost was recognised on the vendor bills); back-posting them would
 * rewrite its P&L. It further requires perpetual inventory to be on, both
 * accounts to exist and be postable, and an OPEN fiscal period for the document
 * date. Anything else is skipped document by document — a data repair must
 * never be able to fail a deployment.
 */
return new class extends Migration
{
    private const DEFAULT_INVENTORY_ACCOUNT = '1-1400';

    private const DEFAULT_CLEARING_ACCOUNT = '2-1150';

    private const DEFAULT_ACCRUAL_ACCOUNT = '2-1600';

    private const DEFAULT_VARIANCE_ACCOUNT = '6-4400';

    private const DEFAULT_ISSUE_EXPENSE_ACCOUNT = '6-4100';

    public function up(): void
    {
        if (! Schema::hasTable('inv_goods_receipts')
            || ! Schema::hasColumn('inv_goods_receipts', 'gl_clearing_amount')
            || ! Schema::hasTable('fin_journals')
            || ! Schema::hasTable('fin_journal_lines')
            || ! Schema::hasTable('fin_accounts')) {
            return; // fresh install ordering, or Finance absent: nothing to repair
        }

        // An empty chart of accounts means this is a fresh install whose seeders
        // have not run yet. There is no history to repair and no account to post
        // against.
        if (DB::table('fin_accounts')->doesntExist()) {
            return;
        }

        // Decided once, before anything is posted: the first repaired receipt
        // would otherwise flip the answer for every document after it.
        $bootstrap = $this->bootstrapRepairAllowed();

        $receipts = DB::table('inv_goods_receipts')
            ->whereNull('deleted_at')
            ->where('status', 'posted')
            ->whereNull('gl_clearing_amount')
            ->orderBy('id')
            ->get(['id', 'code', 'purchase_order_id', 'vendor_id', 'receipt_date', 'received_by']);

        foreach ($receipts as $receipt) {
            $journalId = DB::table('fin_journals')
                ->whereNull('deleted_at')
                ->where('reference_type', 'goods_receipt')
                ->where('reference_id', $receipt->id)
                ->where('status', 'posted')
                ->value('id');

            if ($journalId !== null) {
                $this->backfillFromJournal($receipt, (int) $journalId);

                continue;
            }

            if ($bootstrap) {
                $this->repairMissingJournal($receipt);
            }
        }

        if ($bootstrap) {
            $this->repairMissingIssueJournals();
        }
    }

    /**
     * True only where the GL has never seen this installation's stock: no
     * journal line has ever touched the inventory account. See the class
     * docblock for why anything else is left alone.
     */
    private function bootstrapRepairAllowed(): bool
    {
        if (! $this->perpetualInventoryEnabled() || ! class_exists(JournalService::class)) {
            return false;
        }

        $inventoryAccountId = DB::table('fin_accounts')
            ->where('code', $this->accountCode('accounting.inventory_account', self::DEFAULT_INVENTORY_ACCOUNT))
            ->value('id');

        if ($inventoryAccountId === null) {
            return false;
        }

        return DB::table('fin_journal_lines')->where('account_id', $inventoryAccountId)->doesntExist();
    }

    /**
     * Intentionally a no-op.
     *
     * The columns themselves are dropped by the schema migration next to this
     * one. Un-posting a repaired journal has no business meaning: an
     * installation that ran this now has a GL balance its stock sub-ledger
     * agrees with, and reversing that would recreate the imbalance.
     */
    public function down(): void
    {
        // no-op — see the docblock above.
    }

    /**
     * Copy what the receipt's own journal credited. Nothing is derived here: the
     * ledger is the record of what happened.
     */
    private function backfillFromJournal(object $receipt, int $journalId): void
    {
        $credits = DB::table('fin_journal_lines')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journal_lines.journal_id', $journalId)
            ->where('fin_journal_lines.credit', '>', 0)
            ->get(['fin_accounts.code', 'fin_accounts.account_type', 'fin_journal_lines.credit']);

        // A receipt journal has exactly one credit leg. Anything else was not
        // written by this engine (a hand-edited journal, a merged voucher), and
        // guessing which leg a bill should clear would be worse than recording
        // nothing.
        if ($credits->count() !== 1) {
            return;
        }

        $credit = $credits->first();

        // Only a liability is a balance some document still has to settle.
        if ($credit->account_type !== 'liability') {
            return;
        }

        DB::table('inv_goods_receipts')
            ->where('id', $receipt->id)
            ->update([
                'gl_clearing_account' => $credit->code,
                'gl_clearing_amount' => round((float) $credit->credit, 2),
            ]);
    }

    /**
     * Post the journal the receipt never got, when every precondition for it
     * being correct holds. Each receipt is attempted on its own and a failure
     * skips only that receipt.
     */
    private function repairMissingJournal(object $receipt): void
    {
        $value = $this->receiptValue((int) $receipt->id);

        if ($value <= 0.0) {
            return; // free issue / zero-cost receipt: there was never a journal to write
        }

        $inventoryCode = $this->accountCode('accounting.inventory_account', self::DEFAULT_INVENTORY_ACCOUNT);

        [$creditCode, $clearable] = match (true) {
            $receipt->purchase_order_id !== null => [
                $this->accountCode('accounting.grn_clearing_account', self::DEFAULT_CLEARING_ACCOUNT), true,
            ],
            $receipt->vendor_id !== null => [
                $this->accountCode('accounting.receipt_accrual_account', self::DEFAULT_ACCRUAL_ACCOUNT), true,
            ],
            default => [
                $this->accountCode('accounting.stock_variance_account', self::DEFAULT_VARIANCE_ACCOUNT), false,
            ],
        };

        if (! $this->isPostable($inventoryCode) || ! $this->isPostable($creditCode)) {
            return;
        }

        $date = substr((string) $receipt->receipt_date, 0, 10);

        if (! $this->fiscalPeriodOpen($date)) {
            return; // the books for that month are closed: an accountant must decide
        }

        try {
            app(JournalService::class)->autoPost(
                'goods_receipt',
                (int) $receipt->id,
                [
                    [
                        'account_code' => $inventoryCode,
                        'debit' => $value,
                        'description' => "Penerimaan barang {$receipt->code}",
                    ],
                    [
                        'account_code' => $creditCode,
                        'credit' => $value,
                        'description' => "Penerimaan barang {$receipt->code} (koreksi pembukuan)",
                    ],
                ],
                $date,
                "GRN {$receipt->code} — penerimaan persediaan (koreksi)",
                $receipt->received_by !== null ? (int) $receipt->received_by : null,
            );
        } catch (Throwable) {
            return; // never fail a deployment over a historical repair
        }

        if ($clearable) {
            DB::table('inv_goods_receipts')
                ->where('id', $receipt->id)
                ->update([
                    'gl_clearing_account' => $creditCode,
                    'gl_clearing_amount' => $value,
                ]);
        }
    }

    /**
     * The other half of the bootstrap: a posted material issue with no journal
     * took stock off the sub-ledger without ever relieving the GL, so persediaan
     * would stay overstated and the consumption would never reach the P&L.
     *
     * Mirrors StockService::postIssue(): one debit per cost account (5-xxxx per
     * category on a project issue, general opex without a project), one credit
     * emptying persediaan, plus the project cost row so realisasi matches the
     * ledger.
     */
    private function repairMissingIssueJournals(): void
    {
        if (! Schema::hasTable('inv_issues') || ! Schema::hasTable('inv_issue_items')) {
            return;
        }

        $inventoryCode = $this->accountCode('accounting.inventory_account', self::DEFAULT_INVENTORY_ACCOUNT);

        if (! $this->isPostable($inventoryCode)) {
            return;
        }

        $issues = DB::table('inv_issues')
            ->whereNull('deleted_at')
            ->where('status', 'posted')
            ->orderBy('id')
            ->get(['id', 'code', 'project_id', 'issue_date', 'issued_by', 'purpose']);

        foreach ($issues as $issue) {
            $exists = DB::table('fin_journals')
                ->whereNull('deleted_at')
                ->where('reference_type', 'inventory_issue')
                ->where('reference_id', $issue->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $this->repairMissingIssueJournal($issue, $inventoryCode);
        }
    }

    private function repairMissingIssueJournal(object $issue, string $inventoryCode): void
    {
        [$byAccount, $byCategory] = $this->issueTotals($issue);

        $total = round(array_sum($byAccount), 2);

        if ($total <= 0.0) {
            return; // nothing valued: there was never a journal to write
        }

        $date = substr((string) $issue->issue_date, 0, 10);

        if (! $this->fiscalPeriodOpen($date)) {
            return;
        }

        $lines = [];

        foreach ($byAccount as $accountCode => $amount) {
            if (! $this->isPostable((string) $accountCode)) {
                return; // one missing HPP account: leave the whole issue alone
            }

            $lines[] = [
                'account_code' => $accountCode,
                'debit' => $amount,
                'description' => "Pemakaian material {$issue->code}",
                'project_id' => $issue->project_id,
            ];
        }

        $lines[] = [
            'account_code' => $inventoryCode,
            'credit' => $total,
            'description' => "Pengeluaran persediaan {$issue->code}",
            'project_id' => $issue->project_id,
        ];

        try {
            app(JournalService::class)->autoPost(
                'inventory_issue',
                (int) $issue->id,
                $lines,
                $date,
                "Issue {$issue->code} — pemakaian material (koreksi)",
                $issue->issued_by !== null ? (int) $issue->issued_by : null,
            );
        } catch (Throwable) {
            return; // never fail a deployment over a historical repair
        }

        if ($issue->project_id === null
            || ! class_exists(ProjectCostService::class)
            || ! Schema::hasTable('fin_project_costs')) {
            return;
        }

        // record() is idempotent per (reference_type, reference_id, category).
        foreach ($byCategory as $category => $amount) {
            if (round($amount, 2) <= 0.0) {
                continue;
            }

            try {
                app(ProjectCostService::class)->record(
                    (int) $issue->project_id,
                    $date,
                    CostCategory::from((string) $category),
                    'inventory_issue',
                    (int) $issue->id,
                    "Pemakaian material {$issue->code} — {$issue->purpose}",
                    round($amount, 2),
                );
            } catch (Throwable) {
                // the journal is what the GL needs; a cost-ledger hiccup must
                // not undo it
            }
        }
    }

    /**
     * Value of a posted issue split by debit account and by cost category, using
     * the unit costs the posting already stamped on each line.
     *
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    private function issueTotals(object $issue): array
    {
        $lines = DB::table('inv_issue_items')
            ->leftJoin('inv_items', 'inv_items.id', '=', 'inv_issue_items.item_id')
            ->where('inv_issue_items.issue_id', $issue->id)
            ->get(['inv_issue_items.amount', 'inv_items.item_type']);

        /** @var array<string, float> $byAccount */
        $byAccount = [];
        /** @var array<string, float> $byCategory */
        $byCategory = [];

        foreach ($lines as $line) {
            $amount = round((float) $line->amount, 2);

            if ($amount <= 0.0) {
                continue;
            }

            // Alat bantu is equipment cost, everything else material — the same
            // rule StockService::issueCostCategory() applies.
            $category = $line->item_type === 'tool'
                ? CostCategory::Equipment
                : CostCategory::Material;

            $accountCode = $issue->project_id !== null
                ? $category->cogsAccountCode()
                : self::DEFAULT_ISSUE_EXPENSE_ACCOUNT;

            $byAccount[$accountCode] = round(($byAccount[$accountCode] ?? 0.0) + $amount, 2);
            $byCategory[$category->value] = round(($byCategory[$category->value] ?? 0.0) + $amount, 2);
        }

        return [$byAccount, $byCategory];
    }

    /**
     * The same arithmetic postReceipt() uses: line amounts rounded to the cent,
     * then summed, so a repaired journal matches what the receipt would have
     * booked at the time.
     */
    private function receiptValue(int $goodsReceiptId): float
    {
        $value = 0.0;

        $lines = DB::table('inv_goods_receipt_items')
            ->where('goods_receipt_id', $goodsReceiptId)
            ->get(['qty', 'unit_cost']);

        foreach ($lines as $line) {
            $value = round($value + round(round((float) $line->qty, 3) * round((float) $line->unit_cost, 2), 2), 2);
        }

        return $value;
    }

    /**
     * Effective parameter value without booting SettingService: the stored
     * override when there is one, the shipped config default otherwise.
     */
    private function accountCode(string $key, string $default): string
    {
        $override = $this->override($key);

        return is_string($override) && $override !== ''
            ? $override
            : (string) config("erp.{$key}", $default);
    }

    private function perpetualInventoryEnabled(): bool
    {
        $override = $this->override('accounting.perpetual_inventory');

        return $override === null
            ? (bool) config('erp.accounting.perpetual_inventory', true)
            : (bool) $override;
    }

    private function override(string $key): mixed
    {
        if (! Schema::hasTable('core_settings')) {
            return null;
        }

        $raw = DB::table('core_settings')->where('key', $key)->value('value');

        return $raw === null ? null : json_decode((string) $raw, true);
    }

    private function isPostable(string $code): bool
    {
        return DB::table('fin_accounts')
            ->whereNull('deleted_at')
            ->where('code', $code)
            ->where('is_postable', true)
            ->exists();
    }

    private function fiscalPeriodOpen(string $date): bool
    {
        if (! Schema::hasTable('fin_fiscal_periods')) {
            return false;
        }

        [$year, $month] = array_map('intval', explode('-', $date));

        return DB::table('fin_fiscal_periods')
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'open')
            ->exists();
    }
};
