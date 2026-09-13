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
     * Antrean jawaban yang BELUM diambil.
     *
     * Dibuka supaya sebuah uji bisa menghitung berapa PERMINTAAN yang
     * benar-benar dibuat, bukan hanya berapa kali send() dipanggil — satu
     * pengiriman yang diikuti pengalihan membuat DUA permintaan dari satu
     * panggilan, dan itulah yang dipaku uji "307 tidak diikuti" (putaran
     * verifikasi: A-2). MockHandler adalah Countable.
     */
    public MockHandler $handler;

    /**
     * @param  list<mixed>  $responses  antrean jawaban/pengecualian Guzzle
     */
    public function __construct(array $responses)
    {
        $this->handler = new MockHandler($responses);
        // Hanya handler-nya yang dipasang: opsi produksi (allow_redirects,
        // connect_timeout) tetap berlaku karena WebPushSender menimpakan
        // properti ini DI ATAS-nya, bukan menggantikannya. Sebuah uji yang
        // memaku "pengalihan tidak diikuti" di atas klien yang aturannya
        // berbeda dari produksi memaku sesuatu yang hanya benar di uji.
        $this->clientOptions = ['handler' => HandlerStack::create($this->handler)];
    }

    public function send(PushSubscription $subscription, string $payload): MessageSentReport
    {
        $this->sent[] = ['endpoint' => (string) $subscription->endpoint, 'payload' => $payload];

        return parent::send($subscription, $payload);
    }
}
