<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\AdjustmentReason;

class StockAdjustmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'required', 'integer', Rule::exists('inv_warehouses', 'id')],
            'adjustment_date' => ['sometimes', 'required', 'date'],
            'reason' => ['sometimes', 'required', Rule::enum(AdjustmentReason::class)],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.counted_qty' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
