<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'integer', Rule::exists('inv_warehouses', 'id')],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', Rule::exists('inv_warehouses', 'id')],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
