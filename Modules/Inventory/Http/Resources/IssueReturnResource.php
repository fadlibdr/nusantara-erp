<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'issue_id' => $this->issue_id,
            'issue' => IssueResource::make($this->whenLoaded('issue')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => WarehouseResource::make($this->whenLoaded('warehouse')),
            'return_date' => $this->return_date?->toDateString(),
            'returned_by' => $this->returned_by,
            'reason' => $this->reason,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => IssueReturnItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
