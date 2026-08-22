<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Finance\Models\Account;
use Modules\Finance\Services\JournalService;

/**
 * Data migration: put the counter-entry of OPENING stock where an opening
 * balance belongs — equity — instead of the profit and loss account.
 *
 * THE PROBLEM ON A SHIPPED INSTALLATION. database/database.sqlite carries
 * GRN/2026/VII/0001: Rp 351.250.000 of stock brought in on 1 July 2026 with no
 * purchase order and no vendor, posted before the GL engine existed. Its stock
 * sub-ledger is real and its GL balance was 0,00. Two things follow, and this
 * migration is about the second:
 *
 *  1. the value has to reach the ledger at all. The Inventory data migration
 *     next to this one (…_000495_backfill_goods_receipt_gl_clearing) does that
 *     for installations whose stock never reached the GL, by the receipt
 *     engine's own rules;
 *  2. those rules have no case for an opening balance. A receipt with neither
 *     PO nor vendor credits accounting.stock_variance_account — correct for
 *     found stock and returns from site, both of which are operating events,
 *     and wrong here: it reports the company's entire go-live inventory as
 *     Rp 351.250.000 of operating income. The trial balance still balances and
 *     1-1400 still agrees with the sub-ledger, which is exactly what makes it
 *     a trap: the numbers reconcile and the P&L is fiction.
 *
 * An opening balance has no counterparty, so it raises no liability, and it is
 * not a result of trading, so it is not income. Its counter-entry is equity —
 * 3-3100 Saldo Awal, the intermediate account an accountant closes to Modal
 * Disetor / Laba Ditahan once every opening balance is in. That split is a human
 * decision, which is precisely why the account exists and why no migration makes
 * it.
 *
 * WHAT COUNTS AS OPENING STOCK, read from data rather than guessed. A posted
 * goods receipt with no purchase order and no vendor, belonging to the LEADING
 * RUN of the stock sub-ledger: every movement before it must be one too. That is
 * what "the stock the company started with" means, and it is what separates it
 * from an opname surplus or a site return, which by definition happen after
 * there is stock to find. The run stops at the first movement that is anything
 * else (a transfer, an issue, a receipt against a PO or a vendor), so nothing
 * later than go-live is ever touched.
 *
 * TWO REPAIRS, both idempotent, both skippable:
 *
 *   no journal at all   Dr persediaan / Cr saldo awal, dated on the receipt.
 *   journal credits a   Dr <that P&L account> / Cr saldo awal, a reclassifying
 *   P&L account         entry that leaves the original posting untouched — a
 *                       posted journal is a record of what happened and is
 *                       corrected by another journal, never rewritten.
 *
 * A journal that already credits equity is correct and is left alone; one that
 * credits a liability is a real accrual and is none of this migration's business
 * (and cannot occur in the qualifying set anyway, since those receipts have no
 * counterparty). Running twice changes nothing: the first repair leaves the
 * credit in equity, the second is recognised by its own reference type. On a
 * fresh or empty database every guard short-circuits and nothing is posted.
 */
