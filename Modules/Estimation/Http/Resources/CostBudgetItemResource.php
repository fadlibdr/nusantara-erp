<?php

namespace Modules\Estimation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CostBudgetItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cost_budget_id' => $this->cost_budget_id,
            'boq_item_id' => $this->boq_item_id,
            'boq_wbs_code' => $this->whenLoaded('boqItem', fn () => $this->boqItem?->wbs_code),
            'cost_category' => $this->cost_category->value,
            'cost_category_label' => $this->cost_category->label(),
            'description' => $this->description,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'amount' => $this->amount,
        ];
    }
}
