<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'service_contract_id' => $this->service_contract_id,
            'service_contract_code' => $this->whenLoaded('serviceContract', fn () => $this->serviceContract?->code),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'site_id' => $this->site_id,
            'site' => ContractSiteResource::make($this->whenLoaded('site')),
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            'priority' => $this->priority?->value,
            'priority_label' => $this->priority?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'channel' => $this->channel,
            'reported_by_name' => $this->reported_by_name,
            'reported_at' => $this->reported_at?->toIso8601String(),
            'assigned_to' => $this->assigned_to,
            'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'response_due_at' => $this->response_due_at?->toIso8601String(),
            'resolution_due_at' => $this->resolution_due_at?->toIso8601String(),
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'response_breached' => $this->responseBreached(),
            'resolution_breached' => $this->resolutionBreached(),
            'activities' => TicketActivityResource::collection($this->whenLoaded('activities')),
            'field_reports' => FieldReportResource::collection($this->whenLoaded('fieldReports')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
