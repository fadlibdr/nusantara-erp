<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Base64Url\Base64Url;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Modules\Core\Channels\WebPushChannel;
use Modules\Core\Exceptions\DeliveryRejectedException;
use Modules\Core\Exceptions\DeliveryRetryRefusedException;
use Modules\Core\Exceptions\DeliverySkippedException;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\PushSubscriptions;
use Modules\Core\Support\WebhookUrl;
use Modules\Core\Support\WebPushSender;
use Tests\ErpTestCase;
use Tests\Support\FakeWebPushSender;

/**
 * KE MANA SERVER BOLEH MEM-POST, DAN BARIS SIAPA YANG BOLEH DISENTUH
 * (P-3e, putaran verifikasi 13 Sep 2026).
 *
 * Paket ini dikirim dengan enam lubang yang punya satu bentuk yang sama:
 * sebuah nilai yang datang dari luar dipercaya sebagai identitas. Endpoint
 * dipercaya sebagai alamat yang boleh dituju (A-1/B-2), jawaban pengalihan
 * dipercaya sebagai tujuan yang sama (A-2), endpoint dipercaya sebagai kunci
 * baris yang boleh ditulis (A-3) dan dihapus (A-4), dan id langganan dipercaya
 * sebagai perangkat penerimanya (B-1).
 *
 * Yang dipaku di berkas ini:
 *
 *  1. ALAMAT INTERNAL DITOLAK — di pendaftaran, di rotasi, DAN sekali lagi
 *     tepat sebelum mengirim (DNS bisa berubah di antaranya). Aturannya bukan
 *     aturan baru: ia milik P-3d (WebhookUrl, KEPUTUSAN-INTEGRASI §11),
 *     dipakai ulang lewat PushEndpoint.
 *  2. PENGALIHAN TIDAK DIIKUTI, dan sebuah 3xx tidak pernah `sent`.
 *  3. PLAFON PERANGKAT: fan-out per perangkat tidak boleh bisa dijadikan
 *     penguat lalu lintas oleh pengguna biasa.
 *  4. LANGGANAN YANG BERPINDAH PEMILIK dicatat, dan baris kotak keluar yang
 *     menunjuknya TIDAK DIKIRIM — pemberitahuan orang pertama tidak boleh
 *     muncul di layar orang kedua di komputer yang dipakai bergantian.
 *  5. ROTASI TIDAK PERNAH MENYENTUH BARIS MILIK ORANG LAIN.
 *  6. ENDPOINT TIDAK PERNAH MASUK KOLOM "Galat / alasan".
 *
 * Uji di sini menjalankan kode sungguhan sampai ke soket (enkripsi aes128gcm,
 * tanda tangan VAPID) dan hanya menggantikan JAWABAN layanan push — dan
 * PENYELESAI NAMA-nya, supaya berkas ini tidak pernah bertanya kepada DNS
 * mesin yang menjalankannya.
 */
class WebPushGuardTest extends ErpTestCase
{
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $server = VAPID::createVapidKeys();
        $this->publicKey = $server['publicKey'];

        config([
            'erp.push.vapid_public_key' => $server['publicKey'],
            'erp.push.vapid_private_key' => $server['privateKey'],
            'erp.push.vapid_subject' => 'mailto:pemilik@nusantara.test',
        ]);

        app(SettingService::class)->set('notifications.webpush_enabled', true);

