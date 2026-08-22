<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\BastType;

class BastUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'bast_type' => ['sometimes', Rule::enum(BastType::class)],
            'handover_date' => ['sometimes', 'date'],
            'customer_representative' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'retention_release_due' => ['nullable', 'date'],
        ];
    }
}
