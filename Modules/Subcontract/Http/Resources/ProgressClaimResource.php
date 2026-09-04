<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
