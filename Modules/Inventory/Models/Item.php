<?php

namespace Modules\Inventory\Models;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Enums\ItemType;

class Item extends BaseModel
{
    use SoftDeletes;

    protected $table = 'inv_items';

    /**
     * KOLOM YANG SEBUAH PEMINDAIAN COCOKKAN, DAN SATU-SATUNYA DAFTARNYA.
     *
     * Stiker F/LBL mencetak `barcode` bila kartunya punya dan `code` bila
     * tidak, jadi satu kode yang dibaca alat bisa berupa keduanya — dan satu
     * kode bisa menjadi barcode sebuah item DAN kode item lain sekaligus.
     */
    public const SCAN_KEY_COLUMNS = ['barcode', 'code'];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'min_stock' => 'decimal:3',
            'avg_cost' => 'decimal:2',
            'last_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Item $item): void {
            if (! empty($item->code)) {
                return;
            }

            // ITM-nnnn — zero-padded codes sort lexicographically, so MAX(code)
            // is the latest one issued (fine up to ITM-9999).
            $last = static::withTrashed()
                ->where('code', 'like', 'ITM-%')
                ->max('code');

            $next = $last !== null ? ((int) substr((string) $last, 4)) + 1 : 1;

            $item->code = 'ITM-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * ITEM YANG SEBUAH PEMINDAIAN KODE INI MEMULANGKAN — satu aturan, dan
     * setiap permukaan yang membandingkan kode item memanggilnya (F-6,
     * putaran kedua).
     *
     * ====================================================================
     * KENAPA IA HIDUP DI MODEL, BUKAN DI TIGA PENGONTROL.
     *
     * Aturan ini pernah ditulis tiga kali dan ketiganya berbeda:
     * `ItemScanController` memakai UPPER() di kedua sisi, lembar F/LBL
     * memakai `where('barcode', $encoded)` yang PEKA HURUF di SQLite, dan
     * saringan audit "Barcode ganda" hanya mengelompokkan barcode terhadap
     * barcode. Akibatnya bisa diukur: `F6DUP001` vs `f6dup001` membuat layar
     * Pindai berkata GANDA sementara kedua lembar labelnya diam, dan sebuah
     * barcode yang sama dengan KODE item lain membuat saringan auditnya
     * memulangkan nol baris — yaitu jawaban yang membuat pemilik menyimpulkan
     * katalognya bersih dan menyetujui UNIQUE pada kolom yang tidak unik.
     *
     * COCOK PERSIS, BUKAN `like`: pemindaian adalah pembacaan mesin, ia tepat
     * atau ia gagal. Pencarian sebagian punya tempatnya sendiri
     * (`GET inventory/items?q=`).
     *
     * TETAPI TIDAK PEDULI BESAR-KECIL HURUF ASCII, dengan `UPPER()` di kedua
     * sisi: SQLite membandingkan `=` secara peka huruf, dan papan ketik iOS
     * mengapitalkan huruf pertama sementara jalur ketik adalah satu-satunya
     * jalur di iPhone. `UPPER()` sendiri TIDAK menetralkan collation — yang
     * menutup itu adalah cabang per driver di `scanKeyExpression()`, yang
     * docblock-nya menyebut selisih terukurnya.
     *
     * ITEM YANG DIBUANG TIDAK IKUT, karena pemindaiannya tidak memulangkan
     * mereka: sebuah peringatan "memindai stiker ini akan memulangkan lebih
     * dari satu item" yang datang dari kartu yang sudah dibuang menjanjikan
     * sesuatu yang tidak akan terjadi. Yang boleh `withTrashed()` adalah
     * SUBJEK-nya (lembar label item terbuang tetap bisa dicetak), bukan
     * kembarannya.
     * ====================================================================
     */
    public function scopeMatchingScanCode(Builder $query, string $code): Builder
    {
        self::whereScanKeyEquals($query, $query->getModel()->getTable(), '?', [mb_strtoupper(trim($code))]);

        return $query;
    }

