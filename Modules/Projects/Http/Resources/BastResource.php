<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;

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
            // F-1 — satu perender jejak untuk 25 resource (Core\Http\Resources\
            // ApprovalTrail), supaya "Budi a.n. Sari" muncul di semuanya dan
            // bukan di dua puluh empat di antaranya.
            'approvals' => $this->whenLoaded('approvals', fn (): array => ApprovalTrail::map($this->approvals)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
