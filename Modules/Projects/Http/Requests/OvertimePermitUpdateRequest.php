<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OvertimePermitUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'overtime_date' => ['sometimes', 'string', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'reason' => ['sometimes', 'string', 'max:2000'],
            // 'sometimes': an update that does not send workers keeps the
            // stored rows; one that does replaces them wholesale.
            'workers' => ['sometimes', 'array', 'min:1'],
            'workers.*.employee_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'workers.*.worker_name' => ['nullable', 'string', 'max:150'],
            'workers.*.hours' => ['required_with:workers', 'numeric', 'gt:0', 'max:24'],
        ];
    }
}
