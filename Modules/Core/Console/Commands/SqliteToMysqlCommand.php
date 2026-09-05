<?php

namespace Modules\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Memindahkan seluruh isi berkas SQLite ke basis data MySQL yang baru
 * dimigrasi (roadmap Fase 0, T0.5). Ini alat pemindahan data, bukan
 * sinkronisasi: dijalankan SEKALI saat cut-over, sesudah `migrate:fresh`
 * pada tujuan dan sebelum `.env` berpindah driver.
 *
 * ATURAN YANG MEMBUATNYA AMAN DIJALANKAN PADA PRODUKSI.
 *
 *  - Tujuan harus mysql DAN kosong: setiap tabel kecuali `migrations` nol
 *    baris. Basis data yang sudah berisi ditolak — alat ini tidak pernah
 *    "menambah", jadi tidak pernah menggandakan.
 *  - Ledger migrasi kedua sisi harus sama persis. Sumber yang belum
 *    menjalankan migrasi terbaru (misalnya 000746 live_key) berarti bentuk
 *    tabelnya tertinggal satu langkah dari tujuan; deploy menjalankannya —
 *    alat ini menolak dan menyebut nama migrasinya, bukan menebak.
 *  - Satu transaksi untuk semua baris: gagal di tabel ke-140 berarti tujuan
 *    kembali kosong, bukan setengah terisi. FOREIGN_KEY_CHECKS dimatikan
 *    selama penyalinan (urutan tabel adalah urutan nama, bukan urutan
 *    ketergantungan) dan dinyalakan lagi sesudahnya, apa pun hasilnya.
 *  - Id dipertahankan apa adanya; sesudah commit, AUTO_INCREMENT setiap tabel
 *    dipastikan ≥ max(id) + 1 (InnoDB memajukannya sendiri pada INSERT ber-id
 *    eksplisit; ALTER hanya dikirim bila pembacaan information_schema
 *    mengatakan belum — sehingga jalur normal bebas DDL).
 *
 * APA YANG DIUBAH DALAM PERJALANAN, DAN SEMUANYA DICATAT.
 *
 *  - Kolom generated (live_key di prj_daily_reports / prj_hse_daily) tidak
 *    pernah dikirim: MySQL menolak nilai eksplisit untuk kolom generated,
 *    dan server menghitungnya sendiri dari deleted_at.
 *  - JSON dinormalkan json_encode(json_decode(…)) supaya teks yang masuk
 *    kanonis; teks yang tidak bisa didekode menghentikan pemindahan (MySQL
 *    juga akan menolaknya — lebih baik berhenti di sini dengan id barisnya).
 *  - Desimal dibulatkan ke skala kolom tujuan. Nilai yang SUDAH pada skala
 *    lewat tanpa disentuh dan tanpa lewat float; hanya nilai lepas-skala yang
 *    dibulatkan, dan setiap pembulatan dicatat (tabel, kolom, id, dari, ke)
 *    dalam ringkasan — preflight T0.1 mengukur nol pada salinan produksi,
 *    jadi daftar ini seharusnya kosong.
 *  - Kolom DATE menerima tanggalnya saja: SQLite menyimpan '2026-03-25
 *    00:00:00' untuk kolom date, MySQL menyimpan '2026-03-25'. Bagian jam yang
 *    bukan 00:00:00 berarti ada informasi yang hilang, dan itu dicatat
 *    seperti pembulatan desimal.
 *
 * Sumber dibaca lewat koneksi `sqlite_legacy` (config/database.php,
 * SQLITE_LEGACY_PATH), yang juga dipakai erp:migration-verify — verifikasi
 * sesudah pemindahan memakai jalan yang sama, dan bisa diulang berhari-hari
 * kemudian selama berkasnya masih ada.
 */
class SqliteToMysqlCommand extends Command
{
    protected $signature = 'erp:sqlite-to-mysql
        {--from= : Path berkas SQLite sumber (bawaan: SQLITE_LEGACY_PATH)}
        {--to= : Nama koneksi MySQL tujuan (bawaan: koneksi default)}
        {--chunk=1000 : Baris per INSERT}';

    protected $description = 'Salin seluruh isi berkas SQLite ke basis data MySQL kosong yang sudah dimigrasi (cut-over Fase 0)';

    private const LEGACY = 'sqlite_legacy';

    /** MySQL menolak prepared statement dengan > 65535 placeholder; sisakan ruang. */
    private const MAX_PLACEHOLDERS = 60000;

    /** @var list<array{table:string, column:string, id:mixed, from:string, to:string, why:string}> */
    private array $deltas = [];

