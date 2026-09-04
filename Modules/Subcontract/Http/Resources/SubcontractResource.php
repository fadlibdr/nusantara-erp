<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Terbilang;

class SubcontractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'vendor_id' => $this->vendor_id,
            // Jejak override prakualifikasi milik auditor — PO sudah
            // menampilkannya; SPK tanpa ini tercatat tapi tak terlihat.
            'qualification_override_reason' => $this->qualification_override_reason,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'code' => $this->vendor->code,
                'name' => $this->vendor->name,
                'is_pkp' => (bool) $this->vendor->is_pkp,
                'is_subcontractor' => (bool) $this->vendor->is_subcontractor,
            ]),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'title' => $this->title,
            'scope' => $this->scope,
            'value' => $this->value,
            // Null until the first approved addendum backfills it — "the value
            // this SPK started at", same semantics as crm_contracts.
            'original_value' => $this->original_value,
            'value_terbilang' => Terbilang::rupiah($this->value ?? 0),
            'ppn_rate' => $this->ppn_rate,
            'retention_pct' => $this->retention_pct,
            'pph_scheme' => $this->pph_scheme?->value,
            'pph_scheme_label' => $this->pph_scheme?->label(),
            'pph_rate' => $this->pph_rate,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'defect_liability_until' => $this->defect_liability_until?->toDateString(),
            'needs_director_approval' => (bool) $this->needs_director_approval,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => SubcontractItemResource::collection($this->whenLoaded('items')),
            'claims' => ProgressClaimResource::collection($this->whenLoaded('claims')),
            'retention_releases' => RetentionReleaseResource::collection($this->whenLoaded('retentionReleases')),
            'addenda' => SubcontractAddendumResource::collection($this->whenLoaded('addenda')),
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
