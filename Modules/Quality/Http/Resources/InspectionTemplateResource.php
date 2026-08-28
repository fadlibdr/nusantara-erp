<?php

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'work_package' => $this->work_package,
            'stage' => $this->stage?->value,
            'stage_label' => $this->stage?->label(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sort_order' => (int) $item->sort_order,
                'check_text' => $item->check_text,
                'acceptance' => $item->acceptance,
                'tolerance' => $item->tolerance,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
