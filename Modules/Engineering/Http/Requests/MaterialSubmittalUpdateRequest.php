<?php

namespace Modules\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Engineering\Enums\ReviewerParty;

class MaterialSubmittalUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'item_id' => ['nullable', 'integer', Rule::exists('inv_items', 'id')],
            'material_name' => ['sometimes', 'string', 'max:200'],
            'brand' => ['nullable', 'string', 'max:150'],
            'spec_reference' => ['nullable', 'string', 'max:200'],
            'sample_attached' => ['nullable', 'boolean'],
            'submitted_at' => ['sometimes', 'string', 'date'],
            'reviewer_party' => ['sometimes', Rule::enum(ReviewerParty::class)],
        ];
    }
}
