<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Crm\Enums\LeadStatus;

class LeadStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:40', Rule::unique('crm_leads', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'need_summary' => ['nullable', 'string', 'max:1000'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
