<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Channels\WhatsAppChannel;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\UserPreference;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\DeliveryChannels;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\NotificationTemplates;
use Modules\Core\Support\ProviderErrorScrubber;
use Modules\Core\Support\WhatsAppSetup;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Kanal WhatsApp — Meta Cloud API langsung (P-3a, T3a.3).
 *
 * TIDAK SATU PERMINTAAN PUN KELUAR DARI MESIN INI: setUp memasang
 * Http::preventStrayRequests(), jadi panggilan yang tidak tertangkap
 * Http::fake() menjatuhkan ujinya (perangkap I). Token yang dipakai jelas
 * palsu dan dicari kembali di setiap teks yang disimpan (perangkap E).
 *
 * Yang dipaku:
 *  - urutan sebab `skipped` DeliveryGate untuk WhatsApp, masing-masing tanpa
 *    satu permintaan HTTP: sakelar Pengaturan → konfigurasi → pilihan
 *    pengguna → nomor → opt-in bertanggal → template peristiwa;
 *  - Kirim ulang menolak dengan kalimat + petunjuk untuk tiap sebab;
 *  - kiriman yang berhasil: bentuk permintaan Meta (URL, Bearer, template,
 *    bahasa, tiga parameter, nomor tanpa '+'), dan `sent` HANYA dengan wamid;
 *  - penolakan permanen (401/190, 132001) → `failed` seketika, satu percobaan;
 *    sementara (429, 5xx, jaringan) → diulang pekerja;
 *  - jawaban penyedia yang memuat token/URL/nomor disaring sebelum ke kolom
 *    error dan pesan pengecualian;
 *  - penyedia qontak/tak dikenal → `skipped` yang mengatakannya; Fonnte tidak
 *    punya mode.
 */
class WhatsAppChannelTest extends ErpTestCase
{
    private const TOKEN = 'uji-token-RAHASIA-EAABsbCS1iHgBO9x';

    private const SECRET = 'uji-app-secret-RAHASIA-4f2c';

