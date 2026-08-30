<?php

namespace Tests\Unit\Core;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use InvalidArgumentException;
use Modules\Core\Models\NumberSequence;
use Modules\Core\Models\Setting;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\SettingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * M6 — a document format without {Y} collides every January.
 *
 * NumberSequence is keyed on [type, year], so the counter restarts at 1 on
 * 1 January. A format such as 'PO-{N4}' therefore produces PO-0001 again in the
 * new year, colliding with the previous year's code on a unique `code` column:
 * the document simply cannot be saved. The only structural check used to be
 * "must contain a sequence token", and the SHIPPED default documents.AST =
 * 'AST-{N4}' already had the defect.
 *
 * This suite pins the rule (through the real validator, not by reading the
 * pattern), pins every shipped default against it, and demonstrates the
 * collision itself so the rule can never be dismissed as cosmetic.
 */
class DocumentFormatValidationTest extends ErpTestCase
{
    /**
     * Number of document types shipped in config/erp.php. A new type added
     * without a format — or a format dropped — must fail here rather than
     * quietly escape the data-provider sweep below.
     */
    private const SHIPPED_DOCUMENT_TYPES = 54; // +IKL/ILB/IMK (P0-C) +SDS/SMS/TRM/IPP (P1-ENG) +QCI/NCR (P1-QC) +BAN/AWD/PBL (P2) +OPN/BAPP/BSK (P3) +SP3/OPM (P4) +PPK/PPKB (P5)

