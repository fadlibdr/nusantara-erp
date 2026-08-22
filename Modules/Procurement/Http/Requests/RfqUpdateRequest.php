<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RfqUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer'],
            'rfq_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'vendor_ids' => ['sometimes', 'array', 'min:1'],
            'vendor_ids.*' => ['integer', Rule::exists('prc_vendors', 'id')->whereNull('deleted_at')],
            'items' => ['sometimes', 'array', 'min:1'],
            // id baris yang dipertahankan — tanpanya validated() membuang key
            // itu, sync menganggap semua baris baru, dan FK cascade
            // menghanguskan matriks penawaran pada Ubah judul sekalipun.
            'items.*.id' => ['nullable', 'integer'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
        ];
    }
}
