<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\GatePassItem;

class GatePassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'project_code' => $this->whenLoaded('project', fn () => $this->project?->code),
            'direction' => $this->direction?->value,
            'direction_label' => $this->direction?->label(),
            'pass_date' => $this->pass_date?->toDateString(),
            'vehicle_no' => $this->vehicle_no,
            'driver_name' => $this->driver_name,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor?->name),
            'counterparty' => $this->counterparty,
            'goods_receipt_id' => $this->goods_receipt_id,
            'transfer_id' => $this->transfer_id,
            'checked_by' => $this->checked_by,
            'checked_by_name' => $this->whenLoaded('checkedBy', fn () => $this->checkedBy?->name),
            'checked_at' => $this->checked_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(
                fn (GatePassItem $item): array => [
                    'id' => $item->id,
                    'item_id' => $item->item_id,
                    'description' => $item->description,
                    'qty' => (float) $item->qty,
                    'unit' => $item->unit,
                    'notes' => $item->notes,
                ],
            )->all()),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
