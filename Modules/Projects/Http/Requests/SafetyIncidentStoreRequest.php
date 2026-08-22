<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\IncidentCategory;
use Modules\Projects\Enums\IncidentSeverity;

class SafetyIncidentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            // A date alone would throw away the shift, which is half of what a
            // safety review looks for.
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'location' => ['nullable', 'string', 'max:150'],
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'category' => ['required', Rule::enum(IncidentCategory::class)],
            'description' => ['required', 'string', 'max:5000'],
            'people_involved' => ['nullable', 'integer', 'min:0', 'max:999'],
            'lost_days' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'immediate_action' => ['nullable', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'responsible_employee_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'due_date' => ['nullable', 'date'],
            'is_reportable' => ['nullable', 'boolean'],
            'reported_to_authority_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'occurred_at.before_or_equal' => 'Waktu kejadian tidak boleh di masa depan.',
        ];
    }
}
