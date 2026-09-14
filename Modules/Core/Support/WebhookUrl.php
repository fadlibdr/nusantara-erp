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

        /*
         * BYTE KENDALI DI OTORITAS — DICUCI `parse_url()` MENJADI GARIS BAWAH.
         *
         * `https://contoh\x01.co.id/masuk` dipulangkan `parse_url()` sebagai
         * host `contoh_.co.id`: byte kendalinya DIGANTI, bukan ditolak.
         * Akibatnya aturan ASCII-tercetak di bawah tidak punya apa pun untuk
         * ditolak — `_` tercetak — dan gerbang ini berkata "tersimpan" atas
         * URL yang Guzzle tolak SELAMANYA (`MalformedUriException`; diukur
         * untuk \x00, \x01, \x09, \x0a, \x0d dan \x7f). Itu bukan lubang
         * keamanan melainkan bentuk yang sama dengan titik-ekor-pada-alamat:
         * layar berjanji, lalu setiap pengiriman mati dengan kalimat Inggris
         * di kolom Galat, lima percobaan penuh per pengiriman.
         *
         * Yang dibaca di sini adalah OTORITAS MENTAH dan bukan `$host`, sebab
         * `$host` justru sudah tidak memuat byte itu lagi. `rawAuthority()`
         * menjelaskan mengapa membaca URL mentah UNTUK MENOLAK bukan pengurai
         * kedua — dan mengapa ia berhenti di `/?#`: byte kendali di path,
         * query dan fragmen DITERIMA transport, jadi menolaknya berarti
         * menolak sesuatu yang sebenarnya bisa dikirimi.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', self::rawAuthority($url)) === 1) {
            throw new LogicException(
                "Alamat «{$host}» memuat byte kendali (tab, ganti baris, NUL atau sejenisnya) pada bagian alamatnya "
                .'— biasanya ikut terbawa saat menyalin-tempel. Byte itu tidak tersimpan apa adanya: ia berubah '
                .'menjadi garis bawah, sehingga alamat yang tertulis di layar bukan alamat yang diketik, dan pustaka '
                .'HTTP menolak alamat seperti itu pada setiap pengiriman. Ketiklah alamatnya sekali lagi dengan tangan.'
            );
        }

        /*
         * HOST YANG KAMI DAN TRANSPORT BACA BERBEDA — DUA JARING, BUKAN SATU.
         *
         * `https://127.0.0.%31/masuk` lolos seluruh penilaian di bawah:
         * `numericIpv4()` berhenti di bagian yang bukan angka, `filter_var`
         * menolaknya sebagai IP, jadi `literalAddress()` memulangkan null dan
         * host-nya dinilai sebagai NAMA. Yang menolaknya sesudah itu hanyalah
         * resolver yang kebetulan gagal menjawab nama itu — diukur: dengan
         * resolver seam yang menjawab alamat publik, gerbang KIRIM pun
         * menerimanya. libcurl memecahkan `%31` menjadi `1` dan menyambung ke
         * 127.0.0.1, dan itu persis CVE-2026-69246.
         *
         * Hal yang sama berlaku untuk host di luar ASCII (`ерп.contoh.co.id`):
         * apa yang benar-benar disambungi bergantung pada pihak mana yang
         * menerjemahkannya ke A-label, dan bukan pada apa yang tertulis.
         *
         * Guzzle 7.15.2 menolak KEDUANYA di transport
         * (`HostValidator::assertRequestHost()`), jadi hari ini lubang itu
         * tertutup — OLEH PUSTAKA, bukan oleh gerbang ini. Docblock kelas ini
         * berkata gerbangnya menilai alamat dengan penguraiannya SENDIRI dan
         * tidak pernah menumpang normalisasi pustaka HTTP; untuk dua bentuk
         * ini kalimat itu belum benar sampai baris-baris di bawah ada.
         *
         * DUA SEBAB, DUA KALIMAT. Orang yang menempelkan escape persen salah
         * ketik; orang yang menulis nama internasional TIDAK salah — ia hanya
         * perlu tahu bentuk A-label-nya, dan sebuah kalimat yang menyuruhnya
         * "jangan pakai persen" tidak menolongnya sama sekali.
         */
        if (str_contains($host, '%')) {
            throw new LogicException(
                "Alamat «{$host}» memuat escape persen (%) pada bagian host-nya. Sebuah host ditulis apa adanya, "
                .'tanpa escape: pustaka HTTP memecahkan %31 menjadi 1 sebelum menyambung, sehingga alamat yang '
                .'benar-benar dituju berbeda dari alamat yang tertulis di layar ini.'
            );
        }

        if (preg_match('/\A[\x21-\x7E]*\z/D', $host) !== 1) {
            throw new LogicException(
                "Alamat «{$host}» memuat huruf di luar ASCII pada bagian host-nya. Sebuah nama internasional "
                .'punya bentuk A-label yang dimulai dengan «xn--» (mis. «ерп.contoh.co.id» ditulis '
                .'«xn--e1auc.contoh.co.id»); tulislah bentuk itu, supaya alamat yang dituju tidak bergantung pada '
                .'pihak mana yang menerjemahkannya.'
            );
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

        /*
         * TITIK EKOR SAH PADA NAMA, TIDAK PADA ALAMAT — dan sejak Guzzle
         * 7.15.2 transport menolaknya SELAMANYA.
         *
         * `contoh.co.id.` adalah bentuk FQDN absolut dan tetap diterima
         * (canonicalHost hanya membuang titiknya). `203.0.113.10.` bukan nama
         * sama sekali: ia alamat IP publik dengan titik yang tidak berarti
         * apa-apa, dan `HostValidator::assertNotADottedAddress()` milik Guzzle
         * — tambalan CVE-2026-69246 yang masuk bersama kenaikan paket ini —
         * menolak setiap host yang berbentuk satu sampai empat bagian
         * desimal/oktal/heksa diikuti titik.
         *
         * Tanpa baris ini bentuk itu DITERIMA di layar ("tersimpan"), lalu
         * setiap pengiriman mati di transport dengan kalimat INGGRIS dari
         * pustaka di kolom `error`, lima percobaan penuh (60/300/900/3600
         * detik) per pengiriman. Itu bukan lubang keamanan — ia gagal-tertutup
         * — melainkan layar yang berjanji dan transport yang menolak, dan
         * inkonsistensi itu HANYA ada sesudah gabungan ini. Yang benar adalah
         * menolaknya di sini, saat menyimpan, dalam Bahasa Indonesia, dengan
         * menyebut cara menulisnya.
         */
        if ($literal !== null && str_ends_with(rtrim($parts['host']), '.')) {
            throw new LogicException(
                "Alamat «{$parts['host']}» ditulis sebagai alamat IP dengan titik di ujungnya. Titik di ujung hanya "
                ."berarti pada NAMA (bentuk FQDN absolut), tidak pada alamat — tulis «{$host}» tanpa titik."
            );
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
     *
     * PUBLIK, dan itu bukan kelonggaran: `PushEndpoint` (P-3e) menilai host
     * dengan penolong kelas ini tetapi MENGURAI URL-nya sendiri, sehingga
     * tambalan ini tidak sampai kepadanya selama ia masih memanggil
     * `strtolower()` polos. Diukur pada commit gabungan ini: enam bentuk
     * alamat internal bertitik-ekor DITOLAK di sini dan LOLOS di sana. Satu
     * gerbang berarti satu kanonikalisasi; dua kanonikalisasi yang mirip
     * adalah cara lubang yang sama ditutup sekali dan dibiarkan sekali.
     */
    public static function canonicalHost(string $host): string
    {
        return rtrim(strtolower($host), '.');
    }

    /**
     * IRISAN OTORITAS MENTAH: sesudah `://`, sampai karakter pertama dari
     * `/?#`. HANYA untuk MENOLAK — tidak pernah untuk menentukan tujuan.
     *
     * INI BUKAN PENGURAI KEDUA, DAN KEBERATANNYA DIJAWAB DI SINI, BUKAN
     * DILEWATI. KEPUTUSAN-INTEGRASI §11.2 MENCATAT keberatan itu pada 13 Sep
     * 2026 sebagai sebab butir ini tidak ditutup — ia mencatat, bukan
     * melarang; tidak ada larangan bernomor tentangnya di docblock kelas ini
     * maupun di §11.2, dan menulis "melarang" akan mengirim pembaca berikutnya
     * mencari aturan yang tidak pernah ada (putaran penutup, V-6). Keberatan
     * itu serius: dua
     * pengurai yang BERSELISIH tentang ke mana sebuah permintaan pergi adalah
     * kelas kerentanan tersendiri — CVE-2026-69246 yang baru saja ditambal
     * Guzzle persis bentuk itu.
     *
     * YANG MEMBEDAKAN IRISAN INI DARI SEBUAH PENGURAI ADALAH WEWENANGNYA,
     * bukan ukurannya. Sebuah pengurai MEMULANGKAN TUJUAN: host yang
     * dipulangkannya dinilai publik atau tidak, ditanyakan kepada DNS, dan
     * pada akhirnya disambungi. Irisan ini tidak memulangkan satu pun dari itu
     * dan tidak pernah dipanggil untuk itu. Ia dipakai untuk menjawab SATU
     * pertanyaan ya/tidak — "ada byte kendali di wilayah ini?" — dan
     * satu-satunya hal yang boleh terjadi sesudah jawabannya adalah
     * PENOLAKAN. Host yang dinilai, yang ditanyakan ke DNS dan yang
     * disambungi tetap milik `parse_url()`, sebelum dan sesudah baris ini.
     * Untuk berselisih tentang tujuan, sebuah pengurai harus punya tujuan.
     *
     * YANG BISA SALAH karenanya hanya SATU hal: menolak URL yang sebenarnya
     * sah. Itu diukur, bukan diandaikan — 34 bentuk URL dijalankan terhadap
     * irisan ini SEBELUM satu baris produksi ditulis (14 Sep 2026), dan
     * tabelnya hidup di dalam uji sebagai `WebhookGuardTest::authoritySlices()`:
     *
     *  - Pada SETIAP bentuk yang `parse_url()` bisa baca, wilayah yang diiris
     *    memuat host yang `parse_url()` pulangkan — userinfo yang memuat "/"
     *    ter-encode maupun mentah, "@" ganda, IPv6 berkurung dengan zona
     *    `%25eth0`, port kosong, tanpa path sama sekali, "?" atau "#" sebelum
     *    "/", skema huruf besar, dan spasi di awal/akhir URL. Satu-satunya
     *    wilayah yang BERBEDA muncul pada URL yang `parse_url()` sendiri baca
     *    rusak (`https://[::1/x` → host `[:`), dan di sana irisannya lebih
     *    LEBAR, bukan lebih sempit.
     *  - Irisan ini sengaja lebih lebar daripada host: ia memuat userinfo dan
     *    port. Itu tidak mengetatkan apa pun yang belum ketat — URL
     *    ber-userinfo sudah ditolak seluruhnya di `assertShape()`, dan diukur:
     *    `parse_url()` mengenali userinfo pada SETIAP bentuk
     *    byte-kendali-di-userinfo yang dicoba (NUL, TAB, LF, DEL, dan
     *    penyamaran `contoh.co.id\x01@jahat.co.id`), sehingga kalimat yang
     *    didapat orangnya tetap kalimat userinfo.
     *  - Berhenti di `/?#` juga bukan kerapian. Byte kendali di PATH, QUERY
     *    dan FRAGMEN DITERIMA transport (diukur), jadi menolaknya berarti
     *    gerbang yang menolak sesuatu yang sebenarnya bisa dikirimi.
     *    Pasangan yang paling terang: `https://contoh.co.id\n` ditolak Guzzle
     *    sedangkan `https://contoh.co.id/x\n` diterimanya — byte yang sama,
     *    dan yang membedakan hanyalah ia jatuh di otoritas atau di path.
     *    Irisan ini menarik garis di tempat yang sama.
     *
     * PUBLIK karena `PushEndpoint` memakainya: ia mengurai URL-nya sendiri dan
     * men-`trim()` lebih dulu, jadi ia memanggil irisan ini atas string yang
     * IA urai. Satu gerbang berarti satu irisan.
     */
    public static function rawAuthority(string $url): string
    {
        $scheme = strpos($url, '://');

        // Tanpa "://" tidak ada otoritas untuk dipisahkan — dan URL seperti
        // itu tidak pernah sampai ke sini, sebab `parse_url()` tidak
        // memulangkan host untuknya. Bila ragu, yang diperiksa adalah SELURUH
        // sisanya: sebuah detektor yang hanya menolak boleh terlalu lebar.
        $rest = $scheme === false ? $url : substr($url, $scheme + 3);

        return substr($rest, 0, strcspn($rest, '/?#'));
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

        /*
         * 6to4 (RFC 3056): `2002:<ipv4 dalam heksa>::/48` — dan ALAMAT IPv4-nya
         * ada di byte 2..5, bukan di empat byte terakhir seperti ketiga bentuk
         * di atas (putaran penutup, V-1).
         *
         * Tanpa baris ini `2002:7f00:1::1` dinilai PUBLIK, padahal ia membungkus
         * 127.0.0.1 — dan `2002:a00:5::1` membungkus 10.0.0.5. Docblock §2 kelas
         * ini menjanjikan bahwa "setiap bentuk yang membungkus IPv4 diturunkan
         * lebih dulu"; janji itu tidak benar untuk 6to4 sampai sekarang.
         *
         * DITURUNKAN, BUKAN DITOLAK SEBAGAI RENTANG: sebuah alamat 6to4 yang
         * membungkus IPv4 PUBLIK memang publik, dan menolak `2002::/16` utuh
         * akan menolak alamat yang sebenarnya bisa dikirimi.
         */
        if (substr($packed, 0, 2) === "\x20\x02") {
            return (string) inet_ntop(substr($packed, 2, 4));
        }

        return $address;
    }

    /**
     * Rentang yang DILEWATKAN `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE`, dan
     * sebabnya masing-masing.
     *
     * SATU DAFTAR, BUKAN SATU PENGECUALIAN DITAMBAH TIGA. Sebelum ini CGNAT
     * berdiri sendiri sebagai satu `if` di dalam `isPublicIp()`; menambahkan
     * tiga `if` lagi di sebelahnya adalah cara sebuah rentang yang kelima
     * ditulis di tempat yang salah enam bulan lagi. Masknya DITURUNKAN dari
     * panjang prefiks dan tidak ditulis tangan — `0xFFE00000` dan
     * `0xFFFE0000` berbeda satu huruf dan berbeda 128 kali lipat besarnya.
     *
     * SATU DAFTAR untuk KEDUA keluarga, dan itu juga bukan kerapian belaka:
     * `ff00::/8` masuk ke sini sebagai baris kelima, bukan sebagai daftar
     * IPv6 kedua di sebelahnya, karena dua daftar yang mirip adalah cara
     * sebuah rentang ditutup sekali dan dibiarkan sekali. Perbandingannya
     * dilakukan atas BYTE alamat (`inRefusedBlock()`), sehingga panjang
     * prefiks berlaku sama untuk 4 byte maupun 16 byte dan sebuah blok IPv4
     * tidak pernah bisa cocok dengan alamat IPv6.
     *
     *  - `100.64.0.0/10` — ruang alamat bersama operator (RFC 6598). Di banyak
     *    jaringan operator ini adalah "di dalam".
     *  - `192.0.0.0/24` — IETF Protocol Assignments (RFC 6890), termasuk
     *    alamat DNS64 `192.0.0.170`/`192.0.0.171`. Dirutekan di dalam
     *    sebagian jaringan dan menunjuk layanan sungguhan di sana.
     *  - `198.18.0.0/15` — benchmarking RFC 2544. Lazim dipakai DI DALAM
     *    jaringan lab dan pada appliance yang memakainya sebagai alamat
     *    manajemen.
     *  - `224.0.0.0/4` — multicast (RFC 5771). Di atas TCP ia tidak pernah
     *    membentuk koneksi (diukur: cURL galat 7 dalam 0,00 detik), jadi yang
     *    ditutup baris ini bukan kebocoran melainkan sebuah baris kiriman yang
     *    berjanji lalu gagal — dan sebuah gerbang yang menjawab "alamat ini
     *    tidak dikirimi" lebih jujur daripada lima percobaan yang mati diam.
     *  - `ff00::/8` — multicast IPv6 (RFC 4291 §2.7), KEMBARAN dari
     *    `224.0.0.0/4` tepat di atas. Sebabnya sama dan bobotnya sama rendah:
     *    multicast di atas TCP tidak pernah membentuk koneksi. Yang ditutup
     *    baris ini karena itu bukan lubang yang bisa dieksploitasi melainkan
     *    INKONSISTENSI — gerbang yang menolak `224.0.0.1` sambil memulangkan
     *    true untuk `ff02::1` menilai alamat yang sama dengan dua jawaban yang
     *    berbeda, hanya karena yang satu ditulis dalam empat angka desimal.
     *    Diukur 14 Sep 2026 sebelum baris ini ada: `ff02::1`, `ff00::1` dan
     *    `ff05::1:3` ketiganya dinilai PUBLIK. Rentang IPv6 lain SENGAJA tidak
     *    ikut karena tidak perlu: `fe80::/10` dan `2001:db8::/32` sudah
     *    ditutup `FILTER_FLAG_NO_RES_RANGE` (diukur pada hari yang sama), dan
     *    sebuah baris yang tidak bisa memerah bukan pagar melainkan hiasan.
     *
     * YANG SENGAJA TIDAK IKUT: blok dokumentasi TEST-NET `192.0.2.0/24`,
     * `198.51.100.0/24` dan `203.0.113.0/24` (RFC 5737). Ketiganya memang
     * tidak dirutekan di internet, tetapi `203.0.113.10` adalah fikstur
     * "alamat publik" baku rumah ini di 30 tempat pada 4 berkas uji (18
     * sebelum paku-paku di bawah ditambahkan). Menolak blok itu di sini
     * diukur, bukan diduga: mutasi yang menambahkan `203.0.113.0/24` ke
     * daftar memerahkan 15 uji. Memindahkan fiksturnya ke alamat yang
     * benar-benar publik adalah pekerjaan tersendiri yang harus DIPUTUSKAN,
     * bukan terjadi sebagai efek samping baris ini.
     *
     * @var list<string>
     */
    private const REFUSED_BLOCKS = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
        '224.0.0.0/4',
        'ff00::/8',
        'fec0::/10',
        '2001:2::/48',
        '2001::/32',
        '100::/64',
        '64:ff9b:1::/48',
    ];

    /**
     * Alamat ini publik?
     *
     * `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` milik PHP menutup loopback,
     * privat RFC1918, link-local dan rentang yang dicadangkan sekaligus —
     * termasuk 169.254.169.254, ::1, fe80::/10 dan 2001:db8::/32. Yang TIDAK
     * ditutupnya ada dua: REFUSED_BLOCKS di atas, dan ::ffff:0:0/96 — alamat
     * IPv4 yang ditulis sebagai IPv6. Keduanya diperiksa sendiri.
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

        foreach (self::REFUSED_BLOCKS as $block) {
            if (self::inRefusedBlock($address, $block)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Alamat ini ada di dalam blok CIDR itu?
     *
     * DIBANDINGKAN SEBAGAI BYTE, BUKAN SEBAGAI BILANGAN. `ip2long()` hanya
     * tahu IPv4, jadi sebuah rentang IPv6 yang dibandingkan dengannya menuntut
     * cabang kedua — dan cabang kedua itulah yang menjadi daftar kedua. Bentuk
     * byte membuat panjang prefiks berarti hal yang sama untuk 4 byte dan 16
     * byte: `/8` adalah satu byte utuh pada keduanya.
     *
     * KELUARGA YANG BERBEDA TIDAK PERNAH COCOK, dan itu dijaga oleh panjang:
     * `inet_pton()` memulangkan 4 byte untuk IPv4 dan 16 byte untuk IPv6,
     * sehingga `224.0.0.0/4` tidak bisa menyentuh alamat IPv6 mana pun dan
     * `ff00::/8` tidak bisa menyentuh alamat IPv4 mana pun — termasuk
     * `255.x.x.x`, yang byte pertamanya kebetulan juga 0xff.
     */
    private static function inRefusedBlock(string $address, string $block): bool
    {
        [$network, $bits] = explode('/', $block);

        $packed = @inet_pton($address);
        $base = @inet_pton($network);

        if ($packed === false || $base === false || strlen($packed) !== strlen($base)) {
            return false;
        }

        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;

        if (strncmp($packed, $base, $whole) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        // Bit yang TERSISA sesudah byte-byte utuh: masknya diturunkan dari
        // panjang prefiks, tidak ditulis tangan.
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($base[$whole]) & $mask);
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
