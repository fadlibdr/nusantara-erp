<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CostBudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'boq_id' => $this->boq_id,
            'boq_code' => $this->whenLoaded('boq', fn () => $this->boq?->code),
            'boq_total' => $this->whenLoaded('boq', fn () => $this->boq?->total),
            'project_id' => $this->project_id,
            'target_margin_pct' => $this->target_margin_pct,
            'total_budget' => $this->total_budget,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'items' => CostBudgetItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
