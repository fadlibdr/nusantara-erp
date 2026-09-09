<?php

namespace Modules\Assets\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Assets\Console\Commands\AccruePlantCommand;
use Modules\Assets\Services\MaintenanceDueService;
use Modules\Core\Support\WatchedThresholds;

class AssetsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->commands([
            AccruePlantCommand::class,
        ]);

        /*
         * 05:40 WIB, after fin:ensure-calendar (05:30) and before finance is
         * at a keyboard: the month that just ended is accrued the morning
         * after it ends, so live AC/CPI and the POC preview carry last
         * month's plant without waiting for the close.
         *
         * Daily and idempotent rather than monthly-on-the-1st for the same
         * reason the calendar command is: a server down over a month end
         * heals on its first morning back instead of leaving the month to
         * whoever notices. What cron can still miss (an OLDER month never
         * run), the plant_accrued item on the period-close checklist names
         * to the closer — the schedule is a convenience, the checklist is
         * the control. No cron change: /etc/cron.d/erp1 already runs
         * schedule:run every minute.
         */
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('ast:accrue-plant')->dailyAt('05:40')->timezone('Asia/Jakarta');
        });

        /*
         * F-7 — sisi JAM dari jatuh tempo servis, dipasok ke registri ambang.
         *
         * Core mendeklarasikan entrinya (label, izin, tautan, satuan, ambang);
         * modul pemilik angkanya memasok barisnya, aturan §24 yang sama dengan
         * project_budget_pct milik Finance. Definisi "pembacaan terakhir" (=
         * tertinggi) dan "target yang berlaku" (= baris perawatan terbaru)
         * hidup di MaintenanceDueService, tempat kartu aset membacanya juga —
         * menyalinnya ke Core berarti layar Ambang dan kartu alat bisa
         * menghakimi satu excavator dengan dua jawaban.
         *
         * Closure, bukan hasil: pemindaian bisa terjadi kapan saja sesudah
         * boot, dan menghitungnya di sini membebani SETIAP permintaan dengan
         * kueri log jam seluruh armada.
         */
        WatchedThresholds::supply(
            'maintenance_hour_meter',
            static fn (): array => app(MaintenanceDueService::class)->thresholdRows(),
        );

        Route::middleware('api')
            ->prefix('api/assets')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
