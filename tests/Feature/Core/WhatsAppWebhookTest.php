<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Tests\ErpTestCase;

/**
 * Webhook status Meta — permukaan publik (P-3a, T3a.3, perangkap D).
 *
 * Dipaku dari sisi yang menolak lebih dulu: tanpa app secret di .env semua
 * 403; tanda tangan hilang/salah/atas badan yang berbeda 403 TANPA menyentuh
 * satu baris pun; badan JSON yang di-encode ulang dengan spasi berbeda
 * membawa tanda tangan yang berbeda (verifikasi atas BADAN MENTAH). Lalu sisi
 * yang menerima: hanya baris whatsapp yang provider_id-nya cocok yang
 * diperbarui, tidak ada baris yang dibuat, wamid tak dikenal 200-dan-abaikan,
 * urutan sent<delivered<read dijaga, `failed` menandai baris gagal dengan
 * pesan Meta yang sudah disaring. GET verifikasi: hub.challenge apa adanya
 * hanya bila verify token cocok.
 */
class WhatsAppWebhookTest extends ErpTestCase
{
    private const SECRET = 'uji-app-secret-RAHASIA-4f2c';

    private const VERIFY = 'uji-verify-token-RAHASIA';

    private function configured(): void
    {
        config(['erp.whatsapp.app_secret' => self::SECRET, 'erp.whatsapp.verify_token' => self::VERIFY, 'erp.whatsapp.token' => 'uji-token-RAHASIA']);
    }

