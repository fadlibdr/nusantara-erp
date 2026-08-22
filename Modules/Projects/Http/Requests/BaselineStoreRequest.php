<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BaselineStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('prj_projects', 'id')],
            'effective_date' => ['required', 'date'],
            // Nullable HERE and mandatory in BaselineService for revision > 0.
            // Only the service knows the revision number, and a FormRequest that
            // guessed it would refuse the very first baseline of a project.
            'reason' => ['nullable', 'string', 'max:2000'],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'bac_override' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => 'Proyek wajib dipilih.',
            'project_id.exists' => 'Proyek tidak ditemukan.',
            'effective_date.required' => 'Tanggal berlaku baseline wajib diisi — biasanya tanggal penandatanganan kontrak.',
            'effective_date.date' => 'Tanggal berlaku tidak valid.',
            'reason.max' => 'Alasan maksimal 2.000 karakter.',
            'reference_no.max' => 'Nomor dokumen acuan maksimal 60 karakter.',
            'bac_override.numeric' => 'BAC harus berupa angka.',
            'bac_override.gt' => 'BAC harus lebih besar dari nol.',
            'notes.max' => 'Catatan maksimal 2.000 karakter.',
        ];
    }
}
