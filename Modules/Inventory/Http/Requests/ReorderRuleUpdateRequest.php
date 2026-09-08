<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
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
            'item_id' => ['sometimes', 'required', 'integer', Rule::exists('inv_items', 'id')->whereNull('deleted_at')],
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
        ];
    }
}
