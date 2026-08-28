<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Enums\ExternalDecision;

/**
 * Satu mandat keputusan eksternal: tautan sekali-pakai yang diterbitkan untuk
 * MK/Owner, atau catatan lembar fisik bertanda tangan. Lihat komentar migrasi
 * core_external_approvals untuk aturan bentuknya.
 *
 * token_hash disembunyikan dari SEMUA serialisasi. Daftar tautan di SPA tidak
 * membutuhkannya, dan hash yang bocor tetap sebuah rahasia yang bocor — ia
 * memastikan token, meski tidak membangunnya kembali.
 */
class ExternalApproval extends BaseModel
{
    protected $table = 'core_external_approvals';

    protected $hidden = ['token_hash'];

    public const PARTIES = ['mk' => 'MK', 'owner' => 'Pemilik'];

    public const VIA_LINK = 'link';

    public const VIA_PHYSICAL = 'physical';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'decided_at' => 'datetime',
            'decision' => ExternalDecision::class,
        ];
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'attachment_id');
    }

    public function partyLabel(): string
    {
        return self::PARTIES[$this->party] ?? $this->party;
    }

    public function isDecided(): bool
    {
        return $this->decided_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Tepi tepat: expires_at = sekarang sudah kedaluwarsa. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! now()->lt($this->expires_at);
    }
}
