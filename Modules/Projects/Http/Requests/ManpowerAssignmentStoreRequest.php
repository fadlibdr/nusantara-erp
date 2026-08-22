<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManpowerAssignmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            // Cross-module id (hr_employees) validated as integer, keeping modules decoupled.
            'employee_id' => ['required', 'integer', 'min:1'],
            'role_on_project' => ['required', 'string', 'max:100'],
            'assigned_from' => ['required', 'date'],
            'assigned_until' => ['nullable', 'date', 'after_or_equal:assigned_from'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
