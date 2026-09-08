<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Titik pesan ulang untuk satu pasangan GUDANG × ITEM (F-6).
 *
 * TANPA SoftDeletes, dan itu keputusan tabelnya (lihat migrasi 001700): UNIQUE
 * (warehouse_id, item_id) yang menahan kueri kekurangan dari menggandakan
 * barisnya tidak bisa berdampingan dengan baris yang dibuang lembut. Yang
 * dibutuhkan pemakai adalah saklar, bukan tong sampah, dan saklarnya
 * `is_active`.
 *
 * Kedua relasi withTrashed, dan alasannya sama dengan yang ditulis
 * Warehouse::project(): item dan gudang menghapus-lembut, menghapus salah
 * satunya TIDAK menyentuh baris aturan yang menyebutnya, dan sebuah aturan
 * yang kehilangan namanya di layar terbaca seperti aturan yang rusak alih-alih
 * seperti gudang yang dibuang. Ambangnya sendiri tidak lagi berlaku — kueri
 * kekurangan membuang item dan gudang yang terhapus lebih dulu — tetapi
 * barisnya tetap harus bisa dilihat dan dibuang orangnya.
 */
class ReorderRule extends BaseModel
{
    protected $table = 'inv_reorder_rules';

    protected function casts(): array
    {
        return [
            'reorder_point' => 'decimal:3',
            'reorder_qty' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * ATURAN YANG BENAR-BENAR MENENTUKAN AMBANG SEBUAH PASANGAN — tiga
     * syarat, satu tempat (F-6, putaran kedua).
     *
     * ====================================================================
     * Ketiganya sudah lama ditegakkan `StockService::lowStockAlerts()`:
     * `r.is_active` di klausa ON, `whereNull('i.deleted_at')` dan
     * `whereNull('w.deleted_at')` pada join-nya. Tetapi dua permukaan lain
     * menghitung ulang "berlaku" dengan isi yang BERBEDA, dan keduanya sampai
     * ke layar:
     *
     *   kartu item (loadCount)   is_active saja       → kalimat "stok minimum
     *                                                    di atas TIDAK
     *                                                    berlaku" untuk aturan
     *                                                    yang gudangnya sudah
     *                                                    dibuang;
     *   daftar aturan (applies)  kedua deleted_at saja → aturan NONAKTIF
     *                                                    dikirim `applies:
     *                                                    true`, padahal
     *                                                    bantuan formulirnya
     *                                                    sendiri berkata ia
     *                                                    "tidak menentukan
     *                                                    ambang apa pun".
     *
     * Sekarang keduanya memanggil scope ini (bentuk kueri) atau `governs()`
     * (bentuk baris), dan `ReorderThresholdTest` memaku kesetaraan keduanya
     * dengan kumpulan aturan yang BENAR-BENAR dipatuhi kueri kekurangan.
     * ====================================================================
     *
     * Kueri kekurangan tidak bisa memanggil scope ini — ia berangkat dari
     * `inv_stock_balances` dan menyapa tabel aturan lewat LEFT JOIN, karena
     * pasangan TANPA aturan pun harus tetap muncul. Yang menjaga keduanya
     * tetap satu arti adalah ujinya, persis seperti salinan registri Core.
     */
    public function scopeGoverning(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->whereHas('item', fn ($item) => $item->whereNull('inv_items.deleted_at'))
            ->whereHas('warehouse', fn ($warehouse) => $warehouse->whereNull('inv_warehouses.deleted_at'));
    }

    /** Bentuk BARIS dari scope di atas — tiga syarat yang sama, satu aturan. */
    public function governs(): bool
    {
        return (bool) $this->is_active
            && $this->item !== null && ! $this->item->trashed()
            && $this->warehouse !== null && ! $this->warehouse->trashed();
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id')->withTrashed();
    }
}
