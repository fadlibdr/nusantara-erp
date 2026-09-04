<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'bast_type' => $this->bast_type?->value,
            'bast_type_label' => $this->bast_type?->label(),
            'handover_date' => $this->handover_date?->toDateString(),
            'customer_representative' => $this->customer_representative,
            'notes' => $this->notes,
            'retention_release_due' => $this->retention_release_due?->toDateString(),
            // Stored columns only. The LIVE checklist lives on its own endpoint
            // so the BAST list does not run one evaluation — three cross-module
            // reads apiece — per row.
            'prerequisite_override_reason' => $this->prerequisite_override_reason,
            'prerequisite_override_by' => $this->prerequisite_override_by,
            'prerequisite_override_at' => $this->prerequisite_override_at?->toIso8601String(),
            'prerequisite_snapshot' => $this->prerequisite_snapshot,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
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
