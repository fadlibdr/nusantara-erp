<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceDesk\Enums\TicketCategory;
use Modules\ServiceDesk\Enums\TicketPriority;

class TicketStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'service_contract_id' => ['nullable', 'integer', Rule::exists('svc_contracts', 'id')],
            // Without a contract the reporter must name the customer directly.
            'customer_id' => ['required_without:service_contract_id', 'nullable', 'integer', Rule::exists('crm_customers', 'id')],
            'site_id' => ['nullable', 'integer', Rule::exists('svc_contract_sites', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'channel' => ['nullable', 'string', Rule::in(['phone', 'email', 'wa', 'portal', 'system'])],
            'reported_by_name' => ['nullable', 'string', 'max:100'],
            'reported_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
        ];
    }
}
