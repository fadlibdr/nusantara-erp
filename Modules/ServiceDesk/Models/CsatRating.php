<?php

namespace Modules\ServiceDesk\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\ServiceDesk\Enums\CsatScore;

/**
 * Satu undangan menilai satu tiket — dan, setelah dipakai, penilaiannya.
 *
 * Saudara Modules\Core\Models\ExternalApproval: predikat keadaan hidup DI SINI
 * supaya halaman publik, service, kartu SPA dan uji memakai satu definisi.
 * Terutama isExpired(): `expires_at = sekarang` SUDAH kedaluwarsa (>=, bukan
 * >), aturan tepi yang sama dengan persetujuan eksternal.
 */
class CsatRating extends BaseModel
{
    protected $table = 'svc_csat_ratings';

    /** Satu-satunya nilai rated_via yang bisa ditulis sistem ini hari ini. */
    public const VIA_LINK = 'link';

    protected function casts(): array
    {
        return [
            'score' => CsatScore::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rated_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isRated(): bool
    {
        return $this->rated_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    /** Masih bisa dipakai menilai — sejauh yang bisa dilihat dari BARIS ini. */
    public function isLive(): bool
    {
        return ! $this->isRated() && ! $this->isRevoked() && ! $this->isExpired();
    }
}
