<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;

class BoqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'version' => $this->version,
            'project_id' => $this->project_id,
            'quotation_id' => $this->quotation_id,
            'contract_id' => $this->contract_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total' => $this->total,
            'notes' => $this->notes,
            'sections' => BoqSectionResource::collection($this->whenLoaded('sections')),
            // Jejak persetujuan, bentuk PaymentResource — satu perender di SPA
            // (approvalTimeline) untuk semua dokumen; hanya bila show() memuatnya (T3.3).
            // F-1 — satu perender jejak untuk 25 resource (Core\Http\Resources\
            // ApprovalTrail), supaya "Budi a.n. Sari" muncul di semuanya dan
            // bukan di dua puluh empat di antaranya.
            'approvals' => $this->whenLoaded('approvals', fn (): array => ApprovalTrail::map($this->approvals)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