    private int $jsonNormalised = 0;

    private int $dateNormalised = 0;

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        try {
            $source = $this->sourceConnection();
            $target = $this->targetConnection();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Sumber : sqlite %s', $source->getDatabaseName()));
        $this->line(sprintf('Tujuan : %s %s (koneksi %s)', $target->getDriverName(), $target->getDatabaseName(), $this->targetName()));

        try {
            $this->assertSameMigrationLedger($source, $target);
            $plan = $this->plan($source, $target);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $copied = [];
        $total = 0;

        $target->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $target->beginTransaction();

            foreach ($plan as $table => $columns) {
                $rows = $this->copyTable($source, $target, $table, $columns, $chunk);
                $copied[$table] = $rows;
                $total += $rows;
                $this->line(sprintf('  %-48s %6d baris', $table, $rows));
            }

            $target->commit();
        } catch (Throwable $e) {
            $target->rollBack();
            $target->statement('SET FOREIGN_KEY_CHECKS = 1');
            $this->error('Pemindahan DIBATALKAN, tujuan digulung balik (tetap kosong): '.$e->getMessage());

            return self::FAILURE;
        }

        $target->statement('SET FOREIGN_KEY_CHECKS = 1');

        $sequences = $this->resetAutoIncrements($target, array_keys($plan));

        $this->newLine();
        $this->line(sprintf('%d tabel, %d baris disalin.', count($copied), $total));
        $this->line(sprintf(
            'AUTO_INCREMENT: %d tabel sudah ≥ max(id)+1, %d tabel disetel ulang lewat ALTER TABLE.',
            $sequences['ok'], $sequences['altered'],
        ));
        $this->line(sprintf('JSON dinormalkan: %d nilai; DATE dipotong jamnya: %d nilai.', $this->jsonNormalised, $this->dateNormalised));

        if ($this->deltas === []) {
            $this->line('Perubahan nilai: 0 (tidak ada desimal lepas-skala, tidak ada jam yang hilang dari kolom DATE).');
        } else {
            $this->warn(sprintf('Perubahan nilai: %d — setiap baris di bawah ini berbeda dari sumbernya:', count($this->deltas)));

            foreach ($this->deltas as $delta) {
                $this->line(sprintf(
                    '  %s.%s id=%s  %s → %s  (%s)',
                    $delta['table'], $delta['column'], var_export($delta['id'], true), $delta['from'], $delta['to'], $delta['why'],
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Selanjutnya: php artisan erp:migration-verify --from=%s --to=%s', self::LEGACY, $this->targetName(),
        ));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // connections & preconditions
    // ------------------------------------------------------------------

    private function sourceConnection(): Connection
    {
        $path = (string) ($this->option('from') ?: config('database.connections.'.self::LEGACY.'.database'));

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Berkas SQLite sumber tidak ada atau tidak terbaca: '{$path}' (--from=… atau SQLITE_LEGACY_PATH).");
        }

        $real = realpath($path) ?: $path;

        if ($real !== config('database.connections.'.self::LEGACY.'.database')) {
            config(['database.connections.'.self::LEGACY.'.database' => $real]);
            DB::purge(self::LEGACY);
        }

        $connection = DB::connection(self::LEGACY);

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Koneksi '.self::LEGACY.' harus berdriver sqlite.');
        }

        return $connection;
    }

    private function targetName(): string
    {
        return (string) ($this->option('to') ?: config('database.default'));
    }

    private function targetConnection(): Connection
    {
        $name = $this->targetName();

        if (config("database.connections.{$name}") === null) {
            throw new RuntimeException("Koneksi tujuan '{$name}' tidak ada di config/database.php.");
        }

        $connection = DB::connection($name);

        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException(sprintf(
                "Tujuan harus koneksi mysql; '%s' berdriver %s. Alat ini hanya memindahkan SQLite → MySQL.",
                $name, $connection->getDriverName(),
            ));
        }

        // The current schema only: on MySQL Schema::getTables() without a
        // schema lists every database the account can see.
        $schema = $connection->getSchemaBuilder();
        $tables = $schema->getTableListing($schema->getCurrentSchemaName(), false);

        if (! in_array('migrations', $tables, true)) {
            throw new RuntimeException("Tujuan '{$name}' belum dimigrasi (tabel migrations tidak ada). Jalankan migrate:fresh --force dulu.");
        }

        $filled = [];

        foreach ($tables as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $count = (int) $connection->table($table)->count();

            if ($count > 0) {
                $filled[] = "{$table} ({$count} baris)";
            }
        }

        if ($filled !== []) {
            throw new RuntimeException(
                "Tujuan '{$name}' TIDAK KOSONG — ditolak. Tabel berisi: ".implode(', ', $filled)
                    .'. Jalankan migrate:fresh --force pada tujuan, lalu ulangi.',
            );
        }

        return $connection;
    }

