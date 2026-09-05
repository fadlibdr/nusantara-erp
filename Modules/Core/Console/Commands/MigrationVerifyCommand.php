<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\MigrationDeclaredColumns;
use RuntimeException;
use Throwable;

/**
 * Bukti bahwa dua basis data berisi data yang sama (roadmap Fase 0, T0.5):
 * sesudah erp:sqlite-to-mysql saat cut-over, dan sesudah drill restore
 * (deploy/backup-erp1.sh --restore-drill --engine mysql) setiap bulan.
 *
 * Tiga ukuran per tabel, ketiganya harus sama di kedua sisi:
 *
 *  1. Jumlah baris.
 *  2. Per kolom desimal, SUM(ROUND(col, skala)) — dihitung sebagai bilangan
 *     bulat berskala (SUM(ROUND(col × 10^s))) supaya MySQL menjumlah DECIMAL
 *     secara eksak dan SQLite menjumlah INTEGER secara eksak; hasil keduanya
 *     lalu ditulis kembali dengan titik desimal di tempatnya. Tanpa itu, SUM
 *     float SQLite bisa berbunyi 1234.5600000001 untuk data yang identik.
 *  3. md5 atas lima kolom kunci (id lalu kolom-kolom berikutnya menurut urutan
 *     skema, melewati kolom generated, desimal/float, dan JSON): md5 per baris
 *     diurutkan lalu di-md5 lagi — bebas urutan, jadi collation MySQL yang
 *     tidak peka huruf besar tidak bisa menghasilkan urutan yang berbeda dari
 *     SQLite untuk data yang sama. Kolom DATE dan DATETIME dinormalkan
 *     ('2026-03-25 00:00:00' di SQLite ≡ '2026-03-25' di MySQL).
 *
 * Skala desimal dibaca dari sisi MySQL bila ada (decimal(18,2) tertera di
 * skemanya); bila kedua sisi SQLite, dari deklarasi migrasi
 * (MigrationDeclaredColumns) — SQLite hanya tahu "numeric".
 *
 * KEJUJURAN. Angka yang tidak bisa dihitung ditulis `?`, bukan 0, dan
 * dihitung sebagai selisih. Tabel yang ada di satu sisi saja adalah
 * selisih. Kode keluar 1 pada selisih apa pun. Laporan Markdown ditulis ke
 * storage/app/migration-report-<ts>.md (atau --report=…) untuk
 * docs/bukti-uji/.
 */
class MigrationVerifyCommand extends Command
{
    protected $signature = 'erp:migration-verify
        {--from=sqlite_legacy : Koneksi sumber}
        {--to= : Koneksi tujuan (bawaan: koneksi default)}
        {--ignore= : Tabel yang dilewati, dipisah koma (mis. sessions,cache,jobs)}
        {--report= : Path laporan Markdown (bawaan: storage/app/migration-report-<ts>.md)}
        {--key-columns=5 : Jumlah kolom kunci yang di-hash per tabel}';

    protected $description = 'Bandingkan dua basis data: jumlah baris, SUM desimal, hash kolom kunci per tabel; selisih → exit 1';

    private const NULL_MARK = "\x00NULL\x00";

    /** @var array<string, array<string, array{precision:int, scale:int}>>|null */
    private ?array $declared = null;

