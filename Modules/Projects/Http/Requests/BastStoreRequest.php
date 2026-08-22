<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Enums\BastType;

class BastStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'bast_type' => ['required', Rule::enum(BastType::class)],
            'handover_date' => ['required', 'date'],
            'customer_representative' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'retention_release_due' => ['nullable', 'date', 'after_or_equal:handover_date'],
        ];
    }
}
