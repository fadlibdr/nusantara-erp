<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;
use Modules\Estimation\Models\BoqItem;

class WbsTask extends BaseModel
{
    protected $table = 'prj_wbs_tasks';

    protected function casts(): array
    {
        return [
            'weight_pct' => 'decimal:4',
            'progress_pct' => 'decimal:4',
            'planned_start' => 'date',
            'planned_end' => 'date',
            'actual_start' => 'date',
            'actual_end' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    /** Punch-list items raised against this work package. */
    public function defects(): HasMany
    {
        return $this->hasMany(Defect::class, 'wbs_task_id');
    }

    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }
}
