<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;
use Modules\Core\Support\Terbilang;

class ProgressClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'subcontract_id' => $this->subcontract_id,
            'subcontract' => $this->whenLoaded('subcontract', fn () => [
                'id' => $this->subcontract->id,
                'code' => $this->subcontract->code,
                'title' => $this->subcontract->title,
                'value' => $this->subcontract->value,
                'retention_pct' => $this->subcontract->retention_pct,
                'ppn_rate' => $this->subcontract->ppn_rate,
                'pph_rate' => $this->subcontract->pph_rate,
            ]),
            'claim_no' => (int) $this->claim_no,
            'is_advance' => (bool) $this->is_advance,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'gross_amount' => $this->gross_amount,
            'retention_amount' => $this->retention_amount,
            'net_before_tax' => $this->net_before_tax,
            'ppn_amount' => $this->ppn_amount,
            'pph_amount' => $this->pph_amount,
            'advance_recovery_amount' => $this->advance_recovery_amount,
            'net_payable' => $this->net_payable,
            'net_payable_terbilang' => Terbilang::rupiah($this->net_payable ?? 0),
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => ProgressClaimItemResource::collection($this->whenLoaded('items')),
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
