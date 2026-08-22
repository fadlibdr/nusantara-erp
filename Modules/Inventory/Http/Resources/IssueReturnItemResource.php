<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issue_return_id' => $this->issue_return_id,
            'issue_item_id' => $this->issue_item_id,
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'qty' => $this->qty,
            // The price the goods LEFT at (the issue line's), filled at posting.
            'unit_cost' => $this->unit_cost,
            'amount' => $this->amount,
        ];
    }
}
