<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectCostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'cost_date' => $this->cost_date?->toDateString(),
            'cost_category' => $this->cost_category?->value,
            'cost_category_label' => $this->cost_category?->label(),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'description' => $this->description,
            'amount' => $this->amount,
        ];
    }
}
