<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\ItemType;

class ItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:40', Rule::unique('inv_items', 'code')], // auto ITM-nnnn when empty
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('inv_item_categories', 'id')],
            'unit' => ['required', 'string', 'max:20'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'item_type' => ['required', Rule::enum(ItemType::class)],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'last_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            // avg_cost is system-maintained by StockService and never accepted from input
        ];
    }
}
