<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_adjustment_id' => $this->stock_adjustment_id,
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'system_qty' => $this->system_qty,
            'counted_qty' => $this->counted_qty,
            'diff_qty' => $this->diff_qty,
            'unit_cost' => $this->unit_cost,
        ];
    }
}
