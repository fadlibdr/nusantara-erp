<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\AdjustmentReason;

class StockAdjustmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('inv_warehouses', 'id')],
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('inv_items', 'id')],
            'items.*.counted_qty' => ['required', 'numeric', 'min:0'],
        ];
    }
}
