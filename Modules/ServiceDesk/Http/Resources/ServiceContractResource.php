<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'contract_id' => $this->contract_id,
            'contract_code' => $this->whenLoaded('crmContract', fn () => $this->crmContract?->code),
            'name' => $this->name,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'contract_value' => $this->contract_value,
            'billing_cycle' => $this->billing_cycle?->value,
            'billing_cycle_label' => $this->billing_cycle?->label(),
            'billing_amount_per_period' => $this->billingAmountPerPeriod(),
            'sla_response_hours' => (int) $this->sla_response_hours,
            'sla_resolution_hours' => (int) $this->sla_resolution_hours,
            'coverage' => $this->coverage,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'sites' => ContractSiteResource::collection($this->whenLoaded('sites')),
            'preventive_schedules' => PreventiveScheduleResource::collection($this->whenLoaded('preventiveSchedules')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
