<?php

namespace Modules\ServiceDesk\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FieldReportPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field_report_id' => $this->field_report_id,
            'item_id' => $this->item_id,
            'item_code' => $this->whenLoaded('item', fn () => $this->item?->code),
            'item_name' => $this->whenLoaded('item', fn () => $this->item?->name),
            'qty' => $this->qty,
            'notes' => $this->notes,
        ];
    }
}
