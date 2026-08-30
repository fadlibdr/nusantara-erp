<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Estimation\Models\BoqItem;

/**
 * P7: one SMKK cost line of a RKK — a POINTER at a BoQ row, never a second
 * rupiah figure for the same money. See migration 000392.
 */
class RkkSmkkCost extends BaseModel
{
    protected $table = 'crm_rkk_smkk_costs';

    protected function casts(): array
    {
        return [
            'boq_item_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function rkk(): BelongsTo
    {
        return $this->belongsTo(RkkDocument::class, 'rkk_id');
    }

    /** Crm → Estimation is a drawn arrow; the amount is read off this row. */
    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }
}
