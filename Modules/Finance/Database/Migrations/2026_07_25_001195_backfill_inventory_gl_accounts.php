<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Finance\Models\Account;

/**
 * Data migration: back-fill the COA accounts the perpetual inventory engine
 * posts to.
 *
 * ChartOfAccountsSeeder ships 2-1150 / 6-4400 / 6-4500, but installations that
 * were created before those rows were added to the seeder never got them — and
 * a seeder is not re-run on an existing production database. Without them
 * JournalService::accountId() throws "COA account 2-1150 does not exist" and
 * every goods receipt, stock opname and PO bill is refused.
 *
 *   2-1150  Penerimaan Barang Belum Ditagih   GR/IR clearing (StockService,
 *                                             ApBillService)
 *   6-4400  Selisih Persediaan                stock opname variance
 *   6-4500  Selisih Harga Pembelian           three-way-match price difference
 *
 * Written defensively: it is safe on a database that already has the accounts,
 * safe when the parent group is missing, and safe to run twice.
 */
return new class extends Migration
{
    /**
     * [code, name, account_type, normal_balance, parent_code]
     * All three are postable leaves — the engine posts to them directly.
     */
    private const ACCOUNTS = [
        ['2-1150', 'Penerimaan Barang Belum Ditagih', 'liability', 'credit', '2-1000'],
        ['6-4400', 'Selisih Persediaan', 'expense', 'debit', '6-0000'],
        ['6-4500', 'Selisih Harga Pembelian', 'expense', 'debit', '6-0000'],
    ];

    public function up(): void
    {
        // Module migrations can run before Finance's own schema on a fresh
        // install ordering, and Finance may be absent entirely.
        if (! Schema::hasTable('fin_accounts')) {
            return;
        }

        // Nothing to back-fill on a chart that has not been seeded yet: this is
        // a fresh install (or a test database), and ChartOfAccountsSeeder ships
        // all three accounts as part of the complete chart. Dropping three
        // orphan rows into an otherwise empty fin_accounts would fake a seeded
        // chart — including for the "is the COA seeded yet?" bootstrap probe in
        // StockService::ledgerPostingEnabled().
        if (Account::withTrashed()->doesntExist()) {
            return;
        }

        foreach (self::ACCOUNTS as [$code, $name, $type, $normal, $parentCode]) {
            $existing = Account::withTrashed()->where('code', $code)->first();

            if ($existing !== null) {
                // The row is already there. Do NOT touch name, parent or type:
                // an operator may legitimately have renamed or re-parented the
                // account, and this migration must never undo that.
                //
                // The one thing that is repaired is a soft-deleted account: the
                // unique index on `code` still holds the code, so a replacement
                // cannot be inserted, and JournalService resolves codes through
                // the default (non-trashed) scope — a trashed 2-1150 leaves the
                // engine just as broken as a missing one.
                if ($existing->trashed()) {
                    $existing->restore();
                }

                continue;
            }

            Account::withTrashed()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_type' => $type,
                    'normal_balance' => $normal,
                    'is_postable' => true,
                    'is_active' => true,
                    // Parent is a nicety for the COA tree, not a posting
                    // requirement: attach when the group exists, otherwise
                    // insert at the root rather than failing the migration.
                    'parent_id' => Account::withTrashed()->where('code', $parentCode)->value('id'),
                ],
            );
        }
    }

    /**
     * Intentionally a no-op.
     *
     * Once these accounts exist they carry journal lines (fin_journal_lines
     * references fin_accounts), so deleting them on rollback would either fail
     * on the foreign key or orphan posted journals. Reversing the back-fill has
     * no business meaning either: an installation that ran it is one whose
     * ledger now depends on the accounts. Remove them by hand if you truly must.
     */
    public function down(): void
    {
        // no-op — see the docblock above.
    }
};
