<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quality\Enums\InspectionStage;

/**
 * Bare-instantiable (DocumentImportTest reads rules() with no live request): no
 * $this->route()/input() here — the importer validates the assembled payload
 * against these rules directly.
 */
class InspectionTemplateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('qc_inspection_templates', 'code')->whereNull('deleted_at')],
            'work_package' => ['required', 'string', 'max:150'],
            'stage' => ['required', Rule::enum(InspectionStage::class)],
            'items' => ['sometimes', 'array'],
            'items.*.check_text' => ['required', 'string', 'max:300'],
            'items.*.acceptance' => ['required', 'string', 'max:300'],
            'items.*.tolerance' => ['nullable', 'string', 'max:120'],
        ];
    }
}
