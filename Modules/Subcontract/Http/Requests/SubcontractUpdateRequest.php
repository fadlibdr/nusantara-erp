<?php

namespace Modules\Subcontract\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subcontract\Enums\PphConstructionScheme;

class SubcontractUpdateRequest extends FormRequest
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
            'scope' => ['nullable', 'string'],
            'retention_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'pph_scheme' => ['sometimes', Rule::enum(PphConstructionScheme::class)],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Akhir masa pemeliharaan — the retention time gate reads this.
            // No after_or_equal:start_date here: an update may carry this field
            // alone, and the rule would then compare against the literal string
            // 'start_date' and refuse every date.
            'defect_liability_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'], // lines are replaced wholesale
            'items.*.boq_item_id' => ['nullable', 'integer'],
            'items.*.wbs_code' => ['nullable', 'string', 'max:20'],
            'items.*.description' => ['required_without:items.*.boq_item_id', 'nullable', 'string', 'max:500'],
            'items.*.qty' => ['required_without:items.*.boq_item_id', 'nullable', 'numeric', 'min:0.001'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
