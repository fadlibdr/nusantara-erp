<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BaselineUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    /**
     * Header fields only. Whether the baseline may be edited at all is a
     * question about its status, so BaselineService answers it — a FormRequest
     * that refused an approved baseline would return a validation error where
     * the honest answer is "this document is frozen".
     */
    public function rules(): array
    {
        return [
            'effective_date' => ['sometimes', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reference_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bac_override' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_date.date' => 'Tanggal berlaku tidak valid.',
            'reason.max' => 'Alasan maksimal 2.000 karakter.',
            'reference_no.max' => 'Nomor dokumen acuan maksimal 60 karakter.',
            'bac_override.numeric' => 'BAC harus berupa angka.',
            'bac_override.gt' => 'BAC harus lebih besar dari nol.',
            'notes.max' => 'Catatan maksimal 2.000 karakter.',
        ];
    }
}
