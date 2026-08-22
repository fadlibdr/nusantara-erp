<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'purchase_order_id' => $this->purchase_order_id,
            'vendor_id' => $this->vendor_id,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'delivery_note_no' => $this->delivery_note_no,
            'received_by' => $this->received_by,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // Filled by StockService::cancelReceipt(); the original posting
            // stays in the ledger and a reversing journal sits beside it.
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
