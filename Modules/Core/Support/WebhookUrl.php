<?php

namespace Modules\Core\Support;

use Illuminate\Support\Str;
use LogicException;

/**
 * URL MILIK ORANG LAIN ADALAH PERMUKAAN SSRF (P-3d, perangkap E).
 *
 * Sebuah aplikasi yang mengirim POST BERTANDA TANGAN ke alamat apa pun yang
 * diketik pemakainya adalah proxy permintaan internal: `http://169.254.169.254/`
 * memulangkan kredensial mesin di sebagian besar penyedia awan, `http://
 * 127.0.0.1:9200/` adalah Elasticsearch tetangganya, dan `http://10.0.0.5/`
 * adalah apa pun yang kebetulan ada di jaringan pribadi server. Penerimanya
 * bahkan tidak perlu bisa membaca jawabannya — sebuah POST sudah cukup untuk
 * banyak hal.
 *
 * SIKAP YANG DIAMBIL, dan dituliskan di `docs/KEPUTUSAN-INTEGRASI.md` §11:
 *
 *  1. **https WAJIB** (roadmap). `http://` ditolak tanpa pengecualian — juga
 *     untuk alamat yang "kan cuma di dalam jaringan sendiri".
 *  2. **Alamat internal ditolak**: loopback (127.0.0.0/8, ::1), privat
 *     (10/8, 172.16/12, 192.168/16, fc00::/7), link-local (169.254/16 —
 *     termasuk metadata awan — dan fe80::/10), CGNAT (100.64/10), 0.0.0.0/8,
 *     dan nama yang berakhiran `.local`, `.internal`, `.localhost` atau
 *     `localhost` telanjang.
 *
 *     **DAN BENTUK SAMARANNYA** (V-webhook-2). Sebuah alamat ditulis dengan
 *     lebih dari satu cara, dan penilaian yang membaca bentuknya alih-alih
 *     alamatnya menolak `https://127.0.0.1/` sambil meloloskan
 *     `https://[::ffff:127.0.0.1]/`, `https://2130706433/`,
 *     `https://0177.0.0.1/` dan `https://127.1/` — yang semuanya mendarat di
 *     soket yang SAMA. Maka setiap alamat DINORMALKAN dulu (`normalize()`:
 *     ::ffff:a.b.c.d, ::a.b.c.d dan NAT64 64:ff9b::a.b.c.d diturunkan ke IPv4)
 *     dan setiap host yang bukan nama sungguhan diterjemahkan lebih dulu
 *     (`numericIpv4()`: bentuk desimal, oktal, heksa dan pendek ala
 *     `inet_aton`), lalu yang dinilai adalah hasilnya. Titik ekor dibuang
 *     lebih dulu oleh `canonicalHost()`, yang menjelaskan sebabnya.
 *  3. **Diperiksa DUA KALI: saat MENYIMPAN dan saat MENGIRIM.** DNS bisa
 *     berubah di antara keduanya — sebuah nama yang hari ini menunjuk ke
 *     alamat publik bisa besok menunjuk ke 127.0.0.1, dan itu bukan serangan
 *     teoretis melainkan teknik dengan nama sendiri (DNS rebinding).
 *  4. **Redirect TIDAK DIIKUTI.** Sebuah penerima yang menjawab
 *     `302 Location: http://169.254.169.254/` memindahkan permintaan
 *     bertanda tangan kita ke sana tanpa satu pun pemeriksaan di atas
 *     berlaku lagi. Pengiriman yang dijawab 3xx dicatat GAGAL dengan
 *     kalimatnya.
 *  5. **Waktu tunggu terbatas** (`TIMEOUT`, `CONNECT_TIMEOUT`): sebuah
 *     penerima yang menggantung tidak boleh menahan pekerja antrean.
 *
 * PENYELESAI NAMA ADALAH SEAM. `resolverUsing()` menukarnya di dalam uji,
 * karena uji paket ini tidak boleh menyentuh jaringan sama sekali — bukan
 * HTTP, dan bukan juga DNS.
 */
final class WebhookUrl
{
    public const TIMEOUT = 10;

    public const CONNECT_TIMEOUT = 5;

    /** Akhiran nama yang tidak pernah keluar dari jaringan sendiri. */
    public const PRIVATE_SUFFIXES = ['.local', '.internal', '.localhost', '.home.arpa'];

    /*
     * TIGA PENOLONG DI BAWAH INI PUBLIK KARENA ADA PEMAKAI KEDUA (P-3e,
     * putaran verifikasi: A-1/B-2). Endpoint langganan web push adalah URL
     * milik orang lain persis seperti URL webhook, dan menulis aturan bentuk
     * samaran untuk KEDUA KALINYA adalah cara memiliki dua daftar yang
     * berbeda enam bulan lagi — yang satu tahu `0177.0.0.1`, yang satu tidak.
     * Maka yang dipakai bersama adalah PENILAIAN alamatnya; yang tidak dipakai
     * bersama adalah KALIMATNYA, karena kalimat yang menyebut "webhook" kepada
     * orang yang sedang mendaftarkan ponselnya adalah kalimat yang salah.
     * Lihat Modules/Core/Support/PushEndpoint.php.
     */

