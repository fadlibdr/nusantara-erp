<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COA groundwork for kas kecil / kasbon, mirrored in ChartOfAccountsSeeder so a
 * fresh install and a migrated live DB end up identical (the established
 * two-place pattern from 2026_07_25_001199_add_payroll_liability_accounts).
 *
 *  - 1-1100 Kas becomes a GROUP: every drawer gets its own postable child
 *    (1-1110 Kas Kecil Kantor Pusat, …) so a broken fund cannot hide inside the
 *    sum of the healthy ones on the trial balance. Guarded — only flipped when
 *    NO journal line has ever posted to 1-1100 (zero on the live demo); on an
 *    installation that has posted to it the flip is skipped, funds are still
 *    created as its children, and history stays valid.
 *    THE FLIP LIVES ONLY HERE, deliberately not in ChartOfAccountsSeeder:
 *    Core's suites pin 1-1100 as a postable leaf on a fresh chart
 *    (SettingValidationTest, AccountRepointingGuardTest), so the shipped
 *    default stays postable and the seeder preserves whatever state this
 *    migration left. A drawer works identically under either parent shape.
 *  - 1-1370 Piutang Karyawan (Kasbon): the receivable a kasbon issue debits.
 *    NOT 1-14xx (the brief's suggestion): 1-1400 is already Persediaan
 *    Material, and the receivable family is 1-13xx (1-1300, 1-1350, 1-1360) —
 *    1-1370 continues it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kas = DB::table('fin_accounts')->where('code', '1-1100')->first();

        if ($kas !== null
            && (bool) $kas->is_postable
            && ! DB::table('fin_journal_lines')->where('account_id', $kas->id)->exists()) {
            DB::table('fin_accounts')->where('id', $kas->id)->update([
                'is_postable' => false,
                'updated_at' => now(),
            ]);
        }

        $parent = DB::table('fin_accounts')->where('code', '1-1000')->value('id');

        if ($parent === null) {
            return; // chart not seeded yet; the seeder will create 1-1370
        }

        if (! DB::table('fin_accounts')->where('code', '1-1370')->exists()) {
            DB::table('fin_accounts')->insert([
                'code' => '1-1370',
                'name' => 'Piutang Karyawan (Kasbon)',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_postable' => true,
                'is_active' => true,
                'parent_id' => $parent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Same rule as the payroll-accounts migration: an account carrying postings
     * is somebody's balance sheet, so 1-1370 is removed only while unused, and
     * 1-1100 is flipped back only while it still has no children.
     */
    public function down(): void
    {
        $receivable = DB::table('fin_accounts')->where('code', '1-1370')->first();

        if ($receivable !== null
            && ! DB::table('fin_journal_lines')->where('account_id', $receivable->id)->exists()) {
            DB::table('fin_accounts')->where('id', $receivable->id)->delete();
        }

        $kas = DB::table('fin_accounts')->where('code', '1-1100')->first();

        if ($kas !== null
            && ! DB::table('fin_accounts')->where('parent_id', $kas->id)->exists()) {
            DB::table('fin_accounts')->where('id', $kas->id)->update([
                'is_postable' => true,
                'updated_at' => now(),
            ]);
        }
    }
};
