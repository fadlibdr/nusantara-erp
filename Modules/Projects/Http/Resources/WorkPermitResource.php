<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkPermitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'wbs_task_id' => $this->wbs_task_id,
            'permit_date' => $this->permit_date?->toDateString(),
            'shift' => $this->shift?->value,
            'shift_label' => $this->shift?->label(),
            'work_description' => $this->work_description,
            'hazard_notes' => $this->hazard_notes,
            'ppe_required' => $this->ppe_required,
            'valid_from' => $this->valid_from?->format('Y-m-d H:i'),
            'valid_until' => $this->valid_until?->format('Y-m-d H:i'),
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'safety_officer_id' => $this->safety_officer_id,
            'safety_officer_name' => $this->whenLoaded('safetyOfficer', fn () => $this->safetyOfficer?->name),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
