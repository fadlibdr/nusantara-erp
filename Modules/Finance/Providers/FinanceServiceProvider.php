<?php

namespace Modules\Finance\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Finance\Console\Commands\CloseWatchCommand;
use Modules\Finance\Console\Commands\EnsureFiscalCalendarCommand;

class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->commands([
            EnsureFiscalCalendarCommand::class,
            CloseWatchCommand::class,
        ]);

        /*
         * 05:30 the calendar, 08:15 the reminder — both WIB.
         *
         * The calendar runs before anyone is at a keyboard, so the first
         * posting of the day never meets a missing period. The reminder runs
         * after it and before the workday, so "periode 2026-06 belum ditutup"
         * is waiting when finance opens the ERP rather than arriving mid-task.
         *
         * No cron change: /etc/cron.d/erp1 already runs schedule:run every
         * minute, which is what CoreServiceProvider's backup watch rides on.
         */
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('fin:ensure-calendar')->dailyAt('05:30')->timezone('Asia/Jakarta');
            $schedule->command('fin:close-watch')->dailyAt('08:15')->timezone('Asia/Jakarta');
        });

        Route::middleware('api')
            ->prefix('api/finance')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
