<?php

namespace Modules\Estimation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CostBudgetGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional override; when omitted the RAP's stored target margin is used.
            'target_margin_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
