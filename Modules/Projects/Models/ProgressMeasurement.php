<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Contract;

/**
 * Opname ke pemilik (OPN) — volume measured per BOQ item, per contract, per
 * period. The revenue-side mirror of Subcontract\Models\ProgressClaim.
 *
 * Approvable, and ALSO external-approvable in transition mode: the signature
 * that matters on this sheet is the MK's, and MK is not a users row. The
 * internal cycle stays the house cycle (prj.approve, maker-checker); the MK's
 * link-or-paper decision moves the same document through
 * MeasurementService::applyExternalDecision, on behalf of the link's issuer,
 * exactly as WorkPermit does.
 */
class ProgressMeasurement extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_progress_measurements';

    public string $documentType = 'OPN';

    protected function casts(): array
    {
        return [
            'measurement_no' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'period_amount' => 'decimal:2',
            'cumulative_amount' => 'decimal:2',
            'status' => DocumentStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProgressMeasurementItem::class, 'progress_measurement_id');
    }
}
