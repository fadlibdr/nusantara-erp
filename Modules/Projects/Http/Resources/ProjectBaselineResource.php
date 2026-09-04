<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectBaselineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'revision_no' => (int) $this->revision_no,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'is_current' => $this->isCurrent(),
            'effective_date' => $this->effective_date?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'created_by' => $this->created_by,
            'reason' => $this->reason,
            'reference_type' => $this->reference_type,
            'reference_no' => $this->reference_no,
            'bac' => $this->bac,
            'bac_source' => $this->bac_source?->value,
            'bac_source_label' => $this->bac_source?->label(),
            'cost_budget_id' => $this->cost_budget_id,
            'cost_budget_code' => $this->cost_budget_code,
            'cost_budget_status' => $this->cost_budget_status,
            'contract_id' => $this->contract_id,
            'contract_code' => $this->contract_code,
            'contract_value' => $this->contract_value,
            'planned_start' => $this->planned_start?->toDateString(),
            'planned_finish' => $this->planned_finish?->toDateString(),
            'contract_finish' => $this->contract_finish?->toDateString(),
            'planned_duration_days' => (int) $this->planned_duration_days,
            'curve_source' => $this->curve_source,
            'leaf_task_count' => (int) $this->leaf_task_count,
            'leaf_weight_total' => $this->leaf_weight_total,
            'notes' => $this->notes,
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'superseded_by_id' => $this->superseded_by_id,
            'warnings' => $this->warnings(),
            // whenLoaded so the index stays cheap: PRJ-2026-001's baseline
            // carries 11 tasks and 17 curve points nobody reads in a list.
            'points' => BaselinePointResource::collection($this->whenLoaded('points')),
            'tasks' => BaselineTaskResource::collection($this->whenLoaded('tasks')),
            // Jejak persetujuan, bentuk PaymentResource — satu perender di SPA
            // (approvalTimeline) untuk semua dokumen; hanya bila show() memuatnya (T3.3).
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval): array => [
                'id' => $approval->id,
                'action' => $approval->action,
                'note' => $approval->note,
                'created_at' => $approval->created_at?->toIso8601String(),
                'user' => $approval->relationLoaded('user') && $approval->user !== null
                    ? ['id' => $approval->user->id, 'name' => $approval->user->name]
                    : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
