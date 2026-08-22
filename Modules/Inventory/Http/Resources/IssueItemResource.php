<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issue_id' => $this->issue_id,
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            // The work package this line was consumed by; defaulted from the
            // header at write time. The line grid renders and posts it, and the
            // material variance report reads this and not the header.
            'wbs_task_id' => $this->wbs_task_id,
            'qty' => $this->qty,
            // Already back on the shelf through posted retur material — the
            // return form's "Salin baris dari bon" offers qty minus this, so a
            // second retur never starts from a quantity the first already took.
            'qty_returned' => $this->qtyReturned(),
            'unit_cost' => $this->unit_cost,
            'amount' => $this->amount,
        ];
    }
}
