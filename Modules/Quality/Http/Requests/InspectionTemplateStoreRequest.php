<?php

namespace Modules\Quality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Enums\TemplateKind;

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
            // P6 — absen berarti 'quality' (bawaan kolom): pustaka lama dan
            // berkas impor yang tidak mengenal kolom ini tidak berubah makna.
            'jenis' => ['sometimes', Rule::enum(TemplateKind::class)],
            'items' => ['sometimes', 'array'],
            'items.*.check_text' => ['required', 'string', 'max:300'],
            'items.*.acceptance' => ['required', 'string', 'max:300'],
            'items.*.tolerance' => ['nullable', 'string', 'max:120'],
        ];
    }
}
