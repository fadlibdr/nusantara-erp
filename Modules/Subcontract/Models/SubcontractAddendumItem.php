<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * A new SPK line an addendum brings with it, appended to scm_subcontract_items
 * (progress 0) when the addendum is approved. Removed scope never has items —
 * it only lowers the SPK value.
 */
class SubcontractAddendumItem extends BaseModel
{
    protected $table = 'scm_subcontract_addendum_items';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function addendum(): BelongsTo
    {
        return $this->belongsTo(SubcontractAddendum::class, 'addendum_id');
    }
}
