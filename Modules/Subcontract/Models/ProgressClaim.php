<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;

class ProgressClaim extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'scm_progress_claims';

    public string $documentType = 'CLM';

    protected function casts(): array
    {
        return [
            'claim_no' => 'integer',
            'is_advance' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'decimal:2',
            'retention_amount' => 'decimal:2',
            'net_before_tax' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'pph_amount' => 'decimal:2',
            'advance_recovery_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'status' => DocumentStatus::class,
        ];
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class, 'subcontract_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProgressClaimItem::class, 'progress_claim_id');
    }
}
