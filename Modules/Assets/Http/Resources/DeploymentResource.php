<?php

namespace Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'asset_id' => $this->asset_id,
            'asset' => AssetResource::make($this->whenLoaded('asset')),
            'project_id' => $this->project_id,
            'deployed_from' => $this->deployed_from?->toDateString(),
            'planned_until' => $this->planned_until?->toDateString(),
            'returned_at' => $this->returned_at?->toDateString(),
            'daily_rate_internal' => $this->daily_rate_internal,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Flat, for lookup.js which resolves no dot paths (pola
            // picker_label VendorResource): the picker has to say WHICH
            // machine, not just a DEP number.
            'picker_label' => $this->whenLoaded('asset', fn () => trim(
                ($this->asset->code.' '.$this->asset->name)
                .($this->returned_at !== null ? ' (demobilisasi '.$this->returned_at->toDateString().')' : '')
            )),
            'equipment_logs' => EquipmentLogResource::collection($this->whenLoaded('equipmentLogs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
