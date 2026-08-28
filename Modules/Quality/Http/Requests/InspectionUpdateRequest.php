<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quality\Enums\ItemResult;
use Modules\Quality\Enums\WitnessParty;

/**
 * project_id and template_id are fixed at creation (the service reads them off
 * the route model), so they are not writable here.
 */
class InspectionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ipp_id' => ['nullable', 'integer', Rule::exists('eng_work_permits_ipp', 'id')],
            'location_id' => ['required', 'integer', Rule::exists('core_locations', 'id')],
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
