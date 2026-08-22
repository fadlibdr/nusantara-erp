<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoqItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'boq_id' => $this->boq_id,
            'section_id' => $this->section_id,
            'section_no' => $this->whenLoaded('section', fn () => $this->section->section_no),
            'wbs_code' => $this->wbs_code,
            'description' => $this->description,
            'ahsp_id' => $this->ahsp_id,
            'ahsp_code' => $this->whenLoaded('ahsp', fn () => $this->ahsp?->code),
            'qty' => $this->qty,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'amount' => $this->amount,
            'sort_order' => $this->sort_order,
        ];
    }
}
