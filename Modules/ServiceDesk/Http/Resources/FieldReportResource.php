<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FieldReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'ticket_id' => $this->ticket_id,
            'ticket_code' => $this->whenLoaded('ticket', fn () => $this->ticket?->code),
            'report_date' => $this->report_date?->toDateString(),
            'technician_employee_id' => $this->technician_employee_id,
            'technician_name' => $this->whenLoaded('technician', fn () => $this->technician?->name),
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->name),
            // The bon the acknowledgement posted — the proof the parts left stock.
            'issue_id' => $this->whenLoaded('issue', fn () => $this->issue?->id),
            'issue_code' => $this->whenLoaded('issue', fn () => $this->issue?->code),
            'findings' => $this->findings,
            'actions_taken' => $this->actions_taken,
            'recommendations' => $this->recommendations,
            'customer_sign_name' => $this->customer_sign_name,
            'customer_signed_at' => $this->customer_signed_at?->toIso8601String(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'parts' => FieldReportPartResource::collection($this->whenLoaded('parts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
