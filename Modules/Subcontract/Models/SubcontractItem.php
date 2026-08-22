<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\BaseModel;
use Modules\Estimation\Models\BoqItem;

class SubcontractItem extends BaseModel
{
    protected $table = 'scm_subcontract_items';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'progress_pct' => 'decimal:4',
        ];
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class, 'subcontract_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    public function claimItems(): HasMany
    {
        return $this->hasMany(ProgressClaimItem::class, 'subcontract_item_id');
    }
}
