<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderRuleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // Keduanya ada DI DALAM Inventory, jadi exists: dipakai — sebuah
            // aturan untuk gudang yang tidak ada adalah baris yang tidak akan
            // pernah menentukan apa pun dan tidak akan pernah terlihat.
            'warehouse_id' => [
                'required', 'integer', Rule::exists('inv_warehouses', 'id')->whereNull('deleted_at'),
                /*
                 * UNIQUE-nya ADA DI BASIS DATA (migrasi 001700) dan dua baris
                 * untuk satu pasangan menggandakan setiap baris kekurangan.
                 * Yang ditambahkan di sini bukan aturan kedua melainkan
                 * KALIMATNYA: tanpa baris ini pemakai mendapat 500 dari
                 * QueryException, yang tidak menyebut aturan mana yang sudah
                 * berdiri dan tidak memberi jalan keluar.
                 */
                Rule::unique('inv_reorder_rules', 'warehouse_id')
                    ->where(fn ($query) => $query->where('item_id', $this->input('item_id'))),
            ],
            'item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')->whereNull('deleted_at')],
            'reorder_point' => ['required', 'numeric', 'min:0'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.unique' => 'Gudang dan item ini sudah punya aturan reorder. '
                .'Ubah aturan yang sudah ada — satu pasangan gudang × item hanya boleh punya satu aturan, '
                .'karena dua aturan akan menghitung kekurangan yang sama dua kali.',
        ];
    }
}
