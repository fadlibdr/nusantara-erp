<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AhspResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'overhead_pct' => $this->overhead_pct,
            'unit_price' => $this->unit_price,
            'notes' => $this->notes,
            'components' => AhspComponentResource::collection($this->whenLoaded('components')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
