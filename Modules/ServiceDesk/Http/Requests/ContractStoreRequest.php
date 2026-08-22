<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceDesk\Enums\BillingCycle;
use Modules\ServiceDesk\Enums\ContractStatus;

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
            'contract_id' => ['nullable', 'integer', Rule::exists('crm_contracts', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'contract_value' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'sla_response_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'sla_resolution_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'coverage' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::enum(ContractStatus::class)],
            'sites' => ['required', 'array', 'min:1'],
            'sites.*.site_name' => ['required', 'string', 'max:255'],
            'sites.*.address' => ['nullable', 'string', 'max:255'],
            'sites.*.city' => ['nullable', 'string', 'max:100'],
            'sites.*.pic_name' => ['nullable', 'string', 'max:100'],
            'sites.*.pic_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
