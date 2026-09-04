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
            // P8 — revisi generik: diturunkan dari stempel, bukan flag tersimpan.
            'revision' => (int) $this->revision,
            'is_current' => ! $this->isSuperseded(),
            'superseded_by_id' => $this->superseded_by_id,
            'superseded_by_code' => $this->whenLoaded('supersededBy', fn () => $this->supersededBy?->code),
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
