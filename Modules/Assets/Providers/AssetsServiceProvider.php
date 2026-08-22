<?php

namespace Modules\Assets\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Assets\Console\Commands\AccruePlantCommand;

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

        Route::middleware('api')
            ->prefix('api/assets')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
