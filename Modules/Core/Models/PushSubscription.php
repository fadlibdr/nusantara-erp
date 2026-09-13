<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu langganan web push = satu perangkat (P-3e, T3e.2).
 *
 * Lihat komentar migrasi 001804 untuk alasan bentuk kolomnya. Yang penting di
 * sini: IDENTITAS sebuah langganan adalah hash endpoint-nya, bukan id barisnya
 * dan bukan (user_id, perangkat) — peramban yang berlangganan ulang memberi
 * endpoint yang sama bila langganannya masih sama, dan endpoint BARU bila
 * peramban memutarnya (pushsubscriptionchange). Karena itu setiap pendaftaran
 * lewat updateOrCreate atas `endpoint_hash`, dan tidak pernah lewat create().
 */
class PushSubscription extends BaseModel
{
    protected $table = 'core_push_subscriptions';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_success_at' => 'datetime',
        ];
    }

    /**
     * sha256 heksadesimal dari endpoint — 64 karakter tetap, jadi ia bisa
     * diindeks unik sementara endpoint-nya sendiri (188+ karakter, tanpa batas
     * yang dijanjikan spesifikasi) tidak bisa.
     */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', trim($endpoint));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Nama yang dibaca manusia di layar dan di kolom "Penerima" kotak keluar. */
    public function label(): string
    {
        return trim((string) $this->device_label) ?: 'Perangkat tanpa label';
    }
}
