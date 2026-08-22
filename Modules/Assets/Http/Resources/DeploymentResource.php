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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
