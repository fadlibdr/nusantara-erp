<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaborClaimStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'labor_contract_id' => ['required', 'integer', Rule::exists('scm_labor_contracts', 'id')],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            // Kasbon lintas-modul (fin_kasbons): keberadaan/status/sisa/proyek
            // diperiksa service dengan pesan yang menyebut faktanya.
            'kasbon_id' => ['nullable', 'integer'],
            'kasbon_deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.labor_contract_item_id' => ['required', 'integer', Rule::exists('scm_labor_contract_items', 'id')],
            'items.*.qty_this' => ['required', 'numeric', 'min:0.001'],
        ];
    }
}
