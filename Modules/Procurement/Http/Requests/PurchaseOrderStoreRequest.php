<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')],
            'purchase_requisition_id' => ['nullable', 'integer', Rule::exists('prc_purchase_requisitions', 'id')],
            'project_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            // Alasan menembus gate prakualifikasi vendor; ikut tersimpan di PO
            // sebagai jejak audit (temuan #35).
            'qualification_override_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
