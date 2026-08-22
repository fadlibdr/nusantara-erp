<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2-1110 Hutang Gaji & Upah and 2-1120 Hutang BPJS, for installations that
 * already ran the chart-of-accounts seeder.
 *
 * Payroll had no path into the ledger at all before this, so these two accounts
 * have no history to reconcile — they simply did not exist. The seeder carries
 * them too; this is for databases created before it did.
 *
 * NOTE: this is 001199, the LAST slot in Finance's block (001100–001199) per
 * docs/CONVENTIONS.md. The next Finance migration has nowhere to go without a
 * re-block; do not spill into 001200, which is ServiceDesk's.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['2-1110', 'Hutang Gaji & Upah'],
        ['2-1120', 'Hutang BPJS'],
    ];

    public function up(): void
    {
        $parent = DB::table('fin_accounts')->where('code', '2-1000')->value('id');

        if ($parent === null) {
            return;   // chart not seeded yet; the seeder will create them
        }

        foreach (self::ACCOUNTS as [$code, $name]) {
            if (DB::table('fin_accounts')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('fin_accounts')->insert([
                'code' => $code,
                'name' => $name,
                'account_type' => 'liability',
                'normal_balance' => 'credit',
                'is_postable' => true,
                'is_active' => true,
                'parent_id' => $parent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Removes them only while unused. An account carrying postings is somebody's
     * balance sheet; dropping it would orphan journal lines.
     */
    public function down(): void
    {
        foreach (self::ACCOUNTS as [$code]) {
            $account = DB::table('fin_accounts')->where('code', $code)->first();

            if ($account === null) {
                continue;
            }

            if (DB::table('fin_journal_lines')->where('account_id', $account->id)->exists()) {
                continue;
            }

            DB::table('fin_accounts')->where('id', $account->id)->delete();
        }
    }
};
