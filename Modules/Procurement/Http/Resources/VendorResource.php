<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Enums\VendorStatus;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'npwp' => $this->npwp,
            'is_pkp' => (bool) $this->is_pkp,
            'sppkp_number' => $this->sppkp_number,
            'is_subcontractor' => (bool) $this->is_subcontractor,
            'vendor_type' => $this->vendor_type?->value,
            'vendor_type_label' => $this->vendor_type?->label(),
            'classification' => $this->classification?->value,
            'classification_label' => $this->classification?->label(),
            'address' => $this->address,
            'city' => $this->city,
            'phone' => $this->phone,
            'email' => $this->email,
            'pic_name' => $this->pic_name,
            'bank_name' => $this->bank_name,
            'bank_account_no' => $this->bank_account_no,
            'bank_account_name' => $this->bank_account_name,
            'payment_term_days' => (int) $this->payment_term_days,
            'rating' => $this->rating,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'picker_label' => $this->pickerLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Baris kedua pada picker vendor (temuan #68/#69): rating dan bendera
     * masalah harus terlihat SAAT memilih, bukan sesudah PO terlanjur dibuat —
     * pola picker_label yang sama dengan WbsTaskResource.
     *
     * Hitungan dokumen wajib kedaluwarsa datang dari withCount di
     * VendorController::index (satu subquery, bukan N+1); pada respons yang
     * tidak memuatnya (show) atribut itu absen dan benderanya diam — picker
     * selalu lewat index, jadi picker selalu melihatnya.
     */
    private function pickerLabel(): string
    {
        $parts = [(string) $this->code];

        if ($this->rating !== null) {
            $parts[] = '★ '.number_format((float) $this->rating, 1, ',', '.');
        }

        if ($this->status === VendorStatus::Inactive) {
            $parts[] = 'nonaktif';
        }

        $expired = $this->resource->getAttribute('expired_mandatory_documents_count');

        if ($expired !== null && (int) $expired > 0) {
            $parts[] = 'dok. wajib kedaluwarsa';
        }

        return implode(' · ', $parts);
    }
}
