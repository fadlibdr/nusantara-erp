<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'qty' => $this->qty,
            'avg_cost' => $this->avg_cost,
            'stock_value' => round((float) $this->qty * (float) $this->avg_cost, 2),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
