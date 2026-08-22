<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akun harga perolehan per kategori aset — the missing third hint.
 *
 * A disposal journal must CREDIT the asset-cost account (1-2300 Kendaraan,
 * 1-2400 Peralatan Proyek, ...) to take the acquisition cost off the balance
 * sheet, and the category rows only named the depreciation pair. Without this
 * column AssetDisposalService would have to guess the cost account from the
 * accumulated one ("subtract 10 from the code"), which posts to the wrong
 * asset class the day somebody recodes their chart.
 *
 * The backfill maps the seeded accumulated accounts to their cost siblings so
 * live tenants (erp1's five categories all use the seeded chart) can dispose
 * without editing master data first. A category whose accum hint is not one of
 * the seeded codes stays NULL and every disposal on it is refused with a
 * "lengkapi di Master Data" message — refused, not guessed, same policy as
 * DepreciationService::postJournal.
 */
return new class extends Migration
{
    /**
     * Seeded chart pairs: Akumulasi Penyusutan X (1-2x10) => X (1-2x00).
     *
     * @var array<string, string>
     */
    private const COST_ACCOUNT_FOR_ACCUM = [
        '1-2210' => '1-2200', // Bangunan
        '1-2310' => '1-2300', // Kendaraan
        '1-2410' => '1-2400', // Peralatan Proyek
        '1-2510' => '1-2500', // Peralatan Kantor & IT
    ];

    public function up(): void
    {
        Schema::table('ast_categories', function (Blueprint $table): void {
            $table->string('asset_account_hint', 20)->nullable()->after('accum_account_hint');
        });

        foreach (self::COST_ACCOUNT_FOR_ACCUM as $accum => $cost) {
            DB::table('ast_categories')
                ->where('accum_account_hint', $accum)
                ->whereNull('asset_account_hint')
                ->update(['asset_account_hint' => $cost]);
        }
    }

    public function down(): void
    {
        Schema::table('ast_categories', function (Blueprint $table): void {
            $table->dropColumn('asset_account_hint');
        });
    }
};
