<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\VendorDocumentType;

class VendorDocumentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('prc_vendors', 'id')],
            'doc_type' => ['required', Rule::enum(VendorDocumentType::class)],
            'name' => ['required', 'string', 'max:160'],
            'number' => ['nullable', 'string', 'max:100'],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issued_date' => ['nullable', 'date'],
            // Kosong = tidak kedaluwarsa (NPWP); bukan default diam-diam.
            'valid_until' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
