<?php

namespace Tests\Feature\Core;

use Base64Url\Base64Url;
use Minishlink\WebPush\VAPID;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\SettingService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * KABEL SPA UNTUK KANAL KETIGA, DAN KALIMAT YANG TIDAK BOLEH DISUSUN KLIEN
 * (P-3e, T3e.4/T3e.6).
 *
 * Pelajaran 6 kampanye ini: kalimat yang menjanjikan sesuatu yang tidak terjadi
 * adalah cacat paling sering fase ini. Layar ini punya dua godaan besar:
 *
 *  1. Menjanjikan PRIVASI yang lebih besar daripada yang dipegang kode. Yang
 *     benar dan hanya itu: isi pesan dienkripsi dengan kunci milik peramban,
 *     jadi layanan push tidak bisa MEMBACANYA. Yang TETAP dilihatnya: bahwa
 *     ada pesan, kapan, dan untuk endpoint mana. "Tidak ada yang tahu Anda
 *     dikirimi apa pun" adalah kebohongan.
 *  2. Menyusun kalimat KEDUA untuk jalan buntu yang sudah punya kalimat di
 *     DeliveryGate. Sebab yang benar di satu permukaan tetapi bocor di
 *     permukaan lain adalah cacat yang berulang di kampanye ini.
 *
 * Frasa terlarang yang perlu disebut komentar ditulis di dalam «guillemet»
 * supaya bisa dibaca manusia tanpa memerahkan uji.
 */
class WebPushSpaWiringTest extends ErpTestCase
{
    private const PROFIL = 'public/app/js/views/profil.js';

    /** @var list<string> */
    private const FORBIDDEN = [
        'tidak ada yang tahu',
        'sepenuhnya pribadi',
        'sepenuhnya aman',
        'dijamin sampai',
        'pasti diterima',
        'pasti sampai',
        'tanpa internet',
    ];

    private function source(string $path): string
    {
        $full = base_path($path);
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }

    public function test_the_profile_screen_has_a_sentence_for_each_of_the_four_dead_ends(): void
    {
        $code = $this->source(self::PROFIL);

        // 1. peramban tanpa Push API.
        $this->assertStringContainsString('tidak mendukung Push API', $code);
        // 2. iOS/iPadOS: CARA memasangnya, bukan tombol yang gagal.
        $this->assertStringContainsString('Tambahkan ke Layar Utama', $code);
        $this->assertStringContainsString('16.4', $code);
        // 3. izin yang sudah ditolak di tingkat peramban.
        $this->assertStringContainsString('setelan situs di peramban', $code);
        // 4. sisi server: kalimatnya DATANG DARI SERVER, tidak ditulis di sini.
        $this->assertStringContainsString('state.server_reason', $this->withoutComments($code));
    }

    /**
     * …DAN KEEMPATNYA BENAR-BENAR TERCAPAI.
     *
     * Kalimat yang ada di berkas tetapi tidak pernah dipilih siapa pun adalah
     * tombol mati tanpa kalimat — persis cacat yang T3e.4 ada untuk
     * mencegahnya. Diukur 13 Sep 2026: mutasi yang MENGHAPUS cabang iOS dan
     * hanya mengganti nama konstantanya LOLOS HIJAU atas uji di atas, karena
     * teksnya masih ada di berkas.
     *
     * Karena itu badan pushBlocker() dibandingkan UTUH — pola yang sama dengan
     * shellRequest() di PwaServiceWorkerTest, dan untuk alasan yang sama:
     * sebuah gerbang yang memilih satu dari empat kalimat hanya bisa dipaku
     * sebagai satu kalimat penuh. Mengubahnya dengan sengaja berarti mengubah
     * baris ini juga — itulah gunanya.
     */
    public function test_all_four_dead_ends_are_actually_reachable_in_the_order_of_the_gate(): void
    {
        $body = $this->functionBody($this->withoutComments($this->source(self::PROFIL)), 'pushBlocker');

        $this->assertSame(
            "{ if (state.server_reason) return { kind: 'server', text: state.server_reason }; "
            ."if (isApple() && !isInstalled()) return { kind: 'ios', text: IOS_INSTALL }; "
            ."if (!hasPushApi()) return { kind: 'unsupported', text: NO_API }; "
            ."if (window.Notification && Notification.permission === 'denied') return { kind: 'denied', text: DENIED_HELP }; "
            .'return null; }',
            $this->squash($body),
            'pushBlocker() bukan lagi keempat jalan buntu itu, dalam urutan itu. Urutannya mengikuti DeliveryGate — '
            .'dari yang paling global ke yang paling pribadi — supaya seseorang tidak disuruh memasang aplikasi ke '
            .'Layar Utama pada pemasangan yang VAPID-nya kosong.',
        );
    }

