<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Http\Requests\WebhookSubscriptionRequest;
use Modules\Core\Models\WebhookDelivery;
use Modules\Core\Models\WebhookSubscription;
use Modules\Core\Services\WebhookService;
use Modules\Core\Support\AttachableDocuments;
use Modules\Core\Support\WebhookPayload;
use Modules\Core\Support\WebhookSignature;

/**
 * Sistem › Webhook — langganan keluar dan log pengirimannya (P-3d).
 *
 * RAHASIA TAMPIL SEKALI, di jawaban permintaan yang MEMBUAT atau yang MEMUTAR
 * langganannya. Tidak ada pintu ketiga yang membacakannya kembali; sesudah
 * layar ditutup, satu-satunya cara mendapatkannya lagi adalah memutarnya, yang
 * berarti penerima lama berhenti bisa memverifikasi — dan itu memang
 * konsekuensi yang benar dari sebuah rahasia yang hilang.
 */
class WebhookController extends ApiController
{
    public const SECRET_SHOWN_ONCE = 'Salin rahasia ini sekarang dan pasang di penerima. '
        .'Ia tidak akan ditampilkan lagi — yang tersimpan di server terenkripsi dan tidak dipulangkan API mana pun. '
        .'Kehilangan rahasianya berarti memutarnya, dan memutarnya membuat penerima lama berhenti bisa memverifikasi kiriman.';

    public function __construct(private readonly WebhookService $webhooks) {}

    public function index(): JsonResponse
    {
        $subscriptions = WebhookSubscription::query()->orderByDesc('id')->get()
            ->map(fn (WebhookSubscription $row): array => $this->describe($row))
            ->all();

        return $this->ok([
            'subscriptions' => $subscriptions,
            'selectable_events' => WebhookPayload::EVENTS,
            'selectable_document_types' => AttachableDocuments::slugs(),
            'payload_version' => WebhookPayload::VERSION,
            // Resep tanda tangan dibaca layar dan panduan DARI SINI, bukan
            // diketik ulang di masing-masing: satu-satunya cara agar ketiganya
            // tidak pernah berbeda pendapat tentang apa yang ditandatangani.
            'signature' => [
                'header' => WebhookSignature::HEADER,
                'event_header' => WebhookSignature::EVENT_HEADER,
                'algorithm' => WebhookSignature::ALGORITHM,
                'signed_value' => 't.badan_mentah',
                'tolerance_seconds' => WebhookSignature::TOLERANCE,
                'secret_form' => WebhookSignature::SECRET_FORM,
                'note' => 'HMAC-SHA256 atas "<t>.<badan mentah>" dengan rahasia langganan; '
                    .'bandingkan dengan hash_equals, tolak bila selisih waktu lebih dari '
                    .WebhookSignature::TOLERANCE.' detik, dan tolak '.WebhookSignature::EVENT_HEADER
                    .' yang sudah pernah diproses. Redirect tidak diikuti dan hanya jawaban 2xx dihitung terkirim.',
            ],
            'disable_after_failures' => WebhookService::DISABLE_AFTER_FAILURES,
        ]);
    }

    public function store(WebhookSubscriptionRequest $request): JsonResponse
    {
        $secret = WebhookSignature::newSecret();

        $subscription = new WebhookSubscription;
        $subscription->forceFill($this->attributes($request) + [
            'secret' => $secret,
            'secret_set_at' => now(),
            'created_by' => $request->user()?->getKey(),
        ])->save();

        return $this->created([
            'secret' => $secret,
            'shown_once' => self::SECRET_SHOWN_ONCE,
        ] + $this->describe($subscription->fresh()), 'Langganan webhook dibuat.');
    }

    public function update(WebhookSubscriptionRequest $request, WebhookSubscription $webhook): JsonResponse
    {
        $webhook->forceFill($this->attributes($request))->save();

        return $this->ok($this->describe($webhook->fresh()), 'Langganan webhook diperbarui.');
    }

    /**
     * Memutar rahasia: nilai baru tampil sekali, nilai lama berhenti berlaku
     * SEKETIKA — termasuk untuk kiriman yang sudah diantrekan, yang percobaan
     * berikutnya ditandatangani dengan rahasia BARU (V-webhook-1: tanda tangan
     * dihitung per percobaan, tepat sebelum POST-nya, karena stempel waktunya
     * ikut ditandatangani). Penerima yang memasang rahasia barunya lebih dulu
     * karena itu tidak kehilangan kiriman yang sedang dicoba ulang.
     */
    public function rotate(WebhookSubscription $webhook): JsonResponse
    {
        $secret = WebhookSignature::newSecret();

        $webhook->forceFill(['secret' => $secret, 'secret_set_at' => now()])->save();

        return $this->ok([
            'secret' => $secret,
            'shown_once' => self::SECRET_SHOWN_ONCE,
        ] + $this->describe($webhook->fresh()), 'Rahasia langganan diputar.');
    }

