<?php

namespace Modules\Quality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Models\Location;
use Modules\Projects\Models\Project;

/**
 * A set of concrete specimens (benda uji) cast from one pour. The grade string
 * (K-350 / fc'25) is parsed to its target fc' by ConcreteStrengthService; no
 * document number (F/BU prints the pour identity).
 */
class ConcreteSample extends BaseModel
{
    use SoftDeletes;

    protected $table = 'qc_concrete_samples';

    protected function casts(): array
    {
        return [
            'pour_date' => 'date',
            'slump_cm' => 'decimal:2',
            'volume_m3' => 'decimal:3',
            'sample_count' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(ConcreteTest::class, 'sample_id')->orderBy('age_days');
    }
}
