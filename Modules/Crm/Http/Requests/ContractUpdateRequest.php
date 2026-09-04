<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\ScopeType;

class ContractUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'required', 'integer', Rule::exists('crm_customers', 'id')],
            'quotation_id' => ['nullable', 'integer', Rule::exists('crm_quotations', 'id')],
            'contract_number_customer' => ['nullable', 'string', 'max:100'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'scope_type' => ['sometimes', 'required', Rule::enum(ScopeType::class)],
            'value' => ['sometimes', 'required', 'numeric', 'min:0'],
            // Same rule as on store (T3.6): judged by ContractService against
            // the quotation's DPP; an absent key keeps the stored reason.
            'value_change_reason' => ['nullable', 'string', 'max:500'],
            'ppn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sign_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'retention_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            // The schedule is replaced wholesale when provided.
            'termins' => ['sometimes', 'required', 'array', 'min:1'],
            'termins.*.name' => ['required_with:termins', 'string', 'max:100'],
            'termins.*.percent' => ['required_with:termins', 'numeric', 'min:0.0001', 'max:100'],
            'termins.*.billing_condition' => ['nullable', 'string', 'max:1000'],
            'termins.*.is_retention' => ['nullable', 'boolean'],
            'termins.*.due_date' => ['nullable', 'date'],
        ];
    }
}
