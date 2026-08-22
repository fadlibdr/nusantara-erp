<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['sometimes', 'required', 'integer', Rule::exists('inv_warehouses', 'id')],
            'to_warehouse_id' => ['sometimes', 'required', 'integer', 'different:from_warehouse_id', Rule::exists('inv_warehouses', 'id')],
            'transfer_date' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.001'],
        ];
    }
}
