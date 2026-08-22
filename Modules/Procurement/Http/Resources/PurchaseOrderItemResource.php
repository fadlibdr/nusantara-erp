<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_no' => (int) $this->line_no,
            'item_id' => $this->item_id,
            'boq_item_id' => $this->boq_item_id,
            'description' => $this->description,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'amount' => $this->amount,
            'qty_received' => $this->qty_received,
        ];
    }
}
