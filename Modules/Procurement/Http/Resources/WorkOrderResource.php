<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'vendor_id' => $this->vendor_id,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor?->id,
                'code' => $this->vendor?->code,
                'name' => $this->vendor?->name,
                'vendor_type' => $this->vendor?->vendor_type?->value,
            ]),
            'project_id' => $this->project_id,
            'title' => $this->title,
            'value' => $this->value,
            'ppn_rate' => $this->ppn_rate,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'qualification_override_reason' => $this->qualification_override_reason,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'line_no' => $item->line_no,
                'asset_id' => $item->asset_id,
                'asset_code' => $item->relationLoaded('asset') ? $item->asset?->code : null,
                'description' => $item->description,
                'rate_basis' => $item->rate_basis?->value,
                'rate_basis_label' => $item->rate_basis?->label(),
                'rate' => $item->rate,
                'qty_periods' => $item->qty_periods,
                'unit' => $item->rate_basis?->unit(),
                'amount' => $item->amount,
            ])->values()),
            'billings' => WorkOrderBillingResource::collection($this->whenLoaded('billings')),
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
