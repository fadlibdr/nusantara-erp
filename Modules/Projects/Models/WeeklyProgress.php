<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class WeeklyProgress extends BaseModel
{
    /**
     * The two things actual_pct can BE, named once so the writer
     * (ProgressService), the reader (EvmService) and the print layer cannot
     * drift into three spellings of two facts.
     *
     *   SOURCE_WEEKLY       a percentage a supervisor typed — an estimate;
     *   SOURCE_MEASUREMENT  derived from an APPROVED opname, value-weighted
     *                       over the BOQ — a measurement (P3).
     */
    public const SOURCE_WEEKLY = 'weekly_report';

    public const SOURCE_MEASUREMENT = 'progress_measurement';

    protected $table = 'prj_weekly_progress';

    protected function casts(): array
    {
        return [
            'week_no' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'planned_pct' => 'decimal:4',
            'actual_pct' => 'decimal:4',
            'deviation_pct' => 'decimal:4',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
