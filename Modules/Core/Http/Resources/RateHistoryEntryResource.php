<?php

namespace Modules\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RateHistoryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rate_key' => $this->rate_key,
            'old_rate' => $this->old_rate === null ? null : (float) $this->old_rate,
            'new_rate' => $this->new_rate === null ? null : (float) $this->new_rate,
            'changed_by' => $this->changed_by,
            'changed_by_name' => $this->user?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
