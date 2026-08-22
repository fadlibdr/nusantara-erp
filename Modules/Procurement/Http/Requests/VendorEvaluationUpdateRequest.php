<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorEvaluationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['sometimes', 'integer', Rule::exists('prc_vendors', 'id')],
            'project_id' => ['nullable', 'integer'],
            'evaluated_by' => ['nullable', 'integer'],
            'period' => ['sometimes', 'string', 'max:20'],
            'quality_score' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'delivery_score' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'price_score' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'service_score' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
