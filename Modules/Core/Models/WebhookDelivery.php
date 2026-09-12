<?php

namespace Modules\Core\Models;

/**
 * SATU BARIS PER PENGIRIMAN, DENGAN STATUS YANG TIDAK BERBOHONG (P-3d,
 * perangkap F; pelajaran P-3a "sent butuh pengenal penyedia").
 *
 *   queued   sedang menunggu percobaan berikutnya; `attempts` adalah riwayat
 *            dan `next_attempt_at` menyebut kapan
 *   sent     penerima menjawab 2xx — DAN HANYA ITU. Jawaban 3xx (redirect
 *            yang sengaja tidak diikuti), 4xx dan 5xx bukan terkirim.
 *   failed   percobaan kelima sudah lewat, atau penerima menolak dengan cara
 *            yang tidak akan berubah bila diulang; `error` menyebut sebabnya
 *            dalam bahasa Indonesia dan sudah lewat ProviderErrorScrubber.
 *
 * Satu baris per PERISTIWA per LANGGANAN, bukan satu per percobaan: kelima
 * percobaan menyangkut kiriman yang sama, membawa `X-Nusantara-Event` yang
 * sama, dan penerima yang benar memperlakukan ketiganya sebagai satu. Berapa
 * kali dicoba dibaca dari `attempts`.
 */
class WebhookDelivery extends BaseModel
{
    protected $table = 'core_webhook_deliveries';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'subscription_id', 'subscription_name', 'url', 'event_id', 'event',
        'document_type', 'document_id', 'document_code', 'payload', 'signature', 'status',
    ];

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
