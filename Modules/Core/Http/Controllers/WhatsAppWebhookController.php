<?php

namespace Modules\Core\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Http\ApiController;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\ProviderErrorScrubber;
use Modules\Core\Support\WhatsAppSetup;

/**
 * Webhook status Meta Cloud API — PERMUKAAN PUBLIK (P-3a, T3a.3, perangkap D).
 *
 * Dua rute di Modules/Core/Routes/web.php, tanpa grup 'web' (tidak ada sesi,
 * tidak ada CSRF — pola halaman persetujuan eksternal):
 *
 *   GET  /whatsapp/webhook   verifikasi langganan Meta: hub.mode=subscribe,
 *                            hub.verify_token = WHATSAPP_VERIFY_TOKEN, jawab
 *                            hub.challenge apa adanya (text/plain). Salah/
 *                            kosong → 403.
 *   POST /whatsapp/webhook   status pesan: sent / delivered / read / failed.
 *
 * ATURAN POST, tidak satu pun boleh longgar:
 *  - Tanda tangan WAJIB atas BADAN MENTAH ($request->getContent()), bukan
 *    JSON yang di-decode lalu di-encode ulang — urutan kunci/spasi yang
 *    berbeda mengubah HMAC-nya. X-Hub-Signature-256 = "sha256=" +
 *    HMAC-SHA256(badan, WHATSAPP_APP_SECRET), dibandingkan hash_equals
 *    (waktu-konstan). Hilang/salah → 403 TANPA menyentuh satu baris pun.
 *  - Tanpa WHATSAPP_APP_SECRET di .env → 403 untuk semua orang; webhook tanpa
 *    tanda tangan tidak pernah diterima.
 *  - Hanya memperbarui baris yang provider_id-nya (wamid) COCOK, kanalnya
 *    whatsapp, DAN statusnya `sent`. Tidak pernah membuat baris. wamid yang
 *    tidak dikenal → 200 dan diabaikan: Meta mengulang webhook yang tidak 200,
 *    dan mengulang status untuk pesan yang bukan milik kita tidak berguna bagi
 *    siapa pun. Baris `queued`/`failed`/`skipped` juga 200-dan-abaikan: status
 *    Meta hanya bermakna untuk pesan yang sedang diterima penyedia — Kirim
 *    ulang mengosongkan wamid lama (NotificationService::retry), dan saringan
 *    ini adalah pertahanan keduanya (verifikasi P-3a, 12 Sep 2026: webhook
 *    `failed` yang Meta ulang untuk wamid lama membatalkan kirim ulang yang
 *    sudah antre).
 *  - Urutan: sent < delivered < read; status yang lebih rendah tidak menimpa
 *    yang lebih tinggi (webhook bisa datang tidak berurutan). `failed`
 *    selalu berlaku: status baris → failed (pesan itu memang tidak sampai),
 *    error = pesan Meta yang SUDAH DISARING (ProviderErrorScrubber).
 *  - Badan yang bukan JSON/bentuk yang tidak dikenal → 200, nol perubahan.
 */
class WhatsAppWebhookController extends ApiController
{
    private const RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public function verify(Request $request): Response
    {
        $expected = WhatsAppSetup::verifyToken();
        $query = $request->query();
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        if ($expected === null || $mode !== 'subscribe' || $token === '' || ! hash_equals($expected, $token)) {
            abort(403, 'Verifikasi webhook ditolak.');
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function statuses(Request $request): JsonResponse
    {
        $secret = WhatsAppSetup::appSecret();
        $raw = (string) $request->getContent();
        $header = trim((string) $request->header('X-Hub-Signature-256', ''));

        if ($secret === null || $header === '' || ! str_starts_with($header, 'sha256=')) {
            abort(403, 'Tanda tangan webhook tidak ada.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        if (! hash_equals($expected, $header)) {
            abort(403, 'Tanda tangan webhook tidak cocok.');
        }

        $body = json_decode($raw, true);
        $received = 0;
        $updated = 0;

        foreach (self::statusesIn(is_array($body) ? $body : []) as $status) {
            $received++;
            $updated += $this->applyStatus($status) ? 1 : 0;
        }

        return $this->ok(['received' => $received, 'updated' => $updated]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return \Generator<int, array<string, mixed>>
     */
    private static function statusesIn(array $body): \Generator
    {
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    if (is_array($status)) {
                        yield $status;
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(array $status): bool
    {
        $wamid = trim((string) ($status['id'] ?? ''));
        $state = strtolower(trim((string) ($status['status'] ?? '')));

        if ($wamid === '' || ($state !== 'failed' && ! isset(self::RANK[$state]))) {
            return false;
        }

        $delivery = NotificationDelivery::query()
            ->where('channel', NotificationDelivery::CHANNEL_WHATSAPP)
            ->where('status', NotificationDelivery::SENT)
            ->where('provider_id', $wamid)
            ->first();

        if ($delivery === null) {
            return false;
        }

        // Detik Unix Meta → instan UTC → ZONA APLIKASI sebelum disimpan. Tanpa
        // konversi ini Eloquent memformat Carbon dalam zonanya sendiri (UTC),
        // dan 00:00 UTC tersimpan sebagai "00:00" lalu terbaca 00:00 WIB —
        // tujuh jam lebih awal (kelas cacat yang sama dengan absensi F-4).
        $at = is_numeric($status['timestamp'] ?? null)
            ? CarbonImmutable::createFromTimestamp((int) $status['timestamp'])->setTimezone((string) config('app.timezone'))
            : CarbonImmutable::now();

        if ($state === 'failed') {
            $errors = (array) ($status['errors'] ?? []);
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $text = sprintf(
                'Meta melaporkan gagal%s%s%s',
                is_numeric($first['code'] ?? null) ? " ({$first['code']})" : '',
                filled($first['title'] ?? null) ? ': '.$first['title'] : '',
                filled($first['error_data']['details'] ?? null) ? ' — '.$first['error_data']['details'] : '',
            );

            $delivery->forceFill([
                'status' => NotificationDelivery::FAILED,
                'provider_status' => 'failed',
                'provider_status_at' => $at,
                'error' => ProviderErrorScrubber::whatsapp($text),
                'next_attempt_at' => null,
            ])->save();

            return true;
        }

        $current = self::RANK[(string) $delivery->provider_status] ?? 0;
        if (self::RANK[$state] <= $current) {
            return false;
        }

        $delivery->forceFill([
            'provider_status' => $state,
            'provider_status_at' => $at,
        ])->save();

        return true;
    }
}
