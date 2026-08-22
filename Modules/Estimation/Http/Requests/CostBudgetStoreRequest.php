<?php

namespace Modules\Estimation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CostBudgetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'boq_id' => ['required', 'integer', Rule::exists('est_boqs', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'target_margin_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
