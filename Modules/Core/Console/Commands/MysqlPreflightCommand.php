<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pre-flight audit before the SQLite → MySQL cut-over (roadmap Fase 0, T0.1).
 *
 * THREE QUESTIONS, EACH OF WHICH SQLITE NEVER ASKED. SQLite has type affinity,
 * not types: a column declared decimal(18, 2) is stored as NUMERIC and takes
 * 1.005 without a word; a column declared json is TEXT and takes "{not json"
 * the same way; a raw statement written for SQLite's dialect runs until the
 * day the driver changes. MySQL asks all three on the first INSERT — under
 * STRICT_TRANS_TABLES a DECIMAL rounds the third decimal (silently, which is
 * the worse outcome for a ledger), a JSON column refuses the row outright, and
 * a "-quoted identifier is a string literal. This command asks them on the
 * SQLite side, while the answer can still be a decision instead of an outage.
 *
 *   (a) decimal audit — for every column a migration declared decimal(p, s):
 *       rows whose value is not equal to ROUND(value, s), up to twenty ids,
 *       and the largest difference; plus rows whose magnitude does not fit
 *       p − s integer digits, which MySQL rejects rather than rounds.
 *   (b) JSON audit — for every column a migration declared json: rows whose
 *       stored text json_decode() refuses.
 *   (c) static scan — every string literal in app/, Modules/, database/ and
 *       routes/ carrying strftime(, julianday(, || concatenation, "-quoted
 *       identifiers in a DDL/DML statement, or a handful of other SQLite-only
 *       spellings (pragma, sqlite_master, datetime('now'), random(), CAST … AS
 *       TEXT, AUTOINCREMENT). A file that already branches on
 *       DB::getDriverName() === 'sqlite' is reported as guarded — the site is
 *       still listed, because a guard is a claim a reader should check.
 *
 * WHY PRECISION AND SCALE COME FROM THE MIGRATIONS. On SQLite Laravel's grammar
 * emits bare `numeric` for decimal columns — Schema::getColumns() reports type
 * "numeric", no (p, s) — so the schema cannot say what scale a value should
 * have. The migrations can: `->decimal('amount', 18, 2)` is the declaration
 * MySQL will enforce. They are scanned once (last declaration of a column
 * wins, so a change() restating the type is honoured), then cross-checked
 * against the live schema — a declared column that does not exist is skipped,
 * and on MySQL the live decimal(p,s) is preferred over the declaration.
 *
 * HONESTY. A count that could not be computed is printed as `?`, never 0, and
 * the exit code is 1 in that case too — an audit that could not look is not a
 * clean audit. Exit 1 also for any off-scale, overflowing or invalid row; the
 * static scan is a review list and does not fail the run on its own.
 *
 * `--json` prints one JSON document instead of the report, for
 * docs/bukti-uji/ and for the cut-over runbook to diff against.
 */
class MysqlPreflightCommand extends Command
{
    protected $signature = 'erp:mysql-preflight
        {--json : Print one JSON document instead of the report}
        {--ids=20 : How many offending ids to list per column}';

    protected $description = 'Audit decimals, JSON columns and SQLite-only SQL before the MySQL cut-over';

    /** Laravel's default when ->decimal() is called without (total, places). */
    private const DEFAULT_PRECISION = 8;

    private const DEFAULT_SCALE = 2;

    /** Directories whose PHP is scanned for SQLite-only SQL (relative to base_path). */
    private const SCAN_DIRS = ['app', 'Modules', 'database', 'routes'];

    /** Path segments that are never scanned. */
    private const SCAN_SKIP = ['vendor', 'node_modules', 'tests', 'Tests', 'storage', 'resources'];

    private bool $unknown = false;

    public function handle(): int
    {
        $idLimit = max(1, (int) $this->option('ids'));

        $declared = $this->declaredColumns();

        $decimals = $this->auditDecimals($declared['decimal'], $idLimit);
        $json = $this->auditJson($declared['json'], $idLimit);
        $sql = $this->scanSqliteOnlySql();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'database' => [
                'driver' => DB::getDriverName(),
                'name' => DB::connection()->getDatabaseName(),
                'tables' => count($this->tables()),
            ],
            'decimals' => $decimals,
            'json' => $json,
            'sqlite_only_sql' => $sql,
        ];

        $findings = $this->sum($decimals['off_scale_rows'], $decimals['overflow_rows'], $json['invalid_rows']);
        $report['verdict'] = $this->unknown ? '?' : ($findings === 0 ? 'ok' : 'attention');

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->render($report);
        }

        return ($findings === 0 && ! $this->unknown) ? self::SUCCESS : self::FAILURE;
    }

    // ------------------------------------------------------------------
    // (a) decimals
    // ------------------------------------------------------------------

    /**
     * @param  array<string, array<string, array{precision:int, scale:int}>>  $declared  table → column → (p, s)
     * @return array<string, mixed>
     */
    private function auditDecimals(array $declared, int $idLimit): array
    {
        $details = [];
        $columns = 0;
        $offRows = 0;
        $overflowRows = 0;
        $floatColumns = [];

        foreach ($this->tables() as $table) {
            $name = $table['name'];

            try {
                $live = collect(Schema::getColumns($name))->keyBy('name');
            } catch (Throwable $e) {
                $this->unknown = true;
                $details[] = ['table' => $name, 'column' => '*', 'off_scale' => '?', 'overflow' => '?', 'error' => $this->short($e)];

                continue;
            }

            $hasId = $live->has('id');

            foreach ($live as $column => $meta) {
                // A float/double column has no scale to audit; MySQL keeps it a
                // float. Listed so the operator can decide, never counted.
                if (preg_match('/^(float|double|real)/i', (string) $meta['type'])) {
                    $floatColumns[] = "{$name}.{$column}";
                }

                $spec = $this->decimalSpec($meta, $declared[$name][$column] ?? null);

                if ($spec === null) {
                    continue;
                }

                $columns++;
                [$precision, $scale] = $spec;
                $wrapped = DB::connection()->getQueryGrammar()->wrap($column);

                try {
                    $off = DB::table($name)->whereNotNull($column)
                        ->whereRaw("ROUND({$wrapped}, {$scale}) <> {$wrapped}");
                    $offCount = (clone $off)->count();

                    $maxDelta = $offCount > 0
                        ? (float) (clone $off)->max(DB::raw("ABS({$wrapped} - ROUND({$wrapped}, {$scale}))"))
                        : 0.0;

                    // The limit is spliced in as a literal, not bound: Laravel
                    // binds a PHP float as PDO::PARAM_STR, and SQLite orders
                    // every number below every text — ABS(x) >= '10000' is
                    // false for x = 12345. Both operands are integers here.
                    $limit = '1e'.($precision - $scale);
                    $over = DB::table($name)->whereNotNull($column)->whereRaw("ABS({$wrapped}) >= {$limit}");
                    $overCount = (clone $over)->count();

                    $offRows = $this->sum($offRows, $offCount);
                    $overflowRows = $this->sum($overflowRows, $overCount);

                    if ($offCount > 0 || $overCount > 0) {
                        $details[] = [
                            'table' => $name,
                            'column' => $column,
                            'precision' => $precision,
                            'scale' => $scale,
                            'off_scale' => $offCount,
                            'ids' => $hasId ? $off->orderBy('id')->limit($idLimit)->pluck('id')->all() : '?',
                            'max_delta' => $this->trim($maxDelta, $scale + 6),
                            'overflow' => $overCount,
                            'overflow_ids' => $overCount > 0
                                ? ($hasId ? $over->orderBy('id')->limit($idLimit)->pluck('id')->all() : '?')
                                : [],
                        ];
                    }
                } catch (Throwable $e) {
                    $this->unknown = true;
                    $offRows = '?';
                    $overflowRows = '?';
                    $details[] = [
                        'table' => $name, 'column' => $column, 'precision' => $precision, 'scale' => $scale,
                        'off_scale' => '?', 'overflow' => '?', 'error' => $this->short($e),
                    ];
                }
            }
        }

        return [
            'columns' => $columns,
            'off_scale_rows' => $offRows,
            'overflow_rows' => $overflowRows,
            'float_columns' => $floatColumns,
            'details' => $details,
        ];
    }

    /**
     * (precision, scale) for a column, or null when it is not a decimal.
     *
     * @param  array<string, mixed>  $meta  one Schema::getColumns() entry
     * @param  array{precision:int, scale:int}|null  $declared
     * @return array{0:int, 1:int}|null
     */
    private function decimalSpec(array $meta, ?array $declared): ?array
    {
        $type = strtolower((string) $meta['type']);

        // MySQL (and a SQLite table created by hand) says decimal(18,2) outright.
        if (preg_match('/^(?:decimal|numeric)\((\d+),\s*(\d+)\)/', $type, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        // SQLite: Laravel emits bare `numeric` — only the migration knows (p, s).
        if (in_array($type, ['numeric', 'decimal'], true)) {
            return $declared !== null ? [$declared['precision'], $declared['scale']] : null;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // (b) json
    // ------------------------------------------------------------------

    /**
     * @param  array<string, array<string, true>>  $declared  table → column → true
     * @return array<string, mixed>
     */
    private function auditJson(array $declared, int $idLimit): array
    {
        $details = [];
        $columns = 0;
        $invalidRows = 0;

        $tables = collect($this->tables())->pluck('name')->all();

        foreach ($tables as $name) {
            try {
                $live = collect(Schema::getColumns($name))->keyBy('name');
            } catch (Throwable $e) {
                $this->unknown = true;
                $details[] = ['table' => $name, 'column' => '*', 'invalid' => '?', 'error' => $this->short($e)];

                continue;
            }

            $hasId = $live->has('id');

            foreach ($live as $column => $meta) {
                $isJson = strtolower((string) $meta['type_name']) === 'json' || isset($declared[$name][$column]);

                if (! $isJson) {
                    continue;
                }

                $columns++;

                try {
                    $invalid = 0;
                    $ids = [];

                    $query = DB::table($name)->whereNotNull($column)
                        ->select($hasId ? ['id', $column] : [$column]);

                    $rows = $hasId ? $query->orderBy('id')->lazyById(500, 'id') : $query->cursor();

                    foreach ($rows as $row) {
                        $raw = $row->{$column};

                        if (! is_string($raw)) {
                            $raw = json_encode($raw);
                        }

                        json_decode($raw, true, 512);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $invalid++;

                            if ($hasId && count($ids) < $idLimit) {
                                $ids[] = $row->id;
                            }
                        }
                    }

                    $invalidRows = $this->sum($invalidRows, $invalid);

                    if ($invalid > 0) {
                        $details[] = ['table' => $name, 'column' => $column, 'invalid' => $invalid, 'ids' => $hasId ? $ids : '?'];
                    }
                } catch (Throwable $e) {
                    $this->unknown = true;
                    $invalidRows = '?';
                    $details[] = ['table' => $name, 'column' => $column, 'invalid' => '?', 'error' => $this->short($e)];
                }
            }
        }

        return ['columns' => $columns, 'invalid_rows' => $invalidRows, 'details' => $details];
    }

    // ------------------------------------------------------------------
    // migrations: what the schema was DECLARED to be
    // ------------------------------------------------------------------

    /**
     * Scan every migration for ->decimal('col', p, s) and ->json('col'), keyed
     * by the table of the enclosing Schema::create()/Schema::table() call.
     * Files are read in name order, so a later change() overrides.
     *
     * @return array{decimal: array<string, array<string, array{precision:int, scale:int}>>, json: array<string, array<string, true>>}
     */
    private function declaredColumns(): array
    {
        $decimal = [];
        $json = [];

        $files = array_merge(
            glob(base_path('database/migrations/*.php')) ?: [],
            glob(base_path('Modules/*/Database/Migrations/*.php')) ?: [],
        );
        sort($files);

        $pattern = '/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]'
            .'|->(decimal|unsignedDecimal|json|jsonb)\(\s*[\'"]([a-z0-9_]+)[\'"]\s*(?:,\s*(\d+)\s*(?:,\s*(\d+))?)?\s*\)/';

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $table = null;

            if (! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                if ($m[1] !== '') {
                    $table = $m[1];

                    continue;
                }

                if ($table === null) {
                    continue;
                }

                $column = $m[3];

                if (in_array($m[2], ['json', 'jsonb'], true)) {
                    $json[$table][$column] = true;

                    continue;
                }

                $decimal[$table][$column] = [
                    'precision' => isset($m[4]) && $m[4] !== '' ? (int) $m[4] : self::DEFAULT_PRECISION,
                    'scale' => isset($m[5]) && $m[5] !== '' ? (int) $m[5] : self::DEFAULT_SCALE,
                ];
            }
        }

        return ['decimal' => $decimal, 'json' => $json];
    }

    // ------------------------------------------------------------------
    // (c) static scan
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function scanSqliteOnlySql(): array
    {
        $sites = [];

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);
            $guarded = (bool) preg_match('/getDriverName\(\)\s*[!=]==?\s*[\'"]sqlite[\'"]/', $source);
            $relative = ltrim(str_replace(base_path(), '', $file), '/');

            foreach (token_get_all($source) as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                foreach ($this->sqliteOnlyPatterns($token[1]) as $label) {
                    $sites[] = [
                        'file' => $relative,
                        'line' => $token[2],
                        'pattern' => $label,
                        'guarded' => $guarded,
                        'excerpt' => mb_substr(trim($token[1], '\'"'), 0, 100),
                    ];
                }
            }
        }

        $unguarded = count(array_filter($sites, fn (array $s): bool => ! $s['guarded']));

        return [
            'sites' => count($sites),
            'unguarded' => $unguarded,
            'guarded' => count($sites) - $unguarded,
            'details' => $sites,
        ];
    }

    /**
     * Which SQLite-only spellings a string literal carries.
     *
     * @return list<string>
     */
    private function sqliteOnlyPatterns(string $literal): array
    {
        $found = [];

        if (preg_match('/\bstrftime\s*\(/i', $literal)) {
            $found[] = 'strftime(';
        }

        if (preg_match('/\bjulianday\s*\(/i', $literal)) {
            $found[] = 'julianday(';
        }

        if (str_contains($literal, '||')) {
            $found[] = '|| concat';
        }

        if (preg_match('/"[A-Za-z_][A-Za-z0-9_]*"/', $literal)
            && preg_match('/\b(?:CREATE|DROP|ALTER)\s+(?:UNIQUE\s+)?(?:INDEX|TABLE)\b|\bSELECT\b.*\bFROM\b|\bUPDATE\b.*\bSET\b|\bINSERT\s+INTO\b|\bDELETE\s+FROM\b/is', $literal)) {
            $found[] = '"-quoted identifier';
        }

        if (preg_match('/\bsqlite_master\b|\bpragma\s+\w/i', $literal)) {
            $found[] = 'pragma/sqlite_master';
        }

        if (preg_match('/\b(?:datetime|date)\s*\(\s*[\'"]now[\'"]/i', $literal)) {
            $found[] = "datetime('now')";
        }

        if (preg_match('/\brandom\s*\(\s*\)/i', $literal)) {
            $found[] = 'random()';
        }

        if (preg_match('/\bAS\s+TEXT\b/i', $literal)) {
            $found[] = 'CAST … AS TEXT';
        }

        if (preg_match('/\bAUTOINCREMENT\b/i', $literal)) {
            $found[] = 'AUTOINCREMENT';
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::SCAN_DIRS as $dir) {
            $root = base_path($dir);

            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $f): bool => ! in_array($f->getFilename(), self::SCAN_SKIP, true),
                ),
            );

            foreach ($iterator as $f) {
                /** @var \SplFileInfo $f */
                if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php') || str_ends_with($f->getFilename(), '.blade.php')) {
                    continue;
                }

                // The scanner's own pattern table is the one string set that
                // is not SQL.
                if ($f->getPathname() === __FILE__) {
                    continue;
                }

                $files[] = $f->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    // ------------------------------------------------------------------
    // output & helpers
    // ------------------------------------------------------------------

    /**
     * The tables of the CURRENT schema only. Schema::getTables() on MySQL lists
     * every schema the account can see — with grants on erp, erp_test and
     * erp_scratch that is three copies of the ERP, and the audit counted 380
     * tables for a 190-table database (measured 5 Sep 2026). SQLite's current
     * schema is `main`, so the same call is exact there too.
     *
     * @return list<array<string, mixed>>
     */
    private function tables(): array
    {
        return Schema::getTables(Schema::getCurrentSchemaName());
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $db = $report['database'];
        $this->line("Database: {$db['driver']} {$db['name']} ({$db['tables']} tables)");
        $this->newLine();

        $d = $report['decimals'];
        $this->line(sprintf(
            'Decimals: %d columns audited, %s off-scale rows, %s overflowing rows',
            $d['columns'], $this->fmt($d['off_scale_rows']), $this->fmt($d['overflow_rows']),
        ));

        foreach ($d['details'] as $row) {
            $this->line(sprintf(
                '  %-40s %s off-scale (max delta %s) ids %s; %s overflow%s',
                $row['table'].'.'.$row['column'].(isset($row['precision']) ? "({$row['precision']},{$row['scale']})" : ''),
                $this->fmt($row['off_scale']),
                $row['max_delta'] ?? '?',
                is_array($row['ids'] ?? null) ? implode(',', $row['ids']) : '?',
                $this->fmt($row['overflow']),
                isset($row['error']) ? ' — '.$row['error'] : '',
            ));
        }

        if ($d['float_columns'] !== []) {
            $this->line('  float/double columns (no scale to audit): '.implode(', ', $d['float_columns']));
        }

        $this->newLine();
        $j = $report['json'];
        $this->line(sprintf('JSON: %d columns audited, %s invalid rows', $j['columns'], $this->fmt($j['invalid_rows'])));

        foreach ($j['details'] as $row) {
            $this->line(sprintf(
                '  %-40s %s invalid ids %s%s',
                $row['table'].'.'.$row['column'],
                $this->fmt($row['invalid']),
                is_array($row['ids'] ?? null) ? implode(',', $row['ids']) : '?',
                isset($row['error']) ? ' — '.$row['error'] : '',
            ));
        }

        $this->newLine();
        $s = $report['sqlite_only_sql'];
        $this->line("SQLite-only SQL: {$s['sites']} sites ({$s['unguarded']} unguarded, {$s['guarded']} in files with a sqlite driver branch)");

        foreach ($s['details'] as $site) {
            $this->line(sprintf(
                '  %s:%d  %s%s  %s',
                $site['file'], $site['line'], $site['pattern'], $site['guarded'] ? ' [guarded]' : '', $site['excerpt'],
            ));
        }

        $this->newLine();
        $this->line('Verdict: '.$report['verdict']);
    }

    /**
     * Sum that stays `?` once any operand is `?`.
     */
    private function sum(int|string ...$values): int|string
    {
        $total = 0;

        foreach ($values as $value) {
            if ($value === '?') {
                return '?';
            }

            $total += (int) $value;
        }

        return $total;
    }

    private function fmt(int|string $value): string
    {
        return (string) $value;
    }

    private function trim(float $value, int $places): string
    {
        $s = number_format($value, $places, '.', '');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    private function short(Throwable $e): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160);
    }
}
