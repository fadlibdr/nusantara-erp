<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quality\Enums\InspectionStage;

/**
 * project_id and inspection_id are fixed at creation (the service reads them off
 * the route model), so they are not writable here.
 */
class NcrUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
