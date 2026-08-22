<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoqSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'boq_id' => $this->boq_id,
            'section_no' => $this->section_no,
            'name' => $this->name,
            'subtotal' => $this->subtotal,
            'sort_order' => $this->sort_order,
            'items' => BoqItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
