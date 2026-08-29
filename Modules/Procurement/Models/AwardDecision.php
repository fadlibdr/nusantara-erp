<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Keputusan Pemenang / Award Decision (AWD) — P2.
 *
 * Approvable dengan AMBANG N-LEVEL. Ia memilih ladder 'award_decision' dan
 * menyelesaikannya terhadap awarded_amount: award kecil butuh satu penyetuju,
 * award di atas Rp 100 juta butuh dua (yang kedua direktur), award Rp 1 miliar
 * ke atas butuh tiga. approve() menghitung penyetuju BERBEDA dan baru menjadi
 * 'approved' pada tingkat terakhir — lihat Core\Traits\Approvable.
 */
class AwardDecision extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_award_decisions';

    public string $documentType = 'AWD';

    protected function casts(): array
    {
        return [
            'rab_amount' => 'decimal:2',
            'awarded_amount' => 'decimal:2',
            'deviation_amount' => 'decimal:2',
            'committee' => 'array',
            'status' => DocumentStatus::class,
        ];
    }

    /** Opt into the n-level ladder resolved against the awarded amount. */
    public function approvalLadderKey(): ?string
    {
        return 'award_decision';
    }

    public function approvalAmount(): float
    {
        return (float) $this->awarded_amount;
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
