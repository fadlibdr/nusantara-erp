<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 7-2400 Beban Denda & Potongan Lain-lain — the expense leg of a 'potongan
 * lain-lain' the owner deducts from a termin payment (temuan #15, denda
 * keterlambatan).
 *
 * The seeded chart has no denda account at all (grep 'denda' across the COA:
 * nothing), so the deduction had nowhere honest to land: booking it on 6-4100
 * Beban Umum & Administrasi hides a contractual penalty inside office
 * overhead, and booking it against revenue silently restates the DPP a faktur
 * pajak already reported. 7-2xxx is where non-operating expenses of exactly
 * this kind already live — 7-2100 Beban Admin Bank, 7-2300 Beban Pajak Final
 * (the PP 9/2022 deduction from OUR termins) — so the denda joins that shelf
 * as its own postable leaf, debit-normal under 7-0000.
 *
 * Mirrored in ChartOfAccountsSeeder (the established two-place pattern from
 * 2026_07_25_001199 / 2026_08_03_001115) so a fresh install and a migrated
 * live DB end up identical. CashFlowActivityMap gets a '7-24' => operasi rule
 * alongside its '7-23' sibling — a deduction from an operating receipt is
 * operating activity, not 'lainnya'.
 */
return new class extends Migration
{
    private const CODE = '7-2400';

    public function up(): void
    {
        $parent = DB::table('fin_accounts')->where('code', '7-0000')->value('id');

        if ($parent === null) {
            return; // chart not seeded yet; ChartOfAccountsSeeder creates it
        }

        if (DB::table('fin_accounts')->where('code', self::CODE)->exists()) {
            return;
        }

        DB::table('fin_accounts')->insert([
            'code' => self::CODE,
            'name' => 'Beban Denda & Potongan Lain-lain',
            'account_type' => 'other',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'parent_id' => $parent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Same rule as the payroll- and PPh-23-account migrations: an account
     * carrying postings is somebody's income statement, so it is removed only
     * while no journal line has ever touched it.
     */
    public function down(): void
    {
        $account = DB::table('fin_accounts')->where('code', self::CODE)->first();

        if ($account !== null
            && ! DB::table('fin_journal_lines')->where('account_id', $account->id)->exists()) {
            DB::table('fin_accounts')->where('id', $account->id)->delete();
        }
    }
};
