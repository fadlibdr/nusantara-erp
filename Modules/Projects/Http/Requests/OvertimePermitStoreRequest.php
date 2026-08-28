<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OvertimePermitStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Shared per-line shape for store and update. The XOR (employee_id vs
     * worker_name) and the zero-length-shift refusal live in
     * OvertimePermitService, whose messages name the offending row.
     *
     * hours ≤ 24: one sheet covers one overtime_date; more than a day of hours
     * on a single line is a typo, not a shift.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function workerRules(): array
    {
        return [
            'workers' => ['required', 'array', 'min:1'],
            'workers.*.employee_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'workers.*.worker_name' => ['nullable', 'string', 'max:150'],
            'workers.*.hours' => ['required', 'numeric', 'gt:0', 'max:24'],
        ];
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'overtime_date' => ['required', 'string', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:2000'],
            ...self::workerRules(),
        ];
    }
}
