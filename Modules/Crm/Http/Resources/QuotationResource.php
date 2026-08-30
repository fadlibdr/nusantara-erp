<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'lead_id' => $this->lead_id,
            'title' => $this->title,
            'scope_type' => $this->scope_type?->value,
            'scope_type_label' => $this->scope_type?->label(),
            /*
             * P7 — "Metode Pelaksanaan". Kunci id-nya WAJIB pulang: SPA
             * menyemai form edit dari endpoint show dan mengirim kembali setiap
             * field yang terlihat, jadi rujukan yang tidak pernah terbaca akan
             * terkirim sebagai null dan TERHAPUS oleh penyimpanan yang tidak
             * menyentuhnya sama sekali.
             *
             * Kode dan judulnya diratakan mengikuti idiom Resource modul lain
             * (drawing_number, ipp_code, location_path): pemilih di layar butuh
             * LABEL, dan sebuah id telanjang memaksa satu tembakan kedua ke
             * pustaka metode hanya untuk menuliskan namanya.
             */
            'method_library_id' => $this->method_library_id,
            'method_library_code' => $this->whenLoaded(
                'methodLibraryEntry', fn () => $this->methodLibraryEntry?->code),
            'method_library_title' => $this->whenLoaded(
                'methodLibraryEntry', fn () => $this->methodLibraryEntry?->title),
            'valid_until' => $this->valid_until?->toDateString(),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'dpp' => $this->dpp,
            'ppn_rate' => $this->ppn_rate,
            'ppn_amount' => $this->ppn_amount,
            'total' => $this->total,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'revision' => (int) $this->revision,
            'won_at' => $this->won_at?->toIso8601String(),
            'lost_at' => $this->lost_at?->toIso8601String(),
            'lost_reason' => $this->lost_reason,
            'notes' => $this->notes,
            'items' => QuotationItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