return new class extends Migration
{
    private const DEFAULT_INVENTORY_ACCOUNT = '1-1400';

    private const DEFAULT_OPENING_BALANCE_ACCOUNT = '3-3100';

    /**
     * inv_stock_ledger.reference_type for a goods receipt. No morph map is
     * registered in this application, so the morph string is the FQCN; kept as a
     * literal because Inventory may be absent from the installation entirely.
     */
    private const GOODS_RECEIPT_MORPH = 'Modules\Inventory\Models\GoodsReceipt';

    /** Reference type of the reclassifying entry, and its idempotency marker. */
    private const RECLASS_REFERENCE_TYPE = 'opening_stock_reclass';

    public function up(): void
    {
        if (! Schema::hasTable('fin_accounts')
            || ! Schema::hasTable('fin_journals')
            || ! Schema::hasTable('fin_journal_lines')) {
            return; // Finance absent, or its own schema has not run yet
        }

        // An empty chart is a fresh install whose seeders have not run:
        // ChartOfAccountsSeeder ships 3-3100 and InventoryDatabaseSeeder posts
        // the opening balance itself. Nothing to repair, and inserting an orphan
        // account into an unseeded chart would fake a seeded one — the same
        // reasoning as the sibling back-fill migration.
        if (Account::withTrashed()->doesntExist()) {
            return;
        }

        $this->ensureOpeningBalanceAccount();

        if (! Schema::hasTable('inv_goods_receipts')
            || ! Schema::hasTable('inv_goods_receipt_items')
            || ! Schema::hasTable('inv_stock_ledger')
            || ! class_exists(JournalService::class)) {
            return;
        }

        foreach ($this->openingStockReceipts() as $receipt) {
            $this->repair($receipt);
        }
    }

    /**
     * Intentionally a no-op.
     *
     * Rolling back would mean un-posting journals the ledger now depends on, or
     * deleting an account that carries journal lines. An installation that ran
     * this has an opening balance sitting in equity; putting it back in the P&L
     * has no business meaning. Reverse it with a journal voucher if you must.
     */
    public function down(): void
    {
        // no-op — see the docblock above.
    }

    /**
     * Add 3-3100 for installations created before it was part of the shipped
     * chart. Never touches an existing row beyond restoring a soft-deleted one:
     * an operator may legitimately have renamed or re-parented it.
     */
    private function ensureOpeningBalanceAccount(): void
    {
        $code = $this->accountCode('accounting.opening_balance_account', self::DEFAULT_OPENING_BALANCE_ACCOUNT);

        $existing = Account::withTrashed()->where('code', $code)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return;
        }

        Account::withTrashed()->updateOrCreate(
            ['code' => $code],
            [
                'name' => 'Saldo Awal',
                'account_type' => 'equity',
                'normal_balance' => 'credit',
                'is_postable' => true,
                'is_active' => true,
                // A nicety for the COA tree, not a posting requirement: attach
                // to the equity group when it exists, else sit at the root.
                'parent_id' => Account::withTrashed()->where('code', '3-0000')->value('id'),
            ],
        );
    }

    /**
     * The leading run of the stock sub-ledger, as goods receipt rows.
     *
     * Walked in chronological order and stopped at the first movement that is
     * not a counterparty-less posted receipt, so a single non-qualifying row
     * ends the opening block. Lazily iterated: only the leading run is read, not
     * the whole ledger of an installation that has been trading for years.
     *
     * @return list<object>
     */
    private function openingStockReceipts(): array
    {
        /** @var array<int, object|null> $resolved receipt id => row, or null when it disqualifies */
        $resolved = [];
        $receipts = [];

        $rows = DB::table('inv_stock_ledger')
            ->orderBy('trx_date')
            ->orderBy('id')
            ->select(['reference_type', 'reference_id'])
            ->cursor();

        foreach ($rows as $row) {
            if ($row->reference_type !== self::GOODS_RECEIPT_MORPH) {
                break; // a transfer or an issue: the opening block is over
            }

            $id = (int) $row->reference_id;

            if (! array_key_exists($id, $resolved)) {
                $resolved[$id] = $this->qualifyingReceipt($id);

                if ($resolved[$id] !== null) {
                    $receipts[] = $resolved[$id];
                }
            }

            if ($resolved[$id] === null) {
                break; // a receipt against a PO or a vendor: trading has begun
            }
        }

        return $receipts;
    }

    /**
     * The receipt row when it is a posted receipt with no counterparty at all,
     * null otherwise.
     */
    private function qualifyingReceipt(int $id): ?object
    {
        return DB::table('inv_goods_receipts')
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->where('status', 'posted')
            ->whereNull('purchase_order_id')
            ->whereNull('vendor_id')
            ->first(['id', 'code', 'receipt_date', 'received_by']);
    }

    /**
     * Post what is missing, or reclassify what went to the P&L. Each receipt is
     * attempted on its own: a repair must never be able to fail a deployment.
     */
    private function repair(object $receipt): void
    {
        $inventoryCode = $this->accountCode('accounting.inventory_account', self::DEFAULT_INVENTORY_ACCOUNT);
        $openingCode = $this->accountCode('accounting.opening_balance_account', self::DEFAULT_OPENING_BALANCE_ACCOUNT);

        if (! $this->isPostable($inventoryCode) || ! $this->isPostable($openingCode)) {
            return;
        }

        $date = substr((string) $receipt->receipt_date, 0, 10);

        if (! $this->fiscalPeriodOpen($date)) {
            return; // the books for that month are closed: an accountant decides
        }

        $journalId = DB::table('fin_journals')
            ->whereNull('deleted_at')
            ->where('reference_type', 'goods_receipt')
            ->where('reference_id', $receipt->id)
            ->where('status', 'posted')
            ->value('id');

        if ($journalId === null) {
            $this->postOpeningBalance($receipt, $date, $inventoryCode, $openingCode);

            return;
        }

        $this->reclassifyToEquity($receipt, (int) $journalId, $date, $openingCode);
    }

    /**
     * The receipt never reached the ledger:
     *
     *   Dr 1-1400 Persediaan Material / Cr 3-3100 Saldo Awal
     */
    private function postOpeningBalance(object $receipt, string $date, string $inventoryCode, string $openingCode): void
    {
        $value = $this->receiptValue((int) $receipt->id);

        if ($value <= 0.0) {
            return; // zero-value receipt: there was never an entry to write
        }

        try {
            app(JournalService::class)->autoPost(
                'goods_receipt',
                (int) $receipt->id,
                [
                    [
                        'account_code' => $inventoryCode,
                        'debit' => $value,
                        'description' => "Stok awal {$receipt->code}",
                    ],
                    [
                        'account_code' => $openingCode,
                        'credit' => $value,
                        'description' => "Saldo awal persediaan {$receipt->code}",
                    ],
                ],
                $date,
                "GRN {$receipt->code} — saldo awal persediaan",
                $receipt->received_by !== null ? (int) $receipt->received_by : null,
            );
        } catch (Throwable) {
            // never fail a deployment over a historical repair
        }
    }

    /**
     * The receipt reached the ledger, but its credit landed in the P&L (the
     * stock variance account, by the receipt engine's no-counterparty rule):
     *
     *   Dr 6-4400 Selisih Persediaan / Cr 3-3100 Saldo Awal
     *
     * The original posting stays exactly as it was; this entry moves the balance
     * off the income statement and onto the balance sheet, which is how a posted
     * journal is corrected.
     */
    private function reclassifyToEquity(object $receipt, int $journalId, string $date, string $openingCode): void
    {
        $alreadyReclassified = DB::table('fin_journals')
            ->whereNull('deleted_at')
            ->where('reference_type', self::RECLASS_REFERENCE_TYPE)
            ->where('reference_id', $receipt->id)
            ->exists();

        if ($alreadyReclassified) {
            return;
        }

        $credits = DB::table('fin_journal_lines')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journal_lines.journal_id', $journalId)
            ->where('fin_journal_lines.credit', '>', 0)
            ->get(['fin_accounts.code', 'fin_accounts.account_type', 'fin_journal_lines.credit']);

        // A receipt journal has exactly one credit leg. Anything else was not
        // written by this engine (a hand-edited or merged voucher) and guessing
        // which leg is the opening balance would be worse than leaving it.
        if ($credits->count() !== 1) {
            return;
        }

        $credit = $credits->first();

        // Balance-sheet credits are left alone: equity is already right, and a
        // liability is a genuine accrual some document is expected to clear.
        if (in_array($credit->account_type, ['asset', 'liability', 'equity'], true)) {
            return;
        }

        $amount = round((float) $credit->credit, 2);

        if ($amount <= 0.0) {
            return;
        }

        try {
            app(JournalService::class)->autoPost(
                self::RECLASS_REFERENCE_TYPE,
                (int) $receipt->id,
                [
                    [
                        'account_code' => $credit->code,
                        'debit' => $amount,
                        'description' => "Reklasifikasi saldo awal persediaan {$receipt->code}",
                    ],
                    [
                        'account_code' => $openingCode,
                        'credit' => $amount,
                        'description' => "Saldo awal persediaan {$receipt->code}",
                    ],
                ],
                $date,
                "GRN {$receipt->code} — reklasifikasi saldo awal persediaan ke ekuitas",
                $receipt->received_by !== null ? (int) $receipt->received_by : null,
            );
        } catch (Throwable) {
            // never fail a deployment over a historical repair
        }
    }

    /**
     * The same arithmetic StockService::postReceipt() uses: line amounts rounded
     * to the cent, then summed.
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
        if (Schema::hasTable('core_settings')) {
            $raw = DB::table('core_settings')->where('key', $key)->value('value');
            $override = $raw === null ? null : json_decode((string) $raw, true);

            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        return (string) config("erp.{$key}", $default);
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
