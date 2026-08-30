<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/** P7: satu baris register dokumen lelang. Lihat migrasi 000387. */
class TenderDocument extends BaseModel
{
    protected $table = 'crm_tender_documents';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'issued_date' => 'date',
            'addendum_no' => 'integer',
        ];
    }

    public function tenderPackage(): BelongsTo
    {
        return $this->belongsTo(TenderPackage::class, 'tender_package_id');
    }

    public function isAddendum(): bool
    {
        return $this->addendum_no !== null;
    }
}