    private function sentRow(string $wamid, string $phone = '+628123456789'): NotificationDelivery
    {
        $user = User::query()->create(['name' => 'Direktur '.$wamid, 'email' => $wamid.'@nusantara.test', 'password' => bcrypt('x'), 'is_active' => true]);
        $notification = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SYSTEM, 'template' => 'backup.stale', 'title' => 'Cadangan macet', 'body' => 'x']);

        return NotificationDelivery::query()->create([
            'notification_id' => $notification->id, 'channel' => 'whatsapp', 'recipient' => $phone,
            'status' => 'sent', 'attempts' => 1, 'provider_id' => $wamid, 'sent_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: string} [badan mentah, header tanda tangan] */
    private function signed(array $payload, ?string $secret = self::SECRET): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return [$raw, 'sha256='.hash_hmac('sha256', $raw, (string) $secret)];
    }

    private function statusPayload(string $wamid, string $status, array $extra = []): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => '1234567890', 'changes' => [[
                'field' => 'messages',
                'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '109876543210'], 'statuses' => [
                    ['id' => $wamid, 'status' => $status, 'timestamp' => '1789171200', 'recipient_id' => '628123456789'] + $extra,
                ]],
            ]],
        ]]];
    }

    private function hook(string $raw, ?string $signature): TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        }

        return $this->call('POST', '/whatsapp/webhook', [], [], [], $headers, $raw);
    }

    // ------------------------------------------------------------ verifikasi

    public function test_get_verification_echoes_the_challenge_only_for_the_right_token(): void
    {
        $this->configured();

        $ok = $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.verify_token='.self::VERIFY.'&hub.challenge=1158201444');
        $ok->assertOk();
        $this->assertSame('1158201444', $ok->getContent());
        $this->assertStringStartsWith('text/plain', (string) $ok->headers->get('Content-Type'));

        $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=salah&hub.challenge=1')->assertForbidden();
        $this->get('/whatsapp/webhook?hub.mode=unsubscribe&hub.verify_token='.self::VERIFY.'&hub.challenge=1')->assertForbidden();
        $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.challenge=1')->assertForbidden();

        config(['erp.whatsapp.verify_token' => null]);
        $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
        $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.verify_token='.self::VERIFY.'&hub.challenge=1')->assertForbidden();
    }

    // ------------------------------------------------------- tanda tangan

    public function test_without_an_app_secret_every_post_is_refused(): void
    {
        $row = $this->sentRow('wamid.A');
        [$raw, $sig] = $this->signed($this->statusPayload('wamid.A', 'delivered'), 'apa-pun');

        $this->hook($raw, $sig)->assertForbidden();
        $this->hook($raw, null)->assertForbidden();

        $this->assertNull($row->refresh()->provider_status);
    }

    public function test_a_missing_or_wrong_signature_is_403_and_touches_no_row(): void
    {
        $this->configured();
        $row = $this->sentRow('wamid.A');
        $payload = $this->statusPayload('wamid.A', 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]);
        [$raw, $good] = $this->signed($payload);

        $this->hook($raw, null)->assertForbidden();
        $this->hook($raw, 'sha256=deadbeef')->assertForbidden();
        $this->hook($raw, substr($good, 0, -2).'zz')->assertForbidden();
        $this->hook($raw, 'sha1='.substr($good, 7))->assertForbidden();
        // Ditandatangani dengan secret lain.
        $this->hook($raw, $this->signed($payload, 'secret-lain')[1])->assertForbidden();

        $row->refresh();
        $this->assertSame(NotificationDelivery::SENT, $row->status);
        $this->assertNull($row->provider_status);
        $this->assertNull($row->error);
    }

    /** Verifikasi atas BADAN MENTAH: JSON yang sama dengan spasi berbeda bukan badan yang sama. */
    public function test_the_signature_covers_the_raw_body_not_the_decoded_json(): void
    {
        $this->configured();
        $row = $this->sentRow('wamid.A');
        $payload = $this->statusPayload('wamid.A', 'delivered');
        [, $sig] = $this->signed($payload);
        $pretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->hook($pretty, $sig)->assertForbidden();
        $this->assertNull($row->refresh()->provider_status);

        // …dan badan yang ditandatangani apa adanya diterima.
        $this->hook($pretty, 'sha256='.hash_hmac('sha256', $pretty, self::SECRET))->assertOk();
        $this->assertSame('delivered', $row->refresh()->provider_status);
    }

    // ---------------------------------------------------------- penerapan

    public function test_a_valid_status_updates_only_the_matching_whatsapp_row(): void
    {
        $this->configured();
        $other = $this->sentRow('wamid.LAIN');
        // Umpan: baris E-MAIL dengan pengenal yang kebetulan sama, dibuat LEBIH
        // DULU (id lebih kecil) — tanpa saringan kanal, first() akan memilihnya
        // (mutasi MW3 lolos hijau ketika umpan ini dibuat belakangan).
        $email = NotificationDelivery::query()->create([
            'notification_id' => $other->notification_id, 'channel' => 'email', 'recipient' => 'x@y.test',
            'status' => 'sent', 'attempts' => 1, 'provider_id' => 'wamid.TARGET', 'sent_at' => now(),
        ]);
        $target = $this->sentRow('wamid.TARGET');
        [$raw, $sig] = $this->signed($this->statusPayload('wamid.TARGET', 'delivered'));

        $response = $this->hook($raw, $sig)->assertOk();
        $this->assertSame(['received' => 1, 'updated' => 1], $response->json('data'));

        $this->assertSame('delivered', $target->refresh()->provider_status);
        // 1789171200 = 12 Sep 2026 00:00 UTC = 07:00 WIB. Sebelum konversi zona di
        // controller, kolomnya menyimpan 00:00 dan terbaca 00:00 WIB (tujuh jam lebih awal).
        $this->assertSame('2026-09-12 07:00:00', $target->provider_status_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'), 'timestamp Meta (detik Unix) dipakai, dalam zona yang benar.');
        $this->assertSame(NotificationDelivery::SENT, $target->status, 'delivered tidak mengubah status baris.');
        $this->assertNull($other->refresh()->provider_status);
        $this->assertNull($email->refresh()->provider_status, 'Baris e-mail dengan pengenal kebetulan sama tidak disentuh.');
        $this->assertSame(3, NotificationDelivery::query()->count(), 'Tidak ada baris yang dibuat.');
    }

    public function test_an_unknown_wamid_is_200_and_ignored_and_garbage_bodies_are_200_too(): void
    {
        $this->configured();
        $this->sentRow('wamid.A');

        [$raw, $sig] = $this->signed($this->statusPayload('wamid.TIDAK-DIKENAL', 'read'));
        $this->assertSame(['received' => 1, 'updated' => 0], $this->hook($raw, $sig)->assertOk()->json('data'));

        $raw = 'bukan json';
        $this->assertSame(['received' => 0, 'updated' => 0], $this->hook($raw, 'sha256='.hash_hmac('sha256', $raw, self::SECRET))->assertOk()->json('data'));

        [$raw, $sig] = $this->signed(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [['from' => '628123456789', 'text' => ['body' => 'halo']]]]]]]]]);
        $this->assertSame(['received' => 0, 'updated' => 0], $this->hook($raw, $sig)->assertOk()->json('data'), 'Pesan masuk bukan status — diabaikan, tidak dibalas.');

        $this->assertSame(1, NotificationDelivery::query()->count());
    }

    public function test_out_of_order_statuses_never_downgrade_and_failed_marks_the_row_failed_with_a_scrubbed_message(): void
    {
        $this->configured();
        $row = $this->sentRow('wamid.A');

        [$raw, $sig] = $this->signed($this->statusPayload('wamid.A', 'read'));
        $this->hook($raw, $sig)->assertOk();
        $this->assertSame('read', $row->refresh()->provider_status);

        [$raw, $sig] = $this->signed($this->statusPayload('wamid.A', 'delivered'));
        $this->assertSame(0, $this->hook($raw, $sig)->assertOk()->json('data.updated'));
        $this->assertSame('read', $row->refresh()->provider_status, 'delivered yang datang terlambat tidak menurunkan read.');

        [$raw, $sig] = $this->signed($this->statusPayload('wamid.A', 'sent'));
        $this->hook($raw, $sig)->assertOk();
        $this->assertSame('read', $row->refresh()->provider_status);

        [$raw, $sig] = $this->signed($this->statusPayload('wamid.A', 'failed', ['errors' => [[
            'code' => 131026, 'title' => 'Message undeliverable',
            'message' => 'Message Undeliverable.',
            'error_data' => ['details' => 'Recipient +628123456789 is not a valid WhatsApp user; token uji-token-RAHASIA'],
        ]]]));
        $this->hook($raw, $sig)->assertOk();

        $row->refresh();
        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame('failed', $row->provider_status);
        $this->assertStringStartsWith('Meta melaporkan gagal (131026): Message undeliverable — Recipient [nomor] is not a valid WhatsApp user', (string) $row->error);
        $this->assertStringNotContainsString('uji-token-RAHASIA', (string) $row->error);
        $this->assertNotNull($row->sent_at, 'Diterima penyedia tetap fakta; yang gagal adalah perjalanannya.');
    }

    public function test_the_webhook_needs_no_session_and_no_csrf_token(): void
    {
        $this->configured();
        $row = $this->sentRow('wamid.A');
        [$raw, $sig] = $this->signed($this->statusPayload('wamid.A', 'delivered'));

        // Tanpa cookie, tanpa X-CSRF-TOKEN, tanpa Authorization: hanya tanda tangan.
        $this->hook($raw, $sig)->assertOk();
        $this->assertSame('delivered', $row->refresh()->provider_status);

        // Statusnya ikut ke layar Pengiriman Notifikasi.
        $this->actingAs($this->adminUser(), 'sanctum');
        $listed = $this->getJson('/api/core/notification-deliveries?channel=whatsapp')->assertOk()->json('data.0');
        $this->assertSame('delivered', $listed['provider_status']);
        $this->assertNotNull($listed['provider_status_at']);
    }
}
