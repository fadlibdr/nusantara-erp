<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\WorkShift;

class WorkPermitUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'wbs_task_id' => ['nullable', 'integer', 'min:1'],
            'permit_date' => ['sometimes', 'string', 'date'],
            'shift' => ['sometimes', Rule::enum(WorkShift::class)],
            'work_description' => ['sometimes', 'string', 'max:2000'],
            'hazard_notes' => ['nullable', 'string', 'max:2000'],
            'ppe_required' => ['nullable', 'array'],
            'ppe_required.*' => ['string', 'max:100'],
            'valid_from' => ['sometimes', 'string', 'date'],
            'valid_until' => ['sometimes', 'string', 'date'],
            'requested_by' => ['sometimes', 'integer', Rule::exists('hr_employees', 'id')],
            'safety_officer_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
        ];
    }
}
