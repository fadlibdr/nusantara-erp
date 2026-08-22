<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseRequisitionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'requested_by' => ['nullable', 'integer'],
            'needed_date' => ['required', 'date'],
            'purpose' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:500', 'required_without:items.*.item_id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.estimated_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
        ];
    }
}
