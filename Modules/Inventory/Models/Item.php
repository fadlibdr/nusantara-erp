<?php

namespace Modules\Inventory\Models;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
     * TETAPI TIDAK PEDULI BESAR-KECIL HURUF, dengan `UPPER()` di kedua sisi
     * dan bukan collation: SQLite membandingkan `=` secara peka huruf
     * sementara MySQL utf8mb4_unicode_ci tidak, jadi tanpa itu jawabannya
     * BERBEDA antara mesin uji dan produksi. Papan ketik iOS mengapitalkan
     * huruf pertama, dan jalur ketik adalah satu-satunya jalur di iPhone.
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
     * `$shared = false` adalah lengan "Tidak" saringannya, dan ia
     * `whereNotExists` — bukan `NOT IN` atas daftar barcode, yang bernilai
     * NULL untuk setiap item tanpa barcode dan diam-diam membuang sebagian
     * besar katalog dari lengan itu.
     */
    public function scopeSharingScanCode(Builder $query, bool $shared = true): Builder
    {
        $table = $query->getModel()->getTable();

        $twin = function (BuilderContract $other) use ($table): void {
            $other->selectRaw('1')
                ->from($table.' as other')
                ->whereColumn('other.id', '!=', $table.'.id')
                // Kembarannya harus HIDUP, sama seperti pada pemindaiannya.
                ->whereNull('other.deleted_at')
                ->where(function (BuilderContract $where) use ($table): void {
                    foreach (self::SCAN_KEY_COLUMNS as $column) {
                        $where->orWhere(function (BuilderContract $arm) use ($table, $column): void {
                            // Kunci KOSONG bukan kunci: dua kartu yang sama-sama
                            // belum mengisi barcode tidak berbagi apa pun, dan
                            // tidak ada pemindaian yang bisa memulangkan
                            // keduanya (isian kosong ditolak 422).
                            $arm->whereRaw("COALESCE(TRIM({$table}.{$column}), '') <> ''");

                            self::whereScanKeyEquals($arm, 'other', "UPPER({$table}.{$column})");
                        });
                    }
                });
        };

        return $shared ? $query->whereExists($twin) : $query->whereNotExists($twin);
    }

    /**
     * `UPPER(<kolom kunci>) = <ekspresi>` untuk setiap kolom kunci, sebagai
     * SATU kelompok OR — bentuk perbandingan yang dipakai kedua scope di atas.
     *
     * Ekspresinya bisa sebuah pengikat (`?`, untuk kode yang diketik) atau
     * sebuah kolom (`UPPER(inv_items.code)`, untuk mencari kembaran di dalam
     * tabel yang sama). Yang tidak boleh berbeda di antara keduanya adalah
     * DAFTAR KOLOMNYA dan `UPPER()`-nya; karena itu keduanya cuma ada di sini.
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
                $comparison = "UPPER({$table}.{$column}) = {$expression}";

                $index === 0
                    ? $where->whereRaw($comparison, $bindings)
                    : $where->orWhereRaw($comparison, $bindings);
            }
        });
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
