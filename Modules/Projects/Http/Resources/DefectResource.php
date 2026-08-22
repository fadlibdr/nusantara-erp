<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DefectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            // `when`, not `whenLoaded`: a relation that is loaded but empty must
            // drop out of the payload entirely. Emitting it as null leaves the
            // key behind, and the detail screen renders that as a second, blank
            // row beside the real field — the problem SafetyIncidentResource
            // documents.
            'project' => $this->when(
                $this->relationLoaded('project') && $this->project !== null,
                fn (): array => [
                    'id' => $this->project->id,
                    'code' => $this->project->code,
                    'name' => $this->project->name,
                ],
            ),
            'wbs_task_id' => $this->wbs_task_id,
            'wbs_task' => $this->when(
                $this->relationLoaded('wbsTask') && $this->wbsTask !== null,
                fn (): array => [
                    'id' => $this->wbsTask->id,
                    'wbs_code' => $this->wbsTask->wbs_code,
                    'name' => $this->wbsTask->name,
                ],
            ),
            'subcontract_id' => $this->subcontract_id,
            'location' => $this->location,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity?->value,
            'severity_label' => $this->severity?->label(),
            'blocks_handover' => $this->severity?->blocksHandover(),
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_open' => $this->status?->isOpen(),
            'is_overdue' => $this->isOverdue(),
            'days_open' => $this->daysOpen(),
            'reported_on' => $this->reported_on?->toDateString(),
            'reported_by' => $this->reported_by,
            'responsible_employee_id' => $this->responsible_employee_id,
            'responsible_employee' => $this->when(
                $this->relationLoaded('responsible') && $this->responsible !== null,
                fn (): array => [
                    'id' => $this->responsible->id,
                    'code' => $this->responsible->code,
                    'name' => $this->responsible->name,
                ],
            ),
            'due_date' => $this->due_date?->toDateString(),
            'fixed_at' => $this->fixed_at?->toDateString(),
            'verified_at' => $this->verified_at?->toDateString(),
            'verified_by' => $this->verified_by,
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
