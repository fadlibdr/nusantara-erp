<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * One WBS row as it stood when the baseline was frozen.
 *
 * liveTask is nullable BY DESIGN, and a dangling one is not an error: a frozen
 * leaf whose live task has since been deleted is scope removed after the plan
 * was agreed, which is the single most interesting thing a deviation report can
 * tell you. EvmService counts it as earning nothing and names it in
 * warnings.tasks_removed rather than pretending it never existed.
 */
class BaselineTask extends BaseModel
{
    protected $table = 'prj_baseline_tasks';

    protected function casts(): array
    {
        return [
            'weight_pct' => 'decimal:4',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'is_leaf' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(ProjectBaseline::class, 'baseline_id');
    }

    public function liveTask(): BelongsTo
    {
        return $this->belongsTo(WbsTask::class, 'wbs_task_id');
    }
}
