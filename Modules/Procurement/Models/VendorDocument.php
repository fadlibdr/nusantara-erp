<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Procurement\Enums\VendorDocumentType;

class VendorDocument extends BaseModel
{
    use SoftDeletes;

    protected $table = 'prc_vendor_documents';

    protected function casts(): array
    {
        return [
            'doc_type' => VendorDocumentType::class,
            'issued_date' => 'date',
            'valid_until' => 'date',
            'is_mandatory' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * "Berlaku s/d" berarti masih sah PADA hari terakhirnya: kedaluwarsa baru
     * mulai hari berikutnya, dan NULL berarti tidak kedaluwarsa sama sekali —
     * bacaan yang sama dengan Guarantee::isExpired dan deadline-watch
     * (valid_through_end), supaya register, gate, dan pengingat tidak pernah
     * menyebut dokumen yang sama dengan dua status berbeda.
     */
    public function isExpired(): bool
    {
        return $this->valid_until !== null
            && $this->valid_until->toDateString() < now()->toDateString();
    }
}
