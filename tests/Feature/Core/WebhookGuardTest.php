<?php

namespace Tests\Feature\Core;

use GuzzleHttp\Handler\HostValidator;
use GuzzleHttp\Psr7\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use LogicException;
use Modules\Core\Jobs\DeliverWebhook;
use Modules\Core\Models\WebhookDelivery;
use Modules\Core\Models\WebhookSubscription;
use Modules\Core\Services\WebhookService;
use Modules\Core\Support\PushEndpoint;
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
            // V-webhook-2: alamat internal YANG DISAMARKAN. Kedelapan bentuk di
            // bawah ini lolos kedua pintu sebelum perbaikan — empat karena PHP
            // tidak menganggap ::ffff:0:0/96 "reserved", dan tiga karena
            // `filter_var` menolak bentuk desimal/oktal/pendek sebagai IP
            // sehingga host-nya diperlakukan sebagai NAMA.
            'loopback v6 bertopeng v4' => ['https://[::ffff:127.0.0.1]/masuk', 'jaringan server ini'],
            'privat v6 bertopeng v4' => ['https://[::ffff:10.0.0.1]/masuk', 'jaringan server ini'],
            'metadata awan bertopeng v4' => ['https://[::ffff:169.254.169.254]/latest/meta-data/', 'jaringan server ini'],
            'bertopeng v4 bentuk panjang' => ['https://[0:0:0:0:0:ffff:192.168.1.1]/masuk', 'jaringan server ini'],
            'bertopeng v4 bentuk heksa' => ['https://[::ffff:7f00:1]/masuk', 'jaringan server ini'],
            'NAT64 64:ff9b::' => ['https://[64:ff9b::7f00:1]/masuk', 'jaringan server ini'],
            'desimal' => ['https://2130706433/masuk', 'jaringan server ini'],
            'oktal' => ['https://0177.0.0.1/masuk', 'jaringan server ini'],
            'pendek' => ['https://127.1/masuk', 'jaringan server ini'],
            // BENTUK SAMARAN KESEMBILAN: titik ekor. Ketujuh baris di bawah
            // ini lolos PINTU PERTAMA sebelum `canonicalHost()` ada —
            // `filter_var` menolak `127.0.0.1.` sebagai IP dan `numericIpv4()`
            // berhenti di bagian kelima yang kosong, jadi host-nya
            // diperlakukan sebagai NAMA. Yang menutupnya dulu hanyalah DNS
            // yang kebetulan tidak menjawab nama itu; sebuah penyelesai yang
            // membajak NXDOMAIN (dan ada yang begitu) meloloskannya ke
            // transport, dan libcurl 8.21.0 membuang titiknya lalu menyambung
            // ke 127.0.0.1. Jaring kedua ada di Guzzle 7.15.2
            // (HostValidator, CVE-2026-69246); ini jaring pertama.
            'loopback titik ekor' => ['https://127.0.0.1./masuk', 'jaringan server ini'],
            'loopback titik ekor berlapis' => ['https://127.0.0.1.../masuk', 'jaringan server ini'],
            'desimal titik ekor' => ['https://2130706433./masuk', 'jaringan server ini'],
            'oktal titik ekor' => ['https://0177.0.0.1./masuk', 'jaringan server ini'],
            'pendek titik ekor' => ['https://127.1./masuk', 'jaringan server ini'],
            'metadata awan titik ekor' => ['https://169.254.169.254./latest/meta-data/', 'jaringan server ini'],
            // PRIVATE_SUFFIXES mencocokkan `.local`, dan `kasir.local.`
            // berakhiran `.local.` — bukan hal yang sama.
            'akhiran .local titik ekor' => ['https://kasir.local./masuk', 'nama jaringan internal'],
            // Tidak tersisa apa pun sesudah titik ekornya dibuang.
            'host hanya titik' => ['https://./masuk', 'tidak bisa dibaca'],
            // RENTANG KHUSUS IANA yang DILEWATKAN
            // FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE. Ketiganya lolos kedua
            // pintu sebelum perbaikan ini — diukur, bukan diduga.
            'IETF protocol assignments 192.0.0.0/24' => ['https://192.0.0.171/masuk', 'jaringan server ini'],
            'awal 192.0.0.0/24' => ['https://192.0.0.0/masuk', 'jaringan server ini'],
            'akhir 192.0.0.0/24' => ['https://192.0.0.255/masuk', 'jaringan server ini'],
            'benchmarking 198.18.0.0/15' => ['https://198.18.0.1/masuk', 'jaringan server ini'],
            'akhir 198.18.0.0/15' => ['https://198.19.255.255/masuk', 'jaringan server ini'],
            'multicast 224.0.0.0/4' => ['https://224.0.0.1/masuk', 'jaringan server ini'],
            'akhir multicast 224.0.0.0/4' => ['https://239.255.255.255/masuk', 'jaringan server ini'],
            // KEMBARAN IPv6-nya, yang menyusul sehari kemudian: `ff02::1`
            // dinilai PUBLIK sampai `ff00::/8` masuk ke daftar yang sama.
            'multicast IPv6 ff00::/8' => ['https://[ff02::1]/masuk', 'jaringan server ini'],
            // Dan bentuk samarannya ikut, tanpa satu baris pun tambahan:
            // penilaiannya dilakukan SESUDAH normalize().
            'multicast bertopeng v4' => ['https://[::ffff:224.0.0.1]/masuk', 'jaringan server ini'],
            'DNS64 bertopeng v4' => ['https://[::ffff:192.0.0.171]/masuk', 'jaringan server ini'],
            'benchmarking bentuk desimal' => ['https://3323068417/masuk', 'jaringan server ini'],
            // HOST YANG KAMI DAN TRANSPORT BACA BERBEDA. Kelima bentuk di
            // bawah lolos KEDUA pintu sebelum perbaikan ini: `numericIpv4()`
            // berhenti di bagian yang bukan angka dan `filter_var` menolaknya
            // sebagai IP, jadi host-nya dinilai sebagai NAMA — dan yang
            // menolaknya sesudah itu hanyalah resolver yang kebetulan gagal.
            'host ber-escape persen' => ['https://127.0.0.%31/masuk', 'escape persen'],
            'host ber-escape persen seluruhnya' => ['https://%31%32%37.0.0.1/masuk', 'escape persen'],
            'host ber-escape persen dan titik ekor' => ['https://127.0.0.%31./masuk', 'escape persen'],
            'host non-ASCII' => ['https://ерп.contoh.co.id/masuk', 'di luar ASCII'],
            // Spasi bertahan melewati parse_url (tidak seperti byte kendali,
            // yang diganti menjadi «_» — lihat KEPUTUSAN-INTEGRASI §11.2).
            'host berspasi' => ['https://contoh .co.id/masuk', 'di luar ASCII'],
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

        // Titik ekor pada NAMA SUNGGUHAN adalah bentuk absolut FQDN, dan ia
        // tetap diterima: `canonicalHost()` membuang titiknya, bukan URL-nya.
        WebhookUrl::assertShape('https://penerima.contoh.co.id./nusantara/webhook');

        $this->assertTrue(WebhookUrl::isPublicIp('203.0.113.10'));
        $this->assertFalse(WebhookUrl::isPublicIp('127.0.0.1'));
        $this->assertFalse(WebhookUrl::isPublicIp('169.254.169.254'));
        $this->assertFalse(WebhookUrl::isPublicIp('100.64.0.1'));

        // V-webhook-2: alamat yang SAMA, ditulis dalam bentuk IPv6 bertopeng
        // IPv4. `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` memulangkan keempatnya
        // sebagai alamat yang sah dan publik — ::ffff:0:0/96 bukan rentang yang
        // PHP anggap dicadangkan — jadi penilaiannya harus dilakukan atas
        // bentuk IPv4-nya.
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:127.0.0.1'));
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:10.0.0.1'));
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:169.254.169.254'));
        $this->assertFalse(WebhookUrl::isPublicIp('0:0:0:0:0:ffff:192.168.1.1'));
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:7f00:1'));
        $this->assertFalse(WebhookUrl::isPublicIp('64:ff9b::7f00:1'));
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:100.64.0.1'));

        // Dan sebuah alamat publik yang kebetulan ditulis bertopeng TETAP publik.
        $this->assertTrue(WebhookUrl::isPublicIp('::ffff:203.0.113.10'));
        $this->assertTrue(WebhookUrl::isPublicIp('2606:4700:4700::1111'));

        // Nama internasional yang ditulis BENAR sebagai A-label tetap
        // diterima — aturan non-ASCII di bawah menolak bentuknya, bukan
        // namanya. «xn--e1auc» adalah bentuk A-label dari «ерп».
        WebhookUrl::assertShape('https://xn--e1auc.contoh.co.id/nusantara/webhook');
    }

    /**
     * RENTANG KHUSUS IANA — DAN GARIS YANG TIDAK BOLEH DILEWATI SEBELAHNYA.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` melewatkan tiga rentang yang
     * bukan internet publik: `192.0.0.0/24` (IETF Protocol Assignments,
     * termasuk DNS64 `192.0.0.170`/`.171`), `198.18.0.0/15` (benchmarking
     * RFC 2544, lazim dirutekan di dalam jaringan lab dan appliance) dan
     * multicast `224.0.0.0/4`. Ketiganya PUBLIK menurut gerbang ini sebelum
     * perbaikan — diukur 13 Sep 2026, dan cacatnya PRA-ADA: badan
     * `isPublicIp()` identik byte-per-byte dengan keadaan sebelum gabungan
     * keamanan.
     *
     * SETENGAH KEDUA UJI INI SAMA PENTINGNYA. Sebuah mask yang meleset satu
     * bit menelan tetangganya tanpa satu uji pun memerah, dan blok dokumentasi
     * RFC 5737 (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`) ada
     * TEPAT di sebelah dua di antaranya. `203.0.113.10` adalah fikstur
     * "alamat publik" baku rumah ini di 30 tempat pada 4 berkas uji: mutasi
     * yang menambahkan `203.0.113.0/24` ke daftar memerahkan 15 uji (diukur),
     * dan memindahkan fikstur itu adalah pekerjaan tersendiri yang harus
     * DIPUTUSKAN.
     */
    public function test_the_special_iana_ranges_are_refused_and_the_documentation_blocks_are_not(): void
    {
        // Yang ditutup perbaikan ini — batas bawah dan batas atas tiap rentang.
        foreach ([
            '192.0.0.0', '192.0.0.170', '192.0.0.171', '192.0.0.255',
            '198.18.0.0', '198.18.0.1', '198.19.255.255',
            '224.0.0.0', '224.0.0.1', '239.255.255.255',
        ] as $address) {
            $this->assertFalse(
                WebhookUrl::isPublicIp($address),
                "«{$address}» dinilai publik: ia ada di rentang khusus IANA yang bukan internet publik.",
            );
        }

        // SATU ALAMAT DI LUAR TIAP BATAS — supaya mask yang meleset satu bit
        // memerahkan sesuatu alih-alih diam-diam menelan tetangganya.
        foreach ([
            '191.255.255.255', '192.0.1.0', '192.0.1.1',
            '198.17.255.255', '198.20.0.0', '198.20.0.1',
            '223.255.255.255',
            // TIDAK ADA pasangan "tepat di atas" untuk multicast: 240.0.0.0/4
            // tepat di sebelahnya dan sudah ditolak FILTER_FLAG_NO_RES_RANGE,
            // jadi batas atas 224.0.0.0/4 dijaga dari bawah saja.
        ] as $address) {
            $this->assertTrue(
                WebhookUrl::isPublicIp($address),
                "«{$address}» ditolak: ia ada DI LUAR rentang khusus IANA, dan gerbang yang menolak alamat yang "
                .'sebenarnya bisa dikirimi sama salahnya dengan gerbang yang meloloskan alamat internal.',
            );
        }

        // BLOK DOKUMENTASI RFC 5737 TETAP PUBLIK — dengan sengaja. Lihat
        // docblock di atas dan KEPUTUSAN-INTEGRASI §11.2.
        foreach (['192.0.2.1', '198.51.100.1', '203.0.113.10', '203.0.113.255'] as $address) {
            $this->assertTrue(
                WebhookUrl::isPublicIp($address),
                "«{$address}» ada di blok dokumentasi RFC 5737, yang SENGAJA tidak ikut ditolak: ia fikstur "
                .'"alamat publik" baku suite ini.',
            );
        }

        // Bentuk samarannya dinilai lewat jalan yang sama, karena penilaiannya
        // terjadi SESUDAH normalize().
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:192.0.0.171'));
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:198.18.0.1'));
        $this->assertFalse(WebhookUrl::isPublicIp('::ffff:224.0.0.1'));
    }

    /**
     * MULTICAST IPv6 — KEMBARAN `224.0.0.0/4`, DAN INKONSISTENSI YANG DITUTUP.
     *
     * Diukur 14 Sep 2026 sebelum perbaikan: `ff02::1`, `ff00::1` dan
     * `ff05::1:3` dinilai PUBLIK sementara `224.0.0.1` sudah ditolak. Yang
     * ditutup di sini karena itu BUKAN lubang yang bisa dieksploitasi —
     * multicast di atas TCP tidak pernah membentuk koneksi — melainkan sebuah
     * gerbang yang menjawab dua hal berbeda tentang alamat yang sama, hanya
     * karena yang satu ditulis dalam empat angka desimal.
     *
     * SETENGAH KEDUA UJI INI SAMA PENTINGNYA, dan lebih daripada pada kembaran
     * IPv4-nya. `ff00::/8` adalah satu byte utuh, dan sebuah pemeriksaan yang
     * ditulis sebagai teks alih-alih sebagai byte menelan `ff::1` — yaitu
     * `00ff::1`, alamat yang huruf awalnya kebetulan «ff» dan letaknya di
     * ujung lain ruang alamat. `feff::1` menjaga batas bawahnya: ia SATU BYTE
     * di bawah rentang, dan ia harus tetap publik.
     *
     * Rentang IPv6 lain SENGAJA tidak ikut. `fe80::/10` dan `2001:db8::/32`
     * sudah ditutup `FILTER_FLAG_NO_RES_RANGE` — dua baris terakhir uji ini
     * mengukurnya — dan menambahkannya ke REFUSED_BLOCKS hanya menambah baris
     * yang tidak bisa memerah.
     */
    public function test_ipv6_multicast_is_refused_like_its_ipv4_twin(): void
    {
        foreach (['ff00::', 'ff00::1', 'ff02::1', 'ff05::1:3', 'ff0e::1', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'] as $address) {
            $this->assertFalse(
                WebhookUrl::isPublicIp($address),
                "«{$address}» dinilai publik: ia multicast IPv6 (ff00::/8), kembaran 224.0.0.0/4 yang sudah ditolak.",
            );
        }

        // SATU ALAMAT DI LUAR TIAP BATAS.
        foreach (['feff::1', 'ff::1', '2606:4700:4700::1111'] as $address) {
            $this->assertTrue(
                WebhookUrl::isPublicIp($address),
                "«{$address}» ditolak: ia ada DI LUAR ff00::/8 («ff::1» adalah 00ff::1, dan «feff::1» satu byte di "
                .'bawah rentangnya), dan gerbang yang menolak alamat yang sebenarnya bisa dikirimi sama salahnya '
                .'dengan gerbang yang meloloskan alamat internal.',
            );
        }

        /*
         * SATU DAFTAR UNTUK DUA KELUARGA — DAN GARIS YANG MEMISAHKANNYA.
         *
         * Blok IPv4 dan alamat IPv6 hidup di daftar yang sama sejak
         * `ff00::/8` masuk, dan yang menjaga mereka tidak saling menyentuh
         * adalah PANJANG BYTE (4 vs 16), bukan urutan daftar. Keempat alamat
         * IPv6 di bawah ini adalah kasus terburuknya: byte-byte awalnya
         * PERSIS sama dengan sebuah blok IPv4 di REFUSED_BLOCKS —
         * `e000::` dengan multicast `224.0.0.0/4`, `c000::` dengan
         * `192.0.0.0/24`, `6440:4000::` dengan CGNAT `100.64.0.0/10`,
         * `c612::` dengan benchmarking `198.18.0.0/15`. Keempatnya alamat
         * IPv6 biasa dan harus tetap publik; mutasi yang membuang penjaga
         * panjang byte menolak keempatnya (diukur).
         */
        foreach (['e000::1', 'c000::1', '6440:4000::1', 'c612:1234::5'] as $address) {
            $this->assertTrue(
                WebhookUrl::isPublicIp($address),
                "«{$address}» ditolak: byte awalnya kebetulan sama dengan sebuah blok IPv4 di REFUSED_BLOCKS, tetapi "
                .'ia alamat IPv6 — sebuah blok IPv4 tidak boleh bisa menyentuh keluarga yang lain.',
            );
        }

        // Dan sebaliknya: alamat IPv4 yang byte pertamanya kebetulan juga
        // 0xff ditolak oleh FILTER_FLAG_NO_RES_RANGE (240.0.0.0/4), bukan
        // oleh `ff00::/8`.
        $this->assertFalse(WebhookUrl::isPublicIp('255.255.255.254'));
        $this->assertFalse(WebhookUrl::isPublicIp('224.0.0.1'));

        // Kembaran IPv4-nya tetap ditolak, dan blok IPv6 yang SUDAH ditutup
        // PHP tetap tertutup tanpa satu baris pun di REFUSED_BLOCKS.
        $this->assertFalse(WebhookUrl::isPublicIp('fe80::1'));
        $this->assertFalse(WebhookUrl::isPublicIp('2001:db8::1'));
    }

    /**
     * DAN BENTUK-BENTUK ITU DITOLAK TANPA BERTANYA KEPADA DNS.
     *
     * Uji provider di atas hijau juga bila yang menolak `127.0.0.%31`
     * hanyalah resolver yang tidak menjawab nama itu — dan resolver yang
     * membajak NXDOMAIN bukan barang langka. Di sini resolvernya menjawab
     * SETIAP nama dengan alamat publik, jadi satu-satunya yang bisa menolak
     * adalah ATURAN di gerbang.
     *
     * Diukur sebelum perbaikan, dengan resolver yang sama: gerbang SIMPAN dan
     * gerbang KIRIM menerima keduanya. Yang menutup lubang itu hari ini adalah
     * Guzzle 7.15.2 (`HostValidator::assertRequestHost()`) — PUSTAKANYA, bukan
     * gerbang ini — dan docblock `WebhookUrl` berkata gerbangnya menilai
     * alamat dengan penguraiannya sendiri dan tidak pernah menumpang
     * normalisasi pustaka HTTP. Uji ini adalah kalimat itu, dibuat benar.
     */
    public function test_a_percent_escaped_or_non_ascii_host_is_refused_without_asking_dns(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);

        foreach ([
            'https://127.0.0.%31/masuk' => 'escape persen',
            'https://%31%32%37.0.0.1/masuk' => 'escape persen',
            'https://ерп.contoh.co.id/masuk' => 'di luar ASCII',
        ] as $url => $fragment) {
            foreach (['assertShape', 'assertSafeToSend'] as $gate) {
                try {
                    WebhookUrl::{$gate}($url);
                    $this->fail(
                        "«{$url}» lolos WebhookUrl::{$gate}() ketika resolver menjawab setiap nama dengan alamat "
                        .'publik — artinya yang menolaknya selama ini hanyalah DNS, bukan gerbangnya. libcurl '
                        .'memecahkan %31 menjadi 1 dan menyambung ke 127.0.0.1 (CVE-2026-69246).',
                    );
                } catch (LogicException $e) {
                    $this->assertStringContainsString($fragment, $e->getMessage());
                }
            }
        }

        // Nama internasional yang ditulis BENAR lolos keduanya — aturan ini
        // menolak bentuk yang ambigu, bukan nama yang bukan bahasa Inggris.
        WebhookUrl::assertShape('https://xn--e1auc.contoh.co.id/masuk');
        WebhookUrl::assertSafeToSend('https://xn--e1auc.contoh.co.id/masuk');
    }

    /**
     * V-webhook-2, pintu KEDUA: sebuah baris kiriman yang sudah tersimpan
     * dengan alamat bertopeng tidak boleh berangkat.
     *
     * `assertSentCount(0)` adalah asersinya — bukan statusnya — karena yang
     * dipertaruhkan bukan bunyi baris log melainkan ada-tidaknya POST
     * bertanda tangan ke dalam jaringan server.
     */
    public function test_a_masked_internal_address_never_leaves_the_job(): void
    {
        Http::fake();
        WebhookUrl::resolverUsing(static function (): array {
            throw new \RuntimeException('DNS tidak boleh disentuh untuk alamat literal.');
        });

        $delivery = $this->delivery('https://[::ffff:169.254.169.254]/latest/meta-data/');

        (new DeliverWebhook((int) $delivery->getKey()))->handle(app(WebhookService::class));

        Http::assertSentCount(0);

        $delivery->refresh();

        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertStringContainsString('jaringan server ini', (string) $delivery->error);
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

    /**
     * TITIK EKOR DITOLAK TANPA BANTUAN DNS.
     *
     * Ini asersi yang sebenarnya di balik `canonicalHost()`. Sebelumnya
     * `https://127.0.0.1./` memang berakhir ditolak di kotak mana pun yang
     * penyelesainya waras — tetapi ditolak dengan kalimat yang SALAH («tidak
     * bisa diterjemahkan»), dan ditolak KARENA DNS tidak menjawab, bukan
     * karena gerbangnya mengenali alamatnya. Penyelesai di bawah ini menjawab
     * setiap nama dengan sebuah alamat publik — persis kelakuan penyelesai
     * yang membajak NXDOMAIN — dan di bawah penyelesai itu bentuk bertitik
     * ekor DULU LOLOS ke transport, tempat libcurl membuang titiknya dan
     * menyambung ke loopback.
     *
     * Yang dipaku: kalimatnya menyebut alamat internal, bukan kegagalan DNS.
     */
    public function test_a_trailing_dot_address_is_refused_even_when_dns_answers_everything(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);

        foreach (['https://127.0.0.1./masuk', 'https://2130706433./masuk', 'https://169.254.169.254./x'] as $url) {
            try {
                WebhookUrl::assertSafeToSend($url);
                $this->fail("«{$url}» diloloskan gerbang; ia mendarat di soket yang sama dengan alamat tanpa titik ekor.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('jaringan server ini', $e->getMessage());
            }
        }

        // Dan nama sungguhan berbentuk FQDN absolut TETAP berangkat.
        WebhookUrl::assertSafeToSend('https://penerima.contoh.co.id./masuk');
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

    /**
     * V-webhook-1: SETIAP PERCOBAAN DITANDATANGANI DENGAN JAM PERCOBAAN ITU.
     *
     * Dokumen menyuruh penerima menolak stempel yang selisihnya lebih dari
     * `TOLERANCE` detik, DAN menjanjikan lima percobaan dengan jeda
     * 60/300/900/3600. Sebuah tanda tangan yang dibekukan saat baris kiriman
     * lahir membuat kedua janji itu saling membunuh: percobaan ke-3 berangkat
     * 360 detik sesudah stempelnya, dan penerima yang memasang resep kita
     * menolaknya — 3 dari 5 percobaan mustahil berhasil, dan gangguan penerima
     * yang lebih lama dari satu menit tidak bisa dipulihkan sama sekali.
     *
     * Jarak waktunya adalah jumlah KUMULATIF backoff rumah.
     */
    public function test_every_attempt_is_signed_with_the_clock_of_that_attempt(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);
        Http::fake(['penerima.contoh.co.id/*' => Http::response('kandas', 500)]);

        $subscription = $this->subscription();
        $secret = (string) $subscription->secret;
        $delivery = $this->deliveryFor($subscription);
        $job = new DeliverWebhook((int) $delivery->getKey());

        $elapsed = 0;

        foreach ([0, 60, 360, 1260, 4860] as $attempt => $offset) {
            $this->travel($offset - $elapsed)->seconds();
            $elapsed = $offset;

            try {
                $job->handle(app(WebhookService::class));
            } catch (\RuntimeException $e) {
            }

            $sent = Http::recorded()->last();
            $this->assertNotNull($sent, 'percobaan '.($attempt + 1).' tidak mengirim apa pun');

            $header = $sent[0]->header(WebhookSignature::HEADER)[0];

            $this->assertTrue(
                WebhookSignature::verify($header, $sent[0]->body(), $secret, now()->getTimestamp()),
                'percobaan '.($attempt + 1).' berangkat '.$offset.' detik sesudah baris kiriman lahir, dan '
                .'penerima yang menegakkan jendela '.WebhookSignature::TOLERANCE.' detik menolaknya',
            );

            // Kolom `signature` adalah CATATAN percobaan terakhir: apa yang
            // benar-benar berangkat, bukan janji yang dibuat saat mengantre.
            $this->assertSame($header, (string) $delivery->fresh()->signature);
        }

        $this->assertSame(5, Http::recorded()->count());
    }

    /**
     * V-webhook-4: RAHASIA LANGGANAN YANG DIGEMAKAN PENERIMA TIDAK BOLEH
     * MENDARAT DI KOLOM `error`.
     *
     * Kolom itu 500 karakter yang dipulangkan `GET core/webhooks/deliveries`
     * dan digambar layar Sistem › Webhook untuk setiap pemegang `core.update`,
     * dan baris log SENGAJA tidak ikut terhapus ketika langganannya dicabut —
     * jadi sebuah rahasia yang mendarat di sana tinggal selamanya, dan
     * janji "tampil sekali, tidak pernah dipulangkan API mana pun" batal.
     */
    public function test_a_recipient_that_echoes_the_subscription_secret_does_not_get_it_written_to_the_log(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);

        $subscription = $this->subscription();
        $secret = (string) $subscription->secret;

        Http::fake(['penerima.contoh.co.id/*' => Http::response(
            'signature mismatch: expected 3f9c, your secret '.$secret.' is wrong', 500,
        )]);

        $delivery = $this->deliveryFor($subscription);

        try {
            (new DeliverWebhook((int) $delivery->getKey()))->handle(app(WebhookService::class));
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage(), 'pesan pengecualian ikut ke log pekerja');
        }

        $delivery->refresh();

        $this->assertStringNotContainsString($secret, (string) $delivery->error);
        $this->assertStringContainsString('[rahasia]', (string) $delivery->error);
        $this->assertStringContainsString('Penerima menjawab 500', (string) $delivery->error);
    }

    /**
     * V-webhook-3: BADAN JAWABAN YANG BUKAN UTF-8 SAH.
     *
     * Di SQLite byte tak sah tersimpan diam-diam; di MySQL kolom `error`
     * menolaknya dengan `1366 Incorrect string value`, dan pengecualian yang
     * naik ke pekerja bukan lagi kegagalan pengiriman melainkan kegagalan basis
     * data — lalu layar menampilkan kalimat Inggris yang menyebut soket dan
     * nama basis data. Uji ini HARUS dijalankan di MySQL juga.
     */
    public function test_a_response_body_that_is_not_valid_utf8_still_produces_an_indonesian_reason(): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);
        Http::fake(['penerima.contoh.co.id/*' => Http::response(random_bytes(200), 500)]);

        $delivery = $this->delivery();

        try {
            (new DeliverWebhook((int) $delivery->getKey()))->handle(app(WebhookService::class));
        } catch (\RuntimeException $e) {
        }

        $delivery->refresh();

        $error = (string) $delivery->error;

        $this->assertSame(500, (int) $delivery->response_status);
        $this->assertStringContainsString('Penerima menjawab 500', $error);
        $this->assertStringContainsString('badan yang bukan teks', $error);
        $this->assertTrue(mb_check_encoding($error, 'UTF-8'), 'kolom error harus UTF-8 sah di kedua driver');
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

    private function delivery(?string $url = null): WebhookDelivery
    {
        return $this->deliveryFor($this->subscription(), $url);
    }

    private function deliveryFor(WebhookSubscription $subscription, ?string $url = null): WebhookDelivery
    {
        $body = WebhookPayload::encode(['version' => 1, 'id' => 'uji', 'event' => 'document.approved']);

        return WebhookDelivery::query()->create([
            'subscription_id' => $subscription->getKey(),
            'subscription_name' => $subscription->name,
            'url' => $url ?? $subscription->url,
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

    /* ------------------------------ gerbang vs transport (gabungan keamanan) */

    /**
     * SATU-SATUNYA UJI DI SELURUH SUITE YANG MENYENTUH LAPISAN TEMPAT GUZZLE
     * BERUBAH — dan ia ada karena kenaikan ke 7.15.2 membawa aturan host baru.
     *
     * Seluruh uji keluar-jaringan rumah ini memakai `Http::fake()` atau
     * MockHandler, dan `HostValidator::assertRequestHost()` hanya dipanggil
     * dari handler SUNGGUHAN (CurlHandler, CurlMultiHandler, StreamHandler).
     * Artinya gerbang hijau BUKAN bukti bahwa sebuah kenaikan Guzzle netral:
     * perubahan transport berikutnya akan lolos dengan cara yang persis sama.
     * Uji ini memanggil validator itu LANGSUNG — tanpa soket, tanpa DNS — dan
     * menuntut satu hal saja: GERBANG DAN TRANSPORT BERKATA SAMA.
     *
     * Yang ditemukan audit gabungan ini (C-1): `https://203.0.113.10./…`
     * DITERIMA gerbang dan DITOLAK transport. Akibatnya gagal-tertutup, bukan
     * lubang keamanan — layar berkata "tersimpan", lalu setiap pengiriman mati
     * dengan kalimat INGGRIS dari pustaka di kolom `error`, lima percobaan
     * penuh per pengiriman, dan sesudah 20 pengiriman gagal berturut-turut
     * (WebhookService::DISABLE_AFTER_FAILURES) langganannya dinonaktifkan
     * otomatis. Titik ekor pada NAMA sungguhan tetap sah di ketiganya: ia
     * bentuk FQDN absolut, dan baris pertama tabel di bawah memakukannya.
     */
    #[DataProvider('hostsTheGateAndTheTransportMustAgreeOn')]
    public function test_the_gate_and_the_transport_never_disagree_about_a_host(string $url, bool $acceptable): void
    {
        WebhookUrl::resolverUsing(static fn (): array => ['203.0.113.10']);

        $gate = true;
        try {
            WebhookUrl::assertShape($url);
        } catch (LogicException $e) {
            $gate = false;
        }

        $push = true;
        try {
            PushEndpoint::assertShape($url);
        } catch (LogicException $e) {
            $push = false;
        }

        $transport = true;
        try {
            HostValidator::assertRequestHost(new Request('POST', $url));
        } catch (\Throwable $e) {
            $transport = false;
        }

        $this->assertSame($acceptable, $gate, "Gerbang webhook tidak sependapat dengan tabel tentang «{$url}».");
        $this->assertSame($acceptable, $push, "Gerbang push tidak sependapat dengan gerbang webhook tentang «{$url}».");
        $this->assertSame(
            $gate,
            $transport,
            "Gerbang dan transport berselisih tentang «{$url}». Sebuah URL yang diterima layar tetapi ditolak "
            .'transport tersimpan sebagai "berhasil" lalu gagal pada SETIAP pengiriman, dengan kalimat pustaka '
            .'berbahasa Inggris di kolom Galat — dan sebuah URL yang ditolak layar tetapi diterima transport '
            .'berarti gerbangnya menolak sesuatu yang sebenarnya bisa dikirimi.',
        );
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function hostsTheGateAndTheTransportMustAgreeOn(): array
    {
        return [
            'nama dengan titik ekor adalah FQDN absolut' => ['https://contoh.co.id./nusantara/webhook', true],
            'nama biasa' => ['https://contoh.co.id/nusantara/webhook', true],
            'alamat publik' => ['https://203.0.113.10/nusantara/webhook', true],
            'alamat publik dengan titik ekor' => ['https://203.0.113.10./nusantara/webhook', false],
            'alamat publik lain dengan titik ekor' => ['https://8.8.8.8./x', false],
            'bentuk desimal panjang dengan titik ekor' => ['https://3405803786./x', false],
            'loopback dengan titik ekor' => ['https://127.0.0.1./x', false],
            // Gerbang dan transport kini sependapat tentang kedua bentuk
            // yang §11.2 catat sebagai belum ditutup SAMPAI perbaikan ini.
            // Sebelumnya baris-baris di bawah merah pada kolom GERBANG, bukan
            // pada kolom transport: Guzzle 7.15.2 sudah menolak keduanya,
            // gerbangnya belum.
            'host ber-escape persen' => ['https://127.0.0.%31/x', false],
            'host ber-escape persen seluruhnya' => ['https://%31%32%37.0.0.1/x', false],
            'host non-ASCII' => ['https://ерп.contoh.co.id/x', false],
            // Dan bentuk A-label-nya diterima ketiganya — kalau tidak, aturan
            // di atas menolak setiap nama yang bukan bahasa Inggris.
            'nama internasional bentuk A-label' => ['https://xn--e1auc.contoh.co.id/x', true],
        ];
    }
}