    /**
     * Item yang SALAH SATU kodenya juga dijawab item lain — permukaan audit
     * yang keputusan "boleh `inv_items.barcode` dijadikan UNIQUE?" bersandar
     * padanya.
     *
     * Pertanyaannya berbeda dari kedua permukaan lain dan aturannya sama:
     * layar Pindai bertanya tentang kode yang DIKETIK, lembar F/LBL tentang
     * kode yang SEDANG IA CETAK, dan daftar ini tentang SETIAP kode yang item
     * itu jawab — kodenya sendiri DAN barcode-nya. Yang diaudit adalah
     * katalognya, bukan satu stiker.
     *
     * ===================== DIHITUNG SEKALI, BUKAN SEKALI PER BARIS =====================
     * Bentuk pertamanya adalah `EXISTS (… other …)` berkorelasi — aturan yang sama persis,
     * dan ia mengubah layar audit menjadi layar yang tidak bisa dibuka. Diukur pada SQLite
     * dengan katalog 5.000 item (10 di antaranya bertabrakan):
     *
     *   ganda (count)        20.973 ms
     *   tidak ganda (count)  21.921 ms
     *
     * `listing()` menghitung total sebelum menggambar halaman pertama, jadi angka itu adalah
     * waktu yang dilihat orangnya. Sekarang kunci yang bertabrakan dihitung SEKALI lewat satu
     * kueri berkelompok, dan barisnya hanya mencocokkan kuncinya ke daftar itu.
     *
     * `COUNT(DISTINCT id) > 1`, bukan `COUNT(*) > 1`: sebuah kartu yang barcode-nya SAMA
     * dengan kodenya sendiri menyumbang dua baris untuk kunci yang sama, dan ia tidak
     * bertabrakan dengan siapa pun. UNION (bukan UNION ALL) membuang pasangan (id, kunci)
     * yang kembar itu, dan COUNT(DISTINCT id) menutup sisanya.
     * ==================================================================================
     *
     * `$shared = false` adalah lengan "Tidak" saringannya. Tiap lengan MENYEBUT kunci
     * kosongnya sendiri (`COALESCE(TRIM(...), '') <> ''`) — tanpa itu `UPPER(NULL) IN (…)`
     * bernilai NULL, `NULL OR FALSE` bernilai NULL, dan `NOT NULL` membuang setiap item yang
     * belum punya barcode dari lengan "Tidak", yaitu sebagian besar katalog, tanpa satu pun
     * tanda.
     */
    public function scopeSharingScanCode(Builder $query, bool $shared = true): Builder
    {
        $table = $query->getModel()->getTable();

        $matchesACollidingKey = function (BuilderContract $where) use ($table): void {
            foreach (self::SCAN_KEY_COLUMNS as $column) {
                $where->orWhere(function (BuilderContract $arm) use ($table, $column): void {
                    // Kunci KOSONG bukan kunci: dua kartu yang sama-sama belum
                    // mengisi barcode tidak berbagi apa pun, dan tidak ada
                    // pemindaian yang bisa memulangkan keduanya (isian kosong
                    // ditolak 422).
                    $arm->whereRaw(self::scanKeyPresent($table, $column))
                        ->whereIn(DB::raw(self::scanKeyExpression($table, $column)), self::collidingScanKeys($table));
                });
            }
        };

        return $shared
            ? $query->where($matchesACollidingKey)
            : $query->whereNot($matchesACollidingKey);
    }

    /**
     * Kunci pindai yang dijawab LEBIH DARI SATU item hidup — satu kueri, tanpa korelasi.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private static function collidingScanKeys(string $table)
    {
        $arms = array_map(
            fn (string $column) => DB::table($table)
                ->select('id')
                ->selectRaw(self::scanKeyExpression($table, $column).' as scan_key')
                ->whereNull('deleted_at')
                ->whereRaw(self::scanKeyPresent($table, $column)),
            self::SCAN_KEY_COLUMNS,
        );

        $keys = array_shift($arms);

        foreach ($arms as $arm) {
            $keys->union($arm);
        }

        return DB::query()
            ->fromSub($keys, 'kunci_pindai')
            ->select('scan_key')
            ->groupBy('scan_key')
            ->havingRaw('COUNT(DISTINCT id) > 1');
    }

    /**
     * `UPPER(<kolom kunci>) = <ekspresi>` untuk setiap kolom kunci, sebagai
     * SATU kelompok OR — bentuk perbandingan yang dipakai `matchingScanCode()`.
     *
     * Ekspresinya sebuah pengikat (`?`, untuk kode yang diketik atau dicetak).
     * Yang tidak boleh berbeda antara scope ini dan saudaranya adalah DAFTAR
     * KOLOMNYA dan `UPPER()`-nya; karena itu keduanya cuma ada di sini dan di
     * `scanKeyExpression()`.
     *
     * @param  list<mixed>  $bindings
     */
    private static function whereScanKeyEquals(
        BuilderContract $query,
        string $table,
        string $expression,
        array $bindings = [],
    ): void {
        $query->where(function (BuilderContract $where) use ($table, $expression, $bindings): void {
            foreach (self::SCAN_KEY_COLUMNS as $index => $column) {
                $comparison = self::scanKeyExpression($table, $column).' = '.$expression;

                $index === 0
                    ? $where->whereRaw($comparison, $bindings)
                    : $where->orWhereRaw($comparison, $bindings);
            }
        });
    }

