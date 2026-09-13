<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Base64Url\Base64Url;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\VAPID;
use Modules\Core\Exceptions\DeliveryRetryRefusedException;
use Modules\Core\Jobs\DeliverNotification;
use Modules\Core\Models\Notification;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Models\UserPreference;
use Modules\Core\Services\NotificationService;
use Modules\Core\Services\SettingService;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\WebPushSetup;
use Tests\ErpTestCase;

/**
 * SATU BARIS PER PERANGKAT — DAN SATU DAFTAR SEBAB UNTUK EMPAT PERMUKAAN
 * (P-3e, T3e.3).
 *
 * KEPUTUSAN BENTUK BARIS. Kotak keluar menulis SATU baris per kanal per
 * penerima sejak P-0b. Web push tidak muat dalam bentuk itu: seseorang bisa
 * punya tiga perangkat, dan ketiganya bisa menjawab BERBEDA dalam satu
 * pengiriman — 201 di ponsel, 410 di laptop yang peramban-nya dipasang ulang,
 * timeout di tablet. Satu baris harus memilih salah satu jawaban untuk
 * ditampilkan, memilih satu provider_id dari tiga, dan — yang paling buruk —
 * "Kirim ulang" sesudah 1 dari 3 berhasil akan mengirim ULANG ke perangkat
 * yang SUDAH menerima. Paket ini memilih fan-out: satu baris per LANGGANAN,
 * dengan kolom penghubung push_subscription_id (migrasi 001805). Harganya
 * dikatakan apa adanya: barisnya berlipat sebanyak perangkat.
 *
 * Uji di berkas ini memaku akibat pilihan itu, dan urutan sebab gerbangnya
 * dari yang paling global ke yang paling pribadi.
 */
class WebPushOutboxTest extends ErpTestCase
{
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

    private function device(User $user, string $label): PushSubscription
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

