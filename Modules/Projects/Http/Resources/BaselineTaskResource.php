<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BaselineTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'wbs_task_id' => $this->wbs_task_id,
            'wbs_code' => $this->wbs_code,
            'parent_wbs_code' => $this->parent_wbs_code,
            'name' => $this->name,
            'is_leaf' => (bool) $this->is_leaf,
            'weight_pct' => $this->weight_pct,
            'planned_start' => $this->planned_start?->toDateString(),
            'planned_end' => $this->planned_end?->toDateString(),
            'sort_order' => (int) $this->sort_order,
            // A missing live task is the "scope removed after freezing" case,
            // not an error — the screen shows it struck through rather than
            // dropping the row and quietly shrinking the frozen plan.
            'live_exists' => $this->whenLoaded('liveTask', fn (): bool => $this->liveTask !== null),
            'live_progress_pct' => $this->whenLoaded('liveTask', fn () => $this->liveTask?->progress_pct),
        ];
    }
}
