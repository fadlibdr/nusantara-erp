<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProgressClaimUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'period_start' => ['sometimes', 'date'],
            'period_end' => ['sometimes', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string'],
            // Only a DP claim (is_advance) reads this — the service refuses it
            // on an ordinary opname, whose gross is derived from its lines.
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'items' => ['sometimes', 'array', 'min:1'], // lines are replaced wholesale
            'items.*.subcontract_item_id' => ['required', 'integer', Rule::exists('scm_subcontract_items', 'id')],
            'items.*.current_progress_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
