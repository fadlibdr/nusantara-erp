<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'location_id' => $this->location_id,
            'location_code' => $this->whenLoaded('location', fn () => $this->location?->code),
            'location_path' => $this->whenLoaded('location', fn () => $this->location?->path()),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'blocks_billing' => $this->status?->blocksBilling() ?? false,
            'certified_at' => $this->certified_at?->toDateString(),
            'certified_by_party' => $this->certified_by_party?->value,
            'certified_by_party_label' => $this->certified_by_party?->label(),
            'certified_by_name' => $this->certified_by_name,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
