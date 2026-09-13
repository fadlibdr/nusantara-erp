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
     * OPSI YANG BERLAKU DI SETIAP PENGIRIMAN, TERMASUK DI DALAM UJI.
     *
     * `allow_redirects => false` bukan penyetelan, melainkan aturan 4
     * kebijakan P-3d yang ditulis untuk persis bahaya ini (putaran verifikasi:
     * A-2): "sebuah penerima yang menjawab `302 Location:
     * http://169.254.169.254/` memindahkan permintaan bertanda tangan kita ke
     * sana tanpa satu pun pemeriksaan di atas berlaku lagi". Guzzle mengikuti
     * pengalihan secara bawaan, dan daftar protokolnya bawaan memuat `http`,
     * jadi penurunan skema pun diikuti. Diukur 13 Sep 2026 SEBELUM baris ini
     * ada: sebuah 307 ke http://169.254.169.254/ benar-benar dibuat, dan
     * barisnya berakhir `sent`.
     *
     * `connect_timeout` terpisah dari waktu tunggu jawaban: sebuah alamat yang
     * tidak pernah menjawab SYN menahan pekerja antrean selama waktu tunggu
     * penuh (diukur 15,002 detik), dan pekerja yang sama melayani e-mail dan
     * WhatsApp.
     */
    private const CLIENT_OPTIONS = [
        'allow_redirects' => false,
        'connect_timeout' => PushEndpoint::CONNECT_TIMEOUT,
    ];

    /**
     * Opsi klien TAMBAHAN. KOSONG di produksi; subkelas uji memasang handler
     * tiruan di sini. Lihat docblock kelas.
     *
     * Ia DITIMPAKAN di atas CLIENT_OPTIONS, bukan menggantikannya: sebuah uji
     * yang memasang handler tiruan harus tetap berjalan dengan aturan
     * pengalihan yang sama seperti produksi, karena kalau tidak, uji yang
     * memaku "307 tidak diikuti" akan memaku sesuatu yang hanya benar di uji.
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
            array_replace(self::CLIENT_OPTIONS, $this->clientOptions),
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
