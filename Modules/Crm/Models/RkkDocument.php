<?php

namespace Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Estimation\Models\Boq;

/**
 * P7: RKK penawaran (Permen PUPR 10/2021). See migration 000390.
 *
 * NO project() relation — Crm has no arrow to Projects in ARCHITECTURE.md, and
 * the IBPRP rows are reached through RkkService's raw read instead. boq() IS a
 * relation: Crm → Estimation is a drawn arrow.
 */
class RkkDocument extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'crm_rkk_documents';

    public string $documentType = 'RKK';

    public function tenderPackage(): BelongsTo
    {
        return $this->belongsTo(TenderPackage::class, 'tender_package_id');
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function ibprpLinks(): HasMany
    {
        return $this->hasMany(RkkIbprpLink::class, 'rkk_id')->orderBy('sort_order')->orderBy('id');
    }

    public function smkkCosts(): HasMany
    {
        return $this->hasMany(RkkSmkkCost::class, 'rkk_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
