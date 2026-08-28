<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quality\Enums\InspectionStage;

class InspectionTemplateUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('template')?->id;

        return [
            'code' => ['required', 'string', 'max:20',
                Rule::unique('qc_inspection_templates', 'code')->ignore($id)->whereNull('deleted_at')],
            'work_package' => ['required', 'string', 'max:150'],
            'stage' => ['required', Rule::enum(InspectionStage::class)],
            'items' => ['sometimes', 'array'],
            'items.*.check_text' => ['required', 'string', 'max:300'],
            'items.*.acceptance' => ['required', 'string', 'max:300'],
            'items.*.tolerance' => ['nullable', 'string', 'max:120'],
        ];
    }
}
