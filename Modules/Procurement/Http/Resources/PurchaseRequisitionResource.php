<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'warehouse_id' => $this->warehouse_id,
            'requested_by' => $this->requested_by,
            'requester_name' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'needed_date' => $this->needed_date?->toDateString(),
            'purpose' => $this->purpose,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => PurchaseRequisitionItemResource::collection($this->whenLoaded('items')),
            // Jejak persetujuan, bentuk yang sama dengan PaymentResource (id, action,
            // note, created_at ISO-8601, user {id, name} atau null) supaya satu perender
            // di SPA (approvalTimeline) melayani semua dokumen. Diukur 4 Sep 2026
            // (HASIL-UJI P-4): hanya 5 dari 28 show() memuat approvals, halaman PR
            // tanpa kartu Riwayat Persetujuan, strip status "Diajukan · menunggu
            // persetujuan." tanpa nama dan tanggal — padahal barisnya ada di
            // core_approvals. Nama penyetuju dan tanggalnya adalah inti maker-checker;
            // ia harus tampak di tempat orang mencarinya, bukan di basis data (T3.3).
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
