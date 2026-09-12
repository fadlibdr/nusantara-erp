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

        // RAHASIA YANG TIDAK BISA DIBACA BERHENTI DI SINI, dengan kalimatnya
        // sendiri. Cast `encrypted` melempar ketika ciphertext-nya tidak sah
        // (APP_KEY berganti, baris disunting tangan); sejak tanda tangannya
        // dihitung di job dan bukan saat mengantre (V-webhook-1), di sinilah
        // pembacaan itu terjadi — dan sebuah DecryptException berbahasa Inggris
        // yang diulang lima kali bukan sebab yang bisa dibaca pemilik.
        try {
            $secret = (string) $subscription->secret;
        } catch (Throwable $e) {
            $reason = 'Rahasia langganan ini tidak bisa dibaca dari basis data (ciphertext tidak sah — APP_KEY berubah?). '
                .'Putar rahasianya di Sistem › Webhook, lalu pasang nilai barunya di penerima.';

            $this->stop($delivery, $reason);
            $webhooks->recordFailure($subscription, $reason);

            return;
        }

        // TANDA TANGAN DIHITUNG DI SINI, SEKALI PER PERCOBAAN (V-webhook-1).
        // Stempel waktunya IKUT ditandatangani, dan percobaan kelima berangkat
        // 4.860 detik sesudah barisnya lahir — jauh di luar jendela
        // WebhookSignature::TOLERANCE yang dokumen kita suruh penerima
        // tegakkan. Yang dibekukan hanyalah `payload`: byte-nya sama di kelima
        // percobaan, jadi tanda tangan mana pun tetap bisa diperiksa ulang
        // terhadap baris log (perangkap D tidak tersentuh).
        $signature = WebhookSignature::header((string) $delivery->payload, $secret, now()->getTimestamp());

        // attempts disimpan SEBELUM mengirim: pekerja yang dibunuh pada batas
        // --timeout tidak pernah sampai ke blok catch, dan tanpa ini barisnya
        // tetap attempts=0 sesudah lima kali dibunuh (pelajaran P-0b).
        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'signature' => $signature,
        ])->save();

        try {
            // DNS DIPERIKSA LAGI DI SINI, bukan hanya saat langganan disimpan:
            // sebuah nama yang kemarin menunjuk ke alamat publik bisa hari ini
            // menunjuk ke 127.0.0.1 (perangkap E).
            WebhookUrl::assertSafeToSend((string) $delivery->url);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'Nusantara-ERP-Webhook/'.WebhookSignature::ALGORITHM,
                WebhookSignature::HEADER => $signature,
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
            $scrubbed = ProviderErrorScrubber::scrub($e->getMessage(), $secret === '' ? [] : [$secret]);

            $this->recordAttemptFailure($delivery, null, $scrubbed);

            // Alamat yang ditolak kebijakan SSRF tidak akan berubah karena
            // diulang empat kali lagi; ia gagal SEKETIKA, seperti penolakan
            // permanen penyedia di DeliverNotification.
            if ($e instanceof \LogicException) {
                $this->stop($delivery, $scrubbed);
                $webhooks->recordFailure($subscription, $scrubbed);

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
            : $this->recipientSentence($response->status(), (string) $response->body(), $secret);

        $this->recordAttemptFailure($delivery, $response->status(), $reason);

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
        $subscription = WebhookSubscription::query()->find($delivery->subscription_id);

        $error = match (true) {
            $e instanceof TimeoutExceededException => 'Pekerja antrean kehabisan waktu saat mengirim — penerima tidak menjawab dalam batas waktu pekerja.'.$suffix,
            $e instanceof MaxAttemptsExceededException => 'Percobaan habis sebelum penerima menjawab.'.$suffix,
            $e === null => $last === '' ? 'Gagal tanpa pesan.' : $last,
            default => ProviderErrorScrubber::scrub($e->getMessage(), self::secretsOf($subscription)),
        };

        $this->stop($delivery, $error);

        if ($subscription !== null) {
            app(WebhookService::class)->recordFailure($subscription, $error);
        }
    }

    /**
     * Satu PERCOBAAN yang gagal — bukan satu pengiriman.
     *
     * `consecutive_failures` langganan SENGAJA tidak disentuh di sini:
     * ambang nonaktif-otomatis menghitung PENGIRIMAN yang gagal (lima
     * percobaannya habis), bukan percobaan. Kalau tidak, satu penerima yang
     * mati akan mencapai dua puluh dalam empat pengiriman.
     */
    private function recordAttemptFailure(WebhookDelivery $delivery, ?int $status, string $reason): void
    {
        $attempt = $this->attempts();
        $delay = self::BACKOFF[$attempt - 1] ?? null;

        $delivery->forceFill([
            'response_status' => $status,
            'error' => Str::limit($reason, 490),
            'next_attempt_at' => $delay === null || $attempt >= $this->tries ? null : now()->addSeconds($delay),
        ])->save();
    }

    /**
     * Kalimat untuk jawaban penerima yang BUKAN 2xx.
     *
     * DUA HAL YANG TIDAK BOLEH SAMPAI KE KOLOM `error` (V-webhook-3 dan
     * V-webhook-4). (1) Byte yang bukan teks: sebuah badan galat
     * windows-1252 atau ter-gzip menjatuhkan penulisan barisnya di MySQL
     * (`1366 Incorrect string value`), dan yang akhirnya terbaca di layar
     * adalah kalimat Inggris yang menyebut soket dan nama basis data — bukan
     * sebab pengiriman. Ia diganti hitungan byte-nya. (2) Rahasia langganan:
     * penerima yang menolong ("your secret … is wrong") menuliskannya ke
     * kolom 500 karakter yang dibaca setiap pemegang core.update dan ikut ke
     * setiap backup, membatalkan janji "tampil sekali". Ia diserahkan ke
     * penyaring sebagai rahasia yang DIKENAL, jalur yang sama dengan P-3a.
     */
    private function recipientSentence(int $status, string $body, string $secret): string
    {
        if ($body !== '' && ! mb_check_encoding($body, 'UTF-8')) {
            return "Penerima menjawab {$status} dengan badan yang bukan teks (".strlen($body).' byte). '
                .'Isinya tidak dikutip di sini karena bukan kalimat yang bisa dibaca.';
        }

        return "Penerima menjawab {$status}. ".ProviderErrorScrubber::scrub($body, $secret === '' ? [] : [$secret]);
    }

    /**
     * Rahasia langganan untuk `failed()`, tempat barisnya bisa sudah hilang
     * atau ciphertext-nya tidak bisa dibaca.
     *
     * @return list<string>
     */
    private static function secretsOf(?WebhookSubscription $subscription): array
    {
        try {
            $secret = (string) ($subscription?->secret ?? '');
        } catch (Throwable $e) {
            return [];
        }

        return $secret === '' ? [] : [$secret];
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
