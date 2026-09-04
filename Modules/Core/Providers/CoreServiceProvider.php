<?php

namespace Modules\Core\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Console\Commands\ApprovalWatchCommand;
use Modules\Core\Console\Commands\BackupWatchCommand;
use Modules\Core\Console\Commands\DeadlineWatchCommand;
use Modules\Core\Console\Commands\HardenDemoLoginsCommand;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Listeners\SendApprovalNotifications;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\AuditedModels;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * scoped(), not singleton().
         *
         * SettingService memoises the override map so that a request reading 60
         * parameters costs one lookup instead of 60. This binding is what bounds
         * that memo, and therefore what makes it safe. A singleton hands the same
         * instance — and the same memo — to every job a queue:work process ever
         * runs, which is how a worker ended up serving a PPN rate that had been
         * changed hours earlier (M5). scoped() resolves exactly like a singleton
         * within one unit of work, but the instance is dropped at each boundary
         * that ends one.
         *
         * Where that boundary actually is, verified in this repository's vendor
         * tree rather than assumed:
         *
         *  - queue:work. Illuminate\Queue\QueueServiceProvider::registerWorker()
         *    builds the Worker with a $resetScope callback that calls
         *    $app->forgetScopedInstances(), and Worker::daemon() invokes it at
         *    the top of every loop iteration, before reserving the next job
         *    (Worker.php, `if (isset($this->resetScope)) { ($this->resetScope)(); }`).
         *    So each job starts with a fresh SettingService and an empty memo.
         *
         *  - php-fpm / CLI. Each request, and each one-shot artisan command,
         *    boots its own application, so no instance survives one to begin with.
         *
         *  - The sync queue driver runs a job inside the dispatching request and
         *    shares its container. That is correct: a sync job is not a separate
         *    unit of work, it is part of the one that dispatched it.
         *
         *  - The exception, stated plainly: a bespoke long-running console
         *    command that is NOT queue:work has no such boundary — one process,
         *    one container, one instance for its whole life. A daemon of that
         *    shape that must observe parameter changes has to define its own
         *    unit of work by calling SettingService::flush() at the top of its
         *    loop. There is no such command in this codebase today.
         *
         * What this design guarantees, and what it does not:
         *
         *  - Within one unit of work the parameters are a consistent snapshot.
         *    A payroll run computes every payslip at one set of rates even if an
         *    administrator saves the settings screen halfway through — which is
         *    what you want, and the opposite of what per-lookup re-validation
         *    used to give.
         *
         *  - A write is visible to every other process on that process's NEXT
         *    unit of work — the next job, the next request — provided the cache
         *    store is shared between processes: redis, memcached, database or
         *    file. SettingService::flush() forgets the shared entry, so the next
         *    unit of work reloads from core_settings. No restart is needed.
         *
         *  - With CACHE_STORE=array every process owns a private store, so a
         *    forget cannot cross a process boundary and the bound becomes
         *    SettingService::CACHE_TTL (60s). array is the test store pinned by
         *    phpunit.xml, where there is one process and a write is visible to
         *    the next unit of work immediately; it is not a supportable
         *    production store for an installation running a queue.
         *
         *  - A write made through the SAME instance (the settings screen, a
         *    seeder, a test calling setSetting()) is visible to that instance
         *    immediately: set() flushes its own memo.
         */
        $this->app->scoped(SettingService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // The only Blade in the application: printable documents. Namespaced so
        // the templates live with the module rather than in a global
        // resources/views nothing else uses.
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'coredoc');

        Event::listen(DocumentTransitioned::class, SendApprovalNotifications::class);

        $this->commands([BackupWatchCommand::class, DeadlineWatchCommand::class, ApprovalWatchCommand::class, HardenDemoLoginsCommand::class]);

        // After the 02:15 backup and before the workday: whoever opens the ERP
        // at nine sees "offsite backup stale" the same morning it went stale.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('erp:backup-watch')->dailyAt('08:00')->timezone('Asia/Jakarta');

            // 08:30, after fin:close-watch (08:15): the morning reads in order
            // of blast radius — backups, then the ledger, then every other
            // date that can slide. /etc/cron.d/erp1 already runs schedule:run
            // every minute; no cron change is needed for this line to fire.
            $schedule->command('erp:deadline-watch')->dailyAt('08:30')->timezone('Asia/Jakarta');
            // 08:45: antrean persetujuan yang menua — tanggal yang tidak
            // punya kolom, jadi tidak bisa hidup di WatchedDeadlines.
            $schedule->command('erp:approval-watch')->dailyAt('08:45')->timezone('Asia/Jakarta');
        });

        $this->registerAuditObservers();

        Route::middleware('api')
            ->prefix('api/core')
            ->group(__DIR__.'/../Routes/api.php');

        // Halaman keputusan MK/Owner (P0-F): rute web publik tanpa grup 'web'
        // — tidak ada sesi yang perlu CSRF di halaman yang kapabilitasnya
        // adalah token sekali-pakai di URL-nya. Lihat komentar Routes/web.php.
        Route::group([], __DIR__.'/../Routes/web.php');
    }

    /**
     * Model events, not a trait on each model.
     *
     * A trait would have to be added to every audited class and would be missed
     * on whichever one somebody forgot — and the write path that gets forgotten
     * is exactly the one an investigation cares about. Observing the events
     * catches every path at once: controller, service, console command, seeder,
     * tinker.
     */
    private function registerAuditObservers(): void
    {
        foreach (AuditedModels::classes() as $class) {
            $class::created(fn ($model) => app(AuditService::class)->created($model));
            $class::updated(fn ($model) => app(AuditService::class)->updated($model));
            $class::deleted(fn ($model) => app(AuditService::class)->deleted($model));
        }
    }
}
