<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManpowerAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'employee_id' => $this->employee_id,
            'role_on_project' => $this->role_on_project,
            'assigned_from' => $this->assigned_from?->toDateString(),
            'assigned_until' => $this->assigned_until?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'is_current_today' => $this->isCurrentOn(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
