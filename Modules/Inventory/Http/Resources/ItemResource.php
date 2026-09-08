<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => ItemCategoryResource::make($this->whenLoaded('category')),
            'unit' => $this->unit,
            'barcode' => $this->barcode,
            'item_type' => $this->item_type?->value,
            'item_type_label' => $this->item_type?->label(),
            'min_stock' => $this->min_stock,
            /*
             * ANGKA DI ATAS TIDAK BERLAKU DI SETIAP GUDANG, DAN KARTU INI
             * SATU-SATUNYA LAYAR YANG MEMAJANGNYA TANPA MENGATAKANNYA.
             *
             * CONVENTIONS §31 menuntut prioritas ambang DITULIS di layar, dan
             * empat permukaan sudah menulisnya dari arah aturan → item ("400 ·
             * Aturan reorder gudang ini · stok min. item 200"). Arah
             * sebaliknya tidak dikerjakan: seseorang membuka ITM-0001, membaca
             * "Stok minimum 200,000", dan menyimpulkan itulah ambang di
             * seluruh gudang — sementara pasangan ITM-0001 × Gudang Site
             * berambang 400 dan sedang KURANG 50. Yang menaikkan atau
             * menurunkan angka 200 di sana mengira ia sedang mengubah ambang
             * gudang itu; ia tidak mengubah apa pun.
             *
             * Hadir hanya bila ada aturan AKTIF, dan hanya pada kartu item
             * (loadCount di ItemController::show) — daftar item tidak
             * membutuhkannya dan tidak membayar kuerinya.
             */
            'reorder_rule_note' => $this->when(
                (int) ($this->active_reorder_rules_count ?? 0) > 0,
                fn (): string => sprintf(
                    '%d gudang memakai titik pesan ulang sendiri untuk item ini. Di gudang itu stok minimum '
                    .'di atas TIDAK berlaku — aturannya menggantikan, termasuk bila lebih rendah. '
                    .'Atur di Persediaan › Aturan Reorder.',
                    (int) $this->active_reorder_rules_count,
                ),
            ),
            'avg_cost' => $this->avg_cost,
            'last_price' => $this->last_price,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
