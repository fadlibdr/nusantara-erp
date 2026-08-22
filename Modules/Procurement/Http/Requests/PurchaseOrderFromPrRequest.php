<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderFromPrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')],
            'order_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            // Alasan menembus gate prakualifikasi vendor (temuan #35).
            'qualification_override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
