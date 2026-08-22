<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_return_id' => $this->purchase_return_id,
            'grn_item_id' => $this->grn_item_id,
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'qty' => $this->qty,
            // The price the goods ARRIVED at (the receipt line's), filled at posting.
            'unit_cost' => $this->unit_cost,
            'amount' => $this->amount,
        ];
    }
}
