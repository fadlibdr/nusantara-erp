<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
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
            'billing_address' => $this->billing_address,
            'city' => $this->city,
            'province' => $this->province,
            'phone' => $this->phone,
            'email' => $this->email,
            'pic_name' => $this->pic_name,
            'pic_phone' => $this->pic_phone,
            'payment_term_days' => (int) $this->payment_term_days,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