    /** @var null|callable(string): list<string> */
    private static $resolver = null;

    /**
     * @param  null|callable(string): list<string>  $resolver
     */
    public static function resolverUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Bentuk URL-nya sah dan bukan alamat internal — TANPA menyentuh DNS.
     *
     * Dipakai Request (saat menyimpan langganan) supaya pesan 422-nya cepat
     * dan tidak bergantung pada resolver.
     *
     * @throws LogicException kalimat Indonesia yang menyebut apa yang salah
     */
    public static function assertShape(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new LogicException('URL webhook tidak bisa dibaca. Tulis lengkap dengan skema, mis. https://contoh.co.id/nusantara/webhook.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new LogicException(
                'URL webhook harus memakai https. Muatan yang dikirim memuat nomor dan status dokumen, dan tanda tangan '
                .'X-Nusantara-Signature tidak melindungi apa pun bila isinya lewat di jaringan tanpa enkripsi.'
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new LogicException('URL webhook tidak boleh membawa nama pengguna atau kata sandi di dalamnya. Rahasianya adalah tanda tangan, bukan URL-nya.');
        }

        $host = self::canonicalHost($parts['host']);

        // Sebuah host yang TIDAK TERSISA apa-apa sesudah titik ekornya dibuang
        // (`https://./`, `https://.../`) bukan nama dan bukan alamat.
        if ($host === '') {
            throw new LogicException('URL webhook tidak bisa dibaca. Tulis lengkap dengan skema, mis. https://contoh.co.id/nusantara/webhook.');
        }

        if (self::isPrivateName($host)) {
            throw new LogicException("Alamat «{$host}» adalah nama jaringan internal. Webhook hanya dikirim ke alamat yang bisa dijangkau dari luar.");
        }

        // Host berupa alamat IP — termasuk yang tidak TAMPAK seperti alamat IP:
        // diperiksa langsung, tanpa DNS.
        $literal = self::literalAddress($host);

        if ($literal !== null && ! self::isPublicIp($literal)) {
            throw new LogicException(self::internalAddressSentence($host, self::normalize($literal)));
        }
    }

