<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceDesk\Enums\PmFrequency;

class PreventiveScheduleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'service_contract_id' => ['sometimes', 'required', 'integer', Rule::exists('svc_contracts', 'id')],
            'site_id' => ['sometimes', 'nullable', 'integer', Rule::exists('svc_contract_sites', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'frequency' => ['sometimes', 'required', Rule::enum(PmFrequency::class)],
            'next_due_date' => ['sometimes', 'required', 'date'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
