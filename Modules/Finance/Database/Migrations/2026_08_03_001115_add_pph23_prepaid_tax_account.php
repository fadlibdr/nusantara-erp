<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1-1710 Pajak Dibayar Dimuka PPh 23 — the receivable side of the PPh 23 a
 * customer withholds from our service revenue.
 *
 * The payable side has existed since the chart was seeded (2-1220 Hutang
 * PPh 23, credited by ApBillService whenever WE withhold 2% from a vendor's
 * service bill). The receiving side did not, so a system-integrator termin
 * withheld at PPh 23 — instalasi jaringan, pemeliharaan perangkat, konsultasi
 * teknis — had nowhere to land: the only recordable choice was to call it PPh
 * final 4(2) and park it on 1-1700, which mixes a FINAL tax (that discharges
 * the income's tax for good) with a KREDIT PAJAK (that is subtracted from the
 * year's PPh Badan). Once the two sit in one balance the SPT Tahunan either
 * claims a credit that does not exist or forfeits one that does.
 *
 * A SIBLING of 1-1700, not a child. 1-1700 is a postable leaf that already
 * carries posted PPh final withholdings, and demoting it to a group would drop
 * that balance out of every report that filters is_postable — the exact
 * failure the chart-of-accounts edit guard exists to prevent. Nothing about
 * 1-1700 is touched here.
 *
 * Mirrored in ChartOfAccountsSeeder (the established two-place pattern from
 * 2026_07_25_001199_add_payroll_liability_accounts) so a fresh install and a
 * migrated live DB end up identical. No cash-flow map change is needed: the
 * '1-17' prefix in CashFlowActivityMap already routes it to operasi.
 *
 * Numbering: Finance's 001100-001199 block was exhausted on 2026_07_25 and
 * continues date-forward; 2026_08_02_001114 was the last taken.
 */
return new class extends Migration
{
    private const CODE = '1-1710';

    public function up(): void
    {
        $parent = DB::table('fin_accounts')->where('code', '1-1000')->value('id');

        if ($parent === null) {
            return; // chart not seeded yet; ChartOfAccountsSeeder creates it
        }

        if (DB::table('fin_accounts')->where('code', self::CODE)->exists()) {
            return;
        }

        DB::table('fin_accounts')->insert([
            'code' => self::CODE,
            'name' => 'Pajak Dibayar Dimuka PPh 23',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'parent_id' => $parent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Same rule as the payroll- and petty-cash-account migrations: an account
     * carrying postings is somebody's balance sheet, so it is removed only
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
