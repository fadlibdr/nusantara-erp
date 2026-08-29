<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractVariationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'change_order_id' => $this->change_order_id,
            'change_order_code' => $this->whenLoaded('changeOrder', fn () => $this->changeOrder?->code),
            // Only an APPROVED change order raises the ceiling — the screen
            // shows the status so a QS can see why a volume is not counting yet.
            'change_order_status' => $this->whenLoaded('changeOrder', fn () => $this->changeOrder?->status?->value),
            'boq_item_id' => $this->boq_item_id,
            'boq_wbs_code' => $this->whenLoaded('boqItem', fn () => $this->boqItem?->wbs_code),
            'boq_description' => $this->whenLoaded('boqItem', fn () => $this->boqItem?->description),
            'qty_change' => $this->qty_change,
            'unit' => $this->unit,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
