<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'vendor_id' => $this->vendor_id,
            'vendor' => VendorResource::make($this->whenLoaded('vendor')),
            'purchase_requisition_id' => $this->purchase_requisition_id,
            'purchase_requisition' => PurchaseRequisitionResource::make($this->whenLoaded('purchaseRequisition')),
            // Jejak lembar banding: PO ini membawa harga pemenang RFQ tersebut.
            'rfq_id' => $this->rfq_id,
            'project_id' => $this->project_id,
            'warehouse_id' => $this->warehouse_id,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'payment_term_days' => (int) $this->payment_term_days,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'dpp' => $this->dpp,
            'ppn_rate' => $this->ppn_rate,
            'ppn_amount' => $this->ppn_amount,
            'total' => $this->total,
            'needs_director_approval' => (bool) $this->needs_director_approval,
            'delivery_address' => $this->delivery_address,
            'notes' => $this->notes,
            // Jejak override prakualifikasi: terisi hanya bila PO ini diajukan
            // menembus blokir vendor (nonaktif / dokumen wajib kedaluwarsa).
            'qualification_override_reason' => $this->qualification_override_reason,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
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
