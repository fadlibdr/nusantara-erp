<?php

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Core\Models\WebhookDelivery;
use Modules\Core\Models\WebhookSubscription;
use Modules\Core\Services\WebhookService;
use Modules\Core\Support\ProviderErrorScrubber;
use Modules\Core\Support\WebhookSignature;
use Modules\Core\Support\WebhookUrl;
use Throwable;

/**
 * Kirim SATU baris `core_webhook_deliveries` ke URL langganannya (P-3d).
 *
 * POLA ANTREAN RUMAH, sama persis dengan `DeliverNotification`:
 * `ShouldQueueAfterCommit`, lima percobaan, backoff 60/300/900/3600 detik.
 * Yang dipegang job hanya id barisnya — baris itulah kebenaran tentang
 * pengiriman ini, dan pekerja yang menjalankannya tiga jam kemudian
 * membacanya segar dari basis data, bukan dari muatan yang dibekukan saat
 * dispatch.
 *
 * `ShouldQueueAfterCommit` DI SINI BUKAN HIASAN (perangkap C).
 * `DocumentTransitioned` dipancarkan DARI DALAM transaksi bisnis yang masih
 * menulis ke buku besar. Sebuah persetujuan yang dibatalkan sesaat kemudian —
 * periode fiskal yang tertutup, jurnal yang tidak seimbang — sudah terlanjur
 * memberi tahu sistem lain bahwa dokumennya disetujui, dan sebuah POST tidak
 * bisa ditarik kembali. Pendengarnya `ShouldHandleEventsAfterCommit` dan
 * job-nya `ShouldQueueAfterCommit`: dua lapis yang sama-sama menunggu commit.
 *
 * `sent` HANYA BILA PENERIMA MENJAWAB 2xx. Sebuah 302 ke mana pun bukan
 * terkirim — redirect sengaja tidak diikuti (lihat WebhookUrl) — dan sebuah
 * 500 apalagi. Tidak ada jalur yang membuat kegagalan tampak berhasil.
 */
