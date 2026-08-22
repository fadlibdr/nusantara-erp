<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectSource;

class DefectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // DELIBERATELY NO PROJECT-STATUS RESTRICTION. Masa pemeliharaan runs
            // after BAST I, and approving BAST II closes the project today, so a
            // warranty claim arriving afterwards must still have somewhere to land.
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'wbs_task_id' => ['nullable', 'integer', $this->wbsTaskBelongsToThisProject()],
            'subcontract_id' => ['nullable', 'integer', Rule::exists('scm_subcontracts', 'id')],
            'location' => ['nullable', 'string', 'max:150'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['required', Rule::enum(DefectSeverity::class)],
            'source' => ['required', Rule::enum(DefectSource::class)],
            'reported_on' => ['nullable', 'date'],
            'responsible_employee_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'due_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'wbs_task_id.exists' => 'Item WBS yang dipilih bukan milik proyek ini.',
        ];
    }

    /**
     * A defect may only hang off ITS OWN project's WBS.
     *
     * Copied from MilestoneStoreRequest::terminBelongsToThisProject, and for the
     * same reason: an unchecked foreign key across a boundary means a punch item
     * on one job can name a work package on another, and every count that reads
     * (project_id, wbs_task_id) — including the BAST II gate — then answers about
     * the wrong site.
     */
    private function wbsTaskBelongsToThisProject(): object
    {
        // A missing project leaves this null, which Rule::exists turns into
        // whereNull('project_id') and matches nothing. That is the right answer.
        return Rule::exists('prj_wbs_tasks', 'id')->where('project_id', $this->integer('project_id'));
    }
}
