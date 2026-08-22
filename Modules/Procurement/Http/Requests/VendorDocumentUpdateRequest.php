<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\VendorDocumentType;

class VendorDocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        return [
            // vendor_id sengaja tidak bisa dipindah: memindahkan SBU dari satu
            // vendor ke vendor lain bukan koreksi, itu pemalsuan register.
            'doc_type' => ['sometimes', Rule::enum(VendorDocumentType::class)],
            'name' => ['sometimes', 'string', 'max:160'],
            'number' => ['nullable', 'string', 'max:100'],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issued_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
