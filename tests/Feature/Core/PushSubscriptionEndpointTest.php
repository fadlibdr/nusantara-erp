<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Base64Url\Base64Url;
use Minishlink\WebPush\VAPID;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\DeliveryGate;
use Tests\ErpTestCase;

/**
 * PERANGKAT MILIK SENDIRI, DAN TIDAK PERNAH MILIK ORANG LAIN (P-3e, T3e.4).
 *
 * Ketiga endpoint ini tidak punya gerbang izin — pola me/preferences — jadi
 * yang menggantikannya adalah bentuk kodenya: tidak ada satu parameter pun
 * yang menyebut orang lain, dan destroy() mencari id DI DALAM baris milik
 * pemanggil. Kalau bentuk itu pernah longgar, hasilnya bukan "izin kurang"
 * melainkan seseorang yang mencabut notifikasi orang lain, atau membaca
 * endpoint perangkat orang lain — endpoint adalah kapabilitas: siapa pun yang
 * memegangnya bisa mem-POST ke langganan itu.
 *
 * Karena itu uji di berkas ini menjalankan KEDUA arah: milik sendiri berhasil,
 * milik orang lain dijawab 404 YANG SAMA dengan id yang tidak ada sama sekali
 * (dua kalimat berbeda adalah cara menghitung perangkat orang lain).
 */
class PushSubscriptionEndpointTest extends ErpTestCase
{
    private const P256DH = 'BAQ7Lq3vXk8hHhVrBqEfkS1rXkKq9gYQm2b0sCk5nJd0Uu3rHqDSdwZ9zZKqk1s2OaL0f7d9XyOo2n0F3q9d6bE';

    protected function setUp(): void
    {
        parent::setUp();

        $server = VAPID::createVapidKeys();

        config([
            'erp.push.vapid_public_key' => $server['publicKey'],
            'erp.push.vapid_private_key' => $server['privateKey'],
            'erp.push.vapid_subject' => 'mailto:pemilik@nusantara.test',
        ]);

        app(SettingService::class)->set('notifications.webpush_enabled', true);
    }

