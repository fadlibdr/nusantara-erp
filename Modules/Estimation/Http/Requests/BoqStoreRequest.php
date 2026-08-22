<?php

namespace Modules\Estimation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoqStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            // Cross-module references — no exists rule to keep modules decoupled.
            'project_id' => ['nullable', 'integer', 'min:1'],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            'contract_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'sections' => ['sometimes', 'array'],
            'sections.*.section_no' => ['required', 'string', 'max:10'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.items' => ['sometimes', 'array'],
            'sections.*.items.*.wbs_code' => ['required', 'string', 'max:20'],
            'sections.*.items.*.description' => ['nullable', 'string', 'max:500', 'required_without:sections.*.items.*.ahsp_id'],
            'sections.*.items.*.ahsp_id' => ['nullable', 'integer', Rule::exists('est_ahsp', 'id')->whereNull('deleted_at')],
            'sections.*.items.*.qty' => ['required', 'numeric', 'min:0'],
            'sections.*.items.*.unit' => ['nullable', 'string', 'max:20', 'required_without:sections.*.items.*.ahsp_id'],
            'sections.*.items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'required_without:sections.*.items.*.ahsp_id'],
            'sections.*.items.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
