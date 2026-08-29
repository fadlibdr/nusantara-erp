<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Baris SP3: boq_item x tarif upah x qty. qty adalah PLAFON volume klaim
 * baris ini — roll-forward sisa dikunci pada id baris ini, dan mengapa itu
 * aman di sini (dan tidak di P3) dijelaskan di migrasi scm_labor_contracts.
 */
class LaborContractItem extends BaseModel
{
    protected $table = 'scm_labor_contract_items';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function laborContract(): BelongsTo
    {
        return $this->belongsTo(LaborContract::class, 'labor_contract_id');
    }
}
