<?php

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Http\Resources\ApprovalTrail;

class OverheadBudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'period_year' => $this->period_year,
            'total_amount' => $this->total_amount,
            'status' => $this->status?->value,
            'notes' => $this->notes,
            'lines_count' => $this->whenLoaded('lines', fn () => $this->lines->count()),
            'lines' => OverheadBudgetLineResource::collection($this->whenLoaded('lines')),
            'approvals' => $this->whenLoaded('approvals', fn (): array => ApprovalTrail::map($this->approvals)),
        ];
    }
}
