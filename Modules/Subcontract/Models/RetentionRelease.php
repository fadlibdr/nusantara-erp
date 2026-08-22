<?php

namespace Modules\Subcontract\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Models\ApBill;

class RetentionRelease extends BaseModel
{
    protected $table = 'scm_retention_releases';

    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class, 'subcontract_id');
    }

    /**
     * The AP bill this release raised — the document that debits 2-1500 and
     * that the payment module settles. Null only for releases recorded before
     * the release had a ledger path at all.
     */
    public function apBill(): BelongsTo
    {
        return $this->belongsTo(ApBill::class, 'ap_bill_id');
    }
}
