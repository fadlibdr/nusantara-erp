<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * P6: one recorded APD count — a category ("helm", "harness", …) and how many
 * were in use that day. A category NEVER recorded has NO row: on the printed
 * sheet that cell is ruled, not 0 (the honesty rule).
 */
class HseDailyApd extends BaseModel
{
    protected $table = 'prj_hse_daily_apd';

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    public function hseDaily(): BelongsTo
    {
        return $this->belongsTo(HseDaily::class, 'hse_daily_id');
    }
}