    /** Badan sebuah `function nama(...) { … }` tingkat atas. */
    private function functionBody(string $code, string $name): string
    {
        $start = strpos($code, "function {$name}(");
        $this->assertNotFalse($start, "function {$name}() tidak ada lagi di layar Profil.");

        $open = strpos($code, '{', $start);
        $depth = 0;
        for ($i = $open; $i < strlen($code); $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("Kurung badan function {$name}() tidak seimbang.");
    }

    private function squash(string $code): string
    {
        return trim((string) preg_replace('~\s+~', ' ', $code));
    }

    public function test_the_screen_does_not_write_its_own_sentence_for_the_server_dead_end(): void
    {
        $code = strtolower($this->withoutComments($this->source(self::PROFIL)));

        foreach (['vapid_public_key', 'vapid belum', 'core:vapid-keys'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                "Layar menyusun kalimatnya sendiri tentang konfigurasi server ({$needle}). Kalimat itu milik "
                .'DeliveryGate — satu daftar, empat permukaan — dan kalimat kedua yang mirip akan berselisih dengan '
                .'yang tercatat di Sistem › Pengiriman Notifikasi pada hari salah satunya berubah.',
            );
        }
    }

    public function test_the_screen_promises_only_what_the_encryption_actually_buys(): void
    {
        $code = strtolower($this->withoutComments($this->source(self::PROFIL)));

        foreach (self::FORBIDDEN as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $code,
                "Layar menjanjikan «{$phrase}». Yang benar hanya: isi pesan dienkripsi dengan kunci milik peramban, "
                .'jadi layanan push tidak bisa MEMBACANYA — ia tetap melihat bahwa ada pesan, kapan, dan untuk '
                .'endpoint mana.',
            );
        }

        $this->assertStringContainsString(
            'layanan push tidak bisa membacanya',
            strtolower($this->source(self::PROFIL)),
            'Satu-satunya klaim privasi yang boleh dibuat justru hilang dari layar.',
        );
    }

    public function test_the_permission_request_sits_inside_the_click_handler(): void
    {
        $code = $this->withoutComments($this->source(self::PROFIL));

        $this->assertStringContainsString('Notification.requestPermission()', $code);
        $this->assertStringContainsString('userVisibleOnly: true', $code);
        $this->assertStringContainsString('applicationServerKey: urlBase64ToUint8Array(publicKey)', $code);

        // Ia HARUS dipanggil dari dalam subscribeHere(), yang dipanggil
        // onClick. requestPermission() di luar gestur pengguna ditolak peramban
        // DIAM-DIAM (Chrome memulangkan 'denied' tanpa dialog) — dan yang
        // dilihat orangnya adalah tombol yang tidak melakukan apa-apa.
        $start = strpos($code, 'async function subscribeHere(');
        $this->assertNotFalse($start, 'subscribeHere() hilang dari layar.');
        $request = strpos($code, 'Notification.requestPermission()');
        $this->assertGreaterThan(
            $start,
            $request,
            'requestPermission() dipanggil di luar subscribeHere(), yaitu di luar gestur pengguna: peramban '
            .'menolaknya diam-diam dan tombolnya tidak akan pernah bekerja.',
        );
    }

    public function test_the_public_key_is_read_from_the_server_and_never_typed_into_the_client(): void
    {
        $code = $this->withoutComments($this->source(self::PROFIL));

        $this->assertStringContainsString('state.public_key', $code);
        $this->assertSame(
            0,
            preg_match('~[\'"]B[A-Za-z0-9_-]{80,}[\'"]~', $code),
            'Ada kunci publik VAPID yang diketik ke dalam klien. Kunci yang diketik ulang adalah kunci yang suatu '
            .'hari tidak cocok dengan .env server — dan setiap langganan yang dibuat dengannya ditolak 403.',
        );
    }

    public function test_the_deliveries_screen_names_the_third_channel_and_can_filter_by_it(): void
    {
        $enums = $this->source('public/app/js/enums.js');
        $schema = $this->source('public/app/js/schema.js');

        $this->assertStringContainsString("['webpush', 'Web push']", $enums, 'Kanal ketiga tidak punya label di enums.js; layar akan menggambar kode mentahnya.');
        $this->assertStringContainsString("{ key: 'channel', label: 'Kanal', enum: 'deliveryChannel' }", $schema);
        $this->assertStringContainsString("webpush: 'web push'", $schema, 'Kalimat konfirmasi Kirim ulang menyebut kode kanal mentah.');
        $this->assertStringContainsString('perangkat «${row.recipient', $schema, 'Baris web push adalah satu PERANGKAT; kalimat konfirmasinya harus mengatakannya.');
    }

