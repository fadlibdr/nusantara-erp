<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quality\Enums\ItemResult;
use Modules\Quality\Enums\WitnessParty;

/**
 * Per-field shape; project/location/IPP coherence and the results-belong-to-
 * template rule live in InspectionService. `passed` is never accepted — it is
 * derived from the result rows.
 */
class InspectionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'ipp_id' => ['nullable', 'integer', Rule::exists('eng_work_permits_ipp', 'id')],
            'location_id' => ['required', 'integer', Rule::exists('core_locations', 'id')],
            'template_id' => ['required', 'integer', Rule::exists('qc_inspection_templates', 'id')],
            'inspected_at' => ['required', 'string', 'date'],
            'inspector_employee_id' => ['nullable', 'integer', Rule::exists('hr_employees', 'id')],
            'witness_party' => ['nullable', Rule::enum(WitnessParty::class)],
            'results' => ['nullable', 'array'],
            'results.*.template_item_id' => ['required', 'integer'],
            'results.*.result' => ['required', Rule::enum(ItemResult::class)],
            'results.*.remark' => ['nullable', 'string', 'max:300'],
        ];
    }
}
