<?php

namespace Tests\Feature\Core;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ErpTestCase;

/**
 * Yang harus benar pada koneksi MySQL sebelum produksi pindah dari SQLite
 * (Fase 0 T0.3). Padanan SqlitePragmaTest untuk driver mysql: dilewati di
 * suite SQLite (phpunit.xml), dijalankan oleh phpunit.mysql.xml dan job CI
 * phpunit-mysql.
 *
 * Tiga hal dibaca balik dari server, bukan dari config:
 *  - sql_mode SESI memuat STRICT_TRANS_TABLES. config/database.php memasang
 *    'strict' => true dan Laravel mengirim SET SESSION sql_mode='ONLY_FULL_
 *    GROUP_BY,STRICT_TRANS_TABLES,...' saat konek; deploy/mysql/erp1.cnf hanya
 *    lantai global untuk klien lain (mysql CLI, mysqldump). Yang dibaca
 *
 *    @@SESSION, bukan @@GLOBAL: yang berlaku untuk aplikasi adalah sesi.
 *    Tanpa mode ketat, '2026-13-45' menjadi 0000-00-00 dan teks kepanjangan
 *    dipotong diam-diam — di SQLite keduanya juga lolos, dan preflight T0.1
 *    ada karena itu.
 *  - innodb_lock_wait_timeout ≤ 10 detik: lockForUpdate() sungguhan untuk
 *    pertama kalinya (141 situs / 53 berkas); penunggu harus gagal dalam 10
 *    detik, bukan memegang worker php-fpm selama 50 (bawaan).
 *  - Kolom generated live_key di prj_daily_reports dan prj_hse_daily
 *    (migrasi 000746, T0.2) — pengganti indeks unik parsial SQLite.
 *
 * Dan satu bukti perilaku: kunci baris memang menahan koneksi lain. Di SQLite
 * lockForUpdate() diam-diam tidak berbuat apa-apa, dan seluruh komentar
 * "lockForUpdate() is a no-op on SQLite" di kode layanan bertumpu pada
 * pemeriksaan ulang; di sini koneksi kedua yang mencoba FOR UPDATE pada baris
 * yang sedang dikunci harus menunggu lalu gagal 1205 — bukan membaca lewat.
 */
class MysqlModeTest extends ErpTestCase
{
    private const WAITER = 'mysql_lock_waiter';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Hanya berlaku pada driver mysql (phpunit.mysql.xml); driver aktif: '.DB::getDriverName().'.');
        }
    }

    protected function tearDown(): void
    {
        DB::purge(self::WAITER);

        parent::tearDown();
    }

    public function test_the_session_sql_mode_is_strict(): void
    {
        $mode = (string) DB::selectOne('select @@SESSION.sql_mode as mode')->mode;

        $this->assertStringContainsString('STRICT_TRANS_TABLES', $mode);
        // Laravel's own strict session mode (config 'strict' => true) — what
        // erp1.cnf does not set globally but every app connection carries.
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', $mode);
        $this->assertStringContainsString('NO_ZERO_DATE', $mode);
    }

    public function test_strict_mode_refuses_an_out_of_range_value_instead_of_clipping_it(): void
    {
        // decimal(18,2) on core_settings? Use the sequence counter: an
        // unsigned/int column fed a string is refused, never coerced to 0.
        $this->expectException(QueryException::class);

        DB::table('core_number_sequences')->insert([
            'type' => 'PROBE', 'year' => 'bukan-angka', 'scope' => '', 'last_number' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_lock_waiter_gives_up_within_ten_seconds(): void
    {
        $seconds = (int) DB::selectOne('select @@SESSION.innodb_lock_wait_timeout as t')->t;

        $this->assertLessThanOrEqual(10, $seconds, 'deploy/mysql/erp1.cnf memasang innodb_lock_wait_timeout = 10.');
    }

    public function test_the_live_key_generated_columns_back_the_unique_indexes(): void
    {
        foreach (['prj_daily_reports', 'prj_hse_daily'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'live_key'), "{$table}.live_key hilang.");

            $column = DB::selectOne(
                'select extra as extra, generation_expression as expr from information_schema.columns '
                    .'where table_schema = database() and table_name = ? and column_name = ?',
                [$table, 'live_key'],
            );
            $this->assertStringContainsStringIgnoringCase('generated', (string) $column->extra);
            $this->assertStringContainsStringIgnoringCase('deleted_at', (string) $column->expr);

            $unique = collect(Schema::getIndexes($table))
                ->first(fn (array $index): bool => $index['unique'] && in_array('live_key', $index['columns'], true));
            $this->assertNotNull($unique, "Tidak ada indeks unik yang memuat {$table}.live_key.");
            $this->assertSame(['project_id', 'report_date', 'live_key'], $unique['columns']);
        }
    }

    public function test_a_row_lock_really_blocks_a_second_connection(): void
    {
        DB::table('core_number_sequences')->insert([
            'type' => 'LOCKPROBE', 'year' => 2026, 'scope' => '', 'last_number' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (int) DB::table('core_number_sequences')->where('type', 'LOCKPROBE')->value('id');

        // Held by the test's own transaction until it rolls back.
        DB::table('core_number_sequences')->where('id', $id)->lockForUpdate()->first();

        config(['database.connections.'.self::WAITER => config('database.connections.mysql')]);
        $waiter = DB::connection(self::WAITER);
        $waiter->statement('set session innodb_lock_wait_timeout = 1');

        $started = microtime(true);

        try {
            $waiter->select('select id from core_number_sequences where id = ? for update', [$id]);
            $this->fail('Koneksi kedua membaca baris yang sedang dikunci — lockForUpdate() tidak menahan apa pun.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1], $e->getMessage()); // Lock wait timeout exceeded
        }

        $waited = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(0.9, $waited, 'Gagal seketika, bukan setelah menunggu — kuncinya tidak ada.');
        $this->assertLessThan(5.0, $waited);
    }
}
