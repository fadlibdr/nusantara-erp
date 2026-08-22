<?php

namespace Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Finance\Models\FiscalPeriod;

/**
 * Master data: 2026 fiscal calendar. Extracted from FinanceDatabaseSeeder so
 * ProductionSeeder can reuse it. Idempotent by year+month (updateOrCreate),
 * but note it re-asserts the seeded status on every run — intended for
 * first-time bootstrap, not for re-running after periods have been closed
 * through the API.
 */
class FiscalPeriodSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(1, 12) as $month) {
            FiscalPeriod::query()->updateOrCreate(
                ['year' => 2026, 'month' => $month],
                // January is closed (buku Januari sudah tutup); the demo
                // documents live in Feb-Apr which stay open.
                ['status' => $month === 1 ? 'closed' : 'open'],
            );
        }
    }
}
