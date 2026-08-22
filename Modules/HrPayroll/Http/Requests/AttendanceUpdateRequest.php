<?php

namespace Modules\HrPayroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HrPayroll\Enums\AttendanceStatus;

/**
 * employee_id and date stay immovable — together they ARE the row's identity
 * (the unique key the bulk upsert lands on). Moving a record to another day is
 * a delete plus a new entry, each visible in the register on its own date.
 */
class AttendanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
