<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BaselinePointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'seq' => (int) $this->seq,
            'period_end' => $this->period_end?->toDateString(),
            'planned_pct' => $this->planned_pct,
            'planned_value' => $this->planned_value,
        ];
    }
}
