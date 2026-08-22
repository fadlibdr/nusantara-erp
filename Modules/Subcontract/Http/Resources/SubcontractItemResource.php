<?php

namespace Modules\Subcontract\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubcontractItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subcontract_id' => $this->subcontract_id,
            'boq_item_id' => $this->boq_item_id,
            'line_no' => (int) $this->line_no,
            'wbs_code' => $this->wbs_code,
            'description' => $this->description,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'amount' => $this->amount,
            'progress_pct' => $this->progress_pct,
        ];
    }
}