    /**
     * Both migration ledgers must name the same set of migrations. Batches are
     * irrelevant (a fresh target has one batch, production has dozens).
     */
    private function assertSameMigrationLedger(Connection $source, Connection $target): void
    {
        $sourceSet = $source->table('migrations')->pluck('migration')->all();
        $targetSet = $target->table('migrations')->pluck('migration')->all();

        $onlySource = array_values(array_diff($sourceSet, $targetSet));
        $onlyTarget = array_values(array_diff($targetSet, $sourceSet));

        if ($onlySource === [] && $onlyTarget === []) {
            return;
        }

        $lines = ['Ledger migrasi sumber dan tujuan BERBEDA — ditolak.'];

        if ($onlyTarget !== []) {
            $lines[] = 'Ada di tujuan, belum dijalankan pada sumber ('.count($onlyTarget).'): '.implode(', ', $onlyTarget)
                .'. Jalankan `php artisan migrate --database='.self::LEGACY.' --force` pada sumber dulu (deploy melakukan ini pada berkas produksi).';
        }

        if ($onlySource !== []) {
            $lines[] = 'Ada di sumber, tidak dikenal tujuan ('.count($onlySource).'): '.implode(', ', $onlySource)
                .'. Kode yang memigrasi tujuan lebih tua dari yang pernah dijalankan pada sumber.';
        }

        throw new RuntimeException(implode(PHP_EOL, $lines));
    }

    /**
     * Which columns of which tables will be copied: every target table, with
     * its non-generated columns that also exist on the source. A source table
     * or column the target does not have is data that would be silently
     * dropped — refused.
     *
     * @return array<string, list<array<string, mixed>>> table → target column metas to insert
     */
    private function plan(Connection $source, Connection $target): array
    {
        $sourceSchema = $source->getSchemaBuilder();
        $targetSchema = $target->getSchemaBuilder();

        $sourceTables = $sourceSchema->getTableListing($sourceSchema->getCurrentSchemaName(), false);
        $targetTables = $targetSchema->getTableListing($targetSchema->getCurrentSchemaName(), false);
        sort($sourceTables);
        sort($targetTables);

        $missing = array_values(array_diff($sourceTables, $targetTables));

        if ($missing !== []) {
            throw new RuntimeException('Tabel sumber tanpa padanan di tujuan (datanya akan hilang) — ditolak: '.implode(', ', $missing));
        }

        $plan = [];
        $problems = [];

        foreach ($targetTables as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $targetColumns = collect($targetSchema->getColumns($table))
                ->reject(fn (array $c): bool => $c['generation'] !== null)
                ->keyBy('name');

            if (! in_array($table, $sourceTables, true)) {
                $plan[$table] = [];

                continue;
            }

            $sourceColumns = collect($sourceSchema->getColumns($table))->pluck('name')->all();

            foreach ($sourceColumns as $column) {
                if (! $targetColumns->has($column)) {
                    $problems[] = "{$table}.{$column}";
                }
            }

            $plan[$table] = $targetColumns->filter(fn (array $c): bool => in_array($c['name'], $sourceColumns, true))->values()->all();
        }

        if ($problems !== []) {
            throw new RuntimeException('Kolom sumber tanpa padanan di tujuan (datanya akan hilang) — ditolak: '.implode(', ', $problems));
        }

        return $plan;
    }

