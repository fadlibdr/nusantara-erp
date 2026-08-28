<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quality\Enums\InspectionStage;

/**
 * The responsible-party XOR and the stage inheritance live in NcrService; both
 * party columns are nullable here because either may be the one left null.
 * `stage` is nullable — inherited from the inspection when one is named.
 */
class NcrStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'inspection_id' => ['nullable', 'integer', Rule::exists('qc_inspections', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('core_locations', 'id')],
            'stage' => ['nullable', Rule::enum(InspectionStage::class)],
            'description' => ['required', 'string', 'max:2000'],
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
            'preventive_action' => ['nullable', 'string', 'max:2000'],
            'responsible_employee_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'subcontract_id' => ['nullable', 'integer', Rule::exists('scm_subcontracts', 'id')],
            'due_date' => ['nullable', 'string', 'date'],
        ];
    }
}
