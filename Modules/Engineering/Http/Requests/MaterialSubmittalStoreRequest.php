<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\ReviewerParty;

class MaterialSubmittalStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'item_id' => ['nullable', 'integer', Rule::exists('inv_items', 'id')],
            'material_name' => ['required', 'string', 'max:200'],
            'brand' => ['nullable', 'string', 'max:150'],
            'spec_reference' => ['nullable', 'string', 'max:200'],
            'sample_attached' => ['nullable', 'boolean'],
            'submitted_at' => ['required', 'string', 'date'],
            'reviewer_party' => ['required', Rule::enum(ReviewerParty::class)],
        ];
    }
}
