<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'warehouse_id' => $this->warehouse_id,
            'requested_by' => $this->requested_by,
            'requester_name' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'needed_date' => $this->needed_date?->toDateString(),
            'purpose' => $this->purpose,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'items' => PurchaseRequisitionItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
