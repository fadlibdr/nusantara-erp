<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WbsTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'boq_item_id' => $this->boq_item_id,
            'wbs_code' => $this->wbs_code,
            'name' => $this->name,
            'weight_pct' => $this->weight_pct,
            'planned_start' => $this->planned_start?->toDateString(),
            'planned_end' => $this->planned_end?->toDateString(),
            'actual_start' => $this->actual_start?->toDateString(),
            'actual_end' => $this->actual_end?->toDateString(),
            'progress_pct' => $this->progress_pct,
            'sort_order' => (int) $this->sort_order,
            // Only on the flat picker listing (list() loads the relation; the
            // tree endpoint never does, so its payload is unchanged). The
            // picker shows every project's leaves in one list, and a bare
            // "B.3" names a different package on every job.
            'project_code' => $this->when(
                $this->relationLoaded('project') && $this->project !== null,
                fn (): string => $this->project->code,
            ),
            'picker_label' => $this->when(
                $this->relationLoaded('project') && $this->project !== null,
                fn (): string => $this->project->code.' · '.$this->wbs_code,
            ),
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