    private function endpoint(string $suffix = 'a'): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.str_repeat($suffix, 140);
    }

    private function payload(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => self::P256DH, 'auth' => Base64Url::encode(random_bytes(16))]];
    }

    private function deviceFor(User $user, string $suffix, string $label = 'Chrome di Android'): PushSubscription
    {
        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $this->endpoint($suffix),
            'endpoint_hash' => PushSubscription::hashFor($this->endpoint($suffix)),
            'p256dh' => self::P256DH,
            'auth' => Base64Url::encode(random_bytes(16)),
            'device_label' => $label,
        ]);
    }

    public function test_every_endpoint_needs_a_session(): void
    {
        $this->getJson('api/core/me/push-subscriptions')->assertUnauthorized();
        $this->postJson('api/core/me/push-subscriptions', $this->payload($this->endpoint()))->assertUnauthorized();
        $this->deleteJson('api/core/me/push-subscriptions/1')->assertUnauthorized();
    }

    public function test_registering_a_device_stores_it_with_a_readable_label(): void
    {
        $user = User::factory()->create();
        $endpoint = $this->endpoint();

        $this->actingAs($user, 'sanctum')
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36')
            ->postJson('api/core/me/push-subscriptions', $this->payload($endpoint))
            ->assertOk()
            ->assertJsonPath('data.label', 'Chrome di Android');

        $row = PushSubscription::query()->sole();

        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame($endpoint, (string) $row->endpoint, 'Endpoint harus tersimpan UTUH.');
        $this->assertSame(PushSubscription::hashFor($endpoint), (string) $row->endpoint_hash);
        $this->assertNull($row->last_success_at, '"Terakhir berhasil" kosong sampai ada pengiriman yang benar-benar berhasil — bukan tanggal pendaftaran.');
    }

    public function test_registering_the_same_browser_twice_keeps_one_row(): void
    {
        $user = User::factory()->create();
        $endpoint = $this->endpoint();

        $this->actingAs($user, 'sanctum')->postJson('api/core/me/push-subscriptions', $this->payload($endpoint))->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('api/core/me/push-subscriptions', $this->payload($endpoint))->assertOk();

        $this->assertSame(1, PushSubscription::query()->count(), 'Menekan Aktifkan dua kali di peramban yang sama tidak boleh membuat orangnya menerima setiap pemberitahuan dua kali.');
    }

    public function test_a_non_https_endpoint_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('api/core/me/push-subscriptions', ['endpoint' => 'http://fcm.googleapis.com/fcm/send/x', 'keys' => ['p256dh' => self::P256DH, 'auth' => 'abc']])
            ->assertStatus(422);

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_list_shows_only_my_own_devices_with_the_public_key_and_the_gate_sentence(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        $mine = $this->deviceFor($me, 'a', 'Chrome di Android');
        $theirs = $this->deviceFor($someoneElse, 'b', 'Safari di iPhone');

        $data = $this->actingAs($me, 'sanctum')->getJson('api/core/me/push-subscriptions')->assertOk()->json('data');

        $this->assertSame([$mine->id], array_column($data['devices'], 'id'), 'Daftar perangkat memuat perangkat orang lain.');
        $this->assertSame((string) $mine->endpoint, $data['devices'][0]['endpoint']);
        $this->assertStringNotContainsString(
            (string) $theirs->endpoint,
            json_encode($data),
            'Endpoint orang lain muncul di jawaban. Endpoint adalah kapabilitas: siapa pun yang memegangnya bisa '
            .'mem-POST ke langganan itu.',
        );

        $this->assertSame(config('erp.push.vapid_public_key'), $data['public_key'], 'Tombol Aktifkan membaca kunci publik dari server; kunci yang diketik ulang di klien akan tidak cocok suatu hari.');
        $this->assertNull($data['server_reason'], 'Sisi server siap: tombol Aktifkan boleh ditawarkan.');
        $this->assertNull($data['reason'], 'Dengan satu perangkat terdaftar dan sakelar nyala, tidak ada sebab Dilewati.');
    }

    public function test_the_list_carries_the_same_sentence_the_outbox_will_write_when_the_switch_is_off(): void
    {
        app(SettingService::class)->set('notifications.webpush_enabled', false);
        $user = User::factory()->create();

        $data = $this->actingAs($user, 'sanctum')->getJson('api/core/me/push-subscriptions')->assertOk()->json('data');

        $this->assertSame(DeliveryGate::WEBPUSH_DISABLED, $data['reason']);
        $this->assertSame(
            DeliveryGate::WEBPUSH_DISABLED,
            $data['server_reason'],
            'Sakelar yang mati adalah jalan buntu yang tidak bisa diatasi tindakan apa pun di peramban; layar harus '
            .'tahu itu supaya tidak menawarkan tombol yang pasti gagal.',
        );
    }

    public function test_the_private_key_never_appears_in_any_answer(): void
    {
        $user = User::factory()->create();
        $this->deviceFor($user, 'a');

        $body = $this->actingAs($user, 'sanctum')->getJson('api/core/me/push-subscriptions')->assertOk()->getContent();

        $this->assertStringNotContainsString((string) config('erp.push.vapid_private_key'), (string) $body);
    }

    public function test_i_can_revoke_my_own_device(): void
    {
        $user = User::factory()->create();
        $device = $this->deviceFor($user, 'a');

        $this->actingAs($user, 'sanctum')->deleteJson("api/core/me/push-subscriptions/{$device->id}")->assertOk();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_i_can_never_revoke_somebody_elses_device_and_cannot_tell_it_exists(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();
        $theirs = $this->deviceFor($someoneElse, 'b');

        $mine = $this->actingAs($me, 'sanctum')->deleteJson("api/core/me/push-subscriptions/{$theirs->id}")->assertNotFound();
        $missing = $this->actingAs($me, 'sanctum')->deleteJson('api/core/me/push-subscriptions/999999')->assertNotFound();

        $this->assertSame(
            $missing->json('message'),
            $mine->json('message'),
            'Perangkat orang lain dan perangkat yang tidak ada dijawab dengan kalimat berbeda — itu cara menghitung '
            .'perangkat milik orang lain.',
        );

        $this->assertNotNull(PushSubscription::query()->find($theirs->id), 'Perangkat orang lain terhapus.');
    }
}
