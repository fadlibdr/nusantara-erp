<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceDesk\Enums\TicketCategory;
use Modules\ServiceDesk\Enums\TicketPriority;

class TicketUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'service_contract_id' => ['sometimes', 'nullable', 'integer', Rule::exists('svc_contracts', 'id')],
            'customer_id' => ['sometimes', 'required', 'integer', Rule::exists('crm_customers', 'id')],
            'site_id' => ['sometimes', 'nullable', 'integer', Rule::exists('svc_contract_sites', 'id')],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['sometimes', 'required', Rule::enum(TicketCategory::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TicketPriority::class)],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(['phone', 'email', 'wa', 'portal', 'system'])],
            'reported_by_name' => ['nullable', 'string', 'max:100'],
            'reported_at' => ['sometimes', 'required', 'date'],
        ];
    }
}
