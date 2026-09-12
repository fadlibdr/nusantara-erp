<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Procurement\Enums\VendorDocumentType;
use Modules\Procurement\Models\VendorDocument;

class VendorDocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware guards the route
    }

    public function rules(): array
    {
        /** @var VendorDocument|null $document */
        $document = $this->route('vendorDocument');
        $storedType = $document?->doc_type;
        // Jenis yang BERLAKU sesudah PUT: yang dikirim, atau yang tersimpan.
        $type = $this->has('doc_type') ? $this->input('doc_type') : $storedType?->value;

        return [
            // vendor_id sengaja tidak bisa dipindah: memindahkan SBU dari satu
            // vendor ke vendor lain bukan koreksi, itu pemalsuan register.
            'doc_type' => ['sometimes', Rule::enum(VendorDocumentType::class)],
            'name' => ['sometimes', 'string', 'max:160'],
            // P-3b (V3-5): maju-saja HANYA bila dokumen ini sudah berjenis NPWP —
            // nomor lama yang dikirim kembali apa adanya bukan penulisan baru.
            // Mengganti jenis dokumen lain MENJADI npwp adalah penulisan NPWP
            // pertama untuk nomor itu, jadi diperiksa penuh.
            'number' => ['nullable', 'string', 'max:100', ...VendorDocumentStoreRequest::npwpRuleFor(
                $type,
                $storedType === VendorDocumentType::Npwp ? $document?->number : null,
            )],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issued_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
