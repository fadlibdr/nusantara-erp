<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReorderRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit' => $this->item->unit,
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
            'reorder_point' => (float) $this->reorder_point,
            'reorder_qty' => (float) $this->reorder_qty,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
