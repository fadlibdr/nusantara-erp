<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class LaborClaimItem extends BaseModel
{
    protected $table = 'scm_labor_claim_items';

    protected function casts(): array
    {
        return [
            'qty_prev' => 'decimal:3',
            'qty_this' => 'decimal:3',
            'amount' => 'decimal:2',
        ];
    }

    public function laborClaim(): BelongsTo
    {
        return $this->belongsTo(LaborClaim::class, 'labor_claim_id');
    }

    public function laborContractItem(): BelongsTo
    {
        return $this->belongsTo(LaborContractItem::class, 'labor_contract_item_id');
    }
}
