<?php

namespace Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RkkDocumentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tender_package_id' => ['required', 'integer', Rule::exists('crm_tender_packages', 'id')->whereNull('deleted_at')],
            // Lintas modul, jadi hanya keberadaannya yang diperiksa di sini;
            // aturan "baris IBPRP harus milik proyek ini" ada di RkkService,
            // yang membacanya mentah karena Crm tidak bergantung ke Projects.
            'project_id' => ['nullable', 'integer', Rule::exists('prj_projects', 'id')->whereNull('deleted_at')],
            'boq_id' => ['nullable', 'integer', Rule::exists('est_boqs', 'id')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:250'],
            'policy' => ['nullable', 'string'],
            'program' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
