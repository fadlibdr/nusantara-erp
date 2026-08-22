<?php

namespace Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'asset_id' => $this->asset_id,
            'asset' => AssetResource::make($this->whenLoaded('asset')),
            'maintenance_date' => $this->maintenance_date?->toDateString(),
            'maintenance_type' => $this->maintenance_type?->value,
            'maintenance_type_label' => $this->maintenance_type?->label(),
            'vendor_id' => $this->vendor_id,
            'cost' => $this->cost,
            'description' => $this->description,
            'next_due_date' => $this->next_due_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
