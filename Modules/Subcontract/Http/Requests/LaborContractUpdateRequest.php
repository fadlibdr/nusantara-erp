<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\LaborPphScheme;

class LaborContractUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['sometimes', 'integer'],
            'project_id' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'string', 'max:200'],
            'pph_scheme' => ['sometimes', Rule::enum(LaborPphScheme::class)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.wbs_code' => ['nullable', 'string', 'max:20'],
            'items.*.description' => ['required_without:items.*.boq_item_id', 'nullable', 'string', 'max:500'],
            'items.*.qty' => ['required_without:items.*.boq_item_id', 'nullable', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_rate' => ['required_with:items', 'numeric', 'min:0.01'],
        ];
    }
}