class DeliverWebhook implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Lima percobaan — ROADMAP-HASHMICRO P-3d, dipaku literal di WebhookRetryScheduleTest. */
    public const TRIES = 5;

    /** @var list<int> */
    public const BACKOFF = [60, 300, 900, 3600];

    public int $tries = self::TRIES;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function handle(WebhookService $webhooks): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        // Baris dihapus, atau sudah selesai lewat jalur lain: tidak ada yang
        // perlu dikirim, dan mengirim ulang kiriman yang sudah `sent` adalah
        // kesalahan yang lebih buruk daripada tidak mengirim.
        if ($delivery === null || $delivery->status !== WebhookDelivery::QUEUED) {
            return;
        }

        $subscription = WebhookSubscription::query()->find($delivery->subscription_id);

        if ($subscription === null) {
            $this->stop($delivery, 'Langganan webhook ini sudah dihapus sebelum kiriman sempat berangkat.');

            return;
        }

        if (! $subscription->is_active || $subscription->disabled_at !== null) {
            $this->stop($delivery, 'Langganan webhook ini dimatikan sebelum kiriman sempat berangkat.');

            return;
        }

        // attempts disimpan SEBELUM mengirim: pekerja yang dibunuh pada batas
        // --timeout tidak pernah sampai ke blok catch, dan tanpa ini barisnya
        // tetap attempts=0 sesudah lima kali dibunuh (pelajaran P-0b).
        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            // DNS DIPERIKSA LAGI DI SINI, bukan hanya saat langganan disimpan:
            // sebuah nama yang kemarin menunjuk ke alamat publik bisa hari ini
            // menunjuk ke 127.0.0.1 (perangkap E).
            WebhookUrl::assertSafeToSend((string) $delivery->url);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'Nusantara-ERP-Webhook/'.WebhookSignature::ALGORITHM,
                WebhookSignature::HEADER => (string) $delivery->signature,
                WebhookSignature::EVENT_HEADER => (string) $delivery->event_id,
                WebhookSignature::DELIVERY_HEADER => (string) $delivery->getKey(),
            ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(WebhookUrl::CONNECT_TIMEOUT)
                ->timeout(WebhookUrl::TIMEOUT)
                // BYTE YANG DITANDATANGANI, APA ADANYA. Bukan ->post($url, $array),
                // yang akan meng-encode ulang muatannya dan bisa menghasilkan byte
                // yang berbeda dari yang dipakai menghitung tanda tangan.
                ->withBody((string) $delivery->payload, 'application/json')
                ->post((string) $delivery->url);
        } catch (Throwable $e) {
            $this->recordAttemptFailure($delivery, $subscription, null, ProviderErrorScrubber::scrub($e->getMessage()), $webhooks);

            // Alamat yang ditolak kebijakan SSRF tidak akan berubah karena
            // diulang empat kali lagi; ia gagal SEKETIKA, seperti penolakan
            // permanen penyedia di DeliverNotification.
            if ($e instanceof \LogicException) {
                $this->stop($delivery, ProviderErrorScrubber::scrub($e->getMessage()));
                $webhooks->recordFailure($subscription, ProviderErrorScrubber::scrub($e->getMessage()));

                return;
            }

            throw $e;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDelivery::SENT,
                'response_status' => $response->status(),
                'error' => null,
                'delivered_at' => now(),
                'next_attempt_at' => null,
            ])->save();

            $webhooks->recordSuccess($subscription);

            return;
        }

        $reason = $response->redirect()
            ? "Penerima menjawab {$response->status()} (redirect). Redirect tidak diikuti: sebuah kiriman bertanda tangan "
                .'yang mengikuti Location bisa mendarat di alamat internal. Pakai URL tujuan akhirnya langsung.'
            : "Penerima menjawab {$response->status()}. ".ProviderErrorScrubber::scrub((string) $response->body());

        $this->recordAttemptFailure($delivery, $subscription, $response->status(), $reason, $webhooks);

        throw new \RuntimeException($reason);
    }

    /**
     * Dipanggil pekerja sesudah percobaan terakhir (atau saat job kedaluwarsa).
     *
     * Dua pengecualian datang dari PEKERJA, bukan penerima, dan kalimat
     * Inggrisnya bukan pesan penerima yang dijanjikan kolom `error`.
     */
    public function failed(?Throwable $e): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== WebhookDelivery::QUEUED) {
            return;
        }

        $last = trim((string) $delivery->error);
        $suffix = $last === '' ? '' : " Jawaban terakhir penerima: {$last}";

        $error = match (true) {
            $e instanceof TimeoutExceededException => 'Pekerja antrean kehabisan waktu saat mengirim — penerima tidak menjawab dalam batas waktu pekerja.'.$suffix,
            $e instanceof MaxAttemptsExceededException => 'Percobaan habis sebelum penerima menjawab.'.$suffix,
            $e === null => $last === '' ? 'Gagal tanpa pesan.' : $last,
            default => ProviderErrorScrubber::scrub($e->getMessage()),
        };

        $this->stop($delivery, $error);

        $subscription = WebhookSubscription::query()->find($delivery->subscription_id);

        if ($subscription !== null) {
            app(WebhookService::class)->recordFailure($subscription, $error);
        }
    }

    private function recordAttemptFailure(
        WebhookDelivery $delivery,
        WebhookSubscription $subscription,
        ?int $status,
        string $reason,
        WebhookService $webhooks,
    ): void {
        $attempt = $this->attempts();
        $delay = self::BACKOFF[$attempt - 1] ?? null;

        $delivery->forceFill([
            'response_status' => $status,
            'error' => Str::limit($reason, 490),
            'next_attempt_at' => $delay === null || $attempt >= $this->tries ? null : now()->addSeconds($delay),
        ])->save();

        unset($webhooks);
    }

    private function stop(WebhookDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => WebhookDelivery::FAILED,
            'error' => Str::limit($reason, 490),
            'next_attempt_at' => null,
        ])->save();
    }
}