    private SettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingService::class);
        Carbon::setTestNow('2026-07-15 09:00:00');
    }

    // ---------------------------------------------------------------- the rule

    public function test_the_validator_rejects_a_sequence_token_without_the_year(): void
    {
        // The exact format from the audit, and the shipped AST default that
        // carried the same defect.
        $this->assertRejected('documents.PO', 'PO-{N4}');
        $this->assertRejected('documents.AST', 'AST-{N4}');
        $this->assertRejected('documents.GRN', 'GRN/{RM}/{N5}'); // month, but no year
    }

    public function test_the_validator_accepts_a_format_carrying_both_tokens(): void
    {
        $this->assertAccepted('documents.PO', 'PO-{Y}-{N4}');
        $this->assertAccepted('documents.PO', 'PO/{Y}/{RM}/{N4}');
        $this->assertAccepted('documents.AST', 'AST-{Y}-{N4}');

        // The tokens may appear in either order: two lookaheads, not a sequence.
        $this->assertAccepted('documents.PO', '{N5}/PO/{Y}');
    }

    public function test_the_validator_still_rejects_a_format_with_no_sequence_token(): void
    {
        // {Y} alone would hand every document of a year the same number.
        $this->assertRejected('documents.PO', 'PO-{Y}');
        $this->assertRejected('documents.PO', 'PO-{Y}-{M2}');
        $this->assertRejected('documents.PO', 'PO-2026-0001'); // no tokens at all
    }

    // ---------------------------------------------------------------- the shipped defaults

    /**
     * The test that catches a future default being added with the defect: every
     * entry of config('erp.documents') is fed to the real rule.
     */
    #[DataProvider('shippedDocumentFormats')]
    public function test_every_shipped_document_format_satisfies_the_rule(string $type, string $format): void
    {
        $this->assertAccepted(
            "documents.{$type}",
            $format,
            "Shipped format for {$type} ('{$format}') must carry {Y} and a sequence token.",
        );
    }

    public function test_the_data_provider_covers_every_shipped_document_format(): void
    {
        // The provider reads config/erp.php off disk (providers run before the
        // application boots), so prove it is the very same map the app resolves
        // — otherwise the sweep above could silently cover nothing.
        $fromProvider = [];

        foreach (self::shippedDocumentFormats() as [$type, $format]) {
            $fromProvider[$type] = $format;
        }

        $this->assertSame(config('erp.documents'), $fromProvider);
        $this->assertCount(self::SHIPPED_DOCUMENT_TYPES, $fromProvider);

        // And every one of them is editable on the settings screen, which is
        // what subjects it to the rule at runtime.
        $editable = $this->settings->editableKeys();

        foreach (array_keys($fromProvider) as $type) {
            $this->assertArrayHasKey("documents.{$type}", $editable);
            $this->assertSame('document_format', $editable["documents.{$type}"]['type']);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shippedDocumentFormats(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3).'/config/erp.php';

        $cases = [];

        foreach ($config['documents'] as $type => $format) {
            $cases[$type] = [$type, $format];
        }

        return $cases;
    }

    // ---------------------------------------------------------------- the hazard itself

    public function test_a_year_less_format_repeats_the_same_code_in_the_next_year(): void
    {
        $this->storeLegacyOverride('documents.PO', 'PO-{N4}');

        $numbers = app(DocumentNumberService::class);

        Carbon::setTestNow('2026-12-31 23:59:00');
        $lastOf2026 = $numbers->next('PO');

        Carbon::setTestNow('2027-01-01 00:01:00');
        $firstOf2027 = $numbers->next('PO');

        // Sequences are keyed on [type, year], so both counters read 1 and both
        // codes render as PO-0001 — a collision one minute apart.
        $this->assertSame('PO-0001', $lastOf2026);
        $this->assertSame('PO-0001', $firstOf2027);
        $this->assertSame($lastOf2026, $firstOf2027);

        $this->assertSame(1, (int) NumberSequence::query()->where('type', 'PO')->where('year', 2026)->value('last_number'));
        $this->assertSame(1, (int) NumberSequence::query()->where('type', 'PO')->where('year', 2027)->value('last_number'));

        // Which is precisely why the validator refuses that format.
        $this->assertRejected('documents.PO', 'PO-{N4}');
    }

    public function test_the_colliding_code_cannot_be_saved_on_a_unique_code_column(): void
    {
        $this->storeLegacyOverride('documents.PO', 'PO-{N4}');

        $numbers = app(DocumentNumberService::class);

        Carbon::setTestNow('2026-12-31 23:59:00');
        $lastOf2026 = $numbers->next('PO');

        Carbon::setTestNow('2027-01-01 00:01:00');
        $firstOf2027 = $numbers->next('PO');

        // Every module document table declares code unique — this stands in for
        // prc_purchase_orders.code without dragging its fixtures in.
        Schema::dropIfExists('document_format_probe');
        Schema::create('document_format_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
        });

        DB::table('document_format_probe')->insert(['code' => $lastOf2026]);

        try {
            DB::table('document_format_probe')->insert(['code' => $firstOf2027]);
            $this->fail('Expected the January code to collide with the December one.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }

        $this->assertSame(1, DB::table('document_format_probe')->count());

        Schema::dropIfExists('document_format_probe');
    }

    public function test_the_shipped_format_survives_the_new_year(): void
    {
        // config/erp.php documents.PO = 'PO/{Y}/{RM}/{N4}' — the year token is
        // what keeps the per-year reset safe.
        $numbers = app(DocumentNumberService::class);

        Carbon::setTestNow('2026-12-31 23:59:00');
        $lastOf2026 = $numbers->next('PO');

        Carbon::setTestNow('2027-01-01 00:01:00');
        $firstOf2027 = $numbers->next('PO');

        $this->assertSame('PO/2026/XII/0001', $lastOf2026);
        $this->assertSame('PO/2027/I/0001', $firstOf2027);
        $this->assertNotSame($lastOf2026, $firstOf2027);
    }

    // ---------------------------------------------------------------- the service enforces the rule

    /**
     * A7: the rule used to live only in the FormRequest, so anything calling the
     * service directly — a seeder, a console command, another service, a test —
     * could store 'PO-{N4}' and reproduce the January collision. The service is
     * the API; it has to refuse the value itself.
     */
    public function test_the_service_refuses_a_year_less_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('documents.PO', 'PO-{N4}');
    }

    public function test_a_format_refused_by_the_service_is_not_stored(): void
    {
        try {
            $this->settings->set('documents.PO', 'PO-{N4}');
            $this->fail('Expected the service to refuse a format without {Y}.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('documents.PO', $e->getMessage());
        }

        $this->assertDatabaseMissing('core_settings', ['key' => 'documents.PO']);
        $this->assertSame('PO/{Y}/{RM}/{N4}', $this->settings->get('documents.PO'));
    }

    public function test_the_service_accepts_a_well_formed_format(): void
    {
        $this->settings->set('documents.PO', 'PO-{Y}-{N4}');

        $this->assertSame('PO-{Y}-{N4}', $this->settings->get('documents.PO'));
    }

    /**
     * setMany() must validate the whole batch before writing any of it, so a
     * caller outside a transaction cannot end up with half a batch applied.
     */
    public function test_a_batch_with_one_bad_format_stores_nothing(): void
    {
        try {
            $this->settings->setMany([
                'tax.ppn_rate' => 12.0,          // valid
                'documents.PO' => 'PO-{N4}',     // not
            ]);
            $this->fail('Expected the batch to be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('core_settings', 0);
        $this->assertSame(11.0, (float) $this->settings->get('tax.ppn_rate'));
    }

    // ---------------------------------------------------------------- the anchored pattern

    /**
     * The exact payloads the audit used. The old pattern was
     * '/^(?=.*\{Y\})(?=.*\{N[345]\})/' — unanchored at the end, so it matched a
     * prefix and let everything after it through.
     *
     * @return array<string, array{0: string}>
     */
    public static function maliciousFormats(): array
    {
        return [
            'trailing newline' => ["PO/{Y}/{N4}\nEVIL-LINE"],
            'markup' => ['PO/{Y}/{N4}<script>alert(1)</script>'],
            'bare trailing newline' => ["PO/{Y}/{N4}\n"],
            'carriage return' => ["PO/{Y}/{N4}\r\nEVIL"],
            'leading newline' => ["EVIL\nPO/{Y}/{N4}"],
            'unknown token' => ['PO/{Y}/{N4}/{FOO}'],
            'quote' => ['PO/{Y}/{N4}"'],
            'null byte' => ["PO/{Y}/{N4}\0"],
        ];
    }

    #[DataProvider('maliciousFormats')]
    public function test_the_validator_rejects_an_unsafe_format(string $format): void
    {
        $this->assertRejected('documents.PO', $format);
    }

    #[DataProvider('maliciousFormats')]
    public function test_the_service_rejects_an_unsafe_format(string $format): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('documents.PO', $format);
    }

    public function test_the_pattern_matches_only_the_whole_string(): void
    {
        // Directly against the constant, so the anchoring is pinned even if the
        // rule set around it changes: \A … \z, never ^ … $ (which also matches
        // just before a final newline).
        $this->assertSame(1, preg_match(SettingService::DOCUMENT_FORMAT_PATTERN, 'PO/{Y}/{N4}'));
        $this->assertSame(0, preg_match(SettingService::DOCUMENT_FORMAT_PATTERN, "PO/{Y}/{N4}\n"));
        $this->assertSame(0, preg_match(SettingService::DOCUMENT_FORMAT_PATTERN, "PO/{Y}/{N4}\nEVIL-LINE"));
        $this->assertSame(0, preg_match(SettingService::DOCUMENT_FORMAT_PATTERN, 'PO/{Y}/{N4}<script>alert(1)</script>'));
    }

    /**
     * The safe alphabet has to be wide enough for real Indonesian numbering
     * habits, or operators will simply be blocked.
     */
    public function test_the_alphabet_admits_the_separators_operators_actually_use(): void
    {
        $this->assertAccepted('documents.PO', 'PO/{Y}/{RM}/{N4}');
        $this->assertAccepted('documents.PO', 'PO-{Y}-{N4}');
        $this->assertAccepted('documents.PO', 'PO.{Y}.{N4}');
        $this->assertAccepted('documents.PO', 'PO_{Y}_{N4}');
        $this->assertAccepted('documents.PO', 'PO {Y} {N4}');
        $this->assertAccepted('documents.PO', '{N4}/PO/NKI/{Y}');
    }

    // ---------------------------------------------------------------- stored-before-the-rule detection

    /**
     * An installation may hold a row written before the rule existed. It must be
     * findable, and it must NOT be deleted behind the operator's back.
     */
    public function test_a_legacy_invalid_override_is_reported_by_the_health_check(): void
    {
        $this->storeLegacyOverride('documents.PO', 'PO-{N4}');

        $problems = $this->settings->invalidOverrides();

        $this->assertCount(1, $problems);
        $this->assertSame('documents.PO', $problems[0]['key']);
        $this->assertSame('PO-{N4}', $problems[0]['value']);
        $this->assertStringContainsString('documents.PO', $problems[0]['reason']);

        // Reported, not repaired: the row is still there and still in force.
        $this->assertDatabaseHas('core_settings', ['key' => 'documents.PO']);
        $this->assertSame('PO-{N4}', $this->settings->get('documents.PO'));
    }

    public function test_a_healthy_installation_reports_nothing(): void
    {
        $this->settings->set('documents.PO', 'PO-{Y}-{N4}');
        $this->settings->set('tax.ppn_rate', 12.0);

        $this->assertSame([], $this->settings->invalidOverrides());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Write an override straight to core_settings, bypassing set().
     *
     * This is how a row stored before the format rule existed got there, and it
     * is now the only way such a row can exist — which is exactly what makes it
     * the right fixture both for demonstrating the collision and for testing the
     * health check.
     */
    private function storeLegacyOverride(string $key, mixed $value): void
    {
        Setting::query()->create([
            'key' => $key,
            'value' => $value,
            'group' => 'documents',
        ]);

        $this->settings->flush();
    }

    /**
     * Run one setting through the REAL rule set the API and the settings screen
     * both use, and report whether it failed on that key.
     */
    private function validateSetting(string $key, string $value): ValidatorInstance
    {
        return Validator::make(
            ['settings' => [$key => $value]],
            $this->settings->validationRules(),
        );
    }

    private function assertRejected(string $key, string $value): void
    {
        $validator = $this->validateSetting($key, $value);

        $this->assertTrue($validator->fails(), "Format '{$value}' should have been refused for {$key}.");
        $this->assertArrayHasKey(
            "settings.{$key}",
            $validator->errors()->toArray(),
            "The refusal of '{$value}' must be attributed to {$key}.",
        );
    }

    private function assertAccepted(string $key, string $value, string $message = ''): void
    {
        $validator = $this->validateSetting($key, $value);

        $this->assertFalse(
            $validator->fails(),
            $message !== '' ? $message : "Format '{$value}' should have been accepted for {$key}.",
        );
    }
}
