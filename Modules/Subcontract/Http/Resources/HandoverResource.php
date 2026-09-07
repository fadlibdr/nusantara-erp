<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;

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
            // F-1 — satu perender jejak untuk 25 resource (Core\Http\Resources\
            // ApprovalTrail), supaya "Budi a.n. Sari" muncul di semuanya dan
            // bukan di dua puluh empat di antaranya.
            'approvals' => $this->whenLoaded('approvals', fn (): array => ApprovalTrail::map($this->approvals)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
