<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReorderRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $itemTrashed = (bool) $this->item?->trashed();
        $warehouseTrashed = (bool) $this->warehouse?->trashed();

        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
                'deleted' => $warehouseTrashed,
            ]),
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit' => $this->item->unit,
                'deleted' => $itemTrashed,
                /*
                 * Angka yang aturan ini GANTIKAN, dikirim bersama aturannya.
                 *
                 * Layar daftar aturan harus bisa menulis "20 (stok min. item
                 * 100)" tanpa memuat kartu itemnya satu per satu: sebuah baris
                 * yang hanya menyebut 20 tidak memberi tahu siapa pun bahwa
                 * angka perusahaan untuk barang ini lima kali lebih besar —
                 * dan itulah satu-satunya informasi yang membuat seseorang
                 * berhenti dan memeriksa apakah aturannya masih benar.
                 */
                'min_stock' => (float) $this->item->min_stock,
            ]),
            /*
             * ATURAN YANG AMBANGNYA SUDAH TIDAK MENENTUKAN APA PUN HARUS
             * MENGATAKANNYA — kalau tidak, barisnya tampak hidup.
             *
             * Relasi item()/warehouse() memakai withTrashed() dengan sengaja
             * (docblock ReorderRule) supaya NAMA-nya selamat dan barisnya tetap
             * bisa dilihat serta dibuang orangnya. Tetapi kueri kekurangan
             * membuang item dan gudang yang terhapus lebih dulu, jadi ambang
             * baris seperti itu tidak menentukan apa pun — sementara kolom
             * "Aktif" tetap ✓. Penjaga gudang yang membaca "Semen Portland ·
             * titik 80 · Aktif ✓" menyimpulkan gudang itu akan berteriak di
             * bawah 80, dan tidak ada satu pun tanda di layar yang
             * membantahnya. Itu kebalikan dari janji layar ini bahwa
             * prioritasnya harus TERBACA, bukan hanya berlaku.
             *
             * KETIGA SYARATNYA DATANG DARI SATU TEMPAT (`governs()`), dan
             * syarat ketiga itu — `is_active` — dulu hilang di sini: aturan
             * nonaktif dikirim `applies: true` sementara bantuan formulirnya
             * sendiri berkata ia "tetap tersimpan dan tidak menentukan ambang
             * apa pun". Yang menandainya di layar untuk keadaan itu adalah
             * kolom "Aktif" sendiri; `deleted_labels` di bawah tetap menyebut
             * hanya yang benar-benar DIBUANG, karena itulah namanya.
             */
            'applies' => $this->resource->governs(),
            'deleted_labels' => array_values(array_filter([
                $itemTrashed ? 'Item dibuang' : null,
                $warehouseTrashed ? 'Gudang dibuang' : null,
            ])),
            'reorder_point' => (float) $this->reorder_point,
            'reorder_qty' => (float) $this->reorder_qty,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
