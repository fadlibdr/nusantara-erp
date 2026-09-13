<?php

namespace Tests\Feature\Core;

use Base64Url\Base64Url;
use Minishlink\WebPush\VAPID;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\DeliveryGate;
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
    public function test_all_seven_dead_ends_are_actually_reachable_in_the_order_of_the_gate(): void
    {
        $body = $this->functionBody($this->withoutComments($this->source(self::PROFIL)), 'pushBlocker');

        $this->assertSame(
            "{ if (state.server_reason) return { kind: 'server', text: state.server_reason }; "
            ."if (isApple() && !isInstalled()) return { kind: 'ios', text: IOS_INSTALL }; "
            ."if (!window.isSecureContext) return { kind: 'insecure', text: NO_HTTPS }; "
            ."if (!hasPushApi()) return { kind: 'unsupported', text: NO_API }; "
            ."if (state.no_worker) return { kind: 'no-worker', text: NO_WORKER }; "
            ."if (window.Notification && Notification.permission === 'denied') return { kind: 'denied', text: DENIED_HELP }; "
            ."if (state.user_off && state.reason) return { kind: 'user-off', text: state.reason + USER_OFF_HINT }; "
            .'return null; }',
            $this->squash($body),
            'pushBlocker() bukan lagi ketujuh jalan buntu itu, dalam urutan itu. Urutannya mengikuti DeliveryGate — '
            .'dari yang paling global ke yang paling pribadi — supaya seseorang tidak disuruh memasang aplikasi ke '
            .'Layar Utama pada pemasangan yang VAPID-nya kosong.',
        );
    }

    /**
     * Tiga jalan buntu yang DITAMBAHKAN putaran verifikasi, dan kenapa
     * masing-masing bukan salah satu dari empat yang sudah ada.
     *
     * C-7 — konteks tidak aman. `'serviceWorker' in navigator` bernilai false
     * di http:// yang bukan localhost, jadi tanpa jalan buntu ini Chrome dan
     * Firefox yang SEHAT dijawab "Peramban ini tidak mendukung Push API" dan
     * disodori daftar peramban lain yang sudah memuat peramban mereka. Ia
     * harus lebih dulu daripada NO_API, karena di keadaan itu keduanya menyala.
     *
     * C-3 — tidak ada registrasi worker. hasPushApi() hanya memeriksa ADANYA
     * API; app.js sengaja menelan kegagalan pendaftaran. Tanpa ini tombolnya
     * ditawarkan, izin diberikan (permanen), dan `serviceWorker.ready` tidak
     * pernah selesai — withBusy() berputar sampai halaman dimuat ulang.
     *
     * C-5 — kanalnya dimatikan orangnya sendiri satu kartu di atas. Tanpa ini
     * ia ditawari tombol lalu diberi tahu "pemberitahuan berikutnya akan
     * muncul", sementara kotak keluar akan menulis Dilewati pada setiap baris.
     * Penandanya `user_off` dari server, BUKAN `reason` saja: `reason` juga
     * berbunyi WEBPUSH_NO_DEVICE, dan itu justru keadaan yang tombolnya ada
     * untuk mengubah.
     */
    public function test_the_three_dead_ends_added_by_the_verification_round_have_their_own_sentences(): void
    {
        $code = $this->source(self::PROFIL);

        $this->assertStringContainsString('TIDAK dilayani lewat HTTPS', $code, 'C-7: konteks tidak aman tidak punya kalimatnya sendiri.');
        $this->assertStringContainsString('Peramban Anda tidak bermasalah', $code, 'C-7: kalimatnya masih menyalahkan peramban orangnya.');
        $this->assertStringContainsString('belum terpasang sebagai pekerja latar', $code, 'C-3: worker yang belum terdaftar tidak punya kalimatnya sendiri.');
        $this->assertStringContainsString('Simpan pilihan kanal', $code, 'C-5: kartu tidak menunjukkan di mana kanalnya dinyalakan lagi.');

        // C-5: kalimat SEBABNYA tetap milik server — yang ditambahkan klien
        // hanya petunjuk tindakan. Kalau klien menulis sebabnya sendiri, dua
        // permukaan akan menyimpang diam-diam.
        $this->assertStringContainsString(
            'text: state.reason + USER_OFF_HINT',
            $this->withoutComments($code),
            'Kartu menyusun kalimat sebabnya sendiri alih-alih memakai kalimat DeliveryGate apa adanya.',
        );

        // C-3: sebuah janji yang tidak pernah selesai harus punya batas waktu,
        // atau tombolnya berputar selamanya — `finally` withBusy() tidak
        // pernah berjalan.
        $subscribe = $this->functionBody($this->withoutComments($code), 'subscribeHere');
        $this->assertStringContainsString(
            'Promise.race',
            $subscribe,
            'serviceWorker.ready ditunggu tanpa batas: pada peramban tanpa registrasi ia tidak pernah selesai, dan '
            .'tombolnya berputar sampai halaman dimuat ulang — sesudah orangnya terlanjur memberikan izin.',
        );
    }

    /**
     * C-6 — sesudah orangnya menekan Blokir, yang keluar adalah DENIED_HELP,
     * dan kartunya BERPINDAH ke jalan buntu 3.
     *
     * Dua kalimat untuk satu keadaan adalah bentuk yang paket ini kejar di
     * tempat lain; yang lebih berguna dari keduanya — ikon gembok →
     * Pemberitahuan → Izinkan → muat ulang — justru yang tidak pernah terlihat,
     * karena cabang galat tidak menggambar ulang kartunya.
     */
    public function test_a_denied_permission_uses_the_one_sentence_that_says_how_to_undo_it(): void
    {
        $code = $this->withoutComments($this->source(self::PROFIL));
        $subscribe = $this->functionBody($code, 'subscribeHere');

        $this->assertStringContainsString(
            '? DENIED_HELP',
            $subscribe,
            'Ada kalimat KEDUA untuk izin yang ditolak; DENIED_HELP yang menyebut langkah konkretnya tidak pernah terlihat.',
        );

        $push = $this->functionBody($code, 'pushCard');
        $this->assertMatchesRegularExpression(
            '~catch \(error\) \{ toastError\(error\); await reload\(\); \}~',
            $this->squash($push),
            'Cabang galat tidak menggambar ulang kartunya: keadaan yang baru saja membuat percobaan gagal (izin kini '
            .'ditolak, worker ternyata tidak ada) punya jalan buntunya sendiri, dan tombolnya tetap berdiri tanpa itu.',
        );
    }

    /**
     * B-5/C-4 — endpoint lama IKUT DIKIRIM pada jalur InvalidStateError.
     *
     * Jalur itu ada persis untuk hari setelah pemilik mengganti kunci VAPID:
     * langganan lama dibuang di peramban dan yang baru dibuat dengan kunci
     * baru, yang memberi endpoint BERBEDA. Tanpa `previous_endpoint` baris
     * lama tinggal di server selamanya — 404/410 menghapus, tetapi langganan
     * lama sesudah ganti kunci dijawab 401/403, dan 401/403 tidak menghapus
     * apa pun. Parameter keenam register() sudah ada sejak T3e.2 dan tidak
     * pernah dipanggil siapa pun sampai baris ini.
     */
    public function test_the_old_endpoint_is_sent_when_a_stale_subscription_is_replaced(): void
    {
        $subscribe = $this->functionBody($this->withoutComments($this->source(self::PROFIL)), 'subscribeHere');

        $this->assertStringContainsString(
            'endpointLama = stale.endpoint',
            $subscribe,
            'Endpoint lama dibuang tanpa ditangkap: server tidak punya cara mengenali baris mana yang digantikan.',
        );
        $this->assertStringContainsString(
            'muatan.previous_endpoint = endpointLama',
            $subscribe,
            'Endpoint lama ditangkap tetapi tidak dikirim: baris lama tetap menjadi perangkat hantu yang menghasilkan '
            .'satu baris "Gagal" per pemberitahuan, selamanya.',
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

    /**
     * KARTU JAM TENANG MENYEBUT SETIAP KANAL YANG BENAR-BENAR DITUNDANYA
     * (putaran penutup, V-1).
     *
     * Kalimatnya berbunyi "e-mail dan WhatsApp DITUNDA sampai jam selesai" —
     * dua kanal dari tiga, di layar yang paket ini sendiri tambahi kanal
     * ketiganya, dan satu kartu di atas kartu web push. Kesimpulan yang wajar
     * bagi pembacanya: web push TIDAK ikut jam tenang, jadi ponselnya akan
     * berbunyi pukul 02.00. Kode melakukan sebaliknya — WebPushOutboxTest
     * memakukannya. Ini bentuk §13: layar MENYANGKAL sesuatu yang dilakukan
     * kode, dan tidak ada uji yang menyentuh kalimatnya.
     *
     * Yang diulang uji ini adalah DAFTAR KANAL, bukan kalimatnya: kanal
     * keempat memerahkan berkas ini dengan menyuruh orangnya menamainya di
     * sini DAN di layar. Peta nama sengaja dieja di sini, bukan diturunkan
     * dari kode yang diuji — pin yang membaca harapannya sendiri tidak pernah
     * bisa merah (pelajaran Fase 2).
     */
    public function test_the_quiet_hours_card_names_every_channel_it_actually_postpones(): void
    {
        $names = [
            NotificationDelivery::CHANNEL_EMAIL => 'e-mail',
            NotificationDelivery::CHANNEL_WHATSAPP => 'WhatsApp',
            NotificationDelivery::CHANNEL_WEBPUSH => 'web push',
        ];

        $card = $this->functionBody($this->withoutComments($this->source(self::PROFIL)), 'quietHoursCard');

        foreach (DeliveryGate::USER_CHANNELS as $channel) {
            $this->assertArrayHasKey(
                $channel,
                $names,
                "Kanal {$channel} tidak punya nama di uji ini. Kanal baru harus disebut DI KARTU JAM TENANG juga: "
                .'jam tenang menunda setiap baris `queued` tanpa memandang kanal, jadi kartu yang menyebut sebagian '
                .'kanal sedang menyangkal perilaku yang benar untuk sisanya.',
            );

            $this->assertStringContainsString(
                $names[$channel],
                $card,
                "Kartu \"Jam tenang\" tidak menyebut {$names[$channel]}, padahal jam tenang MENUNDA barisnya juga "
                .'(WebPushOutboxTest memakukannya). Kanal yang tidak disebut terbaca sebagai kanal yang tidak ditunda — '
                .'dan itu kalimat yang membangunkan orang pukul 02.00.',
            );
        }
    }
}
