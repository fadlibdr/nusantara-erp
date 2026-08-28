<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add one break to an existing sample; `pass` is computed, never accepted. */
class ConcreteTestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'age_days' => ['required', 'integer', Rule::in([7, 14, 28])],
            'strength_mpa' => ['required', 'numeric', 'min:0', 'max:200'],
            'lab' => ['nullable', 'string', 'max:120'],
            'tested_at' => ['nullable', 'string', 'date'],
        ];
    }
}
