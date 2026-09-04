<?php

namespace Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Terbilang;

class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'quotation_id' => $this->quotation_id,
            'quotation_code' => $this->whenLoaded('quotation', fn () => $this->quotation?->code),
            'contract_number_customer' => $this->contract_number_customer,
            'title' => $this->title,
            'scope_type' => $this->scope_type?->value,
            'scope_type_label' => $this->scope_type?->label(),
            'value' => $this->value,
            'ppn_rate' => $this->ppn_rate,
            'ppn_amount' => $this->ppn_amount,
            'total_with_ppn' => $this->total_with_ppn,
            // T3.6: why value differs from the linked quotation's DPP; null
            // when it does not (or there is no quotation) — never "unasked".
            'value_change_reason' => $this->value_change_reason,
            'total_terbilang' => Terbilang::rupiah((float) $this->total_with_ppn),
            'sign_date' => $this->sign_date?->toDateString(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'retention_pct' => $this->retention_pct,
            'warranty_months' => (int) $this->warranty_months,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'termins' => ContractTerminResource::collection($this->whenLoaded('termins')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
