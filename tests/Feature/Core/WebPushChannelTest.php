<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Base64Url\Base64Url;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use Modules\Core\Channels\MailChannel;
use Modules\Core\Channels\WebPushChannel;
use Modules\Core\Channels\WhatsAppChannel;
use Modules\Core\Contracts\ChannelWithoutMessageId;
use Modules\Core\Exceptions\DeliverySkippedException;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\WebhookUrl;
use Modules\Core\Support\WebPushSender;
use RuntimeException;
use Tests\ErpTestCase;
use Tests\Support\ExplodingWebPushSender;
use Tests\Support\FakeWebPushSender;

/**
 * KANAL WEB PUSH: TIGA HASIL, DAN SATU PELONGGARAN YANG TIDAK BOLEH MEREMBES
 * (P-3e, T3e.3).
 *
 * Uji di berkas ini menjalankan KODE SUNGGUHAN sampai ke soket — enkripsi
 * aes128gcm dengan kunci P-256 yang sah, penandatanganan VAPID, pembentukan
 * permintaan — dan hanya menggantikan JAWABAN layanan push (lihat
 * Tests\Support\FakeWebPushSender untuk alasan jahitan itu berada di
 * clientOptions dan bukan di Http::fake()).
 *
 * Yang dipaku:
 *
 *  1. Tanpa VAPID, NOL permintaan keluar dari mesin — dipaku dengan pengirim
 *     yang meledak bila dipanggil, karena Http::preventStrayRequests() tidak
 *     melihat Guzzle mentah.
 *  2. 2xx = `sent`, walau web push TIDAK PUNYA message id dalam standarnya.
 *     Header `Location` dipakai bila layanan push mengirimnya; bila tidak,
 *     provider_id KOSONG dan barisnya tetap `sent` — karena buktinya adalah
 *     201 itu sendiri. Pelonggaran ini ditandai di kanalnya
 *     (ChannelWithoutMessageId) dan TIDAK berlaku untuk e-mail/WhatsApp.
 *  3. 404/410 = langganan MATI: perangkatnya dihapus, kejadiannya tercatat di
 *     core_audit_log (yang bertahan sesudah baris langganannya hilang dan
 *     punya layarnya sendiri), dan barisnya `failed` seketika.
 *  4. 401/403 = VAPID ditolak: permanen, dengan kalimat yang menyebutnya.
 *  5. 429/5xx/jaringan = pengecualian biasa, diulang pekerja.
 *  6. Muatan tidak pernah melewati plafon yang membuat panjang badan
 *     permintaan tetap — panjang badan adalah satu-satunya hal tentang isi
 *     pesan yang bisa dibaca layanan push.
 */
class WebPushChannelTest extends ErpTestCase
{
    private string $serverPublicKey;

    private string $serverPrivateKey;

