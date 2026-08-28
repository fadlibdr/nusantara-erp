<?php

namespace Modules\Engineering\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrawingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'number' => $this->number,
            'title' => $this->title,
            'discipline' => $this->discipline?->value,
            'discipline_label' => $this->discipline?->label(),
            'planned_submit_date' => $this->planned_submit_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'current_submittal_code' => $this->whenLoaded('currentSubmittal', fn () => $this->currentSubmittal?->code),
            'current_revision' => $this->whenLoaded('currentSubmittal', fn () => $this->currentSubmittal?->revision),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
