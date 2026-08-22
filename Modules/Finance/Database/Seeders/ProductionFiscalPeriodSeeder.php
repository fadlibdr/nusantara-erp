<?php

namespace Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Finance\Models\FiscalPeriod;

/**
 * Production fiscal calendar bootstrap: the CURRENT year, all months open.
 *
 * Unlike the demo FiscalPeriodSeeder (fixed 2026 canon with January closed),
 * this uses firstOrCreate so re-running ProductionSeeder after go-live never
 * reopens periods that were closed through the API.
 */
class ProductionFiscalPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $year = now()->year;

        foreach (range(1, 12) as $month) {
            FiscalPeriod::query()->firstOrCreate(
                ['year' => $year, 'month' => $month],
                ['status' => 'open'],
            );
        }
    }
}
