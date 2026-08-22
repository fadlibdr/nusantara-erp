<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'from_warehouse_id' => $this->from_warehouse_id,
            'from_warehouse' => WarehouseResource::make($this->whenLoaded('fromWarehouse')),
            'to_warehouse_id' => $this->to_warehouse_id,
            'to_warehouse' => WarehouseResource::make($this->whenLoaded('toWarehouse')),
            'transfer_date' => $this->transfer_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'notes' => $this->notes,
            'items' => TransferItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
