<?php

namespace Tests\Unit\Core;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\Erp;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\ErpTestCase;

class SettingServiceTest extends ErpTestCase
{
    private SettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(SettingService::class);
    }

    public function test_it_falls_back_to_the_shipped_config_default(): void
    {
        $this->assertSame(11.0, (float) $this->settings->get('tax.ppn_rate'));
        $this->assertFalse($this->settings->isOverridden('tax.ppn_rate'));
    }

    public function test_an_override_wins_over_the_config_default(): void
    {
        $this->settings->set('tax.ppn_rate', 12.0);

        $this->assertSame(12.0, (float) $this->settings->get('tax.ppn_rate'));
        $this->assertSame(11.0, (float) $this->settings->default('tax.ppn_rate'));
        $this->assertTrue($this->settings->isOverridden('tax.ppn_rate'));
    }

    public function test_setting_null_removes_the_override(): void
    {
        $this->settings->set('tax.ppn_rate', 12.0);
        $this->settings->set('tax.ppn_rate', null);

        $this->assertSame(11.0, (float) $this->settings->get('tax.ppn_rate'));
        $this->assertFalse($this->settings->isOverridden('tax.ppn_rate'));
        $this->assertDatabaseMissing('core_settings', ['key' => 'tax.ppn_rate']);
    }

    public function test_it_refuses_keys_outside_the_registry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('tax.made_up_rate', 1);
    }

    public function test_the_erp_facade_reads_through_the_override(): void
    {
        $this->settings->set('payroll.overtime.divisor', 200);

        $this->assertSame(200, Erp::int('payroll.overtime.divisor', 173));
    }

    public function test_overview_reports_value_default_and_override_state(): void
    {
        $this->settings->set('projects.default_retention_pct', 7.5);

        $rows = collect($this->settings->overview())
            ->flatMap(fn (array $group) => $group['settings'])
            ->keyBy('key');

        $this->assertSame(7.5, (float) $rows['projects.default_retention_pct']['value']);
        $this->assertSame(5.0, (float) $rows['projects.default_retention_pct']['default']);
        $this->assertTrue($rows['projects.default_retention_pct']['is_overridden']);
        $this->assertFalse($rows['tax.ppn_rate']['is_overridden']);
    }

    public function test_every_registry_key_resolves_to_a_config_default(): void
    {
        foreach (array_keys($this->settings->editableKeys()) as $key) {
            $this->assertNotNull(
                $this->settings->default($key),
                "Setting [{$key}] has no default in config/erp.php.",
            );
        }
    }

    /**
     * M5, restated at the boundary the guarantee actually holds at.
     *
     * The worker is the container-scoped instance a job resolves; the web
     * process writes. The old design re-read a shared version stamp on EVERY
     * lookup, so the worker saw the write mid-job — at the cost of one cache
     * read (one DB query under CACHE_STORE=database) per parameter, 2,400 for a
     * 200-payslip run (A6).
     *
     * The guarantee is now made at the unit-of-work boundary instead:
     * forgetScopedInstances() below is exactly what Illuminate\Queue\Worker runs
     * before reserving each job (QueueServiceProvider::registerWorker builds the
     * Worker with that callback), so this is the worker picking up its next job.
     * It must compute at the new rate from that point on, with no restart.
     *
     * What is deliberately NOT claimed any more: that a write becomes visible
     * part-way through a job already running. It does not, and should not — a
     * payroll run computing half its payslips at 11% and half at 12% is a bug,
     * not a feature.
     */
    public function test_a_write_by_another_process_is_seen_on_the_next_unit_of_work(): void
    {
        $worker = app(SettingService::class);           // the job's instance
        $this->assertSame(11.0, (float) $worker->get('tax.ppn_rate')); // memo holds 11

        (new SettingService)->set('tax.ppn_rate', 5.0); // another process writes

        // Job ends, worker loops, container drops its scoped instances.
        $this->app->forgetScopedInstances();

        $next = app(SettingService::class);
        $this->assertSame(5.0, (float) $next->get('tax.ppn_rate'));
        $this->assertTrue($next->isOverridden('tax.ppn_rate'));
        $this->assertSame(11.0, (float) $next->default('tax.ppn_rate'));
    }

    public function test_a_reset_by_another_process_is_seen_on_the_next_unit_of_work(): void
    {
        (new SettingService)->set('tax.ppn_rate', 5.0);

        $worker = app(SettingService::class);
        $this->assertSame(5.0, (float) $worker->get('tax.ppn_rate'));

        (new SettingService)->set('tax.ppn_rate', null);

        $this->app->forgetScopedInstances();

        $next = app(SettingService::class);
        $this->assertSame(11.0, (float) $next->get('tax.ppn_rate'));
        $this->assertFalse($next->isOverridden('tax.ppn_rate'));
    }

    /**
     * A write through the instance doing the reading is visible at once — set()
     * drops its own memo. Every test in the suite leans on this through
     * setSetting(), and so does the settings screen, which re-renders from the
     * same instance that just saved.
     */
    public function test_a_write_through_the_same_instance_is_visible_immediately(): void
    {
        $this->assertSame(11.0, (float) $this->settings->get('tax.ppn_rate')); // memoise first

        $this->settings->set('tax.ppn_rate', 12.0);

        $this->assertSame(12.0, (float) $this->settings->get('tax.ppn_rate'));

        $this->settings->set('tax.ppn_rate', null);

        $this->assertSame(11.0, (float) $this->settings->get('tax.ppn_rate'));
    }

    /**
     * The memo still has to earn its keep: reading many parameters in a row must
     * not re-query core_settings once per parameter.
     */
    public function test_reading_many_parameters_hits_the_database_once(): void
    {
        $this->settings->set('tax.ppn_rate', 12.0);

        $reader = new SettingService;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach (array_keys($reader->editableKeys()) as $key) {
            $reader->get($key);
        }

        $selects = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'from "core_settings"'),
        ));

        $this->assertCount(
            1,
            $selects,
            'The override map must be loaded once, not once per key: '.implode(' | ', $queries),
        );
    }

    /**
     * M7: a key on the settings screen must have a runtime reader. Anything that
     * is only ever read by a seeder or a migration through config() is a seed
     * default, not a parameter, and advertising it promises an edit that does
     * nothing (tax.pph23_services_rate was exactly that).
     */
    public function test_the_registry_does_not_advertise_seed_only_parameters(): void
    {
        $this->assertArrayNotHasKey('tax.pph23_services_rate', $this->settings->editableKeys());

        // Still a shipped default, because TaxSeeder needs it.
        $this->assertSame(2.0, (float) $this->settings->default('tax.pph23_services_rate'));
    }

    /**
     * The registry contract, DERIVED FROM THE SOURCE rather than from the list
     * in a comment, in both directions:
     *
     *   every key read at runtime through Erp:: is editable  — otherwise a
     *       parameter the engine obeys cannot be corrected without a deploy, and
     *       the settings screen is not the whole truth about what drives it;
     *   every editable key is read at runtime through Erp:: — otherwise the
     *       screen promises an edit that changes nothing (M7,
     *       tax.pph23_services_rate, whose only reader calls config()).
     *
     * Dynamic reads are honoured as prefixes: DocumentNumberService reads
     * Erp::string("documents.{$type}") and PphConstructionScheme reads
     * Erp::float("tax.pph_final_construction.{$this->value}"), so every key under
     * those prefixes has a reader even though no literal names it.
     */
    public function test_the_registry_and_the_source_agree_in_both_directions(): void
    {
        [$literals, $prefixes] = $this->keysReadThroughErp();
        $editable = $this->settings->editableKeys();

        $this->assertNotEmpty($literals, 'The source scan found no Erp:: reads at all; the scan itself is broken.');

        foreach ($literals as $key => $files) {
            if (array_key_exists($key, self::RUNTIME_KEYS_DELIBERATELY_NOT_EDITABLE)) {
                $this->assertArrayNotHasKey($key, $editable, sprintf(
                    '[%s] must stay off the settings screen: %s',
                    $key,
                    self::RUNTIME_KEYS_DELIBERATELY_NOT_EDITABLE[$key],
                ));

                continue;
            }

            $this->assertArrayHasKey($key, $editable, sprintf(
                'Setting [%s] is read at runtime (%s) but is not in the registry, so it cannot be corrected '
                    .'without a deploy. Register it, or document it in RUNTIME_KEYS_DELIBERATELY_NOT_EDITABLE.',
                $key,
                implode(', ', $files),
            ));
        }

        foreach (array_keys($editable) as $key) {
            $covered = array_key_exists($key, $literals);

            foreach ($prefixes as $prefix) {
                $covered = $covered || str_starts_with($key, $prefix);
            }

            $this->assertTrue($covered, sprintf(
                'Setting [%s] is offered on the settings screen but nothing reads it through Erp:: at '
                    .'runtime, so editing it would do nothing.',
                $key,
            ));
        }
    }

    /**
     * The one key the engine reads through Erp:: that must never be editable,
     * with the reason it is not, asserted above.
     */
    private const RUNTIME_KEYS_DELIBERATELY_NOT_EDITABLE = [
        'accounting.perpetual_inventory' => 'it elects the inventory accounting method (audit A2). '
            .'Perpetual and periodic disagree about where the value of on-hand stock lives, so changing it '
            .'once documents exist strands the value on one side or double counts it on the other, and a '
            .'real change of method needs a stock revaluation at a fiscal-period boundary. It is an '
            .'install-time constant in config/erp.php; erp:inventory-method-check reports whether a change '
            .'is safe.',
    ];

    /**
     * Every ERP parameter the application reads at runtime, scanned out of the
     * source of every module and of app/.
     *
     * Literal reads (Erp::float('tax.ppn_rate')) come back as key => files;
     * interpolated reads (Erp::string("documents.{$type}")) come back as the
     * literal prefix that precedes the interpolation. Seeders and migrations are
     * included in the scan because they live in Modules/ too — but they read
     * through config(), which this deliberately does not match: config() does not
     * see an override, which is the whole reason a config-only key is not a
     * parameter.
     *
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function keysReadThroughErp(): array
    {
        $literals = [];
        $prefixes = [];

        // Derived from the class, not hard-coded. A hard-coded list silently
        // stops covering the accessor somebody adds next — and a key nothing is
        // seen to read looks, to the assertion below, exactly like a key nothing
        // reads.
        $accessors = implode('|', $this->erpAccessors());

        foreach ([base_path('Modules'), base_path('app')] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                $name = $file->getBasename();

                if (preg_match_all('/Erp::(?:'.$accessors.')\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $matches)) {
                    foreach ($matches[1] as $key) {
                        $literals[$key][] = $name;
                    }
                }

                if (preg_match_all('/Erp::(?:'.$accessors.')\(\s*"([^"{]*)\{\$/', $source, $matches)) {
                    foreach ($matches[1] as $prefix) {
                        $prefixes[] = $prefix;
                    }
                }
            }
        }

        foreach ($literals as $key => $files) {
            $literals[$key] = array_values(array_unique($files));
        }

        return [$literals, array_values(array_unique($prefixes))];
    }

    /**
     * Every public static reader on Erp, so the scan above cannot fall behind it.
     *
     * @return list<string>
     */
    private function erpAccessors(): array
    {
        $methods = (new \ReflectionClass(Erp::class))->getMethods(\ReflectionMethod::IS_PUBLIC);

        $names = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter($methods, static fn (\ReflectionMethod $method): bool => $method->isStatic()),
        );

        $this->assertNotEmpty($names, 'Erp exposes no static readers; the scan would match nothing.');

        return array_values($names);
    }

    /**
     * A2: the inventory accounting method is not a setting.
     *
     * It was a checkbox next to the PPN rate, read live when a receipt posted and
     * again when the vendor bill was approved. One flip corrupted the ledger in
     * whichever direction it was flipped — on-at-receipt/off-later left the
     * purchase in 1-1400 against a stock sub-ledger of zero and expensed it
     * nowhere; off-at-receipt/on-later expensed it twice and drove 1-1400
     * negative. Withdrawing it from the registry is what makes it unwritable,
     * because set() refuses a key it does not describe — and set() is the API a
     * seeder, a console command, another service and the controller all go
     * through.
     */
    public function test_the_inventory_accounting_method_cannot_be_written_through_the_service(): void
    {
        $this->assertArrayNotHasKey('accounting.perpetual_inventory', $this->settings->editableKeys());

        foreach ([false, true, null] as $value) {
            try {
                $this->settings->set('accounting.perpetual_inventory', $value);
                $this->fail('Expected the service to refuse the accounting-method key.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('accounting.perpetual_inventory', $e->getMessage());
                $this->assertStringContainsString('not editable', $e->getMessage());
            }
        }

        // Not through a batch either, and the valid sibling is not applied.
        try {
            $this->settings->setMany([
                'tax.ppn_rate' => 12,
                'accounting.perpetual_inventory' => false,
            ]);
            $this->fail('Expected the batch to be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('core_settings', 0);

        // It is still a shipped constant, and still perpetual, which is what the
        // whole GR/IR chain and every seeded document assume.
        $this->assertTrue((bool) $this->settings->get('accounting.perpetual_inventory'));
        $this->assertTrue((bool) $this->settings->default('accounting.perpetual_inventory'));
    }

    /**
     * An installation that stored an override BEFORE the key was withdrawn keeps
     * it, deliberately: silently switching a company from periodic to perpetual
     * on the deploy that withdrew the checkbox is the very corruption A2 is
     * about. The row is reported, not obeyed-and-forgotten — invalidOverrides()
     * lists it, and so does erp:inventory-method-check.
     */
    public function test_an_override_stored_before_the_withdrawal_is_still_honoured_and_reported(): void
    {
        Setting::query()->create([
            'key' => 'accounting.perpetual_inventory',
            'value' => false,
            'group' => 'accounting',
        ]);
        $this->settings->flush();

        $this->assertFalse((bool) $this->settings->get('accounting.perpetual_inventory'));

        $reported = collect($this->settings->invalidOverrides())->firstWhere('key', 'accounting.perpetual_inventory');

        $this->assertNotNull($reported, 'A stored row for a withdrawn key must be reported.');
        $this->assertStringContainsString('not editable', $reported['reason']);
    }

    public function test_stored_overrides_are_grouped_for_reporting(): void
    {
        $this->settings->set('tax.ppn_rate', 12.0);

        $this->assertDatabaseHas('core_settings', ['key' => 'tax.ppn_rate', 'group' => 'tax']);
        $this->assertSame(12.0, (float) Setting::get('tax.ppn_rate'));
    }

    // ------------------------------------------------------------ A7: the service enforces the registry

    /**
     * Values the HTTP layer has always refused, now refused by the service too.
     * set() used to check editability and nothing else, so every one of these
     * could be stored by a seeder, a console command or another service.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function valuesTheRegistryRefuses(): array
    {
        return [
            'percent above 100' => ['tax.ppn_rate', 150],
            'negative percent' => ['tax.ppn_rate', -1],
            'non-numeric percent' => ['tax.ppn_rate', 'sebelas persen'],
            'negative currency' => ['payroll.bpjs.kesehatan.salary_cap', -1],
            'integer above its max' => ['payroll.overtime.divisor', 500],
            'integer below its min' => ['payroll.overtime.divisor', 0],
            'fractional integer' => ['payroll.overtime.divisor', 173.5],
            'select outside its options' => ['payroll.bpjs.jkk.default_risk_class', 6],
            'over-long account code' => ['accounting.inventory_account', '1-1400-0000-0000-0000-0000'],
            'document format without the year' => ['documents.PO', 'PO-{N4}'],
        ];
    }

    #[DataProvider('valuesTheRegistryRefuses')]
    public function test_the_service_refuses_a_value_its_registry_entry_rejects(string $key, mixed $value): void
    {
        try {
            $this->settings->set($key, $value);
            $this->fail("Expected [{$key}] to refuse the value.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($key, $e->getMessage());
        }

        $this->assertDatabaseCount('core_settings', 0);
    }

    /**
     * The bounds themselves are still reachable — enforcement must not shrink
     * the legal range.
     */
    public function test_the_service_still_accepts_every_boundary_value(): void
    {
        $this->settings->set('tax.ppn_rate', 0);
        $this->settings->set('tax.ppn_rate', 100);
        $this->settings->set('payroll.bpjs.kesehatan.salary_cap', 0);
        $this->settings->set('payroll.overtime.divisor', 1);
        $this->settings->set('payroll.overtime.divisor', 400);
        $this->settings->set('payroll.bpjs.jkk.default_risk_class', 1);
        $this->settings->set('payroll.bpjs.jkk.default_risk_class', 5);
        $this->settings->set('accounting.inventory_account', '1-1400');

        $this->assertSame(100.0, (float) $this->settings->get('tax.ppn_rate'));
        $this->assertSame(400, (int) $this->settings->get('payroll.overtime.divisor'));
        $this->assertSame('1-1400', $this->settings->get('accounting.inventory_account'));
    }

    /**
     * Whether an account code names a POSTABLE row of fin_accounts stays an
     * HTTP-layer check: Core sits above every module in the dependency graph and
     * Finance is optional, so Core must not query fin_accounts. The service
     * enforces the type (a string of at most 20 characters) and no more, which
     * is why a syntactically fine but non-existent code passes here and is
     * refused by UpdateSettingsRequest (see SettingValidationTest).
     */
    public function test_the_service_does_not_check_an_account_against_the_chart_of_accounts(): void
    {
        $this->settings->set('accounting.inventory_account', '9-9999');

        $this->assertSame('9-9999', $this->settings->get('accounting.inventory_account'));
    }

    // ------------------------------------------------------------ A7: detecting rows stored before the rule

    public function test_the_health_check_reports_an_override_stored_before_its_rule_existed(): void
    {
        // Exactly how such a row got there: straight into the table, back when
        // set() validated nothing but the key.
        Setting::query()->create(['key' => 'documents.PO', 'value' => 'PO-{N4}', 'group' => 'documents']);
        Setting::query()->create(['key' => 'tax.ppn_rate', 'value' => 150, 'group' => 'tax']);
        Setting::query()->create(['key' => 'tax.ppn_rate_lama', 'value' => 10, 'group' => 'tax']);
        $this->settings->flush();

        $problems = collect($this->settings->invalidOverrides())->keyBy('key');

        $this->assertCount(3, $problems);
        $this->assertArrayHasKey('documents.PO', $problems);   // fails its format rule
        $this->assertArrayHasKey('tax.ppn_rate', $problems);   // fails its percent bounds
        $this->assertArrayHasKey('tax.ppn_rate_lama', $problems); // no longer in the registry

        // Nothing was repaired and nothing was deleted: the values are operator
        // data, and only an operator knows what they were meant to be.
        $this->assertDatabaseCount('core_settings', 3);
        $this->assertSame('PO-{N4}', $this->settings->get('documents.PO'));
    }

    public function test_the_health_check_is_silent_on_a_healthy_installation(): void
    {
        $this->settings->set('tax.ppn_rate', 12.0);
        $this->settings->set('documents.PO', 'PO-{Y}-{N4}');

        $this->assertSame([], $this->settings->invalidOverrides());
    }

    // ------------------------------------------------------------ A6: one lookup per unit of work

    /**
     * A6, under the SHIPPED cache store rather than the array store phpunit
     * pins. Every cache read is a real query against the `cache` table, so the
     * total query count IS the lookup count — which is the whole point: the old
     * design re-read a version stamp per parameter and cost 61 queries for 60
     * reads (2,401 for a 200-payslip payroll run).
     */
    public function test_sixty_reads_cost_one_lookup_under_the_database_cache_store(): void
    {
        config(['cache.default' => 'database']);
        Cache::forget(SettingService::CACHE_KEY);

        $keys = array_keys($this->settings->editableKeys());
        $this->assertGreaterThanOrEqual(60, count($keys), 'The registry must supply 60 reads.');
        $reads = array_slice($keys, 0, 60);

        // Warm the shared cache entry the way the previous unit of work would
        // have left it, then start a fresh instance: this is the next request.
        (new SettingService)->get('tax.ppn_rate');

        $reader = new SettingService;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ($reads as $key) {
            $reader->get($key);
        }

        $this->assertCount(
            1,
            $queries,
            '60 parameter reads must cost exactly one lookup: '.implode(' | ', $queries),
        );
        $this->assertStringContainsString('from "cache"', $queries[0]);
    }

    /**
     * The same measurement on a cold cache: one cache miss, one schema probe,
     * one map SELECT and one cache write — then nothing, however many more
     * parameters are read.
     */
    public function test_a_cold_read_of_sixty_parameters_costs_four_queries_then_nothing(): void
    {
        config(['cache.default' => 'database']);
        Cache::forget(SettingService::CACHE_KEY);

        $reads = array_slice(array_keys($this->settings->editableKeys()), 0, 60);

        $reader = new SettingService;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ($reads as $key) {
            $reader->get($key);
        }

        $this->assertCount(4, $queries, 'Cold: '.implode(' | ', $queries));
        $this->assertSame(
            1,
            count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "core_settings"'))),
        );
    }
}
