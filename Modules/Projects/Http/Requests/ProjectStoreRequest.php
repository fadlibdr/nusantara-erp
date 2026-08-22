<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\ProjectType;

class ProjectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // Cross-module ids validated as integers only, keeping modules decoupled.
            'contract_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'boq_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required_without:contract_id', 'string', 'max:255'],
            'type' => ['required_without:contract_id', Rule::enum(ProjectType::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            // Konsultan MK / pengawas: the fourth box on every printed house
            // form. Nullable because plenty of jobs have none, and an empty box
            // is what the paper shows in that case.
            'consultant_name' => ['nullable', 'string', 'max:255'],
            'consultant_role' => ['nullable', 'string', 'max:60'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'actual_start_date' => ['nullable', 'date'],
            'contract_value' => ['nullable', 'numeric', 'min:0'],
            'retention_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'project_manager_id' => ['nullable', 'integer', 'min:1'],
            'site_manager_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
