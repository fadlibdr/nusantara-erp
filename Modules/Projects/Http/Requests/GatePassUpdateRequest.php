<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\GatePassDirection;

class GatePassUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::enum(GatePassDirection::class)],
            'pass_date' => ['sometimes', 'string', 'date'],
            'vehicle_no' => ['nullable', 'string', 'max:20'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('prc_vendors', 'id')],
            'counterparty' => ['nullable', 'string', 'max:200'],
            'goods_receipt_id' => ['nullable', 'integer', 'min:1'],
            'transfer_id' => ['nullable', 'integer', 'min:1'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.description' => ['required_with:items', 'string', 'max:200'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['required_with:items', 'string', 'max:20'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],
        ];
    }
}
