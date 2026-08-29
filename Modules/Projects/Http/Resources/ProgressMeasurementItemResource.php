<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressMeasurementItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'boq_item_id' => $this->boq_item_id,
            'wbs_code' => $this->whenLoaded('boqItem', fn () => $this->boqItem?->wbs_code),
            'location_id' => $this->location_id,
            'location_path' => $this->whenLoaded('location', fn () => $this->location?->path()),
            // Snapshots, not joins — see the migration.
            'description' => $this->description,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'qty_prev' => $this->qty_prev,
            'qty_this' => $this->qty_this,
            'qty_cum' => $this->qty_cum,
            'amount' => $this->amount,
            'notes' => $this->notes,
        ];
    }
}
