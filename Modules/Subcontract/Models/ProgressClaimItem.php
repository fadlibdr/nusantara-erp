<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class ProgressClaimItem extends BaseModel
{
    protected $table = 'scm_progress_claim_items';

    protected function casts(): array
    {
        return [
            'prev_progress_pct' => 'decimal:4',
            'current_progress_pct' => 'decimal:4',
            'period_progress_pct' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ProgressClaim::class, 'progress_claim_id');
    }

    public function subcontractItem(): BelongsTo
    {
        return $this->belongsTo(SubcontractItem::class, 'subcontract_item_id');
    }
}
