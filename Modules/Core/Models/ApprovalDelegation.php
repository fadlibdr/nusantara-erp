<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu delegasi "a.n." — Budi menyetujui atas nama Sari, dalam sebuah jendela.
 *
 * Aturannya sendiri (siapa boleh apa, siapa tidak boleh menyetujui apa) ada di
 * Core\Support\ApprovalDelegations; model ini hanya barisnya.
 */
class ApprovalDelegation extends BaseModel
{
    protected $table = 'core_approval_delegations';

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'revoked_at' => 'datetime',
    ];

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'giver_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Judul baris ini di Log Audit: "Sari → Budi (prc)".
     *
     * Sebuah atribut turunan, bukan kolom, karena tidak ada satu kolom pun
     * yang menjawab "delegasi yang mana" — dan log audit harus terbaca sesudah
     * kedua akunnya dihapus. AuditedModels::labelFor membacanya lewat
     * getAttribute(), yang menyelesaikan accessor seperti kolom biasa.
     */
    public function getAuditLabelAttribute(): string
    {
        $giver = $this->giver?->name ?? "pengguna #{$this->giver_user_id}";
        $delegate = $this->delegate?->name ?? "pengguna #{$this->delegate_user_id}";

        return $giver.' → '.$delegate.($this->scope === null ? '' : " ({$this->scope})");
    }

    /**
     * Hidup HARI INI: belum dicabut, sudah mulai, belum lewat.
     *
     * Jendelanya inklusif di kedua ujung — "10 sampai 20 September" berarti
     * apa yang dikatakannya, termasuk tanggal 20. Dihitung di sini dan di
     * ApprovalDelegations dengan aturan yang sama; layar tidak boleh menyebut
     * "aktif" sebuah baris yang penjaganya tolak.
     */
    public function isActive(?Carbon $now = null): bool
    {
        $today = ($now ?? now())->startOfDay();

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->startOfDay()->greaterThan($today)) {
            return false;
        }

        return $this->ends_at === null || ! $this->ends_at->startOfDay()->lessThan($today);
    }

    /** "Berjalan", "Dijadwalkan", "Selesai" atau "Dicabut" — apa adanya. */
    public function stateLabel(?Carbon $now = null): string
    {
        $today = ($now ?? now())->startOfDay();

        if ($this->revoked_at !== null) {
            return 'Dicabut';
        }

        if ($this->starts_at !== null && $this->starts_at->startOfDay()->greaterThan($today)) {
            return 'Dijadwalkan';
        }

        return $this->isActive($now) ? 'Berjalan' : 'Selesai';
    }
}
