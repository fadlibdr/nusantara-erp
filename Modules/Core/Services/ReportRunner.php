<?php

namespace Modules\Core\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Support\ReportableResources;

/**
 * Menjalankan satu Laporan Bebas: SATU kueri `DB::table` ber-whitelist
 * (Fase 1 / P1-F).
 *
 * ATURAN YANG MEMBUAT BERKAS INI AMAN, dan tidak satu pun bergantung pada
 * kehati-hatian pembacanya:
 *
 *  1. **Tidak ada string dari klien yang pernah menjadi teks SQL.** Setiap
 *     identifier di kueri berasal dari ReportableResources — nama tabel dari
 *     `table`, nama kolom dari `columns[…]['select']`, kolom saringan dari
 *     `filters[…]['column']`. Yang datang dari permintaan hanyalah KUNCI yang
 *     dipakai untuk mencari di dalam registri (dan ditolak 422 bila tidak ada)
 *     dan NILAI, yang selalu binding PDO. `guardIdentifier()` di bawah adalah
 *     sabuk kedua di atas bretel: sebuah entri registri yang salah tulis
 *     melempar di sini alih-alih menjadi SQL.
 *  2. **Agregat dari peta literal.** `sum|avg|min|max|count` adalah kunci peta,
 *     bukan teks yang disisipkan.
 *  3. **Satu kueri.** Dijanjikan di `meta.queries`, dan DIUKUR uji dengan
 *     DB::listen — sebuah klaim yang tidak diperiksa bukan klaim.
 *
 * PORTABILITAS, yang di sini berarti "angka yang sama di SQLite dan MySQL":
 *
 *  - **Ember tanggal memakai `substr`, bukan MONTH()/DATE_FORMAT (MySQL saja)
 *    dan bukan strftime() (SQLite saja, DAN dipindai terlarang oleh
 *    MysqlPreflightCommand).** `substr(col, 1, 7)` memberi '2026-03' di kedua
 *    driver. Ember HARIAN pun memakai `substr(col, 1, 10)` dengan sengaja:
 *    kolom `date` terbaca '2026-03-25' di MySQL tetapi bisa terbaca
 *    '2026-03-25 00:00:00' di SQLite, dan mengelompokkan nilai mentahnya
 *    memberi kunci kelompok yang BERBEDA per driver.
 *  - **ONLY_FULL_GROUP_BY menyala di setiap sesi MySQL dan tidak di SQLite.**
 *    Setiap ekspresi select yang bukan agregat karena itu masuk GROUP BY
 *    dengan string yang SAMA PERSIS, dan `compile()` diekspos supaya
 *    ReportRunnerTest bisa membuktikannya untuk setiap kombinasi tanpa MySQL.
 *  - **Pengurutan dilakukan di PHP**, bukan `orderByRaw`. Kelompok dibatasi 200
 *    baris, jadi mengurutkannya di PHP gratis — dan itu menghapus satu tempat
 *    lagi di mana sebuah ekspresi menjadi teks SQL.
 *
 * KEJUJURAN SEL — aturan yang menjadi syarat paket ini ("sel kosong, bukan 0"):
 * densifikasi di bawah memakai `array_key_exists`, tidak pernah `?? 0` dan
 * tidak pernah `empty()`. Ada TIGA keadaan sel dan ketiganya berbeda:
 *
 *    tidak ada baris sumber        → cells[i] = null, n[i] = 0   ("kosong")
 *    ada baris, agregatnya NULL    → cells[i] = null, n[i] > 0   ("—", mis. nilai
 *                                     buku alat sewa: alatnya ada, angkanya tidak)
 *    ada baris, agregatnya nol     → cells[i] = 0.0, n[i] > 0    ("0", pernyataan)
 *
 * Sebuah 0 adalah pernyataan ("saya menjumlahkan, dan hasilnya nol"). Menulisnya
 * di tempat dua keadaan pertama akan menaruh alat sewa di neraca.
 */
final class ReportRunner
{
    /** Plafon mode rincian. Angka ini DIUMUMKAN di meta, tidak disalin ke SPA. */
    public const MAX_ROWS = 5000;