    // ------------------------------------------------------------------
    // copy
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $columns  target column metas (non-generated, present on the source)
     */
    private function copyTable(Connection $source, Connection $target, string $table, array $columns, int $chunk): int
    {
        if ($columns === []) {
            return 0;
        }

        $names = array_column($columns, 'name');
        $perInsert = max(1, min($chunk, intdiv(self::MAX_PLACEHOLDERS, count($names))));
        $hasId = in_array('id', $names, true);

        $copied = 0;
        $offset = 0;

        while (true) {
            // rowid order: stable on every Laravel-created SQLite table, and
            // identical to id where an integer primary key exists.
            $rows = $source->table($table)
                ->select($names)
                ->orderByRaw('rowid')
                ->offset($offset)
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $batch = [];

            foreach ($rows as $row) {
                $record = [];
                $id = $hasId ? $row->id : null;

                foreach ($columns as $meta) {
                    $record[$meta['name']] = $this->normalise($table, $meta, $row->{$meta['name']}, $id);
                }

                $batch[] = $record;

                if (count($batch) === $perInsert) {
                    $target->table($table)->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $target->table($table)->insert($batch);
            }

            $copied += $rows->count();
            $offset += $chunk;

            if ($rows->count() < $chunk) {
                break;
            }
        }

        return $copied;
    }

    /**
     * One value, shaped for its MySQL column. Anything that changes the value
     * beyond representation (rounding, a dropped time-of-day) lands in
     * $this->deltas with the row id.
     *
     * @param  array<string, mixed>  $meta  the target column
     */
    private function normalise(string $table, array $meta, mixed $value, mixed $id): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = strtolower((string) $meta['type_name']);
        $column = $meta['name'];

        if ($type === 'json') {
            $raw = is_string($value) ? $value : (string) json_encode($value);
            $decoded = json_decode($raw, false, 512);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(sprintf(
                    'JSON rusak di %s.%s id=%s (%s) — MySQL menolaknya; perbaiki di sumber dulu (lihat erp:mysql-preflight).',
                    $table, $column, var_export($id, true), json_last_error_msg(),
                ));
            }

            $canonical = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($canonical !== $raw) {
                $this->jsonNormalised++;
            }

            return $canonical;
        }

        if ($type === 'decimal') {
            $scale = preg_match('/\((\d+),\s*(\d+)\)/', (string) $meta['type'], $m) ? (int) $m[2] : 0;
            // A REAL read back from SQLite is a double; its honest text is the
            // shortest form that round-trips (serialize_precision = -1, what
            // json_encode uses): 21048283043.47, not the float's own tail
            // 21048283043.470001220703 — that tail is the representation, not
            // the value, and reporting it as a rounding would be noise.
            $text = is_float($value) ? (string) json_encode($value) : (string) $value;

            // Already on scale: pass through untouched — never via float.
            if (preg_match('/^-?\d+(?:\.\d{0,'.$scale.'})?$/', $text)) {
                return $text;
            }

            $rounded = is_numeric($text)
                ? number_format((float) $text, $scale, '.', '')
                : throw new RuntimeException(sprintf(
                    "Nilai bukan angka di kolom desimal %s.%s id=%s: '%s'.", $table, $column, var_export($id, true), $text,
                ));

            $this->deltas[] = [
                'table' => $table, 'column' => $column, 'id' => $id,
                'from' => $text, 'to' => $rounded, 'why' => "dibulatkan ke skala {$scale}",
            ];

            return $rounded;
        }

        if ($type === 'date' && is_string($value)) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?Z?$/', $value, $m)) {
                $this->dateNormalised++;

                if ($m[2] !== '00:00:00') {
                    $this->deltas[] = [
                        'table' => $table, 'column' => $column, 'id' => $id,
                        'from' => $value, 'to' => $m[1], 'why' => 'kolom DATE, jam dibuang',
                    ];
                }

                return $m[1];
            }

            return $value;
        }

        if (($type === 'datetime' || $type === 'timestamp') && is_string($value)) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d+)?Z?$/', $value, $m)) {
                return "{$m[1]} {$m[2]}";
            }

            return $value;
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // sequences
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $tables
     * @return array{ok:int, altered:int}
     */
    private function resetAutoIncrements(Connection $target, array $tables): array
    {
        // information_schema.tables caches AUTO_INCREMENT for a day by
        // default on MySQL 8; read the live value.
        $target->statement('SET SESSION information_schema_stats_expiry = 0');

        $schema = $target->getSchemaBuilder();
        $ok = 0;
        $altered = 0;

        foreach ($tables as $table) {
            $auto = collect($schema->getColumns($table))->first(fn (array $c): bool => (bool) $c['auto_increment']);

            if ($auto === null) {
                continue;
            }

            $max = $target->table($table)->max($auto['name']);

            if ($max === null) {
                $ok++;

                continue;
            }

            $current = $target->selectOne(
                'select AUTO_INCREMENT as next from information_schema.tables where table_schema = database() and table_name = ?',
                [$table],
            );

            if ($current !== null && $current->next !== null && (int) $current->next > (int) $max) {
                $ok++;

                continue;
            }

            $target->statement(sprintf('ALTER TABLE %s AUTO_INCREMENT = %d', $target->getQueryGrammar()->wrapTable($table), (int) $max + 1));
            $altered++;
        }

        return ['ok' => $ok, 'altered' => $altered];
    }
}
