<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Core\Jobs\DeliverWebhook;
use Modules\Core\Models\WebhookDelivery;
use Modules\Core\Models\WebhookSubscription;
use Modules\Core\Support\AttachableDocuments;
use Modules\Core\Support\WebhookPayload;
use Modules\Core\Support\WebhookSignature;
use Throwable;

/**
 * Dari satu `DocumentTransitioned` menjadi nol atau lebih baris kiriman.
 *
 * SATU PERISTIWA, SATU ID. Semua langganan yang mendengarkan transisi yang
 * sama menerima `X-Nusantara-Event` yang SAMA, dan kelima percobaan sebuah
 * baris juga — percobaan ulang bukan peristiwa baru, dan penerima yang menolak
 * kiriman ganda berdasarkan id itu harus bisa mempercayainya.
 */
class WebhookService
{
    /**
     * Berapa kali BERTURUT-TURUT sebuah langganan boleh gagal sebelum
     * dinonaktifkan sendiri.
     *
     * KEPUTUSAN, dan alasannya (LAPORAN P-3d §2): langganan yang URL-nya mati
     * membakar lima percobaan bertingkat untuk SETIAP transisi dokumen, selama
     * berhari-hari, di pekerja antrean yang sama yang mengantre e-mail dan
     * WhatsApp. Dua puluh kegagalan beruntun bukan gangguan sesaat — dengan
     * backoff rumah, sebuah gangguan satu jam tidak akan pernah mencapainya.
     * Yang dinonaktifkan MENGATAKANNYA: `disabled_reason` di layar, satu
     * notifikasi ke pemegang core.update, dan tombol Aktifkan lagi. Sebuah
     * langganan yang berhenti bekerja tanpa mengatakannya adalah kegagalan
     * diam, yang justru dihindari seluruh paket ini.
     */
    public const DISABLE_AFTER_FAILURES = 20;

    public const DISABLED_REASON = 'Dinonaktifkan otomatis setelah %d pengiriman gagal berturut-turut. Sebab terakhir: %s';

    public const DISABLED_TITLE = 'Langganan webhook dinonaktifkan otomatis';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Antrekan kiriman untuk setiap langganan yang mendengarkan transisi ini.
     *
     * TIDAK BOLEH MENJATUHKAN PERSETUJUANNYA. Dipanggil dari pendengar yang
     * berjalan sesudah commit, tetapi sebuah tabel yang hilang atau sebuah
     * baris langganan yang rusak tetap tidak boleh melempar ke atas: dokumennya
     * sudah disetujui, dan webhook bersifat advisory.
     *
     * @return int jumlah baris kiriman yang diantrekan
     */
    public function dispatchFor(Model $document, string $action, ?User $actor = null, ?string $note = null): int
    {
        try {
            return $this->queueDeliveries($document, $action, $actor, $note);
        } catch (Throwable $e) {
            Log::warning('Webhook keluar gagal diantrekan untuk '.$document::class.' #'.$document->getKey().': '.$e->getMessage());

            return 0;
        }
    }

    private function queueDeliveries(Model $document, string $action, ?User $actor, ?string $note): int
    {
        $event = WebhookPayload::eventFor($action);

        if (! in_array($event, WebhookPayload::EVENTS, true)) {
            return 0;
        }

        $documentType = AttachableDocuments::slugForClass($document::class) ?? $document->getMorphClass();

        $subscriptions = WebhookSubscription::query()->deliverable()->orderBy('id')->get()
            ->filter(fn (WebhookSubscription $subscription): bool => $subscription->listensTo($event, $documentType));

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $eventId = WebhookPayload::newEventId();
        $payload = WebhookPayload::build($document, $action, $actor, $note, $eventId);
        // SATU KALI menjadi byte, dan byte itulah yang ditandatangani, dikirim,
        // dan disimpan. Lihat WebhookPayload::encode().
        $body = WebhookPayload::encode($payload);
        $timestamp = now()->getTimestamp();
        $queued = 0;

        foreach ($subscriptions as $subscription) {
            $delivery = WebhookDelivery::query()->create([
                'subscription_id' => $subscription->getKey(),
                'subscription_name' => $subscription->name,
                'url' => $subscription->url,
                'event_id' => $eventId,
                'event' => $event,
                'document_type' => $documentType,
                'document_id' => (int) $document->getKey(),
                'document_code' => $payload['data']['document_code'],
                'payload' => $body,
                'signature' => WebhookSignature::header($body, (string) $subscription->secret, $timestamp),
                'status' => WebhookDelivery::QUEUED,
            ]);

            DeliverWebhook::dispatch((int) $delivery->getKey());
            $queued++;
        }

        return $queued;
    }

    public function recordSuccess(WebhookSubscription $subscription): void
    {
        $subscription->forceFill([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
        ])->save();
    }

    /**
     * Satu kegagalan PENGIRIMAN (bukan satu percobaan) — dan, pada kegagalan
     * ke-20 beruntun, penonaktifan yang mengatakan dirinya.
     */
    public function recordFailure(WebhookSubscription $subscription, string $reason): void
    {
        $failures = (int) $subscription->consecutive_failures + 1;

        $subscription->forceFill([
            'last_failure_at' => now(),
            'consecutive_failures' => $failures,
        ])->save();

        if ($failures < self::DISABLE_AFTER_FAILURES || $subscription->disabled_at !== null) {
            return;
        }

        $subscription->forceFill([
            'disabled_at' => now(),
            'disabled_reason' => mb_substr(sprintf(self::DISABLED_REASON, $failures, $reason), 0, 255),
        ])->save();

        $this->notifications->system(
            'core.update',
            self::DISABLED_TITLE,
            "Langganan «{$subscription->name}» tidak dikirimi lagi setelah {$failures} pengiriman gagal berturut-turut. "
            .'Perbaiki alamat penerimanya, lalu tekan Aktifkan lagi di Sistem › Webhook.',
            '/webhook',
            7,
            'webhook-disabled-'.$subscription->getKey(),
            null,
        );
    }
}