    /** Penyaring kanal benar-benar menyaring — bukan hanya ada di daftar. */
    public function test_filtering_the_deliveries_list_by_webpush_returns_only_those_rows(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $server = VAPID::createVapidKeys();
        config([
            'erp.push.vapid_public_key' => $server['publicKey'],
            'erp.push.vapid_private_key' => $server['privateKey'],
            'erp.push.vapid_subject' => 'mailto:pemilik@nusantara.test',
        ]);
        app(SettingService::class)->set('notifications.webpush_enabled', true);

        $admin = $this->adminUser();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 150);
        $device = PushSubscription::query()->create([
            'user_id' => $admin->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => $server['publicKey'],
            'auth' => Base64Url::encode(random_bytes(16)),
            'device_label' => 'Chrome di Android',
        ]);

        $notification = Notification::query()->create([
            'user_id' => $admin->id,
            'event' => Notification::SYSTEM,
            'title' => 'Cadangan luar situs basi',
            'body' => 'Isi.',
        ]);

        foreach ([NotificationDelivery::CHANNEL_EMAIL, NotificationDelivery::CHANNEL_WHATSAPP] as $channel) {
            NotificationDelivery::query()->create([
                'notification_id' => $notification->id,
                'channel' => $channel,
                'recipient' => 'x@nusantara.test',
                'status' => NotificationDelivery::SKIPPED,
                'attempts' => 0,
            ]);
        }

        NotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationDelivery::CHANNEL_WEBPUSH,
            'push_subscription_id' => $device->id,
            'recipient' => $device->label(),
            'status' => NotificationDelivery::QUEUED,
            'attempts' => 0,
        ]);

        $rows = $this->actingAs($admin, 'sanctum')
            ->getJson('api/core/notification-deliveries?channel=webpush')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows, 'Penyaring kanal tidak menyaring web push.');
        $this->assertSame('Chrome di Android', $rows[0]['recipient']);
        $this->assertSame($device->id, $rows[0]['push_subscription_id']);
    }

    public function test_retry_from_the_screen_refuses_with_the_right_sentence_when_the_device_is_gone(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $server = VAPID::createVapidKeys();
        config([
            'erp.push.vapid_public_key' => $server['publicKey'],
            'erp.push.vapid_private_key' => $server['privateKey'],
            'erp.push.vapid_subject' => 'mailto:pemilik@nusantara.test',
        ]);
        app(SettingService::class)->set('notifications.webpush_enabled', true);

        $admin = $this->adminUser();

        // Dua perangkat: satu dicabut (baris yang akan ditolak), satu bertahan
        // supaya gerbang "belum ada satu perangkat pun" tidak menjawab lebih dulu.
        $endpoints = [];
        foreach (['a', 'b'] as $suffix) {
            $endpoint = 'https://fcm.googleapis.com/fcm/send/'.str_repeat($suffix, 150);
            $endpoints[$suffix] = PushSubscription::query()->create([
                'user_id' => $admin->id,
                'endpoint' => $endpoint,
                'endpoint_hash' => PushSubscription::hashFor($endpoint),
                'p256dh' => $server['publicKey'],
                'auth' => Base64Url::encode(random_bytes(16)),
                'device_label' => 'Perangkat '.$suffix,
            ]);
        }

        $notification = Notification::query()->create([
            'user_id' => $admin->id,
            'event' => Notification::SYSTEM,
            'title' => 'Cadangan luar situs basi',
            'body' => 'Isi.',
        ]);

        $row = NotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationDelivery::CHANNEL_WEBPUSH,
            'push_subscription_id' => $endpoints['a']->id,
            'recipient' => 'Perangkat a',
            'status' => NotificationDelivery::FAILED,
            'attempts' => 5,
            'error' => 'Layanan push menjawab HTTP 500',
        ]);

        $endpoints['a']->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("api/core/notification-deliveries/{$row->id}/retry")
            ->assertStatus(422);

        $this->assertStringContainsString('tidak terdaftar', (string) $response->json('message'));
        $this->assertStringContainsString('Aktifkan notifikasi di perangkat ini', (string) $response->json('message'));
        $this->assertSame(NotificationDelivery::FAILED, $row->refresh()->status, 'Baris yang ditolak tidak boleh berubah menjadi Antre.');
    }
}
