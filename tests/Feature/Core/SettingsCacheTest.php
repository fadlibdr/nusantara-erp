<?php

namespace Tests\Feature\Core;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\Erp;
use Tests\ErpTestCase;

/**
 * How a parameter change reaches the processes that read it, and what it costs.
 *
 * M5 — the original defect. SettingService memoised the override map per
 * instance AND was bound as a container singleton, so a queue:work process held
 * one instance, and therefore one memo, for its whole life. flush() cleared only
 * the flushing process's memo plus a shared cache key; nothing could invalidate
 * another process's memo, and the map was cached with rememberForever(), so
 * there was not even a TTL to fall back on. Reproduced: a worker kept computing
 * at PPN 12% for ever after the UI had changed it to 5%.
 *
 * A6 — the repair's own cost. The first fix re-read a shared version stamp from
 * the cache on EVERY lookup. Under the shipped CACHE_STORE=database that is one
 * DB query per parameter: 61 queries for 60 reads, 2,401 for a 200-payslip
 * payroll run — the exact opposite of the "one lookup per request" the code
 * claimed.
 *
 * What is guaranteed now, and where. The memo lives exactly one unit of work,
 * because CoreServiceProvider binds the service scoped() and
 * Illuminate\Queue\Worker::daemon() calls forgetScopedInstances() before
 * reserving each job (the $resetScope callback built in
 * QueueServiceProvider::registerWorker). A write forgets the shared cache entry,
 * so the next unit of work in every process reloads from core_settings.
 * forgetScopedInstances() in the tests below is therefore not a trick to make a
 * test pass — it IS the worker taking its next job.
 *
 * Two independently constructed SettingService instances stand in for two OS
 * processes sharing one cache store.
 */
class SettingsCacheTest extends ErpTestCase
{
    /** @var array<int, string> override-map loads seen since recordMapLoads() */
    private array $mapLoads = [];

    /** Simulate the worker looping to its next job / php-fpm serving the next request. */
    private function nextUnitOfWork(): SettingService
    {
        $this->app->forgetScopedInstances();

        return app(SettingService::class);
    }

    public function test_a_worker_observes_a_write_made_by_another_process(): void
    {
        $worker = app(SettingService::class);   // the instance this job resolved
        $web = new SettingService;              // the request serving the settings screen

        // The worker reads once and memoises: config default PPN 11%.
        $this->assertSame(11.0, (float) $worker->get('tax.ppn_rate'));
        $this->assertFalse($worker->isOverridden('tax.ppn_rate'));

        $web->set('tax.ppn_rate', 5.0);

        // Nobody restarted the worker. Its next job must read 5%, not 11%.
        $next = $this->nextUnitOfWork();
        $this->assertSame(5.0, (float) $next->get('tax.ppn_rate'));
        $this->assertTrue($next->isOverridden('tax.ppn_rate'));

        // The shipped default is unchanged — this is an override, not an edit.
        $this->assertSame(11.0, (float) $next->default('tax.ppn_rate'));
    }

    public function test_a_queued_run_computes_at_the_new_rate_the_ui_reports(): void
    {
        // The scenario the defect was dormant under php-fpm but live for:
        // invoicing runs in a worker while an administrator edits the rate.
        $this->assertSame(11.0, Erp::float('tax.ppn_rate', 11.0));

        // PPN on a 6.200.000 DPP at 11% = 682.000
        $this->assertSame(682000.0, round(6200000 * Erp::float('tax.ppn_rate', 11.0) / 100, 2));

        (new SettingService)->set('tax.ppn_rate', 12.0);

        // Next job, same process, no restart:
        // PPN on 6.200.000 at 12% = 744.000
        $worker = $this->nextUnitOfWork();
        $this->assertSame(12.0, (float) $worker->get('tax.ppn_rate'));
        $this->assertSame(12.0, Erp::float('tax.ppn_rate', 11.0));
        $this->assertSame(744000.0, round(6200000 * Erp::float('tax.ppn_rate', 11.0) / 100, 2));
    }

