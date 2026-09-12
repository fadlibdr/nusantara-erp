<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Rules\ValidNpwp;
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
            // P-3b (V3-5): pintu ke-8 yang menulis NOMOR NPWP. Jenis "NPWP" membawa
            // aturan NPWP yang sama dengan kolom npwp vendor; jenis lain (SIUP, SBU,
            // akta, …) bebas bentuknya — nomor apa adanya dari berkas pindaian.
            'number' => ['nullable', 'string', 'max:100', ...self::npwpRuleFor($this->input('doc_type'))],
            'issuer' => ['nullable', 'string', 'max:160'],
            'issued_date' => ['nullable', 'date'],
            // Kosong = tidak kedaluwarsa (NPWP); bukan default diam-diam.
            'valid_until' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return list<ValidNpwp>
     */
    public static function npwpRuleFor(mixed $docType, ?string $unchanged = null): array
    {
        if ($docType !== VendorDocumentType::Npwp->value && $docType !== VendorDocumentType::Npwp) {
            return [];
        }

        return [$unchanged === null ? new ValidNpwp : ValidNpwp::unlessUnchanged($unchanged)];
    }
}