    public function handle(): int
    {
        $fromName = (string) $this->option('from');
        $toName = (string) ($this->option('to') ?: config('database.default'));
        $keyLimit = max(1, (int) $this->option('key-columns'));
        $ignored = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('ignore')))));

        try {
            $from = $this->connection($fromName);
            $to = $this->connection($toName);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Sumber : %s %s (koneksi %s)', $from->getDriverName(), $from->getDatabaseName(), $fromName));
        $this->line(sprintf('Tujuan : %s %s (koneksi %s)', $to->getDriverName(), $to->getDatabaseName(), $toName));

        $fromTables = $this->tables($from);
        $toTables = $this->tables($to);
        $all = array_values(array_unique(array_merge($fromTables, $toTables)));
        sort($all);

        $rows = [];
        $mismatches = 0;
        $unknown = 0;
        $sourceRows = 0;
        $targetRows = 0;
        $decimalColumns = 0;

        foreach ($all as $table) {
            if (in_array($table, $ignored, true)) {
                $rows[] = ['table' => $table, 'status' => 'dilewati'];

                continue;
            }

            $inFrom = in_array($table, $fromTables, true);
            $inTo = in_array($table, $toTables, true);

            if (! $inFrom || ! $inTo) {
                $mismatches++;
                $rows[] = [
                    'table' => $table,
                    'status' => 'selisih',
                    'count_from' => $inFrom ? $this->count($from, $table) : '—',
                    'count_to' => $inTo ? $this->count($to, $table) : '—',
                    'decimals' => [],
                    'keys' => [],
                    'hash_from' => '—',
                    'hash_to' => '—',
                    'problems' => [$inFrom ? 'tabel tidak ada di tujuan' : 'tabel tidak ada di sumber'],
                ];

                continue;
            }

            $row = $this->compareTable($from, $to, $table, $keyLimit);
            $rows[] = $row;

            $decimalColumns += count($row['decimals']);
            $sourceRows = $this->sum($sourceRows, $row['count_from']);
            $targetRows = $this->sum($targetRows, $row['count_to']);

            if ($row['status'] === 'selisih') {
                $mismatches++;
            } elseif ($row['status'] === '?') {
                $unknown++;
            }
        }

        $compared = count(array_filter($rows, fn (array $r): bool => $r['status'] !== 'dilewati'));
        $verdict = $mismatches === 0 && $unknown === 0 ? 'identik' : 'BERBEDA';

        $reportPath = $this->writeReport([
            'generated_at' => now()->toDateTimeString(),
            'from' => ['name' => $fromName, 'driver' => $from->getDriverName(), 'database' => $from->getDatabaseName()],
            'to' => ['name' => $toName, 'driver' => $to->getDriverName(), 'database' => $to->getDatabaseName()],
            'compared' => $compared,
            'ignored' => $ignored,
            'source_rows' => $sourceRows,
            'target_rows' => $targetRows,
            'decimal_columns' => $decimalColumns,
            'mismatches' => $mismatches,
            'unknown' => $unknown,
            'verdict' => $verdict,
            'rows' => $rows,
        ]);

        $this->newLine();

        foreach ($rows as $row) {
            if ($row['status'] === 'selisih' || $row['status'] === '?') {
                $this->line(sprintf(
                    '  %-48s baris %s / %s  %s',
                    $row['table'], $this->fmt($row['count_from']), $this->fmt($row['count_to']), implode('; ', $row['problems']),
                ));
            }
        }

        $this->line(sprintf(
            '%d tabel dibandingkan (%d dilewati), %s baris sumber / %s baris tujuan, %d kolom desimal, %d selisih, %d tidak diketahui — %s.',
            $compared, count($ignored), $this->fmt($sourceRows), $this->fmt($targetRows), $decimalColumns, $mismatches, $unknown, $verdict,
        ));
        $this->line("Laporan: {$reportPath}");

        return $verdict === 'identik' ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------
    // per table
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function compareTable(Connection $from, Connection $to, string $table, int $keyLimit): array
    {
        $problems = [];
        $unknown = false;

        $countFrom = $this->count($from, $table);
        $countTo = $this->count($to, $table);

        if ($countFrom === '?' || $countTo === '?') {
            $unknown = true;
        } elseif ($countFrom !== $countTo) {
            $problems[] = "jumlah baris {$countFrom} ≠ {$countTo}";
        }

        // Column metadata: the MySQL side knows scale and JSON-ness outright.
        $fromColumns = $this->columns($from, $table);
        $toColumns = $this->columns($to, $table);
        $typed = $to->getDriverName() === 'mysql' ? $toColumns : ($from->getDriverName() === 'mysql' ? $fromColumns : null);

        $decimals = [];

        foreach ($this->decimalColumns($table, $typed, $fromColumns, $toColumns) as $column => $scale) {
            $sumFrom = $this->decimalSum($from, $table, $column, $scale);
            $sumTo = $this->decimalSum($to, $table, $column, $scale);
            $decimals[$column] = ['scale' => $scale, 'from' => $sumFrom, 'to' => $sumTo];

            if ($sumFrom === '?' || $sumTo === '?') {
                $unknown = true;
            } elseif ($sumFrom !== $sumTo) {
                $problems[] = "SUM({$column}) {$sumFrom} ≠ {$sumTo}";
            }
        }

        // The migration ledger is compared by NAME only: a freshly migrated
        // target holds every migration in batch 1 with ids in file order,
        // production holds them in the batches and order they were run —
        // same set, different ids and batches, and only the set matters.
        $keys = $table === 'migrations' && isset($fromColumns['migration'], $toColumns['migration'])
            ? ['migration']
            : $this->keyColumns($typed ?? $toColumns, $fromColumns, $toColumns, $keyLimit);
        $hashFrom = $this->keyHash($from, $table, $keys, $typed ?? $toColumns);
        $hashTo = $this->keyHash($to, $table, $keys, $typed ?? $toColumns);

        if ($hashFrom === '?' || $hashTo === '?') {
            $unknown = true;
        } elseif ($hashFrom !== $hashTo) {
            $problems[] = 'hash kolom kunci ('.implode(',', $keys).') berbeda';
        }

        return [
            'table' => $table,
            'status' => $problems !== [] ? 'selisih' : ($unknown ? '?' : 'sama'),
            'count_from' => $countFrom,
            'count_to' => $countTo,
            'decimals' => $decimals,
            'keys' => $keys,
            'hash_from' => $hashFrom,
            'hash_to' => $hashTo,
            'problems' => $unknown && $problems === [] ? ['tidak bisa dihitung (?)'] : $problems,
        ];
    }

    private function count(Connection $connection, string $table): int|string
    {
        try {
            return (int) $connection->table($table)->count();
        } catch (Throwable) {
            return '?';
        }
    }

    /**
     * Decimal columns of a table with their scale: from the MySQL schema when
     * one side is MySQL, else from the migrations. Only columns present on
     * BOTH sides are compared.
     *
     * @param  array<string, array<string, mixed>>|null  $typed
     * @param  array<string, array<string, mixed>>  $fromColumns
     * @param  array<string, array<string, mixed>>  $toColumns
     * @return array<string, int> column → scale
     */
    private function decimalColumns(string $table, ?array $typed, array $fromColumns, array $toColumns): array
    {
        $result = [];

        if ($typed !== null) {
            foreach ($typed as $name => $meta) {
                if (strtolower((string) $meta['type_name']) === 'decimal'
                    && preg_match('/\((\d+),\s*(\d+)\)/', (string) $meta['type'], $m)) {
                    $result[$name] = (int) $m[2];
                }
            }
        } else {
            $this->declared ??= MigrationDeclaredColumns::scan()['decimal'];

            foreach ($this->declared[$table] ?? [] as $name => $spec) {
                $result[$name] = $spec['scale'];
            }
        }

        return array_filter($result, fn (int $scale, string $name): bool => isset($fromColumns[$name], $toColumns[$name]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * SUM(ROUND(col × 10^scale)) as an exact integer string, then re-scaled.
     * Empty table or all-NULL column → 'kosong' on both sides.
     */
    private function decimalSum(Connection $connection, string $table, string $column, int $scale): string
    {
        $wrapped = $connection->getQueryGrammar()->wrap($column);
        $factor = '1'.str_repeat('0', $scale);

        try {
            $expr = $connection->getDriverName() === 'sqlite'
                ? "SUM(CAST(ROUND({$wrapped} * {$factor}, 0) AS INTEGER))"
                : "CAST(SUM(ROUND({$wrapped} * {$factor}, 0)) AS CHAR)";

            $raw = $connection->table($table)->selectRaw("{$expr} as total")->value('total');
        } catch (Throwable) {
            return '?';
        }

        if ($raw === null) {
            return 'kosong';
        }

        return $this->rescale((string) $raw, $scale);
    }

    /**
     * '-123456' at scale 2 → '-1234.56'. Pure string arithmetic: nothing here
     * passes through a float.
     */
    private function rescale(string $integer, int $scale): string
    {
        // MySQL may hand back '123456.0' for a DECIMAL sum; SQLite may hand
        // back '1.2e+15' for an integer that overflowed into float — refuse
        // the latter rather than guess.
        if (preg_match('/^(-?)(\d+)(?:\.0+)?$/', $integer, $m) !== 1) {
            return '?';
        }

        [, $sign, $digits] = $m;
        $negative = $sign === '-' && ltrim($digits, '0') !== '';

        if ($scale === 0) {
            $whole = ltrim($digits, '0') ?: '0';

            return $negative ? "-{$whole}" : $whole;
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -$scale), '0') ?: '0';
        $fraction = substr($digits, -$scale);
        $text = "{$whole}.{$fraction}";

        return $negative ? "-{$text}" : $text;
    }

    /**
     * The key columns: id first, then schema order — never generated, never
     * decimal/float (summed instead), never JSON (formatting differs by
     * engine) — limited to $limit, and only columns both sides have.
     *
     * @param  array<string, array<string, mixed>>  $typed
     * @param  array<string, array<string, mixed>>  $fromColumns
     * @param  array<string, array<string, mixed>>  $toColumns
     * @return list<string>
     */
    private function keyColumns(array $typed, array $fromColumns, array $toColumns, int $limit): array
    {
        $candidates = [];

        foreach ($typed as $name => $meta) {
            if (! isset($fromColumns[$name], $toColumns[$name])) {
                continue;
            }

            if (($meta['generation'] ?? null) !== null) {
                continue;
            }

            $type = strtolower((string) $meta['type_name']);

            if (in_array($type, ['decimal', 'numeric', 'float', 'double', 'real', 'json', 'jsonb'], true)) {
                continue;
            }

            $candidates[] = $name;
        }

        usort($candidates, fn (string $a, string $b): int => ($a === 'id' ? 0 : 1) <=> ($b === 'id' ? 0 : 1));

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Order-independent md5 over the key columns of every row.
     *
     * @param  list<string>  $keys
     * @param  array<string, array<string, mixed>>  $typed
     */
    private function keyHash(Connection $connection, string $table, array $keys, array $typed): string
    {
        if ($keys === []) {
            return 'tanpa-kolom';
        }

        $types = [];

        foreach ($keys as $key) {
            $types[$key] = strtolower((string) ($typed[$key]['type_name'] ?? ''));
        }

        try {
            $query = $connection->table($table)->select($keys);
            $rows = in_array('id', $keys, true) ? $query->orderBy('id')->lazyById(1000, 'id') : $query->cursor();

            $hashes = [];

            foreach ($rows as $row) {
                $parts = [];

                foreach ($keys as $key) {
                    $parts[] = $this->normalise($row->{$key}, $types[$key]);
                }

                $hashes[] = md5(implode("\x1f", $parts));
            }
        } catch (Throwable) {
            return '?';
        }

        sort($hashes, SORT_STRING);

        return md5(implode('', $hashes));
    }

    private function normalise(mixed $value, string $type): string
    {
        if ($value === null) {
            return self::NULL_MARK;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;

        if ($type === 'date') {
            return preg_replace('/^(\d{4}-\d{2}-\d{2})[ T]00:00:00(?:\.0+)?Z?$/', '$1', $text) ?? $text;
        }

        if ($type === 'datetime' || $type === 'timestamp') {
            return preg_replace('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d+)?Z?$/', '$1 $2', $text) ?? $text;
        }

        return $text;
    }

    // ------------------------------------------------------------------
    // schema helpers
    // ------------------------------------------------------------------

    private function connection(string $name): Connection
    {
        if (config("database.connections.{$name}") === null) {
            throw new RuntimeException("Koneksi '{$name}' tidak ada di config/database.php.");
        }

        $connection = DB::connection($name);
        // Fail here, with the connection's name, rather than at the first table.
        $connection->getPdo();

        return $connection;
    }

    /**
     * The current schema only (on MySQL Schema::getTables() without a schema
     * lists every database the account can see).
     *
     * @return list<string>
     */
    private function tables(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $tables = $schema->getTableListing($schema->getCurrentSchemaName(), false);
        sort($tables);

        return $tables;
    }

    /**
     * @return array<string, array<string, mixed>> column name → meta
     */
    private function columns(Connection $connection, string $table): array
    {
        try {
            return collect($connection->getSchemaBuilder()->getColumns($table))->keyBy('name')->all();
        } catch (Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // report
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report): string
    {
        $path = (string) ($this->option('report') ?: storage_path('app/migration-report-'.now()->format('Ymd-His').'.md'));

        $md = [];
        $md[] = '# Laporan verifikasi migrasi — '.$report['generated_at'];
        $md[] = '';
        $md[] = sprintf('- Sumber: `%s` (%s `%s`)', $report['from']['name'], $report['from']['driver'], $report['from']['database']);
        $md[] = sprintf('- Tujuan: `%s` (%s `%s`)', $report['to']['name'], $report['to']['driver'], $report['to']['database']);
        $md[] = sprintf(
            '- **%d tabel dibandingkan**, %s baris sumber / %s baris tujuan, %d kolom desimal dijumlahkan, **%d selisih**, %d tidak diketahui → **%s**',
            $report['compared'], $this->fmt($report['source_rows']), $this->fmt($report['target_rows']),
            $report['decimal_columns'], $report['mismatches'], $report['unknown'], $report['verdict'],
        );

        if ($report['ignored'] !== []) {
            $md[] = '- Dilewati (`--ignore`): '.implode(', ', array_map(fn (string $t): string => "`{$t}`", $report['ignored']));
        }

        $md[] = '';
        $md[] = 'Ukuran per tabel: jumlah baris; SUM(ROUND(kolom, skala)) per kolom desimal (dihitung eksak sebagai bilangan bulat berskala); md5 bebas-urutan atas kolom kunci (id lalu urutan skema; tanpa kolom generated, desimal, JSON). Angka yang tidak bisa dihitung ditulis `?`.';
        $md[] = '';
        $md[] = '## Selisih';
        $md[] = '';

        $problemRows = array_filter($report['rows'], fn (array $r): bool => in_array($r['status'], ['selisih', '?'], true));

        if ($problemRows === []) {
            $md[] = 'Tidak ada. Setiap tabel yang dibandingkan identik pada ketiga ukuran.';
        } else {
            foreach ($problemRows as $row) {
                $md[] = sprintf('- `%s`: %s', $row['table'], implode('; ', $row['problems']));
            }
        }

        $md[] = '';
        $md[] = '## Per tabel';
        $md[] = '';
        $md[] = '| Tabel | Baris sumber | Baris tujuan | Kolom kunci | Hash sumber | Hash tujuan | Desimal (SUM sumber = tujuan) | Status |';
        $md[] = '|---|---:|---:|---|---|---|---|---|';

        foreach ($report['rows'] as $row) {
            if ($row['status'] === 'dilewati') {
                $md[] = sprintf('| `%s` | | | | | | | dilewati |', $row['table']);

                continue;
            }

            $decimals = [];

            foreach ($row['decimals'] as $column => $sum) {
                $decimals[] = $sum['from'] === $sum['to']
                    ? sprintf('%s=%s', $column, $sum['from'])
                    : sprintf('**%s=%s≠%s**', $column, $sum['from'], $sum['to']);
            }

            $md[] = sprintf(
                '| `%s` | %s | %s | %s | `%s` | `%s` | %s | %s |',
                $row['table'],
                $this->fmt($row['count_from']),
                $this->fmt($row['count_to']),
                implode(', ', $row['keys']),
                $this->short($row['hash_from']),
                $this->short($row['hash_to']),
                $decimals === [] ? '—' : implode(', ', $decimals),
                $row['status'] === 'sama' ? 'sama' : '**'.$row['status'].'**',
            );
        }

        $md[] = '';

        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, implode(PHP_EOL, $md));

        return $path;
    }

    private function short(string $hash): string
    {
        return strlen($hash) === 32 ? substr($hash, 0, 12) : $hash;
    }

    private function sum(int|string $a, int|string $b): int|string
    {
        return ($a === '?' || $b === '?') ? '?' : (int) $a + (int) $b;
    }

    private function fmt(int|string $value): string
    {
        return (string) $value;
    }
}
