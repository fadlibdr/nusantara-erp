<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectSource;
use Modules\Projects\Models\Defect;

class DefectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'wbs_task_id' => ['sometimes', 'nullable', 'integer', $this->wbsTaskBelongsToThisProject()],
            'subcontract_id' => ['sometimes', 'nullable', 'integer', Rule::exists('scm_subcontracts', 'id')],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'severity' => ['sometimes', 'required', Rule::enum(DefectSeverity::class)],
            'source' => ['sometimes', 'required', Rule::enum(DefectSource::class)],
            'reported_on' => ['sometimes', 'required', 'date'],
            'responsible_employee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'resolution_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Read only when severity moves OUT of critical/mayor on an open
            // item — that edit clears the BAST II hard block, so DefectService
            // demands prj.approve plus this written reason.
            'downgrade_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'wbs_task_id.exists' => 'Item WBS yang dipilih bukan milik proyek ini.',
        ];
    }

    /**
     * Resolved against the defect's OWN project — the project cannot be moved by
     * an update, so the route model is the only trustworthy source here.
     */
    private function wbsTaskBelongsToThisProject(): object
    {
        $defect = $this->route('defect');
        $projectId = $defect instanceof Defect ? $defect->project_id : null;

        return Rule::exists('prj_wbs_tasks', 'id')->where('project_id', $projectId);
    }
}
