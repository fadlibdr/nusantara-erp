<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SafetyIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project' => $this->when(
                $this->relationLoaded('project') && $this->project !== null,
                fn () => [
                    'id' => $this->project->id,
                    'code' => $this->project->code,
                    'name' => $this->project->name,
                ],
            ),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'location' => $this->location,
            'severity' => $this->severity?->value,
            'severity_label' => $this->severity?->label(),
            'is_recordable' => $this->severity?->isRecordable(),
            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            'description' => $this->description,
            'people_involved' => $this->people_involved,
            'lost_days' => $this->lost_days,
            'immediate_action' => $this->immediate_action,
            'root_cause' => $this->root_cause,
            'corrective_action' => $this->corrective_action,
            'responsible_employee_id' => $this->responsible_employee_id,
            // `when`, not `whenLoaded`: a relation that is loaded but empty must
            // drop out of the payload entirely. Emitting it as null still leaves
            // the key, and the detail screen renders that as a second, blank
            // "Penanggung jawab" row beside the real one.
            'responsible_employee' => $this->when(
                $this->relationLoaded('responsible') && $this->responsible !== null,
                fn () => [
                    'id' => $this->responsible->id,
                    'code' => $this->responsible->code,
                    'name' => $this->responsible->name,
                ],
            ),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_overdue' => $this->isOverdue(),
            'closed_at' => $this->closed_at?->toDateString(),
            'is_reportable' => $this->is_reportable,
            'reported_to_authority_at' => $this->reported_to_authority_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