    /** Plafon mode kelompok/pivot, dihitung sebagai pasangan (baris, kolom). */
    public const MAX_GROUPS = 200;

    /** Token tervalidasi → ekspresi yang DITULIS TANGAN. Token tidak pernah jadi teks SQL. */
    private const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count'];

    /** Panjang potongan per ember tanggal; kuncinya juga daftar ember yang sah. */
    private const BUCKET_LENGTH = ['day' => 10, 'month' => 7, 'year' => 4];

    /** Jumlah kueri yang dijalankan run() terakhir — diumumkan sebagai meta.queries. */
    private int $queries = 0;

    /**
     * @param  array{resource: string, mode: string, columns?: list<string>, row?: array{column: string, bucket?: string}, column?: array{column: string, bucket?: string}, measure?: array{agg: string, column?: string}, filters?: array<string, mixed>}  $d
     * @return array{sql: string, bindings: list<mixed>, builder: Builder, selects: list<array{expr: string, alias: string, aggregate: bool}>}
     */
    public function compile(array $d): array
    {
        $entry = ReportableResources::definition($d['resource']);
        $table = self::guardIdentifier($entry['table']);

        $query = DB::table($table);

        /* SoftDeletes DENGAN TANGAN: DB::table melewati scope-nya, dan tanpa
           baris ini laporan menghitung dokumen yang sudah dibuang lalu
           berselisih dengan layar daftar yang diklaimnya cermin. */
        if ($entry['soft_deletes']) {
            $query->whereNull($table.'.deleted_at');
        }

        $this->applyDateWindow($query, $table, $entry, $d['filters'] ?? []);
        $this->applyFilters($query, $table, $entry, $d['filters'] ?? []);

        return match ($d['mode']) {
            'detail' => $this->compileDetail($query, $table, $entry, $d),
            'group' => $this->compileGrouped($query, $table, $entry, $d, false),
            'pivot' => $this->compileGrouped($query, $table, $entry, $d, true),
            default => throw new LogicException('Mode laporan tidak dikenal.'),
        };
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public function run(array $d): array
    {
        $compiled = $this->compile($d);

        /* SATU kueri, dan angkanya di bawah adalah KLAIM — bukan pengukuran.
           Yang mengukurnya adalah ReportRunnerTest dengan DB::listen, karena
           memasang listener di sini akan menumpuk satu listener per pemanggilan
           pada koneksi yang hidup selama proses. Sebuah klaim yang diperiksa
           uji lebih baik daripada pengukuran yang membocorkan listener. */
        $rows = $compiled['builder']->get()->all();
        $this->queries = 1;

        return match ($d['mode']) {
            'detail' => $this->shapeDetail($rows, $d),
            'group' => $this->shapeGrouped($rows, $d, false),
            'pivot' => $this->shapeGrouped($rows, $d, true),
            default => throw new LogicException('Mode laporan tidak dikenal.'),
        };
    }

    public function queriesRun(): int
    {
        return $this->queries;
    }

    /* ------------------------------------------------------------- penyusun */

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $d
     * @return array{sql: string, bindings: list<mixed>, builder: Builder, selects: list<array{expr: string, alias: string, aggregate: bool}>}
     */
    private function compileDetail(Builder $query, string $table, array $entry, array $d): array
    {
        $selects = [];

        foreach ($d['columns'] as $key) {
            $column = $entry['columns'][$key];
            $selects[] = [
                'expr' => $table.'.'.self::guardIdentifier($column['select']),
                'alias' => self::aliasOf($key),
                'aggregate' => false,
            ];
        }

        foreach ($selects as $select) {
            $query->addSelect($select['expr'].' as '.$select['alias']);
        }

        // +1 supaya "lebih dari 5.000" diketahui tanpa kueri hitung kedua —
        // yang akan melanggar janji satu kueri untuk mengabarkan sebuah plafon.
        $query->orderBy($table.'.id')->limit(self::MAX_ROWS + 1);

        return $this->finish($query, $selects);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $d
     * @return array{sql: string, bindings: list<mixed>, builder: Builder, selects: list<array{expr: string, alias: string, aggregate: bool}>}
     */
    private function compileGrouped(Builder $query, string $table, array $entry, array $d, bool $pivot): array
    {
        $selects = [
            ['expr' => $this->dimensionExpr($table, $entry, $d['row']), 'alias' => 'row_key', 'aggregate' => false],
        ];

        if ($pivot) {
            $selects[] = ['expr' => $this->dimensionExpr($table, $entry, $d['column']), 'alias' => 'col_key', 'aggregate' => false];
        }

        $measure = $this->measureExpr($table, $entry, $d['measure']);
        $selects[] = ['expr' => $measure, 'alias' => 'measure_value', 'aggregate' => true];
        // Hitungan baris sumber per kelompok: inilah yang membedakan "tidak ada
        // baris" dari "ada baris, agregatnya NULL" saat densifikasi.
        $selects[] = ['expr' => 'count(*)', 'alias' => 'source_rows', 'aggregate' => true];

        foreach ($selects as $select) {
            $query->selectRaw($select['expr'].' as '.$select['alias']);
        }

        /* ONLY_FULL_GROUP_BY: setiap ekspresi select yang bukan agregat masuk
           GROUP BY dengan string yang SAMA PERSIS. Kolom polos lewat groupBy()
           (Laravel yang mengutipnya); hanya ember tanggal yang butuh raw, dan
           string itu dibangun dari identifier registri + konstanta. */
        foreach ($selects as $select) {
            if ($select['aggregate']) {
                continue;
            }

            str_contains($select['expr'], '(')
                ? $query->groupByRaw($select['expr'])
                : $query->groupBy($select['expr']);
        }

        $query->limit(self::MAX_GROUPS + 1);

        return $this->finish($query, $selects);
    }

    /**
     * @param  list<array{expr: string, alias: string, aggregate: bool}>  $selects
     * @return array{sql: string, bindings: list<mixed>, builder: Builder, selects: list<array{expr: string, alias: string, aggregate: bool}>}
     */
    private function finish(Builder $query, array $selects): array
    {
        return ['sql' => $query->toSql(), 'bindings' => $query->getBindings(), 'builder' => $query, 'selects' => $selects];
    }

    /**
     * Ekspresi satu dimensi. Kolom polos apa adanya; kolom tanggal DIPOTONG,
     * termasuk untuk ember harian — lihat catatan portabilitas di docblock.
     *
     * @param  array<string, mixed>  $entry
     * @param  array{column: string, bucket?: string}  $dimension
     */
    private function dimensionExpr(string $table, array $entry, array $dimension): string
    {
        $column = $entry['columns'][$dimension['column']];
        $qualified = $table.'.'.self::guardIdentifier($column['select']);

        if (($column['dimension'] ?? false) !== 'date') {
            return $qualified;
        }

        $bucket = $dimension['bucket'] ?? 'month';
        $length = self::BUCKET_LENGTH[$bucket] ?? null;

        if ($length === null) {
            throw new LogicException('Ember tanggal tidak dikenal.');
        }

        return sprintf('substr(%s, 1, %d)', $qualified, $length);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{agg: string, column?: string}  $measure
     */
    private function measureExpr(string $table, array $entry, array $measure): string
    {
        $agg = $measure['agg'];

        if (! in_array($agg, self::AGGREGATES, true)) {
            throw new LogicException('Agregat tidak dikenal.');
        }

        if ($agg === 'count' && ! isset($measure['column'])) {
            return 'count(*)';
        }

        $column = $entry['columns'][$measure['column']];

        return $agg.'('.$table.'.'.self::guardIdentifier($column['select']).')';
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $filters
     */
    private function applyDateWindow(Builder $query, string $table, array $entry, array $filters): void
    {
        if ($entry['date_column'] === null) {
            return;
        }

        $column = $table.'.'.self::guardIdentifier($entry['date_column']);

        // Operator dari peta literal, batas keduanya inklusif, whereDate —
        // persis ApiController::listing(), jadi jendela laporan dan jendela
        // layar daftar tidak bisa berselisih.
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && is_string($filters[$key]) && $filters[$key] !== '') {
                $query->whereDate($column, $operator, $filters[$key]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, string $table, array $entry, array $filters): void
    {
        foreach (($filters['eq'] ?? []) as $key => $value) {
            $filter = $entry['filters'][$key];
            $column = $table.'.'.self::guardIdentifier($filter['column']);

            $query->where($column, $filter['kind'] === 'key' ? (int) $value : (string) $value);
        }

        foreach (($filters['in'] ?? []) as $key => $values) {
            $filter = $entry['filters'][$key];
            $column = $table.'.'.self::guardIdentifier($filter['column']);
            $cast = $filter['kind'] === 'key'
                ? static fn ($one): int => (int) $one
                : static fn ($one): string => (string) $one;

            $query->whereIn($column, array_map($cast, $values));
        }
    }

    /* ------------------------------------------------------------- pembentuk */

    /**
     * @param  list<object>  $rows
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function shapeDetail(array $rows, array $d): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new LogicException(sprintf(
                'Laporan rincian ini melebihi %s baris. Persempit jendela tanggal, tambahkan saringan, '
                .'atau kelompokkan alih-alih merinci.',
                number_format(self::MAX_ROWS, 0, ',', '.'),
            ));
        }

        $aliases = array_map(self::aliasOf(...), $d['columns']);

        return [
            'mode' => 'detail',
            'columns' => $d['columns'],
            'rows' => array_map(static function (object $row) use ($d, $aliases): array {
                $out = [];
                foreach ($d['columns'] as $index => $key) {
                    $out[$key] = $row->{$aliases[$index]};
                }

                return $out;
            }, $rows),
            'count' => count($rows),
        ];
    }

    /**
     * @param  list<object>  $rows
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function shapeGrouped(array $rows, array $d, bool $pivot): array
    {
        if (count($rows) > self::MAX_GROUPS) {
            /* Yang dihitung pada pivot adalah PASANGAN (baris, kolom), bukan
               baris — 30 kategori × 12 bulan sudah 360. Kalimatnya menyebut
               yang benar-benar dihitung, karena "lebih dari 200 kelompok" pada
               laporan yang barisnya 30 terbaca sebagai galat aplikasi. */
            throw new LogicException(sprintf(
                'Laporan ini menghasilkan lebih dari %d %s, dan angka dari sebagian di antaranya bukan jawaban '
                .'yang benar. Persempit jendela tanggal, tambahkan saringan, atau kelompokkan menurut kolom yang '
                .'nilainya lebih sedikit.',
                self::MAX_GROUPS,
                $pivot ? 'kombinasi baris × kolom' : 'kelompok',
            ));
        }

        $entry = ReportableResources::definition($d['resource']);
        $scale = $this->scaleOf($entry, $d['measure']);

        /** @var array<string, array<string, array{v: ?float, n: int}>> $cells */
        $cells = [];
        $columnKeys = [];
        $rowKeys = [];

        foreach ($rows as $row) {
            $rowKey = self::keyOf($row->row_key);
            $colKey = $pivot ? self::keyOf($row->col_key) : '';

            if (! in_array($rowKey, $rowKeys, true)) {
                $rowKeys[] = $rowKey;
            }
            if ($pivot && ! in_array($colKey, $columnKeys, true)) {
                $columnKeys[] = $colKey;
            }

            $cells[self::mapKey($rowKey)][self::mapKey($colKey)] = [
                'v' => $row->measure_value === null ? null : $this->scale((float) $row->measure_value, $scale),
                'n' => (int) $row->source_rows,
            ];
        }

        // Diurutkan DI SINI, bukan di SQL: ≤ 200 kelompok, dan itu menghapus
        // satu tempat lagi di mana sebuah ekspresi menjadi teks SQL. null
        // (kelompok "tanpa nilai") selalu terakhir.
        self::sortKeys($rowKeys);
        if ($pivot) {
            self::sortKeys($columnKeys);
        } else {
            $columnKeys = [''];
        }

        $out = [];
        foreach ($rowKeys as $rowKey) {
            $values = [];
            $counts = [];
            foreach ($columnKeys as $colKey) {
                $cell = $cells[self::mapKey($rowKey)][self::mapKey($colKey)] ?? null;
                // array_key_exists lewat ?? null di atas: sel yang TIDAK ADA
                // baris sumbernya menjadi null dengan n = 0; sel yang ada tetapi
                // agregatnya NULL menjadi null dengan n > 0. Keduanya bukan 0.
                $values[] = $cell === null ? null : $cell['v'];
                $counts[] = $cell === null ? 0 : $cell['n'];
            }

            $out[] = [
                'key' => $rowKey,
                'cells' => $values,
                'counts' => $counts,
                'total' => $this->rowTotal($values, $d['measure']['agg'], $scale),
                'count' => array_sum($counts),
            ];
        }

        return [
            'mode' => $pivot ? 'pivot' : 'group',
            'column_keys' => $pivot ? $columnKeys : [],
            'rows' => $out,
            'groups' => count($rows),
        ];
    }

    /**
     * Total baris hanya berarti untuk sum dan count. Menjumlahkan rata-rata,
     * minimum atau maksimum adalah kebohongan aritmetika, jadi totalnya null
     * dan SPA menuliskan '—'.
     *
     * @param  list<?float>  $values
     */
    private function rowTotal(array $values, string $agg, int $scale): ?float
    {
        if (! in_array($agg, ['sum', 'count'], true)) {
            return null;
        }

        $present = array_filter($values, static fn (?float $one): bool => $one !== null);

        // Seluruh sel kosong → totalnya juga kosong, bukan nol.
        return $present === [] ? null : $this->scale(array_sum($present), $scale);
    }

    /**
     * Uang dibulatkan ke skala kolomnya. SQLite menyimpan DECIMAL sebagai REAL,
     * jadi SUM atas ratusan baris bisa mengembalikan …,999999999 — bukan angka
     * yang berbeda, tetapi angka yang tampak berbeda dari layar daftarnya.
     * count tidak pernah dibulatkan (skala 0).
     */
    private function scale(float $value, int $scale): float
    {
        return round($value, $scale);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{agg: string, column?: string}  $measure
     */
    private function scaleOf(array $entry, array $measure): int
    {
        if ($measure['agg'] === 'count' || ! isset($measure['column'])) {
            return 0;
        }

        return match ($entry['columns'][$measure['column']]['type']) {
            'currency' => 2,
            'percent' => 4,
            default => 3,
        };
    }

    /* ------------------------------------------------------------- perkakas */

    /** Kunci kelompok apa adanya; SQL NULL bertahan sebagai null (kelompok "tanpa nilai"). */
    private static function keyOf(mixed $value): string|int|float|null
    {
        return $value === null ? null : (is_int($value) || is_float($value) ? $value : (string) $value);
    }

    /** Kunci array PHP untuk sebuah kunci kelompok (null tidak bisa jadi kunci array). */
    private static function mapKey(string|int|float|null $key): string
    {
        return $key === null ? "\0null" : (string) $key;
    }

    /** @param list<string|int|float|null> $keys */
    private static function sortKeys(array &$keys): void
    {
        usort($keys, static function ($a, $b): int {
            if ($a === null) {
                return $b === null ? 0 : 1;
            }
            if ($b === null) {
                return -1;
            }

            return is_string($a) && is_string($b) ? strcmp($a, $b) : $a <=> $b;
        });
    }

    /** Alias select yang aman dan stabil untuk sebuah kunci kolom layar. */
    private static function aliasOf(string $key): string
    {
        return 'c_'.preg_replace('/[^a-z0-9]+/i', '_', $key);
    }

    /**
     * Sabuk kedua: sebuah identifier registri yang salah tulis melempar di sini
     * alih-alih menjadi SQL. Registri adalah satu-satunya sumbernya, jadi ini
     * tidak pernah menyala di jalur normal — dan itulah gunanya.
     */
    private static function guardIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new LogicException(sprintf('Identifier registri tidak sah: "%s".', $identifier));
        }

        return $identifier;
    }
}
