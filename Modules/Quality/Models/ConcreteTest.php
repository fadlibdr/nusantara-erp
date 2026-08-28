<?php

namespace Modules\Quality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * One specimen broken at an age (7/14/28 days), reporting a strength in MPa.
 * `pass` is COMPUTED by ConcreteStrengthService against the sample grade's
 * age-adjusted target — never typed. A line row (no softDeletes).
 */
class ConcreteTest extends BaseModel
{
    protected $table = 'qc_concrete_tests';

    protected function casts(): array
    {
        return [
            'age_days' => 'integer',
            'strength_mpa' => 'decimal:2',
            'tested_at' => 'date',
            'pass' => 'boolean',
        ];
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(ConcreteSample::class, 'sample_id');
    }
}