    /**
     * The flip side, and it is deliberate: a job ALREADY RUNNING reads one
     * consistent snapshot from beginning to end. The version-stamped design gave
     * the opposite, and a payroll run that computes half its payslips at 11% and
     * half at 12% because an administrator saved the screen mid-run is a defect,
     * not freshness. Documents snapshot their rates at creation anyway.
     */
    public function test_a_job_already_running_sees_one_consistent_snapshot(): void
    {
        $worker = app(SettingService::class);
        $this->assertSame(11.0, (float) $worker->get('tax.ppn_rate'));

        (new SettingService)->set('tax.ppn_rate', 12.0);

        // Still mid-job: the rate this run started with is the rate it finishes with.
        $this->assertSame(11.0, (float) $worker->get('tax.ppn_rate'));

        // And the change is picked up the moment the job ends.
        $this->assertSame(12.0, (float) $this->nextUnitOfWork()->get('tax.ppn_rate'));
    }

    public function test_a_reset_by_another_process_is_observed_too(): void
    {
        $web = new SettingService;
        $web->set('tax.ppn_rate', 5.0);

        $worker = app(SettingService::class);
        $this->assertSame(5.0, (float) $worker->get('tax.ppn_rate')); // memoises 5

        $web->set('tax.ppn_rate', null); // "reset to the shipped default"

        $next = $this->nextUnitOfWork();
        $this->assertSame(11.0, (float) $next->get('tax.ppn_rate'));
        $this->assertFalse($next->isOverridden('tax.ppn_rate'));
        $this->assertDatabaseMissing('core_settings', ['key' => 'tax.ppn_rate']);
    }

    public function test_a_row_written_directly_by_another_process_is_observed_after_its_flush(): void
    {
        $worker = app(SettingService::class);
        $this->assertSame(173, (int) $worker->get('payroll.overtime.divisor'));

        // Another process writes the row and invalidates: the forgotten cache
        // entry is what has to carry the write across the process boundary.
        Setting::query()->create([
            'key' => 'payroll.overtime.divisor',
            'value' => 200,
            'group' => 'bpjs',
        ]);
        (new SettingService)->flush();

        // Upah sejam = upah sebulan / pembagi: 5.200.000 / 200 = 26.000
        $next = $this->nextUnitOfWork();
        $this->assertSame(200, (int) $next->get('payroll.overtime.divisor'));
        $this->assertSame(26000.0, round(5200000 / (int) $next->get('payroll.overtime.divisor'), 2));
    }

    /**
     * The invalidation mechanism itself: a write must leave no cache entry
     * behind for the next unit of work to serve.
     */
    public function test_every_write_forgets_the_shared_cache_entry(): void
    {
        $web = new SettingService;
        $web->get('tax.ppn_rate'); // populate the shared entry
        $this->assertIsArray(Cache::get(SettingService::CACHE_KEY));

        $web->set('tax.ppn_rate', 12.0);
        $this->assertNull(Cache::get(SettingService::CACHE_KEY));

        $web->get('tax.ppn_rate');
        $this->assertIsArray(Cache::get(SettingService::CACHE_KEY));

        $web->set('projects.default_retention_pct', 7.5);
        $this->assertNull(Cache::get(SettingService::CACHE_KEY));

        $web->get('tax.ppn_rate');
        $web->set('tax.ppn_rate', null); // a reset is a write too
        $this->assertNull(Cache::get(SettingService::CACHE_KEY));

        // Repopulated on the next read, and holding the post-reset map.
        $this->assertSame(11.0, (float) $this->nextUnitOfWork()->get('tax.ppn_rate'));
        $this->assertSame(
            ['projects.default_retention_pct' => 7.5],
            Cache::get(SettingService::CACHE_KEY),
        );
    }

    /**
     * The cache key is deliberately not the bare 'core.settings.overrides' that
     * earlier builds used — one of them wrote it with rememberForever(). An
     * installation upgrading from such a build must not be able to serve that
     * entry, and must not be left carrying it either.
     */
    public function test_a_forever_entry_from_an_earlier_build_is_never_served(): void
    {
        Cache::forever('core.settings.overrides', ['tax.ppn_rate' => 99.0]);

        $this->assertSame(11.0, (float) (new SettingService)->get('tax.ppn_rate'));

        (new SettingService)->flush();

        $this->assertNull(Cache::get('core.settings.overrides'));
    }

