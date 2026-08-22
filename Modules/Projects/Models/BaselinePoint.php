<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * One precomputed month-end sample of the frozen curve, for charting.
 *
 * NOT the authority. Planned value at an arbitrary date is always recomputed
 * from the frozen task windows by PlannedCurve; interpolating between these
 * points instead is off by a few tenths of a percent wherever a work package
 * starts or ends mid-month.
 */
class BaselinePoint extends BaseModel
{
    protected $table = 'prj_baseline_points';

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'period_end' => 'date',
            'planned_pct' => 'decimal:4',
            'planned_value' => 'decimal:2',
        ];
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(ProjectBaseline::class, 'baseline_id');
    }
}
