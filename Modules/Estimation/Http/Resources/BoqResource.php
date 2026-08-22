<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'version' => $this->version,
            'project_id' => $this->project_id,
            'quotation_id' => $this->quotation_id,
            'contract_id' => $this->contract_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total' => $this->total,
            'notes' => $this->notes,
            'sections' => BoqSectionResource::collection($this->whenLoaded('sections')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
