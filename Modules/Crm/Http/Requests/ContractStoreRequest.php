<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\ScopeType;

class ContractStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('crm_customers', 'id')],
            'quotation_id' => ['nullable', 'integer', Rule::exists('crm_quotations', 'id')],
            'contract_number_customer' => ['nullable', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', Rule::enum(ScopeType::class)],
            'value' => ['required', 'numeric', 'min:0'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sign_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'retention_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'termins' => ['required', 'array', 'min:1'],
            'termins.*.name' => ['required', 'string', 'max:100'],
            'termins.*.percent' => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'termins.*.billing_condition' => ['nullable', 'string', 'max:1000'],
            // Termin retensi (pola "Retensi 5%" dalam jadwal). Kontrak yang
            // memuatnya menolak potongan retensi per invoice — temuan #73.
            'termins.*.is_retention' => ['nullable', 'boolean'],
            // Tanggal rencana tagih. Optional: a progress termin is released by
            // its milestone, only a calendar termin comes due by date.
            'termins.*.due_date' => ['nullable', 'date'],
        ];
    }
}
