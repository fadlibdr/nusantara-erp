<?php

namespace Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipmentLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deployment_id' => $this->deployment_id,
            'deployment' => DeploymentResource::make($this->whenLoaded('deployment')),
            'log_date' => $this->log_date?->toDateString(),
            'hour_meter' => $this->hour_meter,
            'fuel_liters' => $this->fuel_liters,
            'notes' => $this->notes,
            'logged_by' => $this->logged_by,
            // Flat, so the SPA table and the kartu aset can print the clerk's
            // name without walking a nested user object.
            'logged_by_name' => $this->whenLoaded('loggedBy', fn () => $this->loggedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
