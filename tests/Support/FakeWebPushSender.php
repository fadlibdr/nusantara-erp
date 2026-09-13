<?php

namespace Tests\Support;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Minishlink\WebPush\MessageSentReport;
use Modules\Core\Models\PushSubscription;
use Modules\Core\Support\WebPushSender;

/**
 * Pengirim web push dengan pipa Guzzle yang TIRUAN (P-3e, T3e.3).
 *
 * BACA INI SEBELUM MENULIS UJI KANAL INI. `Minishlink\WebPush\WebPush`
 * membuat kliennya sendiri (`new Client($clientOptions)`), jadi `Http::fake()`
 * DAN `Http::preventStrayRequests()` tidak menutupinya sama sekali: keduanya
 * hanya menjaga klien HTTP Laravel. Sebuah uji yang lupa memasang handler di
 * sini benar-benar menghubungi layanan push sungguhan dari mesin uji (diukur
 * 13 Sep 2026). Satu-satunya lubang yang disediakan pustaka itu adalah
 * argumen KEEMPAT konstruktornya — `clientOptions` — dan itulah yang
 * disambung kelas ini.
 *
 * Yang dijalankan tetap KODE SUNGGUHAN sampai ke soket: enkripsi aes128gcm,
 * penandatanganan VAPID, pembentukan permintaan. Yang diganti hanya
 * jawabannya. Karena itu kunci langganan yang dipakai uji harus kunci P-256
 * yang SAH — kunci karangan akan gagal di enkripsi, bukan di jaringan.
 */
class FakeWebPushSender extends WebPushSender
{
    /** @var list<array{endpoint: string, payload: string}> */
    public array $sent = [];

    /**
     * @param  list<mixed>  $responses  antrean jawaban/pengecualian Guzzle
     */
    public function __construct(array $responses)
    {
        $this->clientOptions = ['handler' => HandlerStack::create(new MockHandler($responses))];
    }

    public function send(PushSubscription $subscription, string $payload): MessageSentReport
    {
        $this->sent[] = ['endpoint' => (string) $subscription->endpoint, 'payload' => $payload];

        return parent::send($subscription, $payload);
    }
}
