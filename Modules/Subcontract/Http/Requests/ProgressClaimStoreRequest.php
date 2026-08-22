<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProgressClaimStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'subcontract_id' => ['required', 'integer', Rule::exists('scm_subcontracts', 'id')],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.subcontract_item_id' => ['required', 'integer', Rule::exists('scm_subcontract_items', 'id')],
            'items.*.current_progress_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
