<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/** P6: one temuan & tindak lanjut line of an FM-10-13 sheet. */
class HseDailyFinding extends BaseModel
{
    protected $table = 'prj_hse_daily_findings';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function hseDaily(): BelongsTo
    {
        return $this->belongsTo(HseDaily::class, 'hse_daily_id');
    }
}