    /**
     * Bentuknya sah DAN setiap alamat yang dituju namanya hari ini publik.
     *
     * Dipanggil LAGI di dalam job, tepat sebelum mengirim: DNS yang berubah di
     * antara menyimpan dan mengirim adalah seluruh gunanya pemeriksaan kedua.
     *
     * @throws LogicException
     */
    public static function assertSafeToSend(string $url): void
    {
        self::assertShape($url);

        $host = self::canonicalHost((string) parse_url($url, PHP_URL_HOST));

        // Sebuah alamat literal sudah dinilai `assertShape()` di atas, dalam
        // bentuk apa pun ia ditulis — dan tidak punya nama untuk ditanyakan
        // kepada DNS.
        if (self::literalAddress($host) !== null) {
            return;
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            throw new LogicException("Alamat «{$host}» tidak bisa diterjemahkan ke satu pun alamat IP saat pengiriman dicoba.");
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                throw new LogicException(self::internalAddressSentence($host, self::normalize($address)));
            }
        }
    }

    /** Nama yang tidak pernah keluar dari jaringan sendiri — `localhost` telanjang atau berakhiran PRIVATE_SUFFIXES. */
    public static function isPrivateName(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === 'localhost' || Str::endsWith($host, self::PRIVATE_SUFFIXES);
    }

    /**
     * Host yang dinilai: huruf kecil, DAN TANPA TITIK EKOR.
     *
     * TITIK EKOR ADALAH BENTUK SAMARAN YANG KESEMBILAN (lihat §2 di atas).
     * `127.0.0.1.` menunjuk ke soket yang persis sama dengan `127.0.0.1`,
     * tetapi dibaca berbeda oleh dua pihak yang berbeda: `filter_var` menolak
     * bentuk bertitik-ekor sebagai IP, dan `numericIpv4()` berhenti di bagian
     * kelima yang kosong — sehingga TANPA baris ini keduanya memulangkan
     * "ini sebuah NAMA", lalu yang dinilai adalah jawaban DNS atas nama
     * `127.0.0.1.` alih-alih alamat 127.0.0.1 itu sendiri. libcurl 8.21.0
     * membuang titik itu sebelum menyambung; kamilah yang membacanya sebagai
     * nama. Hal yang sama menyelamatkan `kasir.local.` dari daftar
     * PRIVATE_SUFFIXES, yang mencocokkan akhiran `.local` dan bukan `.local.`.
     *
     * Yang TIDAK berubah: `contoh.co.id.` — titik ekor pada NAMA sungguhan
     * hanyalah bentuk absolut FQDN, dan ia tetap diterima.
     *
     * Di bawah perbaikan ini gerbangnya berhenti bergantung pada DNS untuk
     * menolak bentuk itu. Guzzle 7.15.2 menutup lubang yang sama dari sisi
     * transport (`HostValidator::assertNotADottedAddress()`, CVE-2026-69246),
     * tetapi gerbang ini menilai alamat dengan penguraiannya SENDIRI dan tidak
     * pernah menumpang normalisasi pustaka HTTP — dua jaring, bukan satu.
     */
    private static function canonicalHost(string $host): string
    {
        return rtrim(strtolower($host), '.');
    }

    /**
     * Alamat IP yang benar-benar dituju host ini tanpa bertanya kepada DNS,
     * atau null bila host-nya sebuah NAMA.
     *
     * Dua bentuk yang tidak dikenali `filter_var` ikut dihitung di sini
     * (V-webhook-2): host di dalam kurung siku (`[::1]`) dan bentuk numerik
     * ala `inet_aton` (`2130706433`, `0177.0.0.1`, `127.1`), yang dipakai
     * pustaka HTTP dan libc persis seperti alamat bertitik empat.
     */
    public static function literalAddress(string $host): ?string
    {
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $literal;
        }

        return self::numericIpv4($literal);
    }

    /**
     * Bentuk `inet_aton` → alamat bertitik empat, atau null bila host-nya
     * bukan angka sama sekali.
     *
     * Satu bagian = 32 bit utuh (`2130706433`), dua bagian = a.bbb, tiga =
     * a.b.cc, empat = biasa; setiap bagian boleh desimal, oktal (`0177`) atau
     * heksa (`0x7f`). Sebuah nama sungguhan tidak pernah lolos: `contoh.co.id`
     * berhenti di bagian pertama yang bukan angka.
     */
    private static function numericIpv4(string $host): ?string
    {
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/^0[xX][0-9a-fA-F]{1,8}$/', $part) === 1) {
                $values[] = (int) hexdec(substr($part, 2));
            } elseif (preg_match('/^0[0-7]{1,11}$/', $part) === 1) {
                $values[] = (int) octdec($part);
            } elseif (preg_match('/^(0|[1-9][0-9]{0,9})$/', $part) === 1) {
                $values[] = (int) $part;
            } else {
                return null;
            }
        }

        $last = array_pop($values);

        if ($last === null || $last < 0 || $last >= 256 ** (5 - $count)) {
            return null;
        }

        $long = $last;

        foreach ($values as $index => $value) {
            if ($value > 255) {
                return null;
            }

            $long += $value * 256 ** (3 - $index);
        }

        return long2ip($long);
    }

    /**
     * Alamat yang sama, ditulis dalam bentuk yang dinilai.
     *
     * IPv6 yang MEMBAWA alamat IPv4 di dalamnya diturunkan ke IPv4 itu:
     * ::ffff:a.b.c.d (bertopeng, RFC 4291), ::a.b.c.d (kompatibel, usang tapi
     * masih dirutekan tumpukan sistem) dan 64:ff9b::a.b.c.d (NAT64, RFC 6052).
     * Yang bukan salah satunya dipulangkan apa adanya.
     */
    public static function normalize(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }

        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        $prefix = substr($packed, 0, 12);
        $embedded = substr($packed, 12, 4);

        $mapped = str_repeat("\0", 10)."\xff\xff";
        $compatible = str_repeat("\0", 12);
        $nat64 = "\x00\x64\xff\x9b".str_repeat("\0", 8);

        if ($prefix === $mapped || $prefix === $compatible || $prefix === $nat64) {
            return (string) inet_ntop($embedded);
        }

        return $address;
    }

    /**
     * Alamat ini publik?
     *
     * `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` milik PHP menutup loopback,
     * privat RFC1918, link-local dan rentang yang dicadangkan sekaligus —
     * termasuk 169.254.169.254 dan ::1. Yang TIDAK ditutupnya ada dua: CGNAT
     * 100.64/10, yang di banyak jaringan operator adalah "di dalam", dan
     * ::ffff:0:0/96 — alamat IPv4 yang ditulis sebagai IPv6. Keduanya
     * diperiksa sendiri.
     */
    public static function isPublicIp(string $address): bool
    {
        // DINORMALKAN DULU (V-webhook-2). `::ffff:127.0.0.1` memulangkan true
        // dari filter di bawah — PHP tidak menganggap ::ffff:0:0/96 sebagai
        // rentang yang dicadangkan — dan POST bertanda tangannya benar-benar
        // mendarat di loopback.
        $address = self::normalize($address);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            // 100.64.0.0/10 — ruang alamat bersama operator (RFC 6598).
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values(array_filter((self::$resolver)($host), 'is_string'));
        }

        $v4 = gethostbynamel($host);
        $v6 = @dns_get_record($host, DNS_AAAA);

        $addresses = is_array($v4) ? $v4 : [];

        foreach (is_array($v6) ? $v6 : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function internalAddressSentence(string $host, string $address): string
    {
        return "Alamat «{$host}» menunjuk ke {$address}, yang ada di dalam jaringan server ini (loopback, jaringan privat, "
            .'link-local, atau rentang yang dicadangkan). Webhook tidak dikirim ke sana: sebuah POST bertanda tangan ke '
            .'alamat internal menjadikan aplikasi ini perantara permintaan ke dalam jaringannya sendiri.';
    }
}
