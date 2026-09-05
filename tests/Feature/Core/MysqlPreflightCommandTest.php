<?php

namespace Tests\Feature\Core;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

/**
 * erp:mysql-preflight — the audit that runs on the SQLite side BEFORE the
 * cut-over, so a third decimal or a broken JSON row is a decision, not an
 * outage (roadmap Fase 0, T0.1).
 *
 * The fixtures are planted with DB::table(), not through a model: a model cast
 * would round the value on the way in and the test would prove nothing. On
 * SQLite the plant succeeds — the affinity system takes 0.12345 into a
 * numeric column and "{not json" into a text column without a word — and the
 * command must report both. On MySQL the same INSERTs are refused by the
 * server (strict mode, JSON type), which is precisely why the audit exists;
 * there the test asserts the refusal and a clean run.
 */
class MysqlPreflightCommandTest extends ErpTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function runJson(): array
    {
        $exit = Artisan::call('erp:mysql-preflight', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report, 'the --json output must be one JSON document');
        $report['exit'] = $exit;

        return $report;
    }

    public function test_a_clean_database_is_ok_and_exits_zero(): void
    {
        $report = $this->runJson();

        $this->assertSame('ok', $report['verdict']);
        $this->assertSame(0, $report['exit']);
        $this->assertSame(0, $report['decimals']['off_scale_rows']);
        $this->assertSame(0, $report['decimals']['overflow_rows']);
        $this->assertSame(0, $report['json']['invalid_rows']);

        // The declarations were actually found — an audit of zero columns
        // would also report zero findings.
        $this->assertGreaterThan(200, $report['decimals']['columns']);
        $this->assertGreaterThan(10, $report['json']['columns']);
    }

    public function test_a_third_decimal_an_overflow_and_an_invalid_json_row_are_reported(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->mysqlRefusesWhatSqliteAccepts();

            return;
        }

        // core_rate_history.new_rate is decimal(8, 4): five decimals is off
        // scale, and 12345 does not fit the four integer digits MySQL allows.
        $offScale = DB::table('core_rate_history')->insertGetId([
            'rate_key' => 'tax.ppn_rate', 'old_rate' => 0.11, 'new_rate' => 0.12345,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $overflow = DB::table('core_rate_history')->insertGetId([
            'rate_key' => 'tax.ppn_rate', 'old_rate' => 12345, 'new_rate' => 0.12,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $broken = DB::table('core_settings')->insertGetId([
            'key' => 'preflight.broken', 'value' => '{not json', 'group' => 'general',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->runJson();

        $this->assertSame('attention', $report['verdict']);
        $this->assertSame(1, $report['exit']);

        $this->assertSame(1, $report['decimals']['off_scale_rows']);
        $this->assertSame(1, $report['decimals']['overflow_rows']);
        $this->assertSame(1, $report['json']['invalid_rows']);

        $decimals = collect($report['decimals']['details'])->keyBy(fn (array $d): string => $d['table'].'.'.$d['column']);

        $newRate = $decimals->get('core_rate_history.new_rate');
        $this->assertNotNull($newRate);
        $this->assertSame(8, $newRate['precision']);
        $this->assertSame(4, $newRate['scale']);
        $this->assertSame(1, $newRate['off_scale']);
        $this->assertSame([$offScale], $newRate['ids']);
        $this->assertSame('0.00005', $newRate['max_delta']);

        $oldRate = $decimals->get('core_rate_history.old_rate');
        $this->assertNotNull($oldRate);
        $this->assertSame(1, $oldRate['overflow']);
        $this->assertSame([$overflow], $oldRate['overflow_ids']);

        $json = collect($report['json']['details'])->keyBy(fn (array $d): string => $d['table'].'.'.$d['column']);
        $this->assertSame(1, $json['core_settings.value']['invalid']);
        $this->assertSame([$broken], $json['core_settings.value']['ids']);
    }

    private function mysqlRefusesWhatSqliteAccepts(): void
    {
        $this->expectNotToPerformAssertions();

        try {
            DB::table('core_rate_history')->insert([
                'rate_key' => 'tax.ppn_rate', 'old_rate' => 12345, 'new_rate' => 0.12,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('MySQL accepted 12345 into decimal(8, 4)');
        } catch (QueryException) {
            // out of range — the row never lands.
        }

        try {
            DB::table('core_settings')->insert([
                'key' => 'preflight.broken', 'value' => '{not json', 'group' => 'general',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('MySQL accepted "{not json" into a json column');
        } catch (QueryException) {
            // invalid JSON text — the row never lands.
        }
    }

    public function test_the_static_scan_lists_the_two_partial_index_migrations_and_not_itself(): void
    {
        $report = $this->runJson();

        $sites = collect($report['sqlite_only_sql']['details']);
        $files = $sites->pluck('file')->unique()->values();

        $this->assertContains(
            'Modules/Projects/Database/Migrations/2026_08_28_000721_scope_daily_report_unique_index_to_live_rows.php',
            $files,
        );
        $this->assertContains(
            'Modules/Projects/Database/Migrations/2026_08_30_000742_create_prj_hse_daily_tables.php',
            $files,
        );

        $this->assertTrue(
            $sites->where('file', 'Modules/Projects/Database/Migrations/2026_08_30_000742_create_prj_hse_daily_tables.php')
                ->contains('pattern', '"-quoted identifier'),
        );

        // The scanner's own pattern table would otherwise be fourteen false hits.
        $this->assertNotContains('Modules/Core/Console/Commands/MysqlPreflightCommand.php', $files);

        $this->assertSame(
            $report['sqlite_only_sql']['sites'],
            $report['sqlite_only_sql']['guarded'] + $report['sqlite_only_sql']['unguarded'],
        );
    }
}
