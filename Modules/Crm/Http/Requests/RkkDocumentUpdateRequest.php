<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RkkDocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // tender_package_id absent: a RKK belongs to the tender it was written
        // for.
        return [
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')->whereNull('deleted_at')],
            'boq_id' => ['nullable', 'integer', Rule::exists('est_boqs', 'id')->whereNull('deleted_at')],
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'policy' => ['nullable', 'string'],
            'program' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
