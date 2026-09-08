<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;

class CostBudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'boq_id' => $this->boq_id,
            'boq_code' => $this->whenLoaded('boq', fn () => $this->boq?->code),
            'boq_total' => $this->whenLoaded('boq', fn () => $this->boq?->total),
            'project_id' => $this->project_id,
            'target_margin_pct' => $this->target_margin_pct,
            'total_budget' => $this->total_budget,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            /*
             * F-2 — revisi. `is_governing` dipancarkan sebagai FAKTA server,
             * bukan disimpulkan layar dari (status = approved && !superseded):
             * aturan "RAP mana yang mengatur proyek ini" dibaca gerbang PO/SPK,
             * layar anggaran dan registri ambang, dan sebuah salinan aturan di
             * JavaScript adalah salinan yang akan menua sendiri.
             */
            'revision' => (int) $this->revision,
            'revised_from_id' => $this->revised_from_id,
            'revision_reason' => $this->revision_reason,
            'superseded_at' => $this->superseded_at?->toDateTimeString(),
            'superseded_by_id' => $this->superseded_by_id,
            'is_governing' => $this->isGoverning(),
            'items' => CostBudgetItemResource::collection($this->whenLoaded('items')),
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
