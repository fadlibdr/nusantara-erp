<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HandoverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'subcontract_id' => $this->subcontract_id,
            'subcontract_code' => $this->whenLoaded('subcontract', fn () => $this->subcontract?->code),
            'subcontract_title' => $this->whenLoaded('subcontract', fn () => $this->subcontract?->title),
            'handover_type' => $this->handover_type?->value,
            'handover_type_label' => $this->handover_type?->label(),
            'handover_date' => $this->handover_date?->toDateString(),
            'retention_release_due' => $this->retention_release_due?->toDateString(),
            'scope_notes' => $this->scope_notes,
            'handed_over_by' => $this->handed_over_by,
            'received_by' => $this->received_by,
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
