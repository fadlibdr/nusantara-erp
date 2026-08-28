<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\WorkShift;

class WorkPermitStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Per-field shape only. The rules that COMPARE — valid_from < valid_until,
     * permit_date inside the project window — live in WorkPermitService, one
     * implementation for store and update (the DailyReportService split).
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'wbs_task_id' => ['nullable', 'integer', 'min:1'],
            // 'string' before 'date': a JSON number would survive 'date' and be
            // cast as a UNIX timestamp (the DailyReportStoreRequest lesson).
            'permit_date' => ['required', 'string', 'date'],
            'shift' => ['required', Rule::enum(WorkShift::class)],
            'work_description' => ['required', 'string', 'max:2000'],
            'hazard_notes' => ['nullable', 'string', 'max:2000'],
            'ppe_required' => ['nullable', 'array'],
            'ppe_required.*' => ['string', 'max:100'],
            'valid_from' => ['required', 'string', 'date'],
            'valid_until' => ['required', 'string', 'date'],
            'requested_by' => ['required', 'integer', Rule::exists('hr_employees', 'id')],
            'safety_officer_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
        ];
    }
}
