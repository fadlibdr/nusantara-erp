<?php

namespace Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepreciationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'period' => $this->periodLabel(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'total_amount' => $this->total_amount,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'notes' => $this->notes,
            'entries_count' => $this->whenCounted('entries'),
            'entries' => DepreciationEntryResource::collection($this->whenLoaded('entries')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