    public function test_the_memo_still_serves_repeated_reads_without_re_querying(): void
    {
        // Freshness must not be bought by dropping the memo: a request reading
        // 30 parameters would then cost 30 SELECTs. One write, then six reads.
        (new SettingService)->set('tax.ppn_rate', 12.0);

        $worker = new SettingService;
        $this->recordMapLoads();

        $worker->get('tax.ppn_rate');
        $worker->get('tax.ppn_rate');
        $worker->get('payroll.overtime.divisor');
        $worker->get('projects.default_retention_pct');
        $worker->get('accounting.inventory_account');
        $worker->get('documents.PO');

        $this->assertCount(1, $this->mapLoads, 'The override map must be read once, then memoised.');
    }

    public function test_a_write_costs_the_writing_instance_exactly_one_reload(): void
    {
        $worker = new SettingService;
        $worker->get('tax.ppn_rate'); // memoised

        $this->recordMapLoads();

        $worker->set('tax.ppn_rate', 12.0);

        // Three reads after one write: one reload, then the memo again.
        $this->assertSame(12.0, (float) $worker->get('tax.ppn_rate'));
        $this->assertSame(12.0, (float) $worker->get('tax.ppn_rate'));
        $this->assertSame(12.0, (float) $worker->get('tax.ppn_rate'));

        $this->assertCount(1, $this->mapLoads, 'One write must cost one reload, not one per read.');
    }

    /**
     * A6 stated as a number, under the store the installation actually ships
     * with. Every cache read is a query against the `cache` table there, so the
     * query count IS the lookup count. Before: 61. After: 1.
     */
    public function test_sixty_reads_in_one_request_cost_one_lookup(): void
    {
        config(['cache.default' => 'database']);
        Cache::forget(SettingService::CACHE_KEY);

        $settings = app(SettingService::class);
        $reads = array_slice(array_keys($settings->editableKeys()), 0, 60);
        $this->assertCount(60, $reads);

        // The previous request left the shared entry warm.
        (new SettingService)->get('tax.ppn_rate');

        $request = $this->nextUnitOfWork();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ($reads as $key) {
            $request->get($key);
        }

        $this->assertCount(1, $queries, '60 reads must cost 1 query: '.implode(' | ', $queries));
    }

    /**
     * The audit's payroll figure: 200 payslips x 12 parameters was 2,400
     * queries. Within one job it is now one lookup, whatever the payslip count.
     */
    public function test_a_two_hundred_payslip_run_costs_one_lookup(): void
    {
        config(['cache.default' => 'database']);
        Cache::forget(SettingService::CACHE_KEY);

        $keys = [
            'payroll.bpjs.kesehatan.company', 'payroll.bpjs.kesehatan.employee',
            'payroll.bpjs.kesehatan.salary_cap', 'payroll.bpjs.jht.company',
            'payroll.bpjs.jht.employee', 'payroll.bpjs.jp.company',
            'payroll.bpjs.jp.employee', 'payroll.bpjs.jp.salary_cap',
            'payroll.bpjs.jkk.default_risk_class', 'payroll.bpjs.jkk.rates.3',
            'payroll.bpjs.jkm.company', 'payroll.overtime.divisor',
        ];

        (new SettingService)->get('tax.ppn_rate'); // warm the shared entry

        $job = $this->nextUnitOfWork();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        for ($payslip = 0; $payslip < 200; $payslip++) {
            foreach ($keys as $key) {
                $job->get($key);
            }
        }

        $this->assertSame(1, $queries, '2400 parameter reads must still cost 1 lookup.');
    }

    /**
     * Start collecting override-map loads — the only SELECT on core_settings
     * without a where clause. Schema probes and per-key writes carry one and
     * are ignored.
     */
    private function recordMapLoads(): void
    {
        $this->mapLoads = [];

        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'from "core_settings"') && ! str_contains($query->sql, 'where')) {
                $this->mapLoads[] = $query->sql;
            }
        });
    }
}
