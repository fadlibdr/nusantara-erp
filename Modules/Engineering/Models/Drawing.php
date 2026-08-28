<?php

namespace Modules\Engineering\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Engineering\Enums\Discipline;
use Modules\Engineering\Enums\DrawingStatus;
use Modules\Projects\Models\Project;

/**
 * One row of the shop-drawing register (FM-10-01/21). `status` is a mirror of
 * the current submittal's state, moved only by DrawingSubmittalService —
 * never written from a request.
 */
class Drawing extends BaseModel
{
    use SoftDeletes;

    protected $table = 'eng_drawings';

    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
            'planned_submit_date' => 'date',
            'status' => DrawingStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function submittals(): HasMany
    {
        return $this->hasMany(DrawingSubmittal::class, 'drawing_id');
    }

    /** The one submittal that currently speaks for this drawing. */
    public function currentSubmittal(): HasOne
    {
        return $this->hasOne(DrawingSubmittal::class, 'drawing_id')
            ->whereNull('superseded_at')
            ->latestOfMany();
    }
}
