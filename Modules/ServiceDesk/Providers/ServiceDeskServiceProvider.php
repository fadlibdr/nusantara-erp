<?php

namespace Modules\ServiceDesk\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\ServiceDesk\Console\Commands\GeneratePreventiveTickets;

class ServiceDeskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'svcdesk');

        Route::middleware('api')
            ->prefix('api/servicedesk')
            ->group(__DIR__.'/../Routes/api.php');

        /*
         * Halaman penilaian pelanggan (CSAT, F-9) — publik, tanpa prefix api
         * dan TANPA grup middleware 'web'. Pola CoreServiceProvider untuk
         * /persetujuan/{token}: rute web milik modul tinggal di modulnya,
         * routes/* akar tidak disentuh. Lihat komentar Routes/web.php.
         */
        Route::group([], __DIR__.'/../Routes/web.php');

        $this->commands([
            GeneratePreventiveTickets::class,
        ]);

        // Due PM visits become tickets every morning before the workday (WIB).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('svc:generate-pm')->dailyAt('06:00')->timezone('Asia/Jakarta');
        });
    }
}
