<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ErpTestCase;
use Tests\Support\LegacySqliteFixture;

/**
 * erp:migration-verify — the proof that two databases hold the same data
 * (roadmap Fase 0, T0.5), run after erp:sqlite-to-mysql at cut-over and after
 * every restore drill.
 *
 * Driver-independent on purpose: the command compares any two connections,
 * so here a seeded SQLite file is verified against a byte-for-byte copy of
 * itself (identical), and then against a copy with one amount changed, one
 * row deleted and one table dropped — each of which must be named. The
 * SQLite → MySQL leg is exercised by SqliteToMysqlCommandTest on the MySQL
 * suite and by the dry-run evidence in docs/bukti-uji/.
 */
class MigrationVerifyCommandTest extends ErpTestCase
{
    private const COPY = 'sqlite_verify_copy';

    private string $source;

    private string $copy;

    private string $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = LegacySqliteFixture::copy('verify-src');
        $this->copy = LegacySqliteFixture::copy('verify-copy');
        LegacySqliteFixture::use($this->source);
        LegacySqliteFixture::connection(self::COPY, $this->copy);
        $this->report = storage_path('framework/testing/legacy-sqlite/report-'.getmypid().'.md');
    }

    protected function tearDown(): void
    {
        LegacySqliteFixture::forget(LegacySqliteFixture::CONNECTION, $this->source);
        LegacySqliteFixture::forget(self::COPY, $this->copy);
        @unlink($this->report);

        parent::tearDown();
    }

    private function verify(array $options = []): array
    {
        $exit = Artisan::call('erp:migration-verify', $options + [
            '--from' => LegacySqliteFixture::CONNECTION,
            '--to' => self::COPY,
            '--report' => $this->report,
        ]);

        return ['exit' => $exit, 'output' => Artisan::output()];
    }

    public function test_two_identical_databases_verify_as_identical_and_exit_zero(): void
    {
        $result = $this->verify();

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('0 selisih, 0 tidak diketahui — identik', $result['output']);

        // The comparison actually looked at the schema, not at nothing.
        $this->assertMatchesRegularExpression('/^(\d+) tabel dibandingkan/m', $result['output']);
        preg_match('/^(\d+) tabel dibandingkan \(0 dilewati\), (\d+) baris sumber \/ (\d+) baris tujuan, (\d+) kolom desimal/m', $result['output'], $m);
        $this->assertGreaterThan(150, (int) $m[1], 'fewer tables than the schema has');
        $this->assertGreaterThan(500, (int) $m[2], 'the demo seed has more rows than this');
        $this->assertSame($m[2], $m[3]);
        $this->assertGreaterThan(200, (int) $m[4], 'decimal columns come from the migrations when neither side is MySQL');

        $this->assertFileExists($this->report);
        $markdown = (string) file_get_contents($this->report);
        $this->assertStringContainsString('**0 selisih**', $markdown);
        $this->assertStringContainsString('→ **identik**', $markdown);
        $this->assertStringContainsString('| `fin_journal_lines` |', $markdown);
        // The ledger is compared by name only — ids and batches legitimately differ.
        $this->assertMatchesRegularExpression('/\| `migrations` \| \d+ \| \d+ \| migration \|/', $markdown);
    }

    public function test_a_changed_amount_a_missing_row_and_a_missing_table_are_each_named(): void
    {
        $copy = DB::connection(self::COPY);

        // One cent on one journal line: the row count and the key hash stay
        // equal; only the decimal sum can see it.
        $line = $copy->table('fin_journal_lines')->orderBy('id')->first();
        $this->assertNotNull($line, 'the demo seed posts journals');
        $copy->table('fin_journal_lines')->where('id', $line->id)->update(['debit' => (float) $line->debit + 0.01]);

        $copy->table('users')->orderByDesc('id')->limit(1)->delete();

        Schema::connection(self::COPY)->drop('core_rate_history');

        $result = $this->verify();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('3 selisih', $result['output']);
        $this->assertStringContainsString('BERBEDA', $result['output']);
        $this->assertMatchesRegularExpression('/^\s+fin_journal_lines\s+baris \d+ \/ \d+\s+SUM\(debit\) [\d.]+ ≠ [\d.]+/m', $result['output']);
        $this->assertMatchesRegularExpression('/^\s+users\s+baris (\d+) \/ (\d+)\s+jumlah baris \1 ≠ \2/m', $result['output']);
        $this->assertMatchesRegularExpression('/^\s+core_rate_history\s+baris \d+ \/ —\s+tabel tidak ada di tujuan/m', $result['output']);

        // An untouched table is not in the problem list.
        $this->assertDoesNotMatchRegularExpression('/^\s+fin_accounts\s+baris/m', $result['output']);

        $markdown = (string) file_get_contents($this->report);
        $this->assertStringContainsString('**3 selisih**', $markdown);
        $this->assertStringContainsString('- `fin_journal_lines`: SUM(debit)', $markdown);
        $this->assertStringContainsString('- `users`: jumlah baris', $markdown);
        $this->assertStringContainsString('- `core_rate_history`: tabel tidak ada di tujuan', $markdown);
    }

    public function test_a_changed_key_column_is_caught_by_the_hash_when_counts_and_sums_agree(): void
    {
        $copy = DB::connection(self::COPY);
        $user = $copy->table('users')->orderBy('id')->first();
        $copy->table('users')->where('id', $user->id)->update(['name' => $user->name.' (diubah)']);

        $result = $this->verify();

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertMatchesRegularExpression('/^\s+users\s+baris (\d+) \/ \1\s+hash kolom kunci \(id,[a-z_,]+\) berbeda/m', $result['output']);
    }

    public function test_ignored_tables_are_skipped_and_say_so(): void
    {
        DB::connection(self::COPY)->table('users')->orderByDesc('id')->limit(1)->delete();

        $result = $this->verify(['--ignore' => 'users, sessions']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('(2 dilewati)', $result['output']);
        $this->assertStringContainsString('identik', $result['output']);

        $markdown = (string) file_get_contents($this->report);
        $this->assertStringContainsString('- Dilewati (`--ignore`): `users`, `sessions`', $markdown);
        $this->assertStringContainsString('| `users` | | | | | | | dilewati |', $markdown);
    }

    public function test_an_unknown_connection_fails_before_comparing_anything(): void
    {
        $result = $this->verify(['--to' => 'tidak_ada']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString("Koneksi 'tidak_ada' tidak ada", $result['output']);
        $this->assertFileDoesNotExist($this->report);
    }
}
