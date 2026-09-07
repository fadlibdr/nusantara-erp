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
            //
            // `when(relationLoaded)`, NOT `whenLoaded`: whenLoaded() returns
            // null WITHOUT calling the closure when the relation is loaded but
            // empty, so live_exists was `null` — never `false` — for exactly
            // the rows it exists to flag. evm.js:1145 tests
            // `task.live_exists === false`, so the struck-through "tugas
            // dihapus" row had never once been drawn.
            //
            // WHICH live task, though, is decided by
            // BaselineService::attachLiveTasks (id first, then wbs_code), not
            // by the id-only `liveTask` relation: every frozen id on the demo
            // file dangles, so the relation alone made all 11 rows say "sudah
            // tidak ada di WBS" while EVM was earning value from those very
            // tasks. `live_matched_by` and `live_wbs_code` carry the rule that
            // was actually applied to the screen, so a row whose progress
            // comes from a task with ANOTHER code says so instead of
            // presenting it as its own.
            'live_exists' => $this->when(
                $this->resource->relationLoaded('liveTask'),
                fn (): bool => $this->liveTask !== null,
            ),
            'live_matched_by' => $this->when(
                $this->resource->relationLoaded('liveTask'),
                fn (): ?string => match (true) {
                    $this->liveTask === null => null,
                    $this->liveTask->id === $this->wbs_task_id => 'id',
                    default => 'code',
                },
            ),
            'live_wbs_code' => $this->when(
                $this->resource->relationLoaded('liveTask'),
                fn (): ?string => $this->liveTask?->wbs_code,
            ),
            'live_progress_pct' => $this->when(
                $this->resource->relationLoaded('liveTask'),
                fn () => $this->liveTask?->progress_pct,
            ),
        ];
    }
}