    /** Tulis satu notifikasi lewat jalur kotak keluar yang sesungguhnya. */
    private function notify(User $user): Notification
    {
        Queue::fake();

        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'event' => Notification::SYSTEM,
            'title' => 'Cadangan luar situs basi',
            'body' => 'Cadangan terakhir berumur 3 hari.',
            'link' => 'r/core/settings',
        ]);

        $outbox = (new \ReflectionClass(NotificationService::class))->getMethod('outbox');
        $outbox->setAccessible(true);
        $outbox->invoke(app(NotificationService::class), $notification, $user);

        return $notification;
    }

    /** @return Collection<int, NotificationDelivery> */
    private function pushRows(Notification $notification)
    {
        return NotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->where('channel', NotificationDelivery::CHANNEL_WEBPUSH)
            ->orderBy('id')
            ->get();
    }

    /* ------------------------------------------------------------ fan-out */

    public function test_the_outbox_writes_one_row_per_device_each_naming_its_own(): void
    {
        $user = User::factory()->create();
        $this->device($user, 'Chrome di Android');
        $this->device($user, 'Safari di iPhone');
        $this->device($user, 'Edge di Windows');

        $rows = $this->pushRows($this->notify($user));

        $this->assertCount(
            3,
            $rows,
            'Kotak keluar menulis satu baris untuk tiga perangkat. Satu baris tidak bisa jujur tentang tiga '
            .'jawaban yang berbeda, dan Kirim ulang-nya akan mengirim ulang ke perangkat yang sudah menerima.',
        );

        $this->assertSame(
            ['Chrome di Android', 'Edge di Windows', 'Safari di iPhone'],
            $rows->pluck('recipient')->sort()->values()->all(),
            'Kolom Penerima baris web push harus membawa LABEL perangkat — endpoint 188 karakter di layar tidak '
            .'memberi tahu siapa pun apa pun.',
        );

        $this->assertSame(
            3,
            $rows->pluck('push_subscription_id')->filter()->unique()->count(),
            'Setiap baris harus menunjuk langganannya sendiri; tanpa itu Kirim ulang tidak tahu perangkat mana.',
        );

        foreach ($rows as $row) {
            $this->assertSame(NotificationDelivery::QUEUED, $row->status);
            $this->assertNull($row->error);
        }
    }

    public function test_a_person_without_a_device_still_gets_exactly_one_row_saying_why(): void
    {
        $user = User::factory()->create();

        $rows = $this->pushRows($this->notify($user));

        $this->assertCount(1, $rows, 'Kanal yang tidak meninggalkan baris apa pun adalah kanal yang hilang dari layar.');
        $this->assertSame(NotificationDelivery::SKIPPED, $rows[0]->status);
        $this->assertSame(DeliveryGate::WEBPUSH_NO_DEVICE, (string) $rows[0]->error);
        $this->assertNull($rows[0]->push_subscription_id);
    }

    public function test_the_other_two_channels_still_write_exactly_one_row_each(): void
    {
        $user = User::factory()->create();
        $this->device($user, 'Chrome di Android');
        $this->device($user, 'Safari di iPhone');

        $notification = $this->notify($user);

        foreach ([NotificationDelivery::CHANNEL_EMAIL, NotificationDelivery::CHANNEL_WHATSAPP] as $channel) {
            $this->assertSame(
                1,
                NotificationDelivery::query()->where('notification_id', $notification->id)->where('channel', $channel)->count(),
                "Kanal {$channel} ikut ber-fan-out. Fan-out adalah milik web push saja; e-mail dan WhatsApp punya "
                .'SATU alamat per orang.',
            );
        }
    }

    /* -------------------------------------------------------------- gerbang */

    public function test_the_reasons_run_from_the_most_global_to_the_most_personal(): void
    {
        $user = User::factory()->create();

        // 1. sakelar Pengaturan — mendahului segalanya, termasuk .env yang kosong.
        app(SettingService::class)->set('notifications.webpush_enabled', false);
        $this->assertSame(DeliveryGate::WEBPUSH_DISABLED, DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_WEBPUSH, $user));

        // 2. VAPID belum disetel di .env.
        app(SettingService::class)->set('notifications.webpush_enabled', true);
        config(['erp.push.vapid_private_key' => null]);
        $this->assertSame(WebPushSetup::SKIP_UNCONFIGURED, DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_WEBPUSH, $user));

        // 3. dimatikan pengguna — sesudah konfigurasi server, supaya "Anda
        //    mematikannya" tidak dikatakan pada pemasangan yang kanalnya belum ada.
        $server = VAPID::createVapidKeys();
        config(['erp.push.vapid_public_key' => $server['publicKey'], 'erp.push.vapid_private_key' => $server['privateKey']]);
        UserPreference::query()->create([
            'user_id' => $user->id,
            'key' => DeliveryGate::PREF_CHANNELS,
            'value' => [NotificationDelivery::CHANNEL_WEBPUSH => false],
        ]);
        $this->assertSame(DeliveryGate::USER_OFF, DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_WEBPUSH, $user));

        // 4. belum satu perangkat pun — yang paling pribadi, terakhir.
        UserPreference::query()->where('user_id', $user->id)->delete();
        $this->assertSame(DeliveryGate::WEBPUSH_NO_DEVICE, DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_WEBPUSH, $user));

        $this->device($user, 'Chrome di Android');
        $this->assertNull(DeliveryGate::reasonToSkip(NotificationDelivery::CHANNEL_WEBPUSH, $user));
    }

    public function test_webpush_is_one_of_the_channels_a_person_may_choose(): void
    {
        $this->assertContains(
            NotificationDelivery::CHANNEL_WEBPUSH,
            DeliveryGate::USER_CHANNELS,
            'Kanal yang tidak ada di USER_CHANNELS tidak pernah ditulis kotak keluar dan tidak pernah tampil di Profil.',
        );
    }

    public function test_the_profile_screen_reads_the_same_sentence_the_outbox_will_write(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([]);

        $payload = $this->actingAs($user)->getJson('api/core/me/notification-channels')->assertOk()->json('data');

        $channel = collect($payload['channels'])->firstWhere('channel', NotificationDelivery::CHANNEL_WEBPUSH);

        $this->assertNotNull($channel, 'Layar Profil tidak menyebut kanal web push sama sekali.');
        $this->assertFalse($channel['will_deliver']);
        $this->assertSame(
            DeliveryGate::WEBPUSH_NO_DEVICE,
            $channel['reason'],
            'Layar Profil menulis kalimatnya sendiri. Sebab yang benar di satu permukaan tetapi bocor di permukaan '
            .'lain adalah cacat yang berulang di kampanye ini: satu daftar, empat permukaan.',
        );

        $this->device($user, 'Chrome di Android');
        $this->device($user, 'Safari di iPhone');

        $channel = collect($this->actingAs($user)->getJson('api/core/me/notification-channels')->json('data.channels'))
            ->firstWhere('channel', NotificationDelivery::CHANNEL_WEBPUSH);

        $this->assertTrue($channel['will_deliver']);
        $this->assertSame('2 perangkat', $channel['address']);
    }

    /**
     * SETIAP KANAL PUNYA NAMANYA SENDIRI DI LAYAR.
     *
     * Sampai P-3e label kanal di endpoint ini adalah sebuah ternary —
     * "email ? 'E-mail' : 'WhatsApp'" — yang benar selama kanalnya persis
     * dua dan diam-diam salah pada kanal ketiga. Ditemukan DI PERAMBAN
     * (harness S41m, 13 Sep 2026): baris web push tampil berlabel
     * **WhatsApp**, dengan sebab Dilewati milik web push terbaca di
     * bawahnya — dua baris "WhatsApp" berturut-turut, satu di antaranya
     * berbohong. Tidak satu pun uji PHP yang ada melihatnya, karena semua
     * memeriksa `channel` dan `reason`, tidak pernah `label`.
     */
    public function test_every_channel_carries_its_own_name_on_the_screen(): void
    {
        $user = User::factory()->create();

        $channels = $this->actingAs($user)->getJson('api/core/me/notification-channels')->assertOk()->json('data.channels');

        $this->assertSame(
            ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'webpush' => 'Web push'],
            collect($channels)->pluck('label', 'channel')->all(),
            'Sebuah kanal memakai nama kanal LAIN di layar. Label yang salah di sebelah sebab Dilewati yang benar '
            .'adalah kalimat yang menunjuk orang ke setelan yang bukan miliknya.',
        );
    }

    /* ----------------------------------------------------------- kirim ulang */

    public function test_retry_refuses_with_its_own_sentence_when_the_device_is_gone(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'Chrome di Android');
        // Perangkat kedua yang masih hidup: tanpa dia gerbang "belum ada satu
        // perangkat pun" yang menjawab lebih dulu (dan itu juga benar). Yang
        // diperiksa di sini adalah BARIS yang perangkatnya sendiri hilang.
        $this->device($user, 'Safari di iPhone');
        $notification = $this->notify($user);
        $row = $this->pushRows($notification)->firstWhere('push_subscription_id', $device->id);

        $device->delete();

        try {
            app(NotificationService::class)->retry($row->refresh());
            $this->fail('Kirim ulang ke perangkat yang sudah tidak terdaftar harus ditolak, bukan diantrekan untuk gagal lagi.');
        } catch (DeliveryRetryRefusedException $e) {
            $this->assertStringContainsString('tidak terdaftar', $e->getMessage());
            $this->assertStringContainsString('Aktifkan notifikasi di perangkat ini', $e->getMessage());
        }
    }

    public function test_retry_of_one_device_leaves_the_other_rows_untouched(): void
    {
        $user = User::factory()->create();
        $this->device($user, 'Chrome di Android');
        $second = $this->device($user, 'Safari di iPhone');

        $notification = $this->notify($user);
        $rows = $this->pushRows($notification);

        $failed = $rows->firstWhere('push_subscription_id', $second->id);
        $failed->forceFill(['status' => NotificationDelivery::FAILED, 'attempts' => 5, 'error' => 'Layanan push menjawab HTTP 500'])->save();

        $other = $rows->firstWhere('push_subscription_id', '!=', $second->id);
        $other->forceFill(['status' => NotificationDelivery::SENT, 'sent_at' => now()])->save();

        Queue::fake();
        app(NotificationService::class)->retry($failed->refresh());

        $this->assertSame(NotificationDelivery::QUEUED, $failed->refresh()->status);
        $this->assertSame('Safari di iPhone', (string) $failed->recipient);
        $this->assertSame(5, $failed->attempts, 'attempts adalah riwayat dan tidak direset.');

        $this->assertSame(
            NotificationDelivery::SENT,
            $other->refresh()->status,
            'Kirim ulang satu perangkat menyentuh baris perangkat lain. Inilah yang dibeli oleh satu baris per '
            .'langganan: perangkat yang SUDAH menerima tidak menerima dua kali.',
        );

        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_a_sent_row_is_never_retried_even_for_web_push(): void
    {
        $user = User::factory()->create();
        $this->device($user, 'Chrome di Android');
        $row = $this->pushRows($this->notify($user))->first();
        $row->forceFill(['status' => NotificationDelivery::SENT, 'sent_at' => now()])->save();

        $this->expectException(DeliveryRetryRefusedException::class);
        app(NotificationService::class)->retry($row->refresh());
    }
}
