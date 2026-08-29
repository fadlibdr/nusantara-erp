<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaborClaimUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'period_start' => ['sometimes', 'date'],
            'period_end' => ['sometimes', 'date', 'after_or_equal:period_start'],
            'kasbon_id' => ['nullable', 'integer'],
            'kasbon_deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.labor_contract_item_id' => ['required', 'integer', Rule::exists('scm_labor_contract_items', 'id')],
            'items.*.qty_this' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