        // Nama layanan push yang dipakai berkas ini menunjuk alamat publik.
        // Uji rebinding di bawah menukar penyelesai ini sendiri.
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);
    }

    protected function tearDown(): void
    {
        WebhookUrl::resolverUsing(null);

        parent::tearDown();
    }

    /* ------------------------------------------------------------ pembantu */

    private function keys(): array
    {
        $browser = VAPID::createVapidKeys();

        return ['p256dh' => $browser['publicKey'], 'auth' => Base64Url::encode(random_bytes(16))];
    }

    private function payload(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => $this->keys()];
    }

    private function endpoint(string $suffix = ''): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.($suffix === '' ? bin2hex(random_bytes(24)) : $suffix);
    }

    private function device(User $user, ?string $endpoint = null, string $label = 'Chrome di Android'): PushSubscription
    {
        $endpoint ??= $this->endpoint();
        $keys = $this->keys();

        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
            'device_label' => $label,
        ]);
    }

    private function notificationFor(User $user): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'event' => Notification::SYSTEM,
            'title' => 'Cadangan luar situs basi',
            'body' => 'Cadangan terakhir berumur 3 hari.',
            'link' => 'r/core/settings',
        ]);
    }

    private function rowFor(Notification $notification, PushSubscription $device): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationDelivery::CHANNEL_WEBPUSH,
            'push_subscription_id' => $device->id,
            'recipient' => $device->label(),
            'status' => NotificationDelivery::QUEUED,
            'attempts' => 0,
        ]);
    }

    private function fakeSender(array $responses): FakeWebPushSender
    {
        $sender = new FakeWebPushSender($responses);
        app()->instance(WebPushSender::class, $sender);

        return $sender;
    }

    /* -------------------------------------------------------------- (1) */

    /**
     * A-1/B-2 — alamat DI DALAM jaringan server bukan perangkat siapa pun.
     *
     * Setiap pengguna yang bisa masuk sampai ke rute ini: ia sengaja tidak
     * bergerbang, dan tidak ada izin yang bisa menolongnya. Diukur SEBELUM
     * perbaikan ini: ketujuh bentuk di bawah dijawab HTTP 200 dan disimpan,
     * dan pekerja antrean benar-benar membuka soket ke sana.
     *
     * Bentuk samarannya ikut, karena sebuah penilaian yang membaca BENTUKNYA
     * alih-alih ALAMATNYA menolak 127.0.0.1 sambil meloloskan 2130706433 —
     * yang mendarat di soket yang sama.
     */
    public function test_an_internal_address_is_never_accepted_as_a_device(): void
    {
        $user = User::factory()->create();

        $candidates = [
            'https://169.254.169.254/latest/meta-data/iam/security-credentials/',
            'https://127.0.0.1:9200/_search',
            'https://10.0.0.5/admin',
            'https://[::ffff:127.0.0.1]/x',
            'https://2130706433/x',
            'https://localhost/x',
            'https://backup.internal/x',
            'https://100.100.100.200/x',
        ];

        foreach ($candidates as $candidate) {
            $this->actingAs($user, 'sanctum')
                ->postJson('api/core/me/push-subscriptions', $this->payload($candidate))
                ->assertStatus(422)
                ->assertJsonValidationErrors('endpoint');
        }

        $this->assertSame(
            0,
            PushSubscription::query()->count(),
            'Sebuah alamat internal tersimpan sebagai perangkat: sejak itu SETIAP pemberitahuan untuk orang itu '
            .'membuat pekerja antrean mem-POST ke dalam jaringan server.',
        );
    }

    /** A-1/B-2 — pintu rotasi memakai penjaga yang sama, dan ia TIDAK meminta sesi. */
    public function test_the_rotation_route_refuses_an_internal_address_too(): void
    {
        $user = User::factory()->create();
        $old = $this->device($user);

        $this->postJson('push/rotate', [
            'old_endpoint' => (string) $old->endpoint,
            'endpoint' => 'https://169.254.169.254/latest/meta-data/',
            'keys' => $this->keys(),
        ])->assertStatus(422)->assertJsonValidationErrors('endpoint');

        $this->assertSame((string) $old->endpoint, (string) $old->refresh()->endpoint);
    }

    /**
     * A-1/B-2 — DAN SEKALI LAGI SAAT MENGIRIM.
     *
     * Sebuah baris kotak keluar bisa menunggu berjam-jam (jam tenang menunda
     * sampai pagi), dan sebuah nama yang kemarin menunjuk alamat publik bisa
     * hari ini menunjuk 127.0.0.1 — teknik dengan nama sendiri: DNS rebinding.
     * Pemeriksaan saat menyimpan tidak bisa menangkapnya; hanya pemeriksaan
     * kedua yang bisa.
     */
    public function test_a_name_that_turns_internal_before_sending_is_refused_at_the_socket(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'https://push.contoh.example/wpush/v2/'.bin2hex(random_bytes(16)));
        $row = $this->rowFor($this->notificationFor($user), $device);

        WebhookUrl::resolverUsing(static fn (): array => ['127.0.0.1']);

        $sender = $this->fakeSender([new Response(201)]);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame([], $sender->sent, 'Permintaan tetap keluar ke nama yang kini menunjuk loopback.');
        $this->assertSame(NotificationDelivery::FAILED, $row->refresh()->status);
        $this->assertStringContainsString('di dalam jaringan server ini', (string) $row->error);
    }

    /**
     * A-1/B-2, sisi lain yang sama pentingnya: RESOLVER YANG SEDANG BERMASALAH
     * BUKAN KEGAGALAN PERMANEN.
     *
     * Dua kegagalan penjaga alamat punya umur yang berbeda. "Alamatnya di
     * dalam jaringan server" tidak akan berubah bila diulang — permanen, satu
     * percobaan. "Namanya tidak bisa diterjemahkan" bisa berubah semenit lagi,
     * dan menyatakan sebuah pemberitahuan gagal SELAMANYA karena resolver
     * tersendat sepuluh detik adalah penjaga yang menimbulkan kerugiannya
     * sendiri. Dipisahkan di kelas pengecualian, dipaku di sini — kalau tidak,
     * penyederhanaan berikutnya akan menyatukannya lagi dan tidak ada yang
     * memerah.
     */
    public function test_a_name_that_cannot_be_resolved_right_now_is_retried_not_failed_forever(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'https://push.contoh.example/wpush/v2/'.bin2hex(random_bytes(16)));
        $row = $this->rowFor($this->notificationFor($user), $device);

        WebhookUrl::resolverUsing(static fn (): array => []);

        $sender = $this->fakeSender([new Response(201)]);

        try {
            (new DeliverNotification($row->id))->handle();
        } catch (\RuntimeException) {
            // Job melempar ulang supaya pekerja menjadwalkan percobaan berikutnya.
        }

        $this->assertSame([], $sender->sent, 'Permintaan keluar ke nama yang tidak bisa diterjemahkan.');
        $this->assertNotSame(
            NotificationDelivery::FAILED,
            $row->refresh()->status,
            'Resolver yang tersendat menyatakan pemberitahuan ini gagal SELAMANYA: penjaga alamat menimbulkan '
            .'kerugiannya sendiri, dan percobaan berikutnya yang akan berhasil tidak pernah terjadi.',
        );
        $this->assertStringContainsString('tidak bisa diterjemahkan', (string) $row->error);
    }

    /* -------------------------------------------------------------- (2) */

    /**
     * A-2 — pengalihan TIDAK diikuti, dan 3xx tidak pernah `sent`.
     *
     * Dua hal dipaku sekaligus, karena masing-masing sendirian tidak cukup.
     * Yang pertama: permintaan KEDUA tidak pernah dibuat — diukur dengan
     * antrean jawaban yang tersisa, bukan dengan jumlah panggilan send().
     * Yang kedua: pustaka ini menandai setiap jawaban yang bukan galat HTTP
     * sebagai `success`, TERMASUK 3xx — jadi tanpa cabang 3xx di kanal,
     * mematikan pengalihan justru menghasilkan baris `sent` untuk
     * pemberitahuan yang tidak pernah diterima layanan push.
     */
    public function test_a_redirect_is_not_followed_and_is_never_counted_as_sent(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $row = $this->rowFor($this->notificationFor($user), $device);

        $sender = $this->fakeSender([
            new Response(307, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Response(200, [], 'metadata-body'),
        ]);

        (new DeliverNotification($row->id))->handle();

        $this->assertCount(
            1,
            $sender->handler,
            'Permintaan KEDUA dibuat: pengalihan diikuti, dan permintaan bertanda tangan kita mendarat di alamat '
            .'yang tidak satu pun pemeriksaan alamat berlaku atasnya.',
        );
        $this->assertSame(
            NotificationDelivery::FAILED,
            $row->refresh()->status,
            'Sebuah 3xx tercatat Terkirim: layar mengatakan pemberitahuan sampai ke perangkat orangnya padahal '
            .'layanan push tidak pernah menerimanya.',
        );
        $this->assertStringContainsString('pengalihan', (string) $row->error);
        $this->assertNull(
            $device->refresh()->last_success_at,
            '"Terakhir berhasil menerima" perangkat ini diperbarui oleh jawaban yang bukan penerimaan.',
        );
    }

    /* -------------------------------------------------------------- (3) */

    /** A-5 — satu orang, plafon perangkat; fan-out tidak boleh bisa dijadikan penguat. */
    public function test_a_person_cannot_register_more_devices_than_the_ceiling(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < PushSubscriptions::MAX_PER_USER; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('api/core/me/push-subscriptions', $this->payload($this->endpoint()))
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('api/core/me/push-subscriptions', $this->payload($this->endpoint()))
            ->assertStatus(422)
            ->assertJsonValidationErrors('endpoint');

        $this->assertSame(PushSubscriptions::MAX_PER_USER, PushSubscriptions::countFor($user));

        // Perangkat yang SUDAH terdaftar tetap boleh mendaftar ulang: plafon
        // menolak baris BARU, bukan pembaruan kunci perangkat yang ada.
        $existing = PushSubscription::query()->where('user_id', $user->id)->first();
        $this->actingAs($user, 'sanctum')
            ->postJson('api/core/me/push-subscriptions', $this->payload((string) $existing->endpoint))
            ->assertOk();
    }

    /* -------------------------------------------------------------- (4) */

    /**
     * A-3 — satu peramban, satu baris, dan perpindahannya TIDAK diam-diam.
     *
     * Langganan push milik PERAMBAN, bukan akun: di komputer lapangan yang
     * dipakai bergantian, peramban memulangkan endpoint yang SAMA untuk siapa
     * pun yang sedang masuk. Barisnya memang harus berpindah — dua baris untuk
     * satu langganan berarti pemberitahuan orang pertama tetap dikirim ke
     * layar orang kedua. Yang tidak boleh adalah berpindah tanpa jejak:
     * "kenapa perangkat saya hilang dari daftar" harus punya jawaban.
     */
    public function test_a_subscription_that_changes_hands_leaves_an_audit_row(): void
    {
        $ani = User::factory()->create();
        $budi = User::factory()->create();
        $endpoint = $this->endpoint();

        $this->actingAs($ani, 'sanctum')->postJson('api/core/me/push-subscriptions', $this->payload($endpoint))->assertOk();
        $this->actingAs($budi, 'sanctum')->postJson('api/core/me/push-subscriptions', $this->payload($endpoint))->assertOk();

        $this->assertSame(1, PushSubscription::query()->count(), 'Satu langganan peramban tidak boleh menjadi dua baris.');
        $this->assertSame($budi->id, (int) PushSubscription::query()->first()->user_id);

        $audit = AuditLog::query()
            ->where('auditable_type', PushSubscription::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'Langganan berpindah pemilik tanpa satu baris audit pun: korban kehilangan web push tanpa jejak.');
        $this->assertSame($ani->id, (int) data_get($audit->changes, 'user_id.from'));
        $this->assertSame($budi->id, (int) data_get($audit->changes, 'user_id.to'));
    }

    /**
     * B-1 — baris kotak keluar milik Ani tidak pernah dikirim ke perangkat
     * yang kini terdaftar atas nama Budi.
     *
     * Urutannya persis urutan lapangan: Ani punya dua perangkat (jadi gerbang
     * "belum ada perangkat" tidak menahan apa pun), barisnya ditulis, lalu
     * Budi menekan "Aktifkan" di peramban bersama itu — dan baris Ani yang
     * masih `queued` tetap menunjuk id langganan yang sama.
     *
     * Diukur SEBELUM perbaikan ini: barisnya benar-benar terkirim dan
     * benar-benar tercatat `sent` — judul dan isi pemberitahuan Ani muncul di
     * layar Budi, dan tidak ada satu baris pun yang mengatakan ke mana
     * pesannya pergi.
     */
    public function test_a_queued_row_is_not_delivered_to_a_device_that_now_belongs_to_someone_else(): void
    {
        $ani = User::factory()->create();
        $budi = User::factory()->create();

        $shared = $this->device($ani, null, 'Chrome di Windows');
        $this->device($ani, null, 'Safari di iPhone');
        $row = $this->rowFor($this->notificationFor($ani), $shared);

        PushSubscriptions::register(
            $budi,
            (string) $shared->endpoint,
            (string) $shared->p256dh,
            (string) $shared->auth,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        );

        $this->assertSame($budi->id, (int) $shared->refresh()->user_id, 'Prasyarat: langganan peramban bersama berpindah ke Budi.');

        $sender = $this->fakeSender([new Response(201)]);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(
            [],
            $sender->sent,
            'Pemberitahuan Ani dikirim ke peramban yang kini dipakai Budi: judul dan isinya muncul di layar orang lain.',
        );
        $this->assertSame(
            NotificationDelivery::SKIPPED,
            $row->refresh()->status,
            'Barisnya bukan `skipped`: layar akan mengatakan pemberitahuan ini sampai kepada Ani.',
        );
        $this->assertStringContainsString('pengguna lain', (string) $row->error);
    }

    /**
     * B-6 — lingkup pemilik di jalur "Kirim ulang" TIDAK punya uji sama sekali
     * sampai baris ini ada.
     *
     * Diukur 13 Sep 2026: membuang `where('user_id', …)` dari
     * NotificationService::retry() meninggalkan 85 uji paket ini hijau. Sebuah
     * pemeriksaan tanpa uji adalah pemeriksaan yang hilang pada penyuntingan
     * berikutnya tanpa satu pun sinyal — dan baris yang hilang itu persis
     * baris yang menghalangi B-1 di jalur Kirim ulang.
     */
    public function test_resending_refuses_a_device_that_now_belongs_to_someone_else(): void
    {
        $ani = User::factory()->create();
        $budi = User::factory()->create();

        $shared = $this->device($ani, null, 'Chrome di Windows');
        $this->device($ani, null, 'Safari di iPhone');
        $row = $this->rowFor($this->notificationFor($ani), $shared);
        $row->forceFill(['status' => NotificationDelivery::FAILED, 'error' => 'Layanan push tidak terjangkau.'])->save();

        PushSubscriptions::register($budi, (string) $shared->endpoint, (string) $shared->p256dh, (string) $shared->auth, null);

        $this->expectException(DeliveryRetryRefusedException::class);
        $this->expectExceptionMessageMatches('~tidak terdaftar~');

        app(NotificationService::class)->retry($row);
    }

    /* -------------------------------------------------------------- (5) */

    /**
     * A-4 — rotasi memindahkan langganan DI DALAM satu akun, tidak pernah
     * antar-akun.
     *
     * Rute ini publik: tanpa sesi, tanpa CSRF. Cabang "endpoint barunya sudah
     * terdaftar" membuang baris lama, dan tanpa batas ini ia membuang baris
     * SIAPA PUN yang endpoint-nya dipegang pemanggil. Diukur sebelum
     * perbaikan: satu POST tanpa sesi meninggalkan korban dengan nol
     * langganan.
     */
    public function test_rotation_never_deletes_a_row_that_belongs_to_another_account(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();

        $victimEndpoint = $this->endpoint();
        $attackerEndpoint = $this->endpoint();

        $this->device($victim, $victimEndpoint);
        $this->device($attacker, $attackerEndpoint);

        $this->postJson('push/rotate', [
            'old_endpoint' => $victimEndpoint,
            'endpoint' => $attackerEndpoint,
            'keys' => $this->keys(),
        ])->assertStatus(422);

        $this->assertSame(
            1,
            PushSubscription::query()->where('user_id', $victim->id)->count(),
            'Langganan korban dihapus dari rute yang tidak meminta sesi sama sekali.',
        );
        $this->assertSame(1, PushSubscription::query()->where('user_id', $attacker->id)->count());
    }

    /* -------------------------------------------------------------- (6) */

    /**
     * A-6/B-4 — endpoint tidak pernah masuk kolom "Galat / alasan".
     *
     * Pesan Guzzle memuat URL permintaan lengkap. Kolom itu dibaca SETIAP
     * pemegang core.update di Sistem › Pengiriman Notifikasi, ikut ke setiap
     * cadangan, dan ikut ke setiap tangkapan layar yang dikirim orang saat
     * minta bantuan — sementara endpoint adalah KAPABILITAS: `POST
     * push/rotate` memakainya sebagai satu-satunya kredensial. Yang menjawab
     * "perangkat mana yang gagal" adalah label perangkatnya, dan itu tetap
     * ada.
     */
    public function test_the_endpoint_never_reaches_the_error_column(): void
    {
        $user = User::factory()->create();
        $endpoint = $this->endpoint(str_repeat('e', 152));
        $device = $this->device($user, $endpoint);

        foreach ([429, 403] as $status) {
            $row = $this->rowFor($this->notificationFor($user), $device);
            $this->fakeSender([new Response($status)]);

            try {
                (new DeliverNotification($row->id))->handle();
            } catch (DeliveryRejectedException|DeliverySkippedException) {
                // 403 permanen dilempar ulang oleh job; yang diperiksa kolomnya.
            } catch (\RuntimeException) {
                // 429 diulang pekerja — sama.
            }

            $error = (string) $row->refresh()->error;

            $this->assertStringNotContainsString(
                $endpoint,
                $error,
                "Endpoint utuh mendarat di kolom error pada HTTP {$status}: sebuah kapabilitas yang dibaca dari layar.",
            );
            $this->assertStringContainsString('Chrome di Android', $error, 'Label perangkat hilang: "perangkat mana yang gagal" tidak lagi terjawab.');
        }
    }

    /* -------------------------------------------------------------- (7) */

    /**
     * B-5/C-4 — berlangganan ulang sesudah `unsubscribe()` tidak meninggalkan
     * baris hantu.
     *
     * Jalur ini bukan kasus karangan: ia adalah cabang InvalidStateError di
     * layar Profil, yaitu hari setelah pemilik mengganti kunci VAPID. Peramban
     * yang sama memberi endpoint BARU, dan tanpa `previous_endpoint` baris
     * lama bertahan selamanya — 404/410 menghapus, tetapi langganan lama
     * sesudah ganti kunci dijawab 401/403, dan 401/403 tidak menghapus apa
     * pun. Hasilnya satu baris merah "Gagal" tambahan pada setiap
     * pemberitahuan, untuk perangkat yang sebenarnya sehat.
     */
    public function test_resubscribing_the_same_browser_with_a_new_endpoint_replaces_the_old_row(): void
    {
        $user = User::factory()->create();
        $lama = $this->endpoint();
        $baru = $this->endpoint();

        $this->actingAs($user, 'sanctum')->postJson('api/core/me/push-subscriptions', $this->payload($lama))->assertOk();

        $this->actingAs($user, 'sanctum')->postJson(
            'api/core/me/push-subscriptions',
            $this->payload($baru) + ['previous_endpoint' => $lama],
        )->assertOk();

        $this->assertSame(
            1,
            PushSubscriptions::countFor($user),
            'Satu perangkat menjadi dua baris: Profil menampilkan dua kali perangkat yang sama, dan setiap '
            .'pemberitahuan berikutnya menghasilkan satu baris "Gagal" untuk perangkat yang sehat.',
        );
        $this->assertSame($baru, (string) PushSubscription::query()->first()->endpoint);
    }

    /* -------------------------------------------------------------- (8) */

    /**
     * A-7/B-8 — muatan yang tidak bisa mengecil lagi MELEMPAR, tidak berputar.
     *
     * Syarat henti gelung pemotong dulunya `$body !== ''`, dan setiap putaran
     * menambahkan '…' di ujung — jadi $body tidak pernah menjadi string
     * kosong: ia mengecil sampai '…' lalu berhenti mengecil. Bila bagian yang
     * TIDAK dipotong (judul, tautan dari APP_URL) sendirian sudah melewati
     * plafon, gelungnya berputar tanpa akhir DI DALAM PEKERJA ANTREAN — bukan
     * galat yang tercatat, melainkan pekerja yang menggantung sampai --timeout
     * membunuhnya, berulang untuk setiap percobaan.
     *
     * Uji ini akan MENGGANTUNG, bukan gagal, pada kode yang cacat: itulah
     * bentuk cacatnya, dan karena itu ia dijalankan dengan batas waktunya
     * sendiri.
     */
    public function test_a_payload_that_cannot_shrink_any_further_throws_instead_of_spinning(): void
    {
        config(['app.url' => 'https://'.str_repeat('u', 3000).'.contoh.example']);

        $user = User::factory()->create();
        $notification = $this->notificationFor($user);

        $mulai = microtime(true);

        try {
            WebPushChannel::payloadFor($notification);
            $this->fail('Muatan yang melewati plafon dipulangkan apa adanya; pustaka akan melempar di tempat yang tidak punya kalimatnya.');
        } catch (DeliveryRejectedException $e) {
            $this->assertStringContainsString('APP_URL', $e->getMessage(), 'Kalimatnya tidak menyebut apa yang harus diperiksa.');
        }

        $this->assertLessThan(5.0, microtime(true) - $mulai, 'Pemotongan muatan memakan waktu seperti gelung yang tidak berhenti.');
    }

    /** Dan muatan yang WAJAR tetap dipotong dan tetap terkirim — plafonnya bukan larangan. */
    public function test_a_long_but_ordinary_payload_still_fits_and_still_sends(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);

        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'event' => Notification::SYSTEM,
            'title' => str_repeat('あ', 120),
            'body' => str_repeat('い', 600),
            'link' => 'r/core/settings',
        ]);

        $row = $this->rowFor($notification, $device);
        $sender = $this->fakeSender([new Response(201)]);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(NotificationDelivery::SENT, $row->refresh()->status);
        $this->assertLessThanOrEqual(WebPushChannel::MAX_PAYLOAD_BYTES, strlen($sender->sent[0]['payload']));
    }
}
