<?php

namespace Tests\Unit\Core;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * The settings layer as an API, not as a screen (audit A6 + A7).
 *
 * A7  The document-format rule lived only in UpdateSettingsRequest, so
 *     set('documents.PO', 'PO-{N4}') was accepted through the service and
 *     reproduced the January collision (PO-0001 issued in both 2026 and 2027
 *     against a unique code column). The pattern also had no end anchor, so
 *     "PO/{Y}/{N4}\nEVIL-LINE" and "PO/{Y}/{N4}<script>" both passed it. And an
 *     installation that stored such a row BEFORE the rule existed had no way to
 *     find it.
 *
 * A6  version() was a cache read on EVERY lookup, so under the shipped
 *     CACHE_STORE=database each parameter read was one database query:
 *     30 reads => 30 queries, and a 200-payslip payroll run touching 12
 *     parameters => 200 * 12 = 2.400 queries. That contradicted the class's own
 *     promise that "a request reading 30 parameters costs one lookup rather than
 *     30". The related claim that CACHE_TTL bounded staleness under a private
 *     store was false, because the memo was returned BEFORE the cache was
 *     consulted and under a private store the version stamp never moved.
 *
 * Every count below is a measurement with its arithmetic in a comment.
 */
class SettingServiceEnforcementTest extends ErpTestCase
{
    private SettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingService::class);
    }

    // ------------------------------------------------------------ A7: the service enforces the rule

    public function test_the_service_itself_refuses_an_invalid_document_format(): void
    {
        // No HTTP layer anywhere in this test: this is the API a seeder, a
        // console command or another service calls.
        try {
            $this->settings->set('documents.PO', 'PO-{N4}');
            $this->fail('Expected the service to refuse a format without {Y}.');
        } catch (InvalidArgumentException $e) {
            // The message has to name the key and quote the offending value, or
            // an operator cannot act on it.
            $this->assertStringContainsString('documents.PO', $e->getMessage());
            $this->assertStringContainsString('PO-{N4}', $e->getMessage());
        }

        // Refused means NOT STORED — the row that breaks numbering never exists.
        $this->assertDatabaseCount('core_settings', 0);
        $this->assertFalse($this->settings->isOverridden('documents.PO'));
        $this->assertSame('PO/{Y}/{RM}/{N4}', $this->settings->get('documents.PO'));

        // The batch entry point validates the whole batch before writing any of
        // it, so one bad format cannot leave a valid sibling applied.
        try {
            $this->settings->setMany([
                'documents.GRN' => 'GRN/{Y}/{RM}/{N4}', // fine on its own
                'documents.PO' => 'PO-{N4}',            // not
            ]);
            $this->fail('Expected the batch to be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('core_settings', 0);

        // And the legal form of the same edit still goes through.
        $this->settings->set('documents.PO', 'PO-{Y}-{N4}');
        $this->assertSame('PO-{Y}-{N4}', $this->settings->get('documents.PO'));
    }

    /**
     * The two payloads from the audit, verbatim.
     *
     * @return array<string, array{0: string}>
     */
    public static function unanchoredPayloads(): array
    {
        return [
            'trailing line' => ["PO/{Y}/{N4}\nEVIL-LINE"],
            'markup' => ['PO/{Y}/{N4}<script>'],
        ];
    }

    #[DataProvider('unanchoredPayloads')]
    public function test_the_anchored_pattern_rejects_a_payload_appended_after_a_valid_prefix(string $format): void
    {
        // \A … \z, not ^ … $: `$` also matches immediately before a final
        // newline, which is exactly how "PO/{Y}/{N4}\nEVIL-LINE" got through and
        // would have been stored — and printed — as a two-line document code.
        $this->assertSame(0, preg_match(SettingService::DOCUMENT_FORMAT_PATTERN, $format));
        $this->assertSame(1, preg_match(SettingService::DOCUMENT_FORMAT_PATTERN, 'PO/{Y}/{N4}'));

        // Refused by the service…
        try {
            $this->settings->set('documents.PO', $format);
            $this->fail('Expected the service to refuse an unanchored payload.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('documents.PO', $e->getMessage());
        }

        // …and by the very same rule set the HTTP layer applies, so the two ends
        // cannot drift apart.
        $validator = Validator::make(
            ['settings' => ['documents.PO' => $format]],
            $this->settings->validationRules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('settings.documents.PO', $validator->errors()->toArray());

        $this->assertDatabaseCount('core_settings', 0);
        $this->assertSame('PO/{Y}/{RM}/{N4}', $this->settings->get('documents.PO'));
    }

    // ------------------------------------------------------------ A7: rows stored before the rule

    public function test_the_health_check_reports_a_pre_existing_invalid_override(): void
    {
        // How such rows got there: straight into core_settings, back when set()
        // checked editability and nothing else. Writing them any other way is
        // now impossible, which is precisely why they need a health check.
        Setting::query()->create(['key' => 'documents.PO', 'value' => 'PO-{N4}', 'group' => 'documents']);
        Setting::query()->create(['key' => 'documents.GRN', 'value' => "GRN/{Y}/{N4}\nEVIL", 'group' => 'documents']);
        Setting::query()->create(['key' => 'tax.ppn_rate', 'value' => 12.0, 'group' => 'tax']); // healthy
        $this->settings->flush();

        $problems = collect($this->settings->invalidOverrides())->keyBy('key');

        // 3 stored rows, 2 of them refused by their own registry entry today.
        $this->assertCount(2, $problems);
        $this->assertArrayHasKey('documents.PO', $problems);
        $this->assertArrayHasKey('documents.GRN', $problems);
        $this->assertArrayNotHasKey('tax.ppn_rate', $problems);

        // The report carries what the operator needs to fix it by hand.
        $this->assertSame('PO-{N4}', $problems['documents.PO']['value']);
        $this->assertSame('documents', $problems['documents.PO']['group']);
        $this->assertStringContainsString('documents.PO', $problems['documents.PO']['reason']);

        // Reported, never repaired and never deleted: the value is operator data
        // and only an operator knows what it was meant to be. All three rows are
        // still there and the bad one is still in force.
        $this->assertDatabaseCount('core_settings', 3);
        $this->assertSame('PO-{N4}', $this->settings->get('documents.PO'));
        $this->assertSame(12.0, (float) $this->settings->get('tax.ppn_rate'));

        // Once the operator corrects them through the service, it goes quiet.
        $this->settings->set('documents.PO', 'PO/{Y}/{RM}/{N4}');
        $this->settings->set('documents.GRN', 'GRN/{Y}/{RM}/{N4}');

        $this->assertSame([], $this->settings->invalidOverrides());
    }

    // ------------------------------------------------------------ A6: one lookup per unit of work

    public function test_reading_every_registry_key_costs_one_database_lookup(): void
    {
        // Measured under the SHIPPED cache store, not the array store phpunit
        // pins: every cache read there is a real query against the `cache`
        // table, so the query count IS the lookup count.
        config(['cache.default' => 'database']);
        Cache::forget(SettingService::CACHE_KEY);

        $keys = array_keys($this->settings->editableKeys());
        $this->assertGreaterThanOrEqual(60, count($keys), 'The registry must supply at least 60 keys.');

        // The previous unit of work left the shared entry warm; this instance is
        // the next request or job.
        (new SettingService)->get('tax.ppn_rate');

        $reader = new SettingService;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        foreach ($keys as $key) {
            $reader->get($key);
        }

        // 62 registry keys today => 1 query. The old design re-read a version
        // stamp per lookup: 62 keys => 62 queries.
        $this->assertCount(
            1,
            $queries,
            count($keys).' parameter reads must cost exactly one lookup: '.implode(' | ', $queries),
        );
        $this->assertStringContainsString('from "cache"', $queries[0]);
    }

    public function test_a_payroll_sized_run_of_parameter_reads_still_costs_one_lookup(): void
    {
        // The audit's own arithmetic: 200 payslips * 12 parameters = 2.400
        // reads, which used to be 2.400 queries under CACHE_STORE=database.
        config(['cache.default' => 'database']);
        Cache::forget(SettingService::CACHE_KEY);

        $payrollKeys = [
            'payroll.bpjs.kesehatan.company',
            'payroll.bpjs.kesehatan.employee',
            'payroll.bpjs.kesehatan.salary_cap',
            'payroll.bpjs.jht.company',
            'payroll.bpjs.jht.employee',
            'payroll.bpjs.jp.company',
            'payroll.bpjs.jp.employee',
            'payroll.bpjs.jp.salary_cap',
            'payroll.bpjs.jkk.default_risk_class',
            'payroll.bpjs.jkk.rates.3',
            'payroll.bpjs.jkm.company',
            'payroll.overtime.divisor',
        ];
        $this->assertCount(12, $payrollKeys);

        (new SettingService)->get('tax.ppn_rate'); // warm the shared entry

        $reader = new SettingService; // one payroll run = one unit of work
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $reads = 0;

        for ($payslip = 1; $payslip <= 200; $payslip++) {
            foreach ($payrollKeys as $key) {
                $reader->get($key);
                $reads++;
            }
        }

        // 200 * 12 = 2.400 reads, 1 query.
        $this->assertSame(2400, $reads);
        $this->assertCount(1, $queries, '2.400 reads must cost one lookup: '.implode(' | ', $queries));

        // …and the run computed on ONE consistent snapshot, which is the point
        // of the memo: half the payslips at 11% and half at 12% is a bug.
        $this->assertSame(173, (int) $reader->get('payroll.overtime.divisor'));
    }

    /**
     * The other half of A6: the memo must NOT be returned before the cache is
     * consulted, or the TTL bounds nothing at all on a store that is private to
     * each process.
     */
    public function test_a_fresh_unit_of_work_consults_the_cache_before_serving_anything(): void
    {
        // A doctored entry stands in for "what the previous unit of work left
        // there": if a fresh instance serves it, layer 2 is genuinely consulted
        // and an expiring entry is genuinely re-read.
        Cache::put(SettingService::CACHE_KEY, ['tax.ppn_rate' => 12.0], 60);

        $next = new SettingService;
        $this->assertSame(12.0, (float) $next->get('tax.ppn_rate'));
        $this->assertTrue($next->isOverridden('tax.ppn_rate'));

        // Entry gone (expired, or forgotten by a write in another process): the
        // next unit of work falls through to core_settings, which holds no
        // override, so the shipped default is back in force.
        Cache::forget(SettingService::CACHE_KEY);

        $this->assertSame(11.0, (float) (new SettingService)->get('tax.ppn_rate'));
        $this->assertFalse((new SettingService)->isOverridden('tax.ppn_rate'));
        $this->assertDatabaseCount('core_settings', 0);
    }
}
