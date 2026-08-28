<?php

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NcrResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'inspection_id' => $this->inspection_id,
            'inspection_code' => $this->whenLoaded('inspection', fn () => $this->inspection?->code),
            'location_id' => $this->location_id,
            'location_path' => $this->whenLoaded('location', fn () => $this->location?->path()),
            'stage' => $this->stage?->value,
            'stage_label' => $this->stage?->label(),
            'description' => $this->description,
            'root_cause' => $this->root_cause,
            'corrective_action' => $this->corrective_action,
            'preventive_action' => $this->preventive_action,
            'responsible_employee_id' => $this->responsible_employee_id,
            'responsible_name' => $this->whenLoaded('responsibleEmployee', fn () => $this->responsibleEmployee?->name),
            'subcontract_id' => $this->subcontract_id,
            'subcontract_code' => $this->whenLoaded('subcontract', fn () => $this->subcontract?->code),
            'due_date' => $this->due_date?->toDateString(),
            'verified_by' => $this->verified_by,
            'verified_by_name' => $this->whenLoaded('verifier', fn () => $this->verifier?->name),
            'verified_at' => $this->verified_at?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_open' => $this->status?->isOpen(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
