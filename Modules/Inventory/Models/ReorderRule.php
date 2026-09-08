<?php

namespace Modules\Inventory\Models;

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

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id')->withTrashed();
    }
}
