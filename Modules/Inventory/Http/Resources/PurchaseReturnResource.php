<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'goods_receipt_id' => $this->goods_receipt_id,
            'goods_receipt' => GoodsReceiptResource::make($this->whenLoaded('goodsReceipt')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'vendor_id' => $this->vendor_id,
            'return_date' => $this->return_date?->toDateString(),
            'returned_by' => $this->returned_by,
            'reason' => $this->reason,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