    /** Menyalakan kembali langganan yang dinonaktifkan otomatis: hitungannya ikut nol. */
    public function enable(WebhookSubscription $webhook): JsonResponse
    {
        $webhook->forceFill([
            'is_active' => true,
            'disabled_at' => null,
            'disabled_reason' => null,
            'consecutive_failures' => 0,
        ])->save();

        return $this->ok($this->describe($webhook->fresh()), 'Langganan diaktifkan lagi.');
    }

    public function destroy(WebhookSubscription $webhook): JsonResponse
    {
        $name = (string) $webhook->name;
        $webhook->delete();

        // Baris log SENGAJA tidak ikut terhapus: log pengiriman adalah sejarah,
        // dan pertanyaan "apa yang pernah kita kirim ke sana" tidak berhenti
        // relevan karena langganannya dicabut.
        return $this->ok(null, "Langganan «{$name}» dihapus. Log pengiriman yang sudah ada tetap tersimpan.");
    }

    public function deliveries(Request $request): JsonResponse
    {
        $query = WebhookDelivery::query()->orderByDesc('id');

        if (($status = $request->query('status')) !== null && in_array($status, [WebhookDelivery::QUEUED, WebhookDelivery::SENT, WebhookDelivery::FAILED], true)) {
            $query->where('status', $status);
        }

        if (($subscription = $request->integer('subscription_id')) > 0) {
            $query->where('subscription_id', $subscription);
        }

        return $this->listing($request, $query, null, ['id', 'created_at', 'status'], 'created_at', [
            'counts' => [
                WebhookDelivery::QUEUED => WebhookDelivery::query()->where('status', WebhookDelivery::QUEUED)->count(),
                WebhookDelivery::SENT => WebhookDelivery::query()->where('status', WebhookDelivery::SENT)->count(),
                WebhookDelivery::FAILED => WebhookDelivery::query()->where('status', WebhookDelivery::FAILED)->count(),
            ],
        ], 20, function ($rows) {
            return $rows->map(function (WebhookDelivery $row): array {
                return [
                    'id' => $row->getKey(),
                    'subscription_id' => $row->subscription_id,
                    'subscription_name' => $row->subscription_name,
                    'url' => $row->url,
                    'event' => $row->event,
                    'event_id' => $row->event_id,
                    'document_type' => $row->document_type,
                    'document_id' => $row->document_id,
                    'document_code' => $row->document_code,
                    'status' => $row->status,
                    'attempts' => (int) $row->attempts,
                    'response_status' => $row->response_status,
                    'error' => $row->error,
                    'next_attempt_at' => $row->next_attempt_at?->toIso8601String(),
                    'delivered_at' => $row->delivered_at?->toIso8601String(),
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            });
        });
    }

    /** @return array<string, mixed> */
    private function attributes(WebhookSubscriptionRequest $request): array
    {
        $types = $request->input('document_types');

        return [
            'name' => trim((string) $request->input('name')),
            'url' => trim((string) $request->input('url')),
            'events' => array_values(array_unique($request->input('events'))),
            'document_types' => is_array($types) && $types !== [] ? array_values(array_unique($types)) : null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /** @return array<string, mixed> */
    private function describe(WebhookSubscription $row): array
    {
        return [
            'id' => $row->getKey(),
            'name' => (string) $row->name,
            'url' => (string) $row->url,
            'events' => (array) $row->events,
            'document_types' => $row->document_types,
            // Kalimat, bukan sel kosong: "semua jenis dokumen" adalah pilihan
            // yang harus terbaca sebagai pilihan.
            'document_types_label' => $row->document_types === null || $row->document_types === []
                ? 'Semua jenis dokumen'
                : implode(', ', array_map(AttachableDocuments::labelFor(...), $row->document_types)),
            'is_active' => (bool) $row->is_active,
            'disabled_at' => $row->disabled_at?->toIso8601String(),
            'disabled_reason' => $row->disabled_reason,
            'consecutive_failures' => (int) $row->consecutive_failures,
            'last_success_at' => $row->last_success_at?->toIso8601String(),
            'last_failure_at' => $row->last_failure_at?->toIso8601String(),
            'secret_set_at' => $row->secret_set_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
