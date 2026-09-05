<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;
use Tests\Support\LegacySqliteFixture;

/**
 * erp:sqlite-to-mysql — the one-shot data move at the cut-over (roadmap Fase
 * 0, T0.5). The refusals are driver-independent and run on both suites; the
 * move itself needs a MySQL target and runs on phpunit.mysql.xml, where the
 * suite's own database (erp_test, freshly migrated and empty) is the target:
 * the demo seed in a SQLite file goes in, and erp:migration-verify must then
 * find 0 differences between the two.
 *
 * Inside RefreshDatabase's transaction the copy is a savepoint, so every test
 * leaves erp_test empty again — provided the tool sends no DDL, which it does
 * not on the normal path (InnoDB already advanced every AUTO_INCREMENT past
 * the copied ids; ALTER TABLE is only sent when a read-back says otherwise).
 */
class SqliteToMysqlCommandTest extends ErpTestCase
{
    private const OTHER_SQLITE = 'sqlite_other';

    private string $source;

    private string $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = LegacySqliteFixture::copy('move-src');
        $this->other = LegacySqliteFixture::copy('move-other');
        LegacySqliteFixture::use($this->source);
        LegacySqliteFixture::connection(self::OTHER_SQLITE, $this->other);
    }

    protected function tearDown(): void
    {
        LegacySqliteFixture::forget(LegacySqliteFixture::CONNECTION, $this->source);
        LegacySqliteFixture::forget(self::OTHER_SQLITE, $this->other);

        parent::tearDown();
    }

    private function move(array $options = []): array
    {
        $exit = Artisan::call('erp:sqlite-to-mysql', $options + ['--from' => $this->source, '--to' => 'mysql']);

        return ['exit' => $exit, 'output' => Artisan::output()];
    }

    private function requireMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Butuh tujuan MySQL (phpunit.mysql.xml); driver aktif: '.DB::getDriverName().'.');
        }
    }

    public function test_refuses_a_target_that_is_not_mysql(): void
    {
        $result = $this->move(['--to' => self::OTHER_SQLITE]);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Tujuan harus koneksi mysql', $result['output']);
    }

    public function test_refuses_a_source_file_that_does_not_exist(): void
    {
        $result = $this->move(['--from' => storage_path('framework/testing/tidak-ada.sqlite')]);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Berkas SQLite sumber tidak ada', $result['output']);
    }

    public function test_copies_the_demo_seed_into_an_empty_mysql_database_and_verifies_identical(): void
    {
        $this->requireMysql();

        $legacy = DB::connection(LegacySqliteFixture::CONNECTION);
        $expectedUsers = (int) $legacy->table('users')->count();
        $this->assertGreaterThan(0, $expectedUsers);
        $this->assertSame(0, (int) DB::table('users')->count(), 'the suite database must start empty');

        $result = $this->move();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('Perubahan nilai: 0', $result['output']);
        $this->assertStringContainsString('0 tabel disetel ulang lewat ALTER TABLE', $result['output']);
        $this->assertSame($expectedUsers, (int) DB::table('users')->count());

        // The generated column was never sent and the server computed it.
        $reports = (int) DB::table('prj_daily_reports')->count();
        $this->assertSame((int) $legacy->table('prj_daily_reports')->count(), $reports);
        $this->assertSame(
            (int) DB::table('prj_daily_reports')->whereNull('deleted_at')->count(),
            (int) DB::table('prj_daily_reports')->where('live_key', 1)->count(),
        );

        // DATE columns carry the date only; SQLite carried a midnight time.
        $date = DB::table('prj_daily_reports')->orderBy('id')->value('report_date');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $date);

        // Ids preserved, and the next insert lands past them — the counter
        // was advanced, not left at 1.
        $maxId = (int) DB::table('users')->max('id');
        $this->assertSame((int) $legacy->table('users')->max('id'), $maxId);
        $nextId = DB::table('users')->insertGetId([
            'name' => 'Sesudah pindah', 'email' => 'sesudah@pindah.test', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame($maxId + 1, $nextId);
        DB::table('users')->where('id', $nextId)->delete();

        $report = storage_path('framework/testing/legacy-sqlite/move-report-'.getmypid().'.md');
        $exit = Artisan::call('erp:migration-verify', [
            '--from' => LegacySqliteFixture::CONNECTION, '--to' => 'mysql', '--report' => $report,
        ]);
        $verify = Artisan::output();
        @unlink($report);

        $this->assertSame(0, $exit, $verify);
        $this->assertStringContainsString('0 selisih, 0 tidak diketahui — identik', $verify);
        $this->assertMatchesRegularExpression('/(\d+) baris sumber \/ \1 baris tujuan, (\d+) kolom desimal/', $verify);
    }

    public function test_refuses_a_target_that_already_holds_rows(): void
    {
        $this->requireMysql();

        DB::table('core_number_sequences')->insert([
            'type' => 'PROBE', 'year' => 2026, 'scope' => '', 'last_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->move();

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('TIDAK KOSONG', $result['output']);
        $this->assertStringContainsString('core_number_sequences (1 baris)', $result['output']);
        $this->assertSame(0, (int) DB::table('users')->count(), 'nothing was copied');
    }

    public function test_refuses_when_the_source_has_not_run_every_migration_the_target_has(): void
    {
        $this->requireMysql();

        DB::connection(LegacySqliteFixture::CONNECTION)->table('migrations')
            ->where('migration', 'like', '%000746_add_live_key_unique_for_mysql')->delete();

        $result = $this->move();

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Ledger migrasi sumber dan tujuan BERBEDA', $result['output']);
        $this->assertStringContainsString('2026_09_05_000746_add_live_key_unique_for_mysql', $result['output']);
        $this->assertStringContainsString('migrate --database=sqlite_legacy --force', $result['output']);
        $this->assertSame(0, (int) DB::table('users')->count());
    }

    public function test_an_off_scale_decimal_is_rounded_and_the_delta_recorded(): void
    {
        $this->requireMysql();

        // core_rate_history.new_rate is decimal(8, 4); SQLite takes five
        // decimals without a word (see MysqlPreflightCommandTest).
        $id = DB::connection(LegacySqliteFixture::CONNECTION)->table('core_rate_history')->insertGetId([
            'rate_key' => 'tax.ppn_rate', 'old_rate' => 0.11, 'new_rate' => 0.12345,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->move();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('Perubahan nilai: 1', $result['output']);
        $this->assertStringContainsString("core_rate_history.new_rate id={$id}  0.12345 → 0.1235  (dibulatkan ke skala 4)", $result['output']);
        $this->assertSame('0.1235', (string) DB::table('core_rate_history')->where('id', $id)->value('new_rate'));
        $this->assertSame('0.1100', (string) DB::table('core_rate_history')->where('id', $id)->value('old_rate'));
    }

    public function test_a_broken_json_row_stops_the_move_and_leaves_the_target_empty(): void
    {
        $this->requireMysql();

        $id = DB::connection(LegacySqliteFixture::CONNECTION)->table('core_settings')->insertGetId([
            'key' => 'preflight.broken', 'value' => '{not json', 'group' => 'general',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->move();

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString("JSON rusak di core_settings.value id={$id}", $result['output']);
        $this->assertStringContainsString('digulung balik', $result['output']);
        $this->assertSame(0, (int) DB::table('users')->count(), 'the transaction must roll every table back');
        $this->assertSame(0, (int) DB::table('core_settings')->count());
    }
}
