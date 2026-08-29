<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Finance\Models\Kasbon;

/**
 * Opname mandor (P4): volume upah per baris SP3 per periode, potongan
 * kasbon, lalu tagihan AP lewat ApBillService::createFromLaborClaim
 * (fin_ap_bills.labor_claim_id — cermin subcontract_claim_id).
 */
class LaborClaim extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'scm_labor_claims';

    public string $documentType = 'OPM';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'pph_amount' => 'decimal:2',
            'kasbon_deduction_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'status' => DocumentStatus::class,
        ];
    }

    public function laborContract(): BelongsTo
    {
        return $this->belongsTo(LaborContract::class, 'labor_contract_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LaborClaimItem::class, 'labor_claim_id');
    }

    /** Cross-module READ; writes to the kasbon go through KasbonService only. */
    public function kasbon(): BelongsTo
    {
        return $this->belongsTo(Kasbon::class, 'kasbon_id');
    }
}