    protected function setUp(): void
    {
        parent::setUp();

        $server = VAPID::createVapidKeys();
        $this->serverPublicKey = $server['publicKey'];
        $this->serverPrivateKey = $server['privateKey'];

        config([
            'erp.push.vapid_public_key' => $this->serverPublicKey,
            'erp.push.vapid_private_key' => $this->serverPrivateKey,
            'erp.push.vapid_subject' => 'mailto:pemilik@nusantara.test',
        ]);

        app(SettingService::class)->set('notifications.webpush_enabled', true);

        // PENYELESAI NAMA ADALAH SEAM, DAN UJI INI TIDAK MENYENTUH DNS.
        // Kanal memeriksa alamat endpoint sekali lagi tepat sebelum mengirim
        // (putaran verifikasi: A-1/B-2), dan pemeriksaan itu bertanya kepada
        // resolver. Tanpa baris ini uji di berkas ini benar-benar menanyakan
        // fcm.googleapis.com kepada DNS mesin uji — yaitu uji yang hasilnya
        // bergantung pada jaringan orang yang menjalankannya. Diukur 13 Sep
        // 2026 dengan resolver yang melempar: delapan uji di berkas ini
        // menyentuhnya.
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);
    }

    protected function tearDown(): void
    {
        WebhookUrl::resolverUsing(null);

        parent::tearDown();
    }

    /** Kunci langganan harus SAH: kunci karangan gagal di enkripsi, bukan di jaringan. */
    private function device(User $user, string $label = 'Chrome di Android'): PushSubscription
    {
        $browser = VAPID::createVapidKeys();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.bin2hex(random_bytes(24));

        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'p256dh' => $browser['publicKey'],
            'auth' => Base64Url::encode(random_bytes(16)),
            'device_label' => $label,
        ]);
    }

    private function notificationFor(User $user, string $title = 'Cadangan luar situs basi', ?string $body = null): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'event' => Notification::SYSTEM,
            'title' => $title,
            'body' => $body ?? 'Cadangan terakhir berumur 3 hari.',
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

    /**
     * @param  list<mixed>  $responses
     */
    private function fakeSender(array $responses): FakeWebPushSender
    {
        $sender = new FakeWebPushSender($responses);
        app()->instance(WebPushSender::class, $sender);

        return $sender;
    }

    /* ---------------------------------------------------------------- (1) */

    public function test_without_vapid_not_one_request_leaves_the_machine(): void
    {
        config(['erp.push.vapid_private_key' => null]);

        $sender = new ExplodingWebPushSender;
        app()->instance(WebPushSender::class, $sender);

        $user = User::factory()->create();
        $device = $this->device($user);
        $row = $this->rowFor($this->notificationFor($user), $device);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(0, $sender->calls, 'Pengirim dipanggil tanpa VAPID: sebuah permintaan sungguhan akan keluar dari mesin ini.');
        $this->assertSame(NotificationDelivery::SKIPPED, $row->refresh()->status);
        $this->assertStringContainsString('core:vapid-keys', (string) $row->error);
        $this->assertSame(0, $row->attempts, 'Dilewati bukan percobaan.');
    }

    /**
     * Dan pemeriksaannya ada DI KANAL, bukan hanya di job.
     *
     * Gerbang di DeliverNotification::handle() menahan baris ini lebih dulu,
     * jadi menghapus pemeriksaan di kanal tetap hijau lewat jalur job (diukur
     * 13 Sep 2026: mutasi itu LOLOS). Kanal dipanggil langsung di sini dengan
     * alasan yang sama seperti MailChannel: ia juga dipanggil dari Kirim
     * ulang dan dari job yang keadaannya berubah, dan kanal yang percaya
     * pemanggilnya sudah memeriksa adalah kanal yang suatu hari mengirim
     * tanpa kredensial.
     */
    public function test_the_channel_itself_refuses_before_touching_the_sender(): void
    {
        config(['erp.push.vapid_private_key' => null]);

        $sender = new ExplodingWebPushSender;
        app()->instance(WebPushSender::class, $sender);

        $user = User::factory()->create();
        $device = $this->device($user);
        $notification = $this->notificationFor($user);
        $row = $this->rowFor($notification, $device);

        try {
            (new WebPushChannel)->send($row, $notification);
            $this->fail('Kanal mengirim tanpa VAPID.');
        } catch (DeliverySkippedException $e) {
            $this->assertStringContainsString('VAPID_PRIVATE_KEY', $e->getMessage());
        }

        $this->assertSame(0, $sender->calls);
    }

    /* ---------------------------------------------------------------- (2) */

    public function test_a_201_without_a_location_header_is_sent_with_an_empty_provider_id(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $row = $this->rowFor($this->notificationFor($user), $device);

        $sender = $this->fakeSender([new Response(201)]);

        (new DeliverNotification($row->id))->handle();

        $row->refresh();

        $this->assertSame(
            NotificationDelivery::SENT,
            $row->status,
            'Web push tidak punya message id dalam standarnya (RFC 8030 §5: Location opsional). Menolak menandai '
            .'`sent` di atas 201 berarti setiap pengiriman yang BERHASIL dicatat gagal.',
        );
        $this->assertNull($row->provider_id, 'Pengenal yang tidak diberikan penyedia tidak boleh dikarang.');
        $this->assertNotNull($row->sent_at);
        $this->assertCount(1, $sender->sent);
    }

    public function test_a_location_header_is_kept_verbatim_as_the_provider_id(): void
    {
        $user = User::factory()->create();
        $row = $this->rowFor($this->notificationFor($user), $this->device($user));

        $this->fakeSender([new Response(201, ['Location' => 'https://fcm.googleapis.com/fcm/send/pesan-123'])]);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame('https://fcm.googleapis.com/fcm/send/pesan-123', (string) $row->refresh()->provider_id);
    }

    public function test_the_loosening_is_marked_on_the_channel_and_does_not_reach_email_or_whatsapp(): void
    {
        $this->assertInstanceOf(ChannelWithoutMessageId::class, new WebPushChannel);

        $this->assertNotInstanceOf(
            ChannelWithoutMessageId::class,
            new MailChannel,
            'MailChannel menandai dirinya "tanpa message id". Message-ID e-mail ADA dan hanya sampai sesudah '
            .'percakapan SMTP ditutup 250 — melonggarkannya mengembalikan `sent` palsu MAIL_MAILER=log yang '
            .'P-3a dibangun untuk menghapusnya.',
        );
        $this->assertNotInstanceOf(ChannelWithoutMessageId::class, new WhatsAppChannel, 'wamid ADA; kosong dari Meta berarti tidak ada bukti.');
    }

    /* ---------------------------------------------------------------- (3) */

    public function test_a_410_deletes_the_device_and_records_it_where_it_survives_the_row(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'Firefox di Linux');
        $deviceId = $device->id;
        $row = $this->rowFor($this->notificationFor($user), $device);

        $this->fakeSender([new Response(410)]);

        (new DeliverNotification($row->id))->handle();

        $row->refresh();

        $this->assertSame(NotificationDelivery::FAILED, $row->status, '410 permanen: mengulang empat kali ke endpoint yang sudah tidak ada hanya menunda kabar yang sama.');
        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->next_attempt_at);
        $this->assertStringContainsString('Firefox di Linux', (string) $row->error);
        $this->assertStringContainsString('410', (string) $row->error);

        $this->assertNull(PushSubscription::query()->find($deviceId), 'Langganan yang dijawab 410 harus dihapus.');

        $logged = DB::table('core_audit_log')
            ->where('auditable_type', PushSubscription::class)
            ->where('auditable_id', $deviceId)
            ->first();

        $this->assertNotNull(
            $logged,
            'Penghapusan langganan tidak tercatat di mana pun yang bertahan. "Tercatat di baris yang dihapus" bukan '
            .'tercatat: baris itulah yang hilang.',
        );
        $this->assertSame('deleted', $logged->event);
        $this->assertSame('Firefox di Linux', $logged->auditable_label);
        $this->assertStringContainsString('410', (string) $logged->changes);
    }

    public function test_a_404_is_treated_exactly_like_a_410(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $row = $this->rowFor($this->notificationFor($user), $device);

        $this->fakeSender([new Response(404)]);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(NotificationDelivery::FAILED, $row->refresh()->status);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    /* ---------------------------------------------------------------- (4) */

    public function test_a_403_is_permanent_and_says_the_vapid_keys_were_refused(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $row = $this->rowFor($this->notificationFor($user), $device);

        $this->fakeSender([new Response(403, [], 'forbidden')]);

        (new DeliverNotification($row->id))->handle();

        $row->refresh();

        $this->assertSame(NotificationDelivery::FAILED, $row->status);
        $this->assertSame(1, $row->attempts, '401/403 permanen: satu percobaan, bukan lima.');
        $this->assertStringContainsString('VAPID', (string) $row->error);
        $this->assertNotNull(PushSubscription::query()->find($device->id), '403 bukan langganan mati — perangkatnya tidak boleh ikut dihapus.');
    }

    /* ---------------------------------------------------------------- (5) */

    public function test_a_500_is_an_ordinary_failure_that_the_worker_retries(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $row = $this->rowFor($this->notificationFor($user), $device);

        $this->fakeSender([new Response(500, [], 'internal')]);

        try {
            (new DeliverNotification($row->id))->handle();
            $this->fail('Kegagalan sementara harus DILEMPAR ULANG supaya pekerja menjadwalkan percobaan berikutnya.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $row->refresh();

        $this->assertSame(NotificationDelivery::QUEUED, $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->next_attempt_at, 'Percobaan berikutnya harus dijadwalkan (backoff 60 detik).');
        $this->assertNotNull(PushSubscription::query()->find($device->id), '5xx bukan langganan mati.');
    }

    public function test_an_unreachable_push_service_is_retried_too(): void
    {
        $user = User::factory()->create();
        $row = $this->rowFor($this->notificationFor($user), $this->device($user));

        $this->fakeSender([new ConnectException('Connection refused', new Request('POST', 'https://fcm.googleapis.com/fcm/send/x'))]);

        try {
            (new DeliverNotification($row->id))->handle();
            $this->fail('Jaringan yang gagal harus dilempar ulang.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak terjangkau', $e->getMessage());
        }

        $this->assertSame(NotificationDelivery::QUEUED, $row->refresh()->status);
    }

    public function test_a_device_that_was_unsubscribed_before_the_job_ran_is_skipped_not_failed(): void
    {
        $user = User::factory()->create();
        $gone = $this->device($user, 'Chrome di Android');
        // Perangkat KEDUA yang masih hidup: tanpa dia, gerbang "belum ada satu
        // perangkat pun" yang menjawab lebih dulu (dan itu juga benar — lihat
        // uji di bawah). Yang diperiksa di sini adalah baris yang PERANGKATNYA
        // sendiri hilang sementara orangnya masih punya yang lain.
        $this->device($user, 'Safari di iPhone');
        $row = $this->rowFor($this->notificationFor($user), $gone);

        $sender = new ExplodingWebPushSender;
        app()->instance(WebPushSender::class, $sender);

        $gone->delete();

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(
            NotificationDelivery::SKIPPED,
            $row->refresh()->status,
            'Perangkat yang dicabut antara baris ditulis dan job berjalan bukan KEGAGALAN: tidak ada yang gagal, '
            .'sasarannya yang sudah tidak ada.',
        );
        $this->assertStringContainsString('tidak terdaftar', (string) $row->error);
        $this->assertSame(0, $sender->calls);
    }

    public function test_when_the_last_device_is_gone_the_gate_answers_first_with_its_own_sentence(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $row = $this->rowFor($this->notificationFor($user), $device);

        $sender = new ExplodingWebPushSender;
        app()->instance(WebPushSender::class, $sender);

        $device->delete();

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(NotificationDelivery::SKIPPED, $row->refresh()->status);
        $this->assertSame(
            DeliveryGate::WEBPUSH_NO_DEVICE,
            (string) $row->error,
            'Gerbang diperiksa ULANG saat job berjalan, dan sebabnya datang dari DeliveryGate — satu daftar, '
            .'empat permukaan.',
        );
        $this->assertSame(0, $sender->calls);
    }

    /* ---------------------------------------------------------------- (6) */

    public function test_the_payload_is_trimmed_below_the_budget_that_keeps_the_body_length_constant(): void
    {
        $user = User::factory()->create();

        // AKSARA MULTIBYTE, dengan sengaja. Judul dan isi sudah dipotong per
        // KARAKTER (120 dan 600) sebelum dirangkai, jadi dengan teks ASCII
        // muatannya tidak akan pernah mendekati plafon — dan sebuah uji yang
        // memakai ASCII memaku plafon yang tidak pernah tersentuh (diukur:
        // membuang pemotongnya tetap HIJAU). Satu emoji = 4 byte: 600 aksara
        // isi + 120 aksara judul = 2.880 byte, melewati plafon 2.820.
        $notification = $this->notificationFor($user, str_repeat('📄', 200), str_repeat('🧾', 900));

        $payload = WebPushChannel::payloadFor($notification);

        $this->assertLessThanOrEqual(
            WebPushChannel::MAX_PAYLOAD_BYTES,
            strlen($payload),
            'Muatan melewati plafon 2.820 byte. Di bawah angka itu setiap badan permintaan keluar dengan panjang '
            .'yang sama persis (diukur 2.922 byte); di atasnya panjang badan ikut berubah — dan panjang badan adalah '
            .'satu-satunya hal tentang isi pesan yang bisa dibaca layanan push.',
        );

        $decoded = json_decode($payload, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('judul', $decoded);
        $this->assertArrayHasKey('isi', $decoded);
        $this->assertArrayHasKey('tautan', $decoded);
        $this->assertSame('erp-notif-'.$notification->id, $decoded['tag'], 'tag = id notifikasi: pemberitahuan yang sama yang sampai dua kali MENIMPA, bukan menumpuk.');
        $this->assertStringNotContainsString("\n", $decoded['isi']);
    }

    public function test_a_long_payload_still_actually_sends(): void
    {
        $user = User::factory()->create();
        $notification = $this->notificationFor($user, str_repeat('📄', 200), str_repeat('🧾', 900));
        $row = $this->rowFor($notification, $this->device($user));

        $sender = $this->fakeSender([new Response(201)]);

        (new DeliverNotification($row->id))->handle();

        $this->assertSame(NotificationDelivery::SENT, $row->refresh()->status);
        $this->assertLessThanOrEqual(WebPushChannel::MAX_PAYLOAD_BYTES, strlen($sender->sent[0]['payload']));
    }
}
