<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The grade string is validated for shape here (K-xxx / fc'-xx) and parsed for
 * real by ConcreteStrengthService, which owns the target computation. `pass` on
 * each test is never accepted — it is computed.
 */
class ConcreteSampleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'location_id' => ['required', 'integer', Rule::exists('core_locations', 'id')],
            'pour_date' => ['required', 'string', 'date'],
            'grade' => ['required', 'string', 'max:20'],
            'slump_cm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'truck_no' => ['nullable', 'string', 'max:30'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'],
            'sample_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'tests' => ['nullable', 'array'],
            'tests.*.age_days' => ['required', 'integer', Rule::in([7, 14, 28])],
            'tests.*.strength_mpa' => ['required', 'numeric', 'min:0', 'max:200'],
            'tests.*.lab' => ['nullable', 'string', 'max:120'],
            'tests.*.tested_at' => ['nullable', 'string', 'date'],
        ];
    }
}
