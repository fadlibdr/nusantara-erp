<?php

namespace Modules\Core\Support;

use LogicException;
use RuntimeException;

/**
 * ENDPOINT LANGGANAN PUSH ADALAH URL MILIK ORANG LAIN (P-3e, putaran
 * verifikasi: A-1/B-2).
 *
 * Paket ini dikirim dengan satu-satunya pemeriksaan `url` +
 * `starts_with:https://`. Artinya setiap pengguna yang bisa masuk — rute
 * perangkat memang tidak bergerbang, dan tidak ada izin yang bisa
 * menolongnya — dapat mendaftarkan `https://169.254.169.254/…`,
 * `https://127.0.0.1:9200/_search` atau `https://10.0.0.5/` sebagai
 * "perangkat"-nya, dan sejak itu SETIAP pemberitahuan untuknya membuat pekerja
 * antrean mem-POST ke alamat itu dari dalam jaringan server. Diukur 13 Sep
 * 2026 di pohon ini: tujuh bentuk alamat internal dijawab HTTP 200 dan
 * disimpan, dan sebuah endpoint loopback benar-benar menerima TLS ClientHello
 * dari pekerja.
 *
 * Aturannya TIDAK ditulis ulang di sini. Rumah ini sudah memutuskannya di
 * P-3d untuk webhook keluar (KEPUTUSAN-INTEGRASI §11, Modules/Core/Support/
 * WebhookUrl.php): https wajib, loopback/privat/link-local/CGNAT/nama
 * internal ditolak, bentuk samarannya (`[::ffff:127.0.0.1]`, `2130706433`,
 * `0177.0.0.1`, `127.1`) diterjemahkan lebih dulu, dan alamatnya dinilai DUA
 * KALI — saat menyimpan dan lagi saat mengirim, karena DNS bisa berubah di
 * antara keduanya. Kelas ini memakai PENILAIAN itu apa adanya lewat penolong
 * publik WebhookUrl dan hanya mengganti KALIMATNYA: orang yang sedang
 * mendaftarkan ponselnya tidak boleh dijawab kalimat tentang webhook.
 *
 * YANG SENGAJA TIDAK DILAKUKAN: daftar-izin host layanan push yang dikenal
 * (fcm.googleapis.com, *.push.services.mozilla.com, web.push.apple.com, …).
 * Ia terdengar lebih ketat dan justru lebih rapuh: daftar itu harus benar
 * untuk SETIAP peramban di dunia, termasuk yang belum ada, dan sebuah layanan
 * push yang sah tetapi tidak terdaftar gagal dengan kalimat yang menyalahkan
 * orangnya. Yang menjadi ancaman di sini adalah alamat DI DALAM jaringan
 * server, dan itulah yang ditolak — sama seperti webhook.
 */
final class PushEndpoint
{
    /**
     * Waktu tunggu PENYAMBUNGAN, terpisah dari waktu tunggu jawaban
     * (WebPushSetup::timeoutSeconds(), bawaan 15 detik).
     *
     * Sebuah alamat yang tidak pernah menjawab SYN menahan pekerja antrean
     * selama waktu tunggu penuh — diukur 15,002 detik untuk satu endpoint yang
     * menggantung — dan pekerja yang sama melayani e-mail dan WhatsApp.
     */
    public const CONNECT_TIMEOUT = 5;

