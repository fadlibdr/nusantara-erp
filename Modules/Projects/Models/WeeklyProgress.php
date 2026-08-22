<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class WeeklyProgress extends BaseModel
{
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
