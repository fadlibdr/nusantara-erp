<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Support\Terbilang;

class LaborContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'vendor_id' => $this->vendor_id,
            'qualification_override_reason' => $this->qualification_override_reason,
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'code' => $this->vendor->code,
                'name' => $this->vendor->name,
                'vendor_type' => $this->vendor->vendor_type?->value,
                'is_pkp' => (bool) $this->vendor->is_pkp,
            ]),
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
            ]),
            'title' => $this->title,
            'value' => $this->value,
            'value_terbilang' => Terbilang::rupiah($this->value ?? 0),
            'ppn_rate' => $this->ppn_rate,
            'pph_scheme' => $this->pph_scheme?->value,
            'pph_scheme_label' => $this->pph_scheme?->label(),
            'pph_rate' => $this->pph_rate,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => LaborContractItemResource::collection($this->whenLoaded('items')),
            'claims' => LaborClaimResource::collection($this->whenLoaded('claims')),
            'import_source' => $this->import_source,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