    /**
     * Bentuk endpoint-nya sah dan bukan alamat internal — TANPA menyentuh DNS.
     *
     * Dipakai saat MENYIMPAN (pendaftaran perangkat dan rotasi), supaya
     * kalimat 422-nya cepat dan tidak bergantung pada resolver.
     *
     * @throws LogicException kalimat Indonesia yang menyebut apa yang salah
     */
    public static function assertShape(string $endpoint): void
    {
        $parts = parse_url(trim($endpoint));

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new LogicException('Endpoint langganan push tidak bisa dibaca sebagai alamat.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new LogicException('Endpoint langganan push harus https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new LogicException('Endpoint langganan push tidak boleh membawa nama pengguna atau kata sandi di dalamnya.');
        }

        // Kanonikalisasi yang SAMA dengan gerbang webhook — bukan strtolower()
        // kedua yang mirip. Sebuah titik ekor (`127.0.0.1.`) menunjuk ke soket
        // yang persis sama tetapi dibaca sebagai NAMA oleh filter_var dan oleh
        // numericIpv4(), jadi tanpa baris ini enam bentuk alamat internal lolos
        // di sini sementara WebhookUrl menolaknya (diukur pada commit gabungan
        // yang menambal WebhookUrl: 127.0.0.1. / 169.254.169.254. /
        // kasir.local. / 10.0.0.5. / localhost. / 2130706433.).
        $host = WebhookUrl::canonicalHost($parts['host']);

        // Host yang tidak menyisakan apa pun sesudah titiknya dibuang
        // (`https://./`) bukan nama dan bukan alamat.
        if ($host === '') {
            throw new LogicException('Endpoint langganan push tidak bisa dibaca sebagai alamat.');
        }

        if (WebhookUrl::isPrivateName($host)) {
            throw new LogicException(self::sentence($host, null));
        }

        $literal = WebhookUrl::literalAddress($host);

        if ($literal !== null && ! WebhookUrl::isPublicIp($literal)) {
            throw new LogicException(self::sentence($host, WebhookUrl::normalize($literal)));
        }
    }

    /**
     * Bentuknya sah DAN setiap alamat yang dituju namanya hari ini publik.
     *
     * Dipanggil LAGI di kanal, tepat sebelum mengirim. Sebuah baris kotak
     * keluar bisa menunggu berjam-jam di jam tenang, dan sebuah nama yang
     * kemarin menunjuk alamat publik bisa hari ini menunjuk 127.0.0.1 — itu
     * bukan serangan teoretis melainkan teknik dengan nama sendiri (DNS
     * rebinding). Penyelesai namanya adalah seam yang sama
     * (WebhookUrl::resolverUsing()), jadi uji kanal ini tidak menyentuh DNS.
     *
     * DUA KEGAGALAN, DUA KELAS — dan bedanya bukan kosmetik. "Alamatnya di
     * dalam jaringan server" TIDAK akan berubah bila diulang: ia permanen, dan
     * mengulanginya lima kali hanya mengulang permintaan yang justru dilarang.
     * "Namanya tidak bisa diterjemahkan" bisa berubah semenit lagi — resolver
     * yang sedang bermasalah bukan alasan menyatakan sebuah pemberitahuan
     * gagal selamanya. Pemanggil membedakannya lewat kelas pengecualian.
     *
     * @throws LogicException alamatnya internal atau bentuknya salah — PERMANEN
     * @throws RuntimeException namanya tidak bisa diterjemahkan sekarang — SEMENTARA
     */
    public static function assertSafeToSend(string $endpoint): void
    {
        self::assertShape($endpoint);

        $host = WebhookUrl::canonicalHost((string) parse_url(trim($endpoint), PHP_URL_HOST));

        if (WebhookUrl::literalAddress($host) !== null) {
            return;
        }

        $addresses = WebhookUrl::resolve($host);

        if ($addresses === []) {
            throw new RuntimeException(
                "Alamat layanan push «{$host}» tidak bisa diterjemahkan ke satu pun alamat IP saat pengiriman dicoba.",
            );
        }

        foreach ($addresses as $address) {
            if (! WebhookUrl::isPublicIp($address)) {
                throw new LogicException(self::sentence($host, WebhookUrl::normalize($address)));
            }
        }
    }

    /** Sama bentuknya dengan kalimat webhook, tetapi menyebut perangkat — bukan webhook. */
    private static function sentence(string $host, ?string $address): string
    {
        $where = $address === null ? "«{$host}» adalah nama jaringan internal" : "«{$host}» menunjuk ke {$address}, yang ada di dalam jaringan server ini";

        return "Alamat {$where}. Sebuah layanan push berada di luar jaringan ini — alamat di dalamnya bukan perangkat "
            .'siapa pun, dan sebuah pemberitahuan yang di-POST ke sana menjadikan aplikasi ini perantara permintaan ke '
            .'dalam jaringannya sendiri.';
    }
}
