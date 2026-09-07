<?php

namespace Modules\Core\Providers;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Console\Commands\ApprovalWatchCommand;
use Modules\Core\Console\Commands\BackupWatchCommand;
use Modules\Core\Console\Commands\DeadlineWatchCommand;
use Modules\Core\Console\Commands\HardenDemoLoginsCommand;
use Modules\Core\Console\Commands\HeartbeatCommand;
use Modules\Core\Console\Commands\MigrationVerifyCommand;
use Modules\Core\Console\Commands\MysqlPreflightCommand;
use Modules\Core\Console\Commands\SqliteToMysqlCommand;
use Modules\Core\Console\Commands\WatchdogAlarmCommand;
use Modules\Core\Events\DocumentTransitioned;
use Modules\Core\Listeners\SendApprovalNotifications;
use Modules\Core\Models\Approval;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\ApprovalDelegationMemo;
use Modules\Core\Support\ApprovalDelegations;
use Modules\Core\Support\ApprovalStamp;
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

        /*
         * F-1 — potret delegasi persetujuan, dengan batas yang sama persis
         * dan untuk alasan yang sama: Gate::before berjalan puluhan kali per
         * permintaan, dan memo statis akan membuat pekerja antrean memegang
         * delegasi yang sudah dicabut sampai ia direstart.
         */
        $this->app->scoped(ApprovalDelegationMemo::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // The only Blade in the application: printable documents. Namespaced so
        // the templates live with the module rather than in a global
        // resources/views nothing else uses.
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'coredoc');

        Event::listen(DocumentTransitioned::class, SendApprovalNotifications::class);

        $this->commands([
            BackupWatchCommand::class, DeadlineWatchCommand::class, ApprovalWatchCommand::class, HardenDemoLoginsCommand::class,
            MysqlPreflightCommand::class, SqliteToMysqlCommand::class, MigrationVerifyCommand::class,
            HeartbeatCommand::class, WatchdogAlarmCommand::class,
        ]);

        // After the 02:15 backup and before the workday: whoever opens the ERP
        // at nine sees "offsite backup stale" the same morning it went stale.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // P-0b: detak jantung penjadwal. Tiap 5 menit, dari penjadwal itu
            // sendiri — satu-satunya bukti bahwa ia masih berjalan. Dibaca
            // GET core/health, spanduk dasbor, dan deploy/erp1-watchdog.sh
            // (restart unit + erp:watchdog-alarm bila > 20 menit).
            $schedule->command('erp:heartbeat')->everyFiveMinutes();

            $schedule->command('erp:backup-watch')->dailyAt('08:00')->timezone('Asia/Jakarta');

            // 08:30, after fin:close-watch (08:15): the morning reads in order
            // of blast radius — backups, then the ledger, then every other
            // date that can slide. The erp1-scheduler unit (schedule:work,
            // deploy/systemd) fires every minute; nothing on the host changes
            // for this line to run.
            $schedule->command('erp:deadline-watch')->dailyAt('08:30')->timezone('Asia/Jakarta');
            // 08:45: antrean persetujuan yang menua — tanggal yang tidak
            // punya kolom, jadi tidak bisa hidup di WatchedDeadlines.
            $schedule->command('erp:approval-watch')->dailyAt('08:45')->timezone('Asia/Jakarta');
        });

        $this->registerAuditObservers();
        $this->registerApprovalStamping();
        $this->registerApprovalDelegationGate();

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

    /**
     * F-1 — stempel kebijakan dan "a.n." pada setiap baris core_approvals.
     *
     * Observer dan bukan baris di dalam Traits\Approvable: Payment,
     * ProjectBaseline dan JournalService menulis baris persetujuan tanpa
     * memakai trait itu, dan jalur yang terlewat adalah jalur yang dicari
     * sebuah penyelidikan. Lihat ApprovalStamp.
     */
    private function registerApprovalStamping(): void
    {
        Approval::creating(fn (Approval $approval) => ApprovalStamp::stamp($approval));
    }

    /**
     * F-1 — delegasi "a.n." sebagai Gate::before.
     *
     * MENGEMBALIKAN null, BUKAN false, ketika ia tidak berpendapat. Sebuah
     * Gate::before yang mengembalikan false MENOLAK ability itu di seluruh
     * aplikasi, mendahului setiap policy dan setiap middleware permission —
     * satu baris salah di sini akan mengunci semua orang dari segalanya.
     * ApprovalDelegations::grants() hanya pernah mengembalikan true atau null,
     * dan hanya untuk ability berbentuk <awalan>.approve / .approve-director.
     *
     * DAN HANYA DI PINTU KEPUTUSAN DOKUMEN (putaran verifikasi F-1).
     * Menyaring nama ability tidak cukup: <awalan>.approve sendiri
     * menggerbangi 15 rute yang bukan approve/reject sebuah dokumen —
     * memposting jurnal manual, membuka kembali periode fiskal, advance payout
     * dan retention release SPK di antaranya. honouredOnThisRequest()
     * menutupnya dari BENTUK rutenya, jadi rute ke-16 tertutup secara bawaan.
     * Antrean persetujuan memanggil grants() langsung, karena ia bacaan.
     *
     * Memo delegasinya dibuang di batas unit kerja yang sama dengan memo
     * SettingService — permintaan berikutnya harus melihat delegasi yang baru
     * dicabut, tetapi satu permintaan membaca satu potret dari awal ke akhir.
     */
    private function registerApprovalDelegationGate(): void
    {
        Gate::before(static function ($user, string $ability): ?bool {
            if (! $user instanceof User || ! ApprovalDelegations::honouredOnThisRequest()) {
                return null;
            }

            return ApprovalDelegations::grants($user, $ability);
        });
    }
}
