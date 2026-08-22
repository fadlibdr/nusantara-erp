<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_id' => $this->transfer_id,
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'qty' => $this->qty,
            'unit_cost' => $this->unit_cost,
        ];
    }
}