    private const PHONE_ID = '109876543210';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function holder(string $permission = 'core.update', string $name = 'Direktur'): User
    {
        $role = Role::findOrCreate('peran-'.md5($permission.$name), 'web');
        $role->givePermissionTo($permission);
        $user = User::query()->create([
            'name' => $name,
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function optedIn(User $user, string $phone = '+628123456789'): User
    {
        $user->forceFill(['phone_e164' => $phone, 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_via' => 'profil'])->save();

        return $user;
    }

    /** Sakelar nyala + kredensial palsu + kelima template terisi. */
    private function fullyConfigured(): void
    {
        app(SettingService::class)->set('notifications.whatsapp_enabled', true);
        config([
            'erp.whatsapp.provider' => 'meta',
            'erp.whatsapp.token' => self::TOKEN,
            'erp.whatsapp.phone_number_id' => self::PHONE_ID,
            'erp.whatsapp.app_secret' => self::SECRET,
            'erp.whatsapp.api_version' => 'v21.0',
            'erp.whatsapp.language' => 'id',
        ]);
        foreach (NotificationTemplates::KEYS as $key) {
            config(["erp.whatsapp.templates.{$key}" => 'erp_'.str_replace('.', '_', $key)]);
        }
    }

    private function alarm(string $template = NotificationTemplates::BACKUP_STALE, ?string $link = null): void
    {
        app(NotificationService::class)->system('core.update', 'Salinan cadangan offsite macet', "Sinkronisasi 5 hari lalu.\nPeriksa log.", $link, null, null, $template);
    }

    private function waRow(): NotificationDelivery
    {
        return NotificationDelivery::query()->where('channel', NotificationDelivery::CHANNEL_WHATSAPP)->sole();
    }

    private function metaAccepts(string $wamid = 'wamid.HBgNNjI4MTIzNDU2Nzg5FQIAERgSQzRBNDYyM0Q5RjA0RTE3RTRBAA=='): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '628123456789', 'wa_id' => '628123456789']],
                'messages' => [['id' => $wamid]],
            ], 200),
        ]);
    }

    // ---------------------------------------------- sebab skipped, tanpa HTTP

    public function test_each_prerequisite_missing_is_a_skipped_row_with_its_own_sentence_and_no_http_call(): void
    {
        Http::fake();
        Queue::fake();
        $user = $this->holder();

        $expectations = [
            // 1. sakelar Pengaturan mati (bawaan)
            [fn () => null, DeliveryGate::WHATSAPP_DISABLED],
            // 2. sakelar nyala, .env kosong
            [fn () => app(SettingService::class)->set('notifications.whatsapp_enabled', true), WhatsAppSetup::SKIP_UNCONFIGURED],
            // 3. terkonfigurasi, pengguna mematikan
            [function () use ($user): void {
                $this->fullyConfigured();
                UserPreference::query()->updateOrCreate(['user_id' => $user->id, 'key' => 'notify.channels'], ['value' => ['whatsapp' => false]]);
            }, DeliveryGate::USER_OFF],
            // 4. dinyalakan, tanpa nomor
            [fn () => UserPreference::query()->where('user_id', $user->id)->delete(), DeliveryGate::WHATSAPP_NO_PHONE],
            // 5. nomor ada, belum opt-in
            [fn () => $user->forceFill(['phone_e164' => '+628123456789'])->save(), DeliveryGate::WHATSAPP_NO_OPTIN],
            // 6. opt-in ada, template peristiwa ini belum diisi
            [function () use ($user): void {
                $user->forceFill(['whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_via' => 'profil'])->save();
                config(['erp.whatsapp.templates.backup.stale' => '']);
            }, 'Template WhatsApp untuk peristiwa backup.stale belum disetujui Meta / belum diisi di .env (WHATSAPP_TEMPLATE_BACKUP_STALE) — Meta hanya menerima pesan template yang disetujui.'],
        ];

        foreach ($expectations as $i => [$arrange, $reason]) {
            $arrange();
            Notification::query()->delete();
            NotificationDelivery::query()->delete();

            $this->alarm();

            $row = $this->waRow();
            $this->assertSame(NotificationDelivery::SKIPPED, $row->status, "langkah {$i}");
            $this->assertSame($reason, $row->error, "langkah {$i}");
            $this->assertSame(0, $row->attempts);
            // Kanal kebenaran tidak ikut mati.
            $this->assertSame(1, Notification::query()->where('user_id', $user->id)->count());
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_an_event_without_a_whatsapp_template_is_skipped_never_sent_as_free_text(): void
    {
        Http::fake();
        Queue::fake();
        $this->fullyConfigured();
        $this->optedIn($this->holder());

        // Alarm umum (tanpa kunci template) — dan pengajuan dokumen jatuh ke sini juga.
        app(NotificationService::class)->system('core.update', 'Tutup buku terlambat', 'Periode Juli masih terbuka.');

        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::SKIPPED, $row->status);
        $this->assertSame(DeliveryGate::WHATSAPP_NO_TEMPLATE_FOR_EVENT, $row->error);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_retry_refuses_each_whatsapp_reason_with_its_hint(): void
    {
        Http::fake();
        Queue::fake();
        $user = $this->holder();
        $this->alarm();
        $row = $this->waRow();
        // Admin dibuat SESUDAH alarm: ia memegang core.update juga, dan baris WhatsApp-nya bukan yang diuji.
        $this->actingAs($this->adminUser(), 'sanctum');

        $refuse = fn () => $this->postJson("/api/core/notification-deliveries/{$row->id}/retry")->assertStatus(422)->json('message');

        $this->assertStringStartsWith('WhatsApp masih dinonaktifkan di Pengaturan', $refuse());

        app(SettingService::class)->set('notifications.whatsapp_enabled', true);
        $this->assertStringStartsWith('Kanal WhatsApp belum dikonfigurasi — isi WHATSAPP_TOKEN dan WHATSAPP_PHONE_NUMBER_ID', $refuse());

        $this->fullyConfigured();
        $this->assertStringStartsWith('Penerima tidak punya nomor WhatsApp; ia mengisinya sendiri di Profil › Notifikasi', $refuse());

        $user->forceFill(['phone_e164' => '+628123456789'])->save();
        $this->assertStringStartsWith('Penerima belum opt-in WhatsApp; persetujuannya dicatat di Profil › Notifikasi', $refuse());

        $this->optedIn($user);
        config(['erp.whatsapp.templates.backup.stale' => '']);
        $this->assertStringContainsString('isi nama template yang disetujui Meta di .env, lalu kirim ulang', $refuse());

        $this->assertSame(NotificationDelivery::SKIPPED, $row->refresh()->status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------ kiriman berhasil

    public function test_a_fully_configured_channel_sends_a_template_message_and_marks_sent_with_the_wamid(): void
    {
        $this->fullyConfigured();
        $this->metaAccepts('wamid.UJI-001');
        $this->optedIn($this->holder());

        $this->alarm(NotificationTemplates::BACKUP_STALE, 'r/core/notification-deliveries');

        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::SENT, $row->status);
        $this->assertSame('wamid.UJI-001', $row->provider_id);
        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->error);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->provider_status, 'Status balik hanya dari webhook.');

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $this->assertSame('https://graph.facebook.com/v21.0/'.self::PHONE_ID.'/messages', $request->url());
            $this->assertSame('Bearer '.self::TOKEN, $request->header('Authorization')[0]);
            $this->assertSame('whatsapp', $body['messaging_product']);
            $this->assertSame('628123456789', $body['to'], "Meta menerima digit tanpa '+'.");
            $this->assertSame('template', $body['type']);
            $this->assertSame('erp_backup_stale', $body['template']['name']);
            $this->assertSame(['code' => 'id'], $body['template']['language']);
            $params = $body['template']['components'][0]['parameters'];
            $this->assertCount(3, $params);
            $this->assertSame('Salinan cadangan offsite macet', $params[0]['text']);
            $this->assertSame('Sinkronisasi 5 hari lalu. Periksa log.', $params[1]['text'], 'Baris baru diratakan.');
            $this->assertStringEndsWith('/app/r/core/notification-deliveries', $params[2]['text']);

            return true;
        });
    }

    public function test_a_2xx_without_a_wamid_is_not_sent(): void
    {
        $this->fullyConfigured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp'], 200)]);
        $this->optedIn($this->holder());
        config(['queue.default' => 'database']);

        $this->alarm();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status, 'Percobaan gagal, diulang — bukan sent.');
        $this->assertNull($row->provider_id);
        $this->assertStringContainsString('tanpa messages[0].id', (string) $row->error);
    }

    // ------------------------------------------- penolakan permanen vs sementara

    public function test_an_invalid_token_fails_at_once_and_the_token_never_reaches_the_error_column(): void
    {
        $this->fullyConfigured();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Error validating access token: token '.self::TOKEN.' from https://graph.facebook.com/v21.0/'.self::PHONE_ID.'/messages?access_token='.self::TOKEN.' for +628123456789',
                'type' => 'OAuthException', 'code' => 190, 'fbtrace_id' => 'AbCdEf',
            ],
        ], 401)]);
        $this->optedIn($this->holder());
        config(['queue.default' => 'database']);

        $this->alarm();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);

        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame(1, $row->attempts, 'Permanen: satu percobaan, bukan lima.');
        $this->assertNull($row->next_attempt_at);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringStartsWith('WhatsApp (Meta) HTTP 401 (190): Error validating access token', (string) $row->error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $row->error, 'Token bocor ke kolom error.');
        $this->assertStringNotContainsString('628123456789', (string) $row->error, 'Nomor bocor ke kolom error.');
        $this->assertStringContainsString('[rahasia]', (string) $row->error);
        $this->assertStringContainsString('[nomor]', (string) $row->error);
        $this->assertStringContainsString('?[…]', (string) $row->error, 'Query string URL disamarkan.');
    }

    public function test_a_missing_template_code_is_permanent_and_a_rate_limit_is_retried(): void
    {
        $this->fullyConfigured();
        $this->optedIn($this->holder());
        config(['queue.default' => 'database']);

        // Satu fake, dua jawaban berurutan: Http::fake() yang dipanggil dua kali
        // menumpuk stub dan yang PERTAMA menang.
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Template name does not exist in the translation', 'code' => 132001]], 400)
            ->push(['error' => ['message' => 'Too many requests', 'code' => 130429]], 429)]);

        $this->alarm();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertStringContainsString('(132001): Template name does not exist', (string) $row->error);

        Notification::query()->delete();
        NotificationDelivery::query()->delete();
        $this->alarm();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status, 'Sementara: diulang.');
        $this->assertNotNull($row->next_attempt_at);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('HTTP 429 (130429): Too many requests', (string) $row->error);
    }

    public function test_a_5xx_is_retried_and_a_dead_network_too(): void
    {
        $this->fullyConfigured();
        $this->optedIn($this->holder());
        config(['queue.default' => 'database']);

        $calls = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$calls) {
            return match (++$calls) {
                1 => Http::response('Bad Gateway', 502),
                // 5xx yang badannya membawa kode "permanen" (100): tetap
                // SEMENTARA — statusnya yang menentukan, bukan kodenya (mutasi
                // MW6 lolos hijau sebelum kasus ini ada).
                2 => Http::response(['error' => ['message' => 'An unknown error occurred', 'code' => 100]], 500),
                default => throw new ConnectionException('cURL error 28: Connection timed out after 5001 ms for https://graph.facebook.com/v21.0/'.self::PHONE_ID.'/messages?access_token='.self::TOKEN),
            };
        }]);

        $this->alarm();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertStringContainsString('HTTP 502: Bad Gateway', (string) $row->error);

        Notification::query()->delete();
        NotificationDelivery::query()->delete();
        $this->alarm();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status, '500 dengan kode 100 tetap diulang.');
        $this->assertStringContainsString('HTTP 500 (100)', (string) $row->error);

        Notification::query()->delete();
        NotificationDelivery::query()->delete();
        $this->alarm();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 5]);
        $row = $this->waRow();
        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertStringStartsWith('WhatsApp (Meta) tidak terjangkau: cURL error 28', (string) $row->error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $row->error);
    }

    // ------------------------------------------------------------- penyedia

    public function test_qontak_is_recognised_but_honestly_not_implemented_and_fonnte_has_no_mode(): void
    {
        Http::fake();
        Queue::fake();
        app(SettingService::class)->set('notifications.whatsapp_enabled', true);
        $this->optedIn($this->holder());

        config(['erp.whatsapp.provider' => 'qontak', 'erp.whatsapp.token' => 'x', 'erp.whatsapp.phone_number_id' => 'y']);
        $this->alarm();
        $this->assertStringStartsWith('Penyedia WhatsApp "qontak" dikenali tetapi pengirimnya belum ditulis', (string) $this->waRow()->error);

        Notification::query()->delete();
        NotificationDelivery::query()->delete();
        config(['erp.whatsapp.provider' => 'fonnte']);
        $this->alarm();
        $reason = (string) $this->waRow()->error;
        $this->assertStringStartsWith('Penyedia WhatsApp "fonnte" tidak dikenal', $reason);
        $this->assertStringContainsString('DITOLAK karena nomornya bisa diblokir Meta', $reason);

        Http::assertNothingSent();
        $this->assertSame(['meta', 'qontak'], WhatsAppSetup::PROVIDERS);
    }

    public function test_the_registry_resolves_whatsapp_and_still_refuses_webpush(): void
    {
        $this->assertInstanceOf(WhatsAppChannel::class, DeliveryChannels::for('whatsapp'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kanal webpush belum tersedia (Fase 3, P-3e).');
        DeliveryChannels::for('webpush');
    }

    // ------------------------------------------------------------- penyaring

    public function test_the_scrubber_removes_tokens_bearer_headers_query_strings_and_phone_numbers(): void
    {
        $text = 'Bearer '.self::TOKEN.' rejected; retry https://graph.facebook.com/v21.0/123/messages?access_token='.self::TOKEN.'&x=1 '
            .'for +628123456789 and 6281111222333; secret='.self::SECRET.' code 131026 trace 7';

        $out = ProviderErrorScrubber::scrub($text, [self::TOKEN, self::SECRET]);

        $this->assertStringNotContainsString(self::TOKEN, $out);
        $this->assertStringNotContainsString(self::SECRET, $out);
        $this->assertStringNotContainsString('628123456789', $out);
        $this->assertStringNotContainsString('6281111222333', $out);
        $this->assertStringContainsString('Bearer [rahasia]', $out);
        $this->assertStringContainsString('https://graph.facebook.com/v21.0/123/messages?[…]', $out);
        $this->assertStringContainsString('code 131026', $out, 'Kode galat Meta (≤ 6 digit) tidak disentuh.');
        $this->assertStringContainsString('[nomor]', $out);
        $this->assertLessThanOrEqual(ProviderErrorScrubber::LIMIT + 1, mb_strlen($out));

        $this->assertLessThanOrEqual(ProviderErrorScrubber::LIMIT + 1, mb_strlen(ProviderErrorScrubber::scrub(str_repeat('a', 2000))));
    }

    // ----------------------------------------------------------- job & profil

    public function test_the_job_re_checks_the_whatsapp_gate_with_the_rows_template(): void
    {
        Http::fake();
        $this->fullyConfigured();
        $user = $this->optedIn($this->holder());
        $notification = Notification::query()->create(['user_id' => $user->id, 'event' => Notification::SYSTEM, 'template' => 'scheduler.down', 'title' => 'Penjadwal tidak berjalan', 'body' => 'x']);
        $row = NotificationDelivery::query()->create(['notification_id' => $notification->id, 'channel' => 'whatsapp', 'recipient' => '+628123456789', 'status' => 'queued', 'attempts' => 0]);

        // Opt-in dicabut SESUDAH baris ditulis.
        $user->forceFill(['whatsapp_opt_in_at' => null, 'whatsapp_opt_in_via' => null])->save();
        (new DeliverNotification($row->id))->handle();

        $this->assertSame(NotificationDelivery::SKIPPED, $row->refresh()->status);
        $this->assertSame(DeliveryGate::WHATSAPP_NO_OPTIN, $row->error);
        Http::assertNothingSent();
    }

    public function test_the_profile_endpoint_measures_whatsapp_readiness_and_leaks_no_secret(): void
    {
        $user = $this->holder();
        $this->actingAs($user, 'sanctum');

        $data = $this->getJson('/api/core/me/notification-channels')->assertOk()->json('data');
        $this->assertSame(['provider' => 'meta', 'configured' => false, 'templates_ready' => 0, 'templates_total' => 5, 'phone_e164' => null, 'opt_in_at' => null, 'opt_in_via' => null], $data['whatsapp']);
        $this->assertSame(DeliveryGate::WHATSAPP_DISABLED, $data['channels'][1]['reason']);

        // Kredensial terisi, opt-in ada, tetapi 0 dari 5 template disetujui —
        // jendela nyata berhari-hari (KEPUTUSAN-INTEGRASI §4.2: 1–7 hari, bisa
        // ditolak): SETIAP baris WhatsApp akan Dilewati, jadi lencananya tidak
        // boleh hijau "Akan dikirim" (verifikasi P-3a, 12 Sep 2026).
        $this->fullyConfigured();
        $this->optedIn($user);
        foreach (NotificationTemplates::KEYS as $key) {
            config(["erp.whatsapp.templates.{$key}" => '']);
        }
        $data = $this->getJson('/api/core/me/notification-channels')->assertOk()->json('data');
        $this->assertSame(0, $data['whatsapp']['templates_ready']);
        $this->assertFalse($data['channels'][1]['will_deliver'], 'Tanpa satu pun template, tidak ada yang akan dikirim.');
        $this->assertSame(
            'Belum ada satu pun template WhatsApp yang disetujui Meta / diisi di .env (0 dari 5, WHATSAPP_TEMPLATE_*) — prasyarat pemilik (KEPUTUSAN-INTEGRASI.md §4); setiap pesan WhatsApp akan Dilewati sampai satu template terisi.',
            $data['channels'][1]['reason'],
        );
        // …dan kotak keluar pada keadaan yang sama memang Dilewati, tanpa HTTP.
        Http::fake();
        $this->alarm();
        $this->assertSame(NotificationDelivery::SKIPPED, $this->waRow()->status);
        Http::assertNothingSent();

        // Satu template terisi sudah cukup untuk "akan mencoba" — per peristiwa diperiksa kotak keluar.
        $this->fullyConfigured();
        config(['erp.whatsapp.templates.ar.dunning' => '']);
        $json = $this->getJson('/api/core/me/notification-channels')->assertOk()->getContent();
        $data = json_decode($json, true)['data'];
        $this->assertTrue($data['whatsapp']['configured']);
        $this->assertSame(4, $data['whatsapp']['templates_ready']);
        $this->assertSame('profil', $data['whatsapp']['opt_in_via']);
        $this->assertTrue($data['channels'][1]['will_deliver'], 'Ringkasan per orang tidak memeriksa template per peristiwa.');
        $this->assertStringNotContainsString(self::TOKEN, $json);
        $this->assertStringNotContainsString(self::SECRET, $json);
    }
}
