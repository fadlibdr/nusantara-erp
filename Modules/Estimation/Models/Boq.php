<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Quotation;
use Modules\Projects\Models\Project;

/**
 * BOQ / RAB — Bill of Quantities (Rencana Anggaran Biaya).
 */
class Boq extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    public string $documentType = 'BOQ';

    protected $table = 'est_boqs';

    protected $casts = [
        'status' => DocumentStatus::class,
        'version' => 'integer',
        'total' => 'decimal:2',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(BoqSection::class, 'boq_id')->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BoqItem::class, 'boq_id');
    }

    public function costBudgets(): HasMany
    {
        return $this->hasMany(CostBudget::class, 'boq_id');
    }

    /**
     * The three cross-module links est_boqs has carried as bare ids since the
     * module was written.
     *
     * Nullable on purpose and often all three null at once: an estimate exists
     * before the job is won, which is exactly when a RAB is printed. Declared
     * as relations so the printed sheet can put the PROYEK box on its
     * letterhead and quote the penawaran and kontrak numbers it was priced
     * for — traceability the estimator otherwise writes on the paper by hand —
     * without a query inside a print composer.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
