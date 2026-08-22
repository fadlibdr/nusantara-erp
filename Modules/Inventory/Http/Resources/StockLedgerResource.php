<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'trx_date' => $this->trx_date?->toDateString(),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'direction' => $this->direction,
            'qty' => $this->qty,
            'unit_cost' => $this->unit_cost,
            'balance_qty_after' => $this->balance_qty_after,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