    /**
     * NILAI sebuah kolom kunci sebagaimana pemindaian membandingkannya.
     *
     * ====================================================================
     * DUA HAL, DAN KEDUANYA PERLU — `UPPER()` SAJA TIDAK CUKUP.
     *
     * `UPPER()` menutup selisih huruf besar-kecil ASCII (papan ketik iOS
     * mengapitalkan huruf pertama, dan jalur ketik adalah satu-satunya jalur
     * di iPhone). Ia TIDAK menetralkan collation: yang membandingkan hasilnya
     * tetap collation kolomnya, dan kolom itu `utf8mb4_unicode_ci`. Di MySQL
     * 8.0.46 `UPPER('café') = 'CAFE'` memulangkan 1 — diukur.
     *
     * Akibatnya terukur di dua mesin, dengan kartu `CAFÉ-2026` dan
     * `CAFE-2026`:
     *
     *   SQLITE                              MYSQL 8 (sebelum)
     *   pindai 'CAFE-2026' → satu: E002     → ambiguous: E001, E002
     *   saringan ganda     → (kosong)       → E001, E002
     *
     * Yaitu persis selisih yang `UPPER()` dipasang untuk menutup. Maka di
     * MySQL perbandingannya dipaksa `utf8mb4_bin`: yang memutuskan "sama"
     * adalah BYTE hasil `UPPER()`-nya, bukan tabel bobot collation. Pindai
     * adalah pembacaan MESIN — ia tepat atau ia gagal — dan `café` dan `cafe`
     * adalah dua kode yang berbeda di setiap pemindai di dunia.
     *
     * YANG MASIH BERBEDA ANTARA KEDUA MESIN, dan tidak ditutup ekspresi ini:
     * huruf besar-kecil DI LUAR ASCII. `UPPER()` MySQL melipat `é` → `É`,
     * `UPPER()` SQLite (tanpa ICU) tidak. Selisihnya satu arah — MySQL
     * memulangkan kumpulan yang SAMA atau LEBIH BESAR, tidak pernah lebih
     * kecil — jadi produksi tidak pernah diam-diam melewatkan tabrakan yang
     * mesin uji lihat, dan pemindaian ambigu tidak pernah dipilihkan diam-diam
     * (server memulangkan semuanya). Kode Code 128 sendiri wajib ASCII
     * 32–126, jadi selisih itu hanya bisa muncul pada kode yang lembarnya
     * memang cetak tanpa batang.
     *
     * SATU FUNGSI, satu cabang: setiap permukaan (pemindaian, lembar F/LBL,
     * saringan audit, dan kedua sisi pengelompokan `collidingScanKeys`)
     * membaca ekspresi yang sama, jadi tidak ada permukaan yang bisa memakai
     * aturan collation yang berbeda dari saudaranya.
     * ====================================================================
     */
    private static function scanKeyExpression(string $table, string $column): string
    {
        $upper = "UPPER({$table}.{$column})";

        return DB::getDriverName() === 'mysql'
            ? $upper.' COLLATE utf8mb4_bin'
            : $upper;
    }

    /** Kolom kunci yang benar-benar berisi sesuatu — kunci kosong bukan kunci. */
    private static function scanKeyPresent(string $table, string $column): string
    {
        return "COALESCE(TRIM({$table}.{$column}), '') <> ''";
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * Aturan titik pesan ulang yang menyebut item ini (F-6).
     *
     * Dipakai kartu item untuk MENGATAKAN bahwa `min_stock` di atasnya sudah
     * tidak berlaku di gudang-gudang itu — satu-satunya layar yang memajang
     * angka yang KALAH, dan yang sampai putaran perbaikan F-6 tidak menyebut
     * penggantinya sama sekali. Indeks `item_id` pada migrasi 001700 dibuat
     * untuk arah ini.
     */
    public function reorderRules(): HasMany
    {
        return $this->hasMany(ReorderRule::class, 'item_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'item_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class, 'item_id');
    }
}
