<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Support\WebhookPayload;

/**
 * Satu penerima webhook keluar (P-3d).
 *
 * RAHASIANYA TERENKRIPSI DI KOLOM DAN TIDAK PERNAH DIPULANGKAN API. Cast
 * `encrypted` memakai `APP_KEY`, jadi sebuah dump basis data yang bocor tidak
 * menyerahkan kemampuan menandatangani kiriman atas nama aplikasi ini. Ia
 * tampil SATU KALI, di dalam jawaban permintaan yang membuatnya (atau yang
 * memutarnya), dan sesudah itu tidak ada pintu yang membacakannya kembali —
 * termasuk untuk administrator. `$hidden` menjaga agar ia tidak ikut terbawa
 * oleh `toArray()` yang lalai.
 */
class WebhookSubscription extends BaseModel
{
    protected $table = 'core_webhook_subscriptions';

    protected $fillable = [
        'name', 'url', 'events', 'document_types', 'is_active', 'created_by',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'document_types' => 'array',
            'is_active' => 'boolean',
            'secret_set_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** Langganan yang benar-benar akan dikirimi: aktif DAN belum dinonaktifkan otomatis. */
    public function scopeDeliverable(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('disabled_at');
    }

    /** Langganan ini mendengarkan peristiwa ini atas jenis dokumen ini? */
    public function listensTo(string $event, string $documentType): bool
    {
        if (! in_array($event, (array) $this->events, true)) {
            return false;
        }

        $types = $this->document_types;

        // null / daftar kosong = SETIAP jenis dokumen. Dituliskan di layar
        // sebagai "semua jenis dokumen", bukan dibiarkan sebagai sel kosong.
        return ! is_array($types) || $types === [] || in_array($documentType, $types, true);
    }

    /** @return list<string> */
    public static function selectableEvents(): array
    {
        return WebhookPayload::EVENTS;
    }
}
