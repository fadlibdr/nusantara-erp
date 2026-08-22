<?php

namespace Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\PeriodEventAction;

/**
 * One close or one reopen of one fiscal period.
 *
 * Append-only by construction: nothing in the application updates or deletes a
 * row here. A period closed, reopened and closed again carries three rows, and
 * the middle one is the one an auditor asks about.
 */
class PeriodEvent extends BaseModel
{
    protected $table = 'fin_period_events';

    protected function casts(): array
    {
        return [
            'action' => PeriodEventAction::class,
            'overrides' => 'array',
            'checklist' => 'array',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
