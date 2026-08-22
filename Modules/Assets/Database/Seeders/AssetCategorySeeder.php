<?php

namespace Modules\Assets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Assets\Models\AssetCategory;

/**
 * Master data: asset categories only — no assets, deployments, maintenance
 * or depreciation runs. Safe for production; extracted from
 * AssetsDatabaseSeeder so ProductionSeeder can reuse it. Idempotent
 * (updateOrCreate by code).
 */
class AssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        // COA hints must resolve against the Finance chart of accounts
        // (FinanceDatabaseSeeder): 6-3100 Beban Penyusutan, 1-2310 Akum.
        // Penyusutan Kendaraan, 1-2410 Akum. Penyusutan Peralatan Proyek,
        // 1-2510 Akum. Penyusutan Peralatan Kantor & IT. asset_account_hint is
        // the matching COST account (1-2300 Kendaraan, 1-2400 Peralatan
        // Proyek, 1-2500 Peralatan Kantor & IT) — the account a disposal
        // credits to take the acquisition cost off the balance sheet; without
        // it AssetDisposalService refuses to dispose anything in the category.
        $categories = [
            ['code' => 'ALAT-BERAT', 'name' => 'Alat Berat', 'useful_life_months_default' => 96, 'depreciation_account_hint' => '6-3100', 'accum_account_hint' => '1-2410', 'asset_account_hint' => '1-2400'],
            ['code' => 'KENDARAAN', 'name' => 'Kendaraan', 'useful_life_months_default' => 60, 'depreciation_account_hint' => '6-3100', 'accum_account_hint' => '1-2310', 'asset_account_hint' => '1-2300'],
            ['code' => 'ALAT-UKUR', 'name' => 'Alat Ukur & Uji', 'useful_life_months_default' => 48, 'depreciation_account_hint' => '6-3100', 'accum_account_hint' => '1-2410', 'asset_account_hint' => '1-2400'],
            ['code' => 'PERALATAN-IT', 'name' => 'Peralatan IT', 'useful_life_months_default' => 48, 'depreciation_account_hint' => '6-3100', 'accum_account_hint' => '1-2510', 'asset_account_hint' => '1-2500'],
            ['code' => 'PERALATAN-KANTOR', 'name' => 'Peralatan Kantor', 'useful_life_months_default' => 48, 'depreciation_account_hint' => '6-3100', 'accum_account_hint' => '1-2510', 'asset_account_hint' => '1-2500'],
        ];

        foreach ($categories as $category) {
            AssetCategory::withTrashed()->updateOrCreate(
                ['code' => $category['code']],
                $category,
            );
        }
    }
}
