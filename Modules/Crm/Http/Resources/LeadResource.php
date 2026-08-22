<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'source' => $this->source,
            'phone' => $this->phone,
            'email' => $this->email,
            'need_summary' => $this->need_summary,
            'estimated_value' => $this->estimated_value,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'user_id' => $this->user_id,
            'owner_name' => $this->whenLoaded('owner', fn () => $this->owner?->name),
            'next_follow_up_at' => $this->next_follow_up_at?->toDateString(),
            // Non-null berarti sudah dikonversi — tombol "Jadikan Pelanggan"
            // menyembunyikan diri berdasarkan field ini.
            'customer_id' => $this->customer_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
