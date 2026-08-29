<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\ProcurementPlanItem;

class ProcurementPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'project_id' => $this->project_id,
            'cost_budget_id' => $this->cost_budget_id,
            'title' => $this->title,
            'notes' => $this->notes,
            'status' => $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(
                fn (ProcurementPlanItem $item): array => [
                    'id' => $item->id,
                    'line_no' => (int) $item->line_no,
                    'boq_item_id' => $item->boq_item_id,
                    'package' => $item->package,
                    'method' => $item->method?->value,
                    'method_label' => $item->method?->label(),
                    'estimated_amount' => $item->estimated_amount,
                    'target_contract_date' => $item->target_contract_date?->toDateString(),
                    'pic' => $item->pic,
                    'status' => $item->status,
                    'notes' => $item->notes,
                ],
            )->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
