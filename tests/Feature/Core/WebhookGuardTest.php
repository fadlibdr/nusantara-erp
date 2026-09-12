<?php

namespace Tests\Feature\Core;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use LogicException;
use Modules\Core\Jobs\DeliverWebhook;
use Modules\Core\Models\WebhookDelivery;
use Modules\Core\Models\WebhookSubscription;
use Modules\Core\Services\WebhookService;
use Modules\Core\Support\WebhookPayload;
use Modules\Core\Support\WebhookSignature;
use Modules\Core\Support\WebhookUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * URL MILIK ORANG LAIN ADALAH PERMUKAAN SSRF, dan HMAC adalah kontrak yang
 * dibaca orang lain (P-3d, perangkap D dan E).
 *
 * Tidak ada permintaan HTTP sungguhan dan tidak ada DNS sungguhan: `Http::fake()`
 * + `preventStrayRequests()`, dan penyelesai nama `WebhookUrl` ditukar seam.
 */
class WebhookGuardTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TANPA stub tangkap-semua di sini: `Http::fake()` MENGGABUNGKAN stub,
        // dan sebuah pola '*' yang terdaftar lebih dulu menang atas pola yang
        // lebih tepat yang didaftarkan sebuah uji — dua uji di bawah ini
        // mendapat 200 alih-alih 302/500 sebelum baris ini dibuang.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        WebhookUrl::resolverUsing(null);

        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string}> */
    public static function refusedUrls(): array
    {
        return [
            'http biasa' => ['http://penerima.contoh.co.id/masuk', 'harus memakai https'],
            'loopback v4' => ['https://127.0.0.1/masuk', 'jaringan server ini'],
            'loopback nama' => ['https://localhost/masuk', 'nama jaringan internal'],
            'loopback v6' => ['https://[::1]/masuk', 'jaringan server ini'],
            'privat 10/8' => ['https://10.0.0.5/masuk', 'jaringan server ini'],
            'privat 172.16/12' => ['https://172.16.3.4/masuk', 'jaringan server ini'],
            'privat 192.168/16' => ['https://192.168.1.10/masuk', 'jaringan server ini'],
            'metadata awan' => ['https://169.254.169.254/latest/meta-data/', 'jaringan server ini'],
            'CGNAT 100.64/10' => ['https://100.64.0.1/masuk', 'jaringan server ini'],
            'nol' => ['https://0.0.0.0/masuk', 'jaringan server ini'],
            'akhiran .local' => ['https://kasir.local/masuk', 'nama jaringan internal'],
            'akhiran .internal' => ['https://api.internal/masuk', 'nama jaringan internal'],
            'kredensial di URL' => ['https://admin:rahasia@penerima.contoh.co.id/masuk', 'nama pengguna atau kata sandi'],
            'bukan URL' => ['penerima.contoh.co.id/masuk', 'tidak bisa dibaca'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_an_internal_or_plaintext_url_is_refused_when_the_subscription_is_saved(string $url, string $fragment): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($fragment, '/').'/');

        WebhookUrl::assertShape($url);
    }

    public function test_a_public_https_url_is_accepted(): void
    {
        WebhookUrl::assertShape('https://penerima.contoh.co.id/nusantara/webhook');
        WebhookUrl::assertShape('https://203.0.113.10/nusantara/webhook');

        $this->assertTrue(WebhookUrl::isPublicIp('203.0.113.10'));
        $this->assertFalse(WebhookUrl::isPublicIp('127.0.0.1'));
        $this->assertFalse(WebhookUrl::isPublicIp('169.254.169.254'));
        $this->assertFalse(WebhookUrl::isPublicIp('100.64.0.1'));
    }

    /**
     * DNS BISA BERUBAH DI ANTARA MENYIMPAN DAN MENGIRIM.
     *
     * Sebuah nama yang lolos pemeriksaan saat langganannya disimpan bisa
     * menunjuk ke 127.0.0.1 ketika kiriman benar-benar berangkat. Itulah
     * seluruh alasan pemeriksaan kedua ada di dalam job.
     */
    public function test_a_name_that_starts_pointing_inward_is_refused_at_send_time(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);
        WebhookUrl::assertSafeToSend('https://berubah.contoh.co.id/masuk');

        WebhookUrl::resolverUsing(static fn (): array => ['127.0.0.1']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/jaringan server ini/');

        WebhookUrl::assertSafeToSend('https://berubah.contoh.co.id/masuk');
    }

    public function test_a_name_that_resolves_to_nothing_is_refused_at_send_time(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak bisa diterjemahkan/');

        WebhookUrl::assertSafeToSend('https://hilang.contoh.co.id/masuk');
    }

    /** Satu alamat internal di antara beberapa sudah cukup untuk menolak. */
    public function test_one_inward_address_among_several_is_enough_to_refuse(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10', '10.1.2.3']);

        $this->expectException(LogicException::class);

        WebhookUrl::assertSafeToSend('https://campuran.contoh.co.id/masuk');
    }

    /**
     * REDIRECT TIDAK DIIKUTI, dan kiriman yang dijawab 3xx dicatat GAGAL.
     *
     * Sebuah penerima yang menjawab `302 Location: http://169.254.169.254/`
     * memindahkan kiriman bertanda tangan kita ke sana tanpa satu pun
     * pemeriksaan di atas berlaku lagi.
     */
    public function test_a_redirect_is_not_followed_and_is_not_counted_as_delivered(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);

        Http::fake([
            'penerima.contoh.co.id/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        ]);

        $delivery = $this->delivery();

        try {
            (new DeliverWebhook((int) $delivery->getKey()))->handle(app(WebhookService::class));
        } catch (\RuntimeException $e) {
            // Percobaan yang gagal melempar supaya pekerja menjadwalkan yang berikutnya.
        }

        $delivery->refresh();

        $this->assertSame(WebhookDelivery::QUEUED, $delivery->status);
        $this->assertSame(302, (int) $delivery->response_status);
        $this->assertStringContainsString('Redirect tidak diikuti', (string) $delivery->error);
        Http::assertSentCount(1);
    }

    /** Jawaban 500 bukan terkirim, dan percobaan berikutnya dijadwalkan. */
    public function test_a_five_hundred_is_not_delivered_and_schedules_the_next_attempt(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);
        Http::fake(['penerima.contoh.co.id/*' => Http::response('kandas', 500)]);

        $delivery = $this->delivery();

        try {
            (new DeliverWebhook((int) $delivery->getKey()))->handle(app(WebhookService::class));
        } catch (\RuntimeException $e) {
        }

        $delivery->refresh();

        $this->assertSame(WebhookDelivery::QUEUED, $delivery->status);
        $this->assertSame(1, (int) $delivery->attempts);
        $this->assertSame(500, (int) $delivery->response_status);
        $this->assertStringContainsString('Penerima menjawab 500', (string) $delivery->error);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertNull($delivery->delivered_at);
    }

    /** Sesudah percobaan terakhir: `failed`, dengan sebab Indonesia yang terbaca. */
    public function test_after_the_last_attempt_the_row_says_failed_in_indonesian(): void
    {
        $delivery = $this->delivery();

        (new DeliverWebhook((int) $delivery->getKey()))->failed(new MaxAttemptsExceededException);

        $delivery->refresh();

        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertStringContainsString('Percobaan habis sebelum penerima menjawab', (string) $delivery->error);
        $this->assertNull($delivery->next_attempt_at);
    }

    /**
     * LANGGANAN YANG TERUS GAGAL DINONAKTIFKAN — DAN MENGATAKANNYA.
     *
     * Angkanya dipaku literal: sebuah uji yang membaca ambangnya dari kelas
     * yang diujinya akan tetap hijau ketika seseorang mengetik 2.
     */
    public function test_a_subscription_that_keeps_failing_is_disabled_and_says_so(): void
    {
        $this->assertSame(20, WebhookService::DISABLE_AFTER_FAILURES);

        $subscription = $this->subscription();
        $service = app(WebhookService::class);

        for ($i = 1; $i <= 19; $i++) {
            $service->recordFailure($subscription->fresh(), 'Penerima menjawab 500.');
        }

        $this->assertNull($subscription->fresh()->disabled_at);
        $this->assertSame(19, (int) $subscription->fresh()->consecutive_failures);

        $service->recordFailure($subscription->fresh(), 'Penerima menjawab 500.');

        $disabled = $subscription->fresh();
        $this->assertNotNull($disabled->disabled_at);
        $this->assertStringContainsString('20 pengiriman gagal berturut-turut', (string) $disabled->disabled_reason);
        $this->assertStringContainsString('Penerima menjawab 500.', (string) $disabled->disabled_reason);
    }

    /** Satu pengiriman yang berhasil mengembalikan hitungannya ke nol. */
    public function test_one_success_resets_the_failure_count(): void
    {
        $subscription = $this->subscription();
        $service = app(WebhookService::class);

        $service->recordFailure($subscription->fresh(), 'Penerima menjawab 500.');
        $service->recordFailure($subscription->fresh(), 'Penerima menjawab 500.');
        $this->assertSame(2, (int) $subscription->fresh()->consecutive_failures);

        $service->recordSuccess($subscription->fresh());

        $this->assertSame(0, (int) $subscription->fresh()->consecutive_failures);
        $this->assertNotNull($subscription->fresh()->last_success_at);
    }

    // ------------------------------------------------------------ tanda tangan

    /**
     * RESEP TANDA TANGAN, DIPAKU LITERAL.
     *
     * Nilai yang dihitung di sini ditulis dengan tangan dari resep yang
     * dijanjikan dokumen kepada penerima — bukan dibaca dari kelas yang
     * diujinya. Kalau `WebhookSignature` berubah bentuk, uji ini merah, dan
     * itulah gunanya: penerima di luar sana sudah menulis kode terhadap resep
     * ini.
     */
    public function test_the_signature_recipe_is_exactly_what_the_document_promises(): void
    {
        $body = '{"version":1,"event":"document.approved"}';
        $secret = 'rahasia';
        $timestamp = 1757683200;

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertSame('t='.$timestamp.',v1='.$expected, WebhookSignature::header($body, $secret, $timestamp));
        $this->assertSame('X-Nusantara-Signature', WebhookSignature::HEADER);
        $this->assertSame('X-Nusantara-Event', WebhookSignature::EVENT_HEADER);
        $this->assertSame('sha256', WebhookSignature::ALGORITHM);
        $this->assertSame(300, WebhookSignature::TOLERANCE);
        $this->assertSame(64, strlen($expected));
    }

    public function test_verification_rejects_a_tampered_body_a_wrong_secret_and_a_stale_timestamp(): void
    {
        $body = '{"version":1,"event":"document.approved"}';
        $secret = 'rahasia';
        $now = 1757683200;
        $header = WebhookSignature::header($body, $secret, $now);

        $this->assertTrue(WebhookSignature::verify($header, $body, $secret, $now));
        $this->assertTrue(WebhookSignature::verify($header, $body, $secret, $now + 299));

        $this->assertFalse(WebhookSignature::verify($header, $body.' ', $secret, $now), 'badan yang diubah satu spasi');
        $this->assertFalse(WebhookSignature::verify($header, $body, 'rahasia-lain', $now), 'rahasia yang salah');
        $this->assertFalse(WebhookSignature::verify($header, $body, $secret, $now + 301), 'di luar jendela 300 detik');
        $this->assertFalse(WebhookSignature::verify($header, $body, $secret, $now - 301), 'stempel dari masa depan');
        $this->assertFalse(WebhookSignature::verify('v1=abc', $body, $secret, $now), 'tanpa stempel waktu');
        $this->assertFalse(WebhookSignature::verify('t=bukanangka,v1=abc', $body, $secret, $now));
    }

    /** Stempel waktu IKUT ditandatangani — kalau tidak, jendela waktunya hiasan. */
    public function test_the_timestamp_is_part_of_what_is_signed(): void
    {
        $body = '{"a":1}';
        $secret = 'rahasia';

        $this->assertNotSame(
            WebhookSignature::digest($body, $secret, 1757683200),
            WebhookSignature::digest($body, $secret, 1757683201),
        );
    }

    /** Muatan menjadi byte di SATU tempat, dengan flag yang disebut dokumen. */
    public function test_the_payload_is_encoded_in_exactly_one_way(): void
    {
        $encoded = WebhookPayload::encode(['a' => 'finance/ar-invoices', 'b' => 'Penerimaan — selesai']);

        $this->assertStringContainsString('finance/ar-invoices', $encoded);
        $this->assertStringNotContainsString('finance\\/ar-invoices', $encoded);
        $this->assertStringContainsString('—', $encoded);
        $this->assertSame(['document.submitted', 'document.approved', 'document.rejected'], WebhookPayload::EVENTS);
        $this->assertSame(1, WebhookPayload::VERSION);
    }

    private function subscription(): WebhookSubscription
    {
        $row = new WebhookSubscription;
        $row->forceFill([
            'name' => 'Akuntansi eksternal',
            'url' => 'https://penerima.contoh.co.id/nusantara/webhook',
            'secret' => 'rahasia-uji-'.str_repeat('a', 40),
            'secret_set_at' => now(),
            'events' => WebhookPayload::EVENTS,
            'document_types' => null,
            'is_active' => true,
        ])->save();

        return $row->fresh();
    }

    private function delivery(): WebhookDelivery
    {
        $subscription = $this->subscription();
        $body = WebhookPayload::encode(['version' => 1, 'id' => 'uji', 'event' => 'document.approved']);

        return WebhookDelivery::query()->create([
            'subscription_id' => $subscription->getKey(),
            'subscription_name' => $subscription->name,
            'url' => $subscription->url,
            'event_id' => 'uji-'.str()->random(8),
            'event' => 'document.approved',
            'document_type' => 'finance/ap-bills',
            'document_id' => 1,
            'document_code' => 'BILL/2026/0001',
            'payload' => $body,
            'signature' => WebhookSignature::header($body, (string) $subscription->secret, now()->getTimestamp()),
            'status' => WebhookDelivery::QUEUED,
        ]);
    }
}
