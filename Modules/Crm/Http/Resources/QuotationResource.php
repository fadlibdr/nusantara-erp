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
