<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\ReorderRule;

class ReorderRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * KOTAK YANG DIKOSONGKAN ADALAH ANGKA NOL, BUKAN 500 — lihat docblock
     * yang sama di ReorderRuleStoreRequest.
     *
     * Bedanya satu, dan ia yang membuat dua metode alih-alih satu: pada
     * SUNTINGAN, `is_active` null berarti "tidak disebut", dan tidak disebut
     * tidak boleh berarti dimatikan. Nilainya diambil dari baris yang sedang
     * disunting, bukan dari `true` — sebuah aturan nonaktif yang menyala
     * kembali karena seseorang mengubah titiknya adalah ambang yang berubah
     * tanpa satu pun kata di layar.
     */
    protected function prepareForValidation(): void
    {
        /** @var ReorderRule|null $rule */
        $rule = $this->route('reorderRule');

        if ($this->has('reorder_qty') && $this->input('reorder_qty') === null) {
            $this->merge(['reorder_qty' => 0]);
        }

        if ($this->has('is_active') && $this->input('is_active') === null) {
            $this->merge(['is_active' => (bool) ($rule?->is_active ?? true)]);
        }
    }

    public function rules(): array
    {
        $rule = $this->route('reorderRule');

        return [
            // Pasangannya BOLEH dipindah, dan penjaga uniknya ikut pindah:
            // mengabaikan baris ini akan membuat "pindahkan aturan ini ke
            // gudang lain" bertabrakan dengan aturan yang sudah ada di sana
            // sebagai 500, bukan sebagai kalimat.
            'warehouse_id' => [
                'sometimes', 'required', 'integer', Rule::exists('inv_warehouses', 'id')->whereNull('deleted_at'),
                Rule::unique('inv_reorder_rules', 'warehouse_id')
                    ->ignore($rule?->id)
                    ->where(fn ($query) => $query->where('item_id', $this->input('item_id', $rule?->item_id))),
            ],
            /*
             * PENJAGA YANG SAMA, ARAH YANG LAIN — dan ketiadaannya dulu
             * membuat request ini gagal pada pekerjaan yang menjadi alasannya.
             *
             * Penjaga unik di atas menumpang pada `warehouse_id`, yang
             * bertanda `sometimes`. Sebuah muatan yang hanya menyebut
             * `item_id` — pembaruan parsial dari integrasi, skrip, atau curl —
             * melewatkan seluruh pemeriksaan dan mendarat sebagai 500 dari
             * QueryException, yang tidak menyebut aturan mana yang sudah
             * berdiri dan tidak memberi jalan keluar. Layar SPA aman hanya
             * karena ia kebetulan mengirim seluruh field pada sunting; sebuah
             * aturan yang berlaku pada satu permukaan saja bukan aturan.
             */
            'item_id' => [
                'sometimes', 'required', 'integer', Rule::exists('inv_items', 'id')->whereNull('deleted_at'),
                Rule::unique('inv_reorder_rules', 'item_id')
                    ->ignore($rule?->id)
                    ->where(fn ($query) => $query->where('warehouse_id', $this->input('warehouse_id', $rule?->warehouse_id))),
            ],
            'reorder_point' => ['sometimes', 'required', 'numeric', 'min:0'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.unique' => 'Gudang dan item ini sudah punya aturan reorder yang lain. '
                .'Satu pasangan gudang × item hanya boleh punya satu aturan.',
            'item_id.unique' => 'Gudang dan item ini sudah punya aturan reorder yang lain. '
                .'Satu pasangan gudang × item hanya boleh punya satu aturan.',
        ];
    }
}
