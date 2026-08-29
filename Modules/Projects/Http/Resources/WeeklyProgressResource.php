<?php

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\WeeklyProgress;

class WeeklyProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'week_no' => (int) $this->week_no,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'planned_pct' => $this->planned_pct,
            'actual_pct' => $this->actual_pct,
            /*
             * P3 — WHICH of the two things actual_pct is, on every row.
             *
             * recordWeekly DISCARDS a typed percentage for any week an approved
             * opname covers. Without this column the screen shows a supervisor
             * a number he did not enter, with nothing on the page saying why —
             * and a curve that cannot tell a measurement from an estimate is
             * worse than either (MeasurementService's own line).
             */
            'actual_pct_source' => $this->actual_pct_source ?? WeeklyProgress::SOURCE_WEEKLY,
            'deviation_pct' => $this->deviation_pct,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
