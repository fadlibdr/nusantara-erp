<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManpowerAssignmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'role_on_project' => ['sometimes', 'string', 'max:100'],
            'assigned_from' => ['sometimes', 'date'],
            'assigned_until' => ['nullable', 'date', 'after_or_equal:assigned_from'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
