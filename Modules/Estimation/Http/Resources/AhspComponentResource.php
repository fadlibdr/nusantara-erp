<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AhspComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ahsp_id' => $this->ahsp_id,
            'component_type' => $this->component_type->value,
            'component_type_label' => $this->component_type->label(),
            'name' => $this->name,
            'item_id' => $this->item_id,
            'unit' => $this->unit,
            'coefficient' => $this->coefficient,
            'unit_price' => $this->unit_price,
            'subtotal' => number_format($this->subtotal(), 2, '.', ''),
        ];
    }
}
