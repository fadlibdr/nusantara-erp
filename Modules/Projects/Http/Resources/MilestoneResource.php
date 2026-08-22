<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'due_date' => $this->due_date?->toDateString(),
            'achieved_date' => $this->achieved_date?->toDateString(),
            'is_achieved' => $this->isAchieved(),
            'is_overdue' => $this->isOverdue(),
            'termin_id' => $this->termin_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
