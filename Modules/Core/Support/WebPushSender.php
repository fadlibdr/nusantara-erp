<?php

namespace Modules\Core\Support;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Modules\Core\Models\PushSubscription;

/**
 * Satu POST bertanda tangan VAPID ke layanan push — DAN SATU-SATUNYA JAHITAN
 * UJI KANAL INI (P-3e, T3e.3).
 *
 * Kenapa kelas setipis ini ada sama sekali: `Minishlink\WebPush\WebPush`
 * MEMBUAT KLIEN GUZZLE-NYA SENDIRI di konstruktor (`$this->client = new
 * Client($clientOptions)`), jadi `Http::fake()` dan bahkan
 * `Http::preventStrayRequests()` TIDAK MENUTUPINYA — keduanya hanya menjaga
 * klien HTTP Laravel. Diukur 13 Sep 2026: sebuah uji yang lupa memasang
 * handler benar-benar menghubungi fcm.googleapis.com dari mesin uji.
 *
 * Satu-satunya lubang yang disediakan pustaka itu adalah argumen KEEMPAT
 * konstruktornya, `$clientOptions`, yang diteruskan apa adanya ke Guzzle. Maka
 * jahitan uji paket ini persis satu: properti `$clientOptions` di bawah, yang
 * dipasang subkelas uji dengan
 * `['handler' => HandlerStack::create(new MockHandler([...]))]`. Kelasnya
 * diresolusi lewat container (`app(WebPushSender::class)`), jadi uji juga bisa
 * mengganti seluruh pengirimnya dengan `app()->instance(...)` — dipakai untuk
 * memakukan bahwa TANPA VAPID tidak ada satu permintaan pun yang keluar.
 *
 * Kelas ini tidak memutuskan apa pun: ia tidak membaca gerbang, tidak menulis
 * baris, tidak menghapus langganan. Semua keputusan ada di WebPushChannel,
 * supaya yang diganti uji hanyalah pipa jaringannya.
 */
class WebPushSender
{
    /**
     * Opsi klien Guzzle. KOSONG di produksi; subkelas uji memasang handler
     * tiruan di sini. Lihat docblock kelas.
     *
     * @var array<string, mixed>
     */
    protected array $clientOptions = [];

    public function send(PushSubscription $subscription, string $payload): MessageSentReport
    {
        $client = new WebPush(
            WebPushSetup::auth(),
            ['TTL' => WebPushSetup::ttlSeconds()],
            WebPushSetup::timeoutSeconds(),
            $this->clientOptions,
        );

        return $client->sendOneNotification($this->subscriptionFor($subscription), $payload);
    }

    /**
     * `aes128gcm` DISEBUT, tidak dibiarkan bawaan. Bawaan pustaka ini masih
     * `aesgcm` (skema draf lama) demi kompatibilitas mundur, sementara sw.js
     * kita dan setiap peramban yang mendukung Push API hari ini memakai
     * aes128gcm (RFC 8188/8291). Di v10 ia enum PHP, bukan string.
     */
    protected function subscriptionFor(PushSubscription $subscription): Subscription
    {
        return new Subscription(
            (string) $subscription->endpoint,
            (string) $subscription->p256dh,
            (string) $subscription->auth,
            ContentEncoding::aes128gcm,
        );
    }
}
