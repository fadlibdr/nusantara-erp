<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaborContractItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_no' => (int) $this->line_no,
            'boq_item_id' => $this->boq_item_id,
            'wbs_code' => $this->wbs_code,
            'description' => $this->description,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'unit_rate' => $this->unit_rate,
            'amount' => $this->amount,
        ];
    }
}
