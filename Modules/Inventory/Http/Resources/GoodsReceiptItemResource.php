<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goods_receipt_id' => $this->goods_receipt_id,
            'item_id' => $this->item_id,
            'item' => ItemResource::make($this->whenLoaded('item')),
            'po_item_id' => $this->po_item_id,
            'qty' => $this->qty,
            // Already sent back through posted retur pembelian — the return
            // form's "Salin baris dari GRN" offers qty minus this.
            'qty_returned' => $this->qtyReturned(),
            'unit_cost' => $this->unit_cost,
            'amount' => $this->amount,
        ];
    }
}
