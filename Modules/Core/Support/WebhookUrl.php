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

        $host = strtolower($parts['host']);

        if ($host === 'localhost' || Str::endsWith($host, self::PRIVATE_SUFFIXES)) {
            throw new LogicException("Alamat «{$host}» adalah nama jaringan internal. Webhook hanya dikirim ke alamat yang bisa dijangkau dari luar.");
        }

        // Host berupa alamat IP: diperiksa langsung, tanpa DNS.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false && ! self::isPublicIp($literal)) {
            throw new LogicException(self::internalAddressSentence($host, $literal));
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

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            throw new LogicException("Alamat «{$host}» tidak bisa diterjemahkan ke satu pun alamat IP saat pengiriman dicoba.");
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                throw new LogicException(self::internalAddressSentence($host, $address));
            }
        }
    }

    /**
     * Alamat ini publik?
     *
     * `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` milik PHP menutup loopback,
     * privat RFC1918, link-local dan rentang yang dicadangkan sekaligus —
     * termasuk 169.254.169.254 dan ::1. Yang TIDAK ditutupnya adalah CGNAT
     * 100.64/10, yang di banyak jaringan operator adalah "di dalam", jadi ia
     * diperiksa sendiri.
     */
    public static function isPublicIp(string $address): bool
    {
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
    private static function resolve(string $host): array
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
