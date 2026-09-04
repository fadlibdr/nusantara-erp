<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\ApprovalLevels;

class AwardDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'rfq_id' => $this->rfq_id,
            'rfq_code' => $this->whenLoaded('rfq', fn () => $this->rfq?->code),
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'rab_amount' => $this->rab_amount,
            'awarded_amount' => $this->awarded_amount,
            'deviation_amount' => $this->deviation_amount,
            'deviation_reason' => $this->deviation_reason,
            'committee' => $this->committee ?? [],
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Berapa penyetuju berbeda yang diperlukan vs yang sudah masuk —
            // supaya layar tahu jenjang masih menunggu tingkat berikutnya.
            'required_levels' => $this->requiredApprovalLevels(),
            'approvals_given' => ApprovalLevels::distinctApprovals($this->resource),
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
