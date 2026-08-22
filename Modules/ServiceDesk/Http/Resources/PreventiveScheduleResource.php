<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreventiveScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_contract_id' => $this->service_contract_id,
            'service_contract_code' => $this->whenLoaded('contract', fn () => $this->contract?->code),
            'site_id' => $this->site_id,
            'site' => ContractSiteResource::make($this->whenLoaded('site')),
            'name' => $this->name,
            'frequency' => $this->frequency?->value,
            'frequency_label' => $this->frequency?->label(),
            'next_due_date' => $this->next_due_date?->toDateString(),
            'assigned_to' => $this->assigned_to,
            'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'checklist' => $this->checklist ?? [],
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
