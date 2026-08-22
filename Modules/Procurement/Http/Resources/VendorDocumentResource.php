<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor' => VendorResource::make($this->whenLoaded('vendor')),
            'doc_type' => $this->doc_type?->value,
            'doc_type_label' => $this->doc_type?->label(),
            'name' => $this->name,
            'number' => $this->number,
            'issuer' => $this->issuer,
            'issued_date' => $this->issued_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'is_mandatory' => (bool) $this->is_mandatory,
            'is_expired' => $this->isExpired(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
