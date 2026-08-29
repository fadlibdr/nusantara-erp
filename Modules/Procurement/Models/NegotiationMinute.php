<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Berita Acara Negosiasi (BAN) — risalah klarifikasi & negosiasi harga vendor.
 *
 * SENGAJA TANPA Approvable: BAN adalah CATATAN sebuah pertemuan, bukan dokumen
 * yang disetujui berjenjang. Ia menjadi PRASYARAT: award yang nilainya berbeda
 * dari penawaran terakhir tidak sah tanpa BAN (kriteria #4).
 */
class NegotiationMinute extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prc_negotiation_minutes';

    public string $documentType = 'BAN';

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'peserta' => 'array',
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

    public function items(): HasMany
    {
        return $this->hasMany(NegotiationMinuteItem::class, 'negotiation_minute_id')->orderBy('line_no');
    }
}
