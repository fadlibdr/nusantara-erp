<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Satu baris tabulasi berbobot: skor vendor X pada RFQ Y (P2).
 *
 * harga_score dihitung dari rasio penawaran ke RAB; aspek lain diinput 0–100;
 * weighted_score & rank diisi BidEvaluationService::rank() atas seluruh baris
 * RFQ sekaligus, jadi peringkat selalu konsisten satu sama lain.
 */
class BidEvaluation extends BaseModel
{
    protected $table = 'prc_bid_evaluations';

    protected function casts(): array
    {
        return [
            'rab_amount' => 'decimal:2',
            'offered_amount' => 'decimal:2',
            'harga_score' => 'decimal:2',
            'mutu_score' => 'decimal:2',
            'waktu_score' => 'decimal:2',
            'keuangan_score' => 'decimal:2',
            'k3_score' => 'decimal:2',
            'weighted_score' => 'decimal:2',
            'rank' => 'integer',
        ];
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
