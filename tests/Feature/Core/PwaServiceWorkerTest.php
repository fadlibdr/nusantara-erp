<?php

namespace Tests\Feature\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * `public/app/sw.js` (P1-I) — ATURAN "TIDAK PERNAH DI-CACHE" SEBAGAI UJI.
 *
 * Aturan itu ditulis di kepala sw.js sebagai rujukan, tetapi sebuah komentar
 * tidak pernah menahan siapa pun. Yang berbahaya bukan worker yang rusak —
 * worker yang rusak terlihat seketika — melainkan worker yang bekerja SEDIKIT
 * TERLALU BANYAK: satu baris yang menyimpan jawaban `/api/*` akan menyajikan
 * daftar dokumen milik orang sebelumnya di tablet lapangan yang dipakai
 * bergantian, tanpa satu pun pesan galat, sampai ada yang menyadarinya berminggu
 * kemudian. Tidak ada runtime JS di host ini, jadi yang dipaku adalah SUMBERNYA,
 * dengan komentar dibuang lebih dulu (stripComments) supaya rujukan di kepala
 * berkas — yang memang menyebut `/api/*` — tidak lolos sebagai kode.
 *
 * Delapan hal:
 *  (a) sw.js DIDAFTARKAN dengan path yang benar-benar ada, tanpa opsi scope —
 *      lingkup yang lebih lebar daripada folder skripnya menuntut header
 *      Service-Worker-Allowed dan gagal dengan diam di produksi;
 *  (b) shellRequest() memuat KELIMA syarat daftar izin;
 *  (c) hanya ADA SATU respondWith(), dan ia berada di belakang shellRequest();
 *  (d) hanya ADA SATU cache.put(), dan ia berada di belakang storable();
 *  (e) storable() menuntut 200 + type 'basic' (bukan opaque, bukan 206);
 *  (f) kode (tanpa komentar) tidak pernah menyebut /api, /storage, lampiran atau
 *      unduhan — daftar izin tidak butuh daftar larangan, dan sebuah daftar
 *      larangan yang muncul di sini berarti aturannya sudah berubah bentuk;
 *  (g) SHELL cocok DUA ARAH dengan berkas yang benar-benar ada, dan setiap
 *      entrinya relatif sehingga tidak bisa keluar dari /app/;
 *  (h) nama cache berversi dan activate membuang versi lain.
 */
class PwaServiceWorkerTest extends TestCase
{
    /** Ekstensi yang dimuat peramban saat aplikasi berjalan — definisi "cangkang". */
    private const SHELL_EXTENSIONS = ['html', 'css', 'js', 'svg', 'webmanifest'];

    /* --------------------------------------------------------------- (a) */

    public function test_the_worker_is_registered_at_a_path_that_exists_and_without_a_widened_scope(): void
    {
        $app = (string) file_get_contents(public_path('app/js/app.js'));

        $this->assertSame(
            1,
            preg_match('~navigator\.serviceWorker\.register\(\s*([^)]*?)\s*\)~', $app, $call),
            'app.js tidak lagi mendaftarkan service worker di tempat yang bisa dibaca uji ini.',
        );

        $this->assertSame(
            1,
            preg_match("~^'([^']+)'$~", trim($call[1]), $arg),
            "register() dipanggil dengan argumen selain satu literal path ({$call[1]}): sebuah opsi "
            .'{ scope } yang lebih lebar daripada folder skripnya menuntut header Service-Worker-Allowed '
            .'dan gagal dengan diam di produksi.',
        );

        // Relatif terhadap halaman /app/index.html.
        $this->assertFileExists(
            public_path('app/'.$arg[1]),
            "app.js mendaftarkan '{$arg[1]}', dan tidak ada berkas di public/app/{$arg[1]}: pendaftaran "
            .'gagal diam-diam dan aplikasi kehilangan seluruh lapisan luringnya.',
        );

        // Lingkup sebuah worker = folder skripnya. sw.js di akar /app/ berarti
        // lingkup /app/ — persis scope yang diumumkan manifest.
        $this->assertStringNotContainsString('/', $arg[1], "Worker harus berada di akar /app/ supaya lingkupnya /app/; '{$arg[1]}' bersarang lebih dalam.");
        $manifest = json_decode((string) file_get_contents(public_path('app/manifest.webmanifest')), true);
        $this->assertSame('/app/', $manifest['scope']);
    }

    /* --------------------------------------------------------------- (b) */

    public function test_shell_request_carries_all_five_allowlist_conditions(): void
    {
        $body = $this->functionBody('shellRequest');

        $conditions = [
            "request.method !== 'GET'" => 'metode selain GET bisa masuk cache',
            "request.headers.has('Authorization')" => 'permintaan ber-Authorization bisa masuk cache',
            'url.origin !== self.location.origin' => 'permintaan lintas asal bisa masuk cache',
            'url.pathname.startsWith(SCOPE)' => 'permintaan DI LUAR /app/ — termasuk /api/* — bisa masuk cache',
            'url.pathname === self.location.pathname' => 'worker bisa men-cache dirinya sendiri',
        ];

        foreach ($conditions as $needle => $consequence) {
            $this->assertStringContainsString(
                $needle,
                $body,
                "Syarat daftar izin `{$needle}` hilang dari shellRequest(): {$consequence}.",
            );
        }

        $this->assertSame(
            1,
            preg_match("~const SCOPE = new URL\('\./', self\.location\)\.pathname;~", $this->worker()),
            'SCOPE tidak lagi diturunkan dari lokasi worker: sebuah awalan yang ditulis tangan bisa '
            .'melebar melewati /app/ tanpa ada yang menyadarinya.',
        );
    }

    /* --------------------------------------------------------------- (c) */

    public function test_there_is_exactly_one_respond_with_and_it_sits_behind_the_allowlist(): void
    {
        $code = $this->code();

        $this->assertSame(
            1,
            substr_count($code, 'event.respondWith('),
            'Lebih dari satu respondWith(): setiap tambahan adalah gerbang kedua yang harus mengulang kelima syarat.',
        );
        $this->assertMatchesRegularExpression(
            '~if \(!shellRequest\(event\.request\)\) return;\s*event\.respondWith\(~',
            $code,
            'respondWith() tidak lagi berada tepat di belakang shellRequest(): permintaan yang tidak lolos '
            .'daftar izin harus lewat TANPA disentuh worker.',
        );
    }

    /* --------------------------------------------------------------- (d) */

    public function test_there_is_exactly_one_cache_write_and_it_sits_behind_the_storable_guard(): void
    {
        $code = $this->code();

        $this->assertSame(
            1,
            substr_count($code, 'cache.put('),
            'Lebih dari satu cache.put(): satu-satunya tulisan ke cache di jalur fetch harus tetap satu.',
        );
        $this->assertMatchesRegularExpression(
            '~if \(storable\(response\)\) event\.waitUntil\(cache\.put\(request, response\.clone\(\)\)\);~',
            $code,
            'Tulisan cache tidak lagi berpenjaga storable(): jawaban 206, opaque atau bukan-200 bisa masuk cache.',
        );

        // cache.add() hanya boleh muncul di install, atas daftar SHELL.
        $this->assertSame(
            1,
            substr_count($code, 'cache.add('),
            'cache.add() dipakai di lebih dari satu tempat: satu-satunya sumber isi cache selain jalur fetch adalah SHELL.',
        );
    }

    /* --------------------------------------------------------------- (e) */

    public function test_only_a_plain_same_origin_200_may_be_stored(): void
    {
        $body = $this->functionBody('storable');

        $this->assertStringContainsString('response.status === 200', $body, 'storable() tidak lagi menuntut 200: 206 Range dan 30x bisa masuk cache.');
        $this->assertStringContainsString("response.type === 'basic'", $body, "storable() tidak lagi menuntut type 'basic': jawaban opaque lintas asal bisa masuk cache.");
    }

    /* --------------------------------------------------------------- (f) */

    public function test_the_code_needs_no_blocklist_because_the_allowlist_is_the_rule(): void
    {
        // Daftar SHELL dibuang lebih dulu: ia memang memuat 'js/api.js' dan
        // 'js/views/attachments.js' — nama BERKAS cangkang, bukan awalan URL
        // server. Yang diperiksa di sini adalah logikanya.
        $code = strtolower(preg_replace('~const SHELL = \[.*?\n\];~s', '', $this->code()));

        foreach (['/api', 'storage', 'attachment', 'lampiran', 'download', 'unduh'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                "Kode sw.js menyebut '{$needle}'. Aturannya adalah DAFTAR IZIN satu awalan; sebuah daftar "
                .'larangan yang muncul di sini berarti bentuk aturannya sudah berubah — dan daftar larangan '
                .'membusuk pada endpoint berikutnya yang lupa didaftarkan.',
            );
        }
    }

    /* --------------------------------------------------------------- (g) */

    public function test_the_shell_list_matches_the_files_that_actually_exist(): void
    {
        $listed = $this->shellList();
        $onDisk = $this->shellFilesOnDisk();

        $missing = array_values(array_diff($listed, array_merge(['./'], $onDisk)));
        $this->assertSame(
            [],
            $missing,
            'sw.js memasang berkas yang tidak ada: '.implode(', ', $missing)
            .'. Satu 404 saat install dicatat console.warn dan tidak terlihat siapa pun.',
        );

        $unlisted = array_values(array_diff($onDisk, $listed));
        $this->assertSame(
            [],
            $unlisted,
            'Berkas cangkang yang TIDAK terdaftar di SHELL sw.js: '.implode(', ', $unlisted)
            .'. Aplikasi tetap jalan daring, lalu setengah mati saat luring — tambahkan berkasnya ke SHELL '
            .'dan naikkan SHELL_VERSION (CONVENTIONS § 21).',
        );

        $this->assertContains('./', $listed, "SHELL harus memuat './': navigasi ke /app/ tanpa jaringan tidak punya apa-apa untuk digambar.");
    }

    public function test_no_shell_entry_can_escape_the_worker_scope(): void
    {
        foreach ($this->shellList() as $entry) {
            $this->assertDoesNotMatchRegularExpression(
                '~^(?:/|\.\./|[a-z][a-z0-9+.-]*:|//)~i',
                $entry,
                "Entri SHELL '{$entry}' tidak relatif terhadap /app/. cache.add('/api/…') menyimpan jawaban "
                .'API di cache yang sama — lubang yang dibuat oleh daftar, bukan oleh jalur fetch.',
            );
        }

        $this->assertNotContains('sw.js', $this->shellList(), 'Worker tidak boleh men-cache dirinya sendiri: peramban yang membandingkan byte sw.js harus selalu bertanya ke jaringan.');
    }

    /* --------------------------------------------------------------- (h) */

    public function test_the_cache_is_versioned_and_old_versions_are_deleted_on_activate(): void
    {
        $worker = $this->worker();

        $this->assertSame(1, preg_match("~const SHELL_VERSION = '([^']+)';~", $worker, $version));
        $this->assertMatchesRegularExpression(
            '~const CACHE = `nusantara-shell-v\$\{SHELL_VERSION\}`;~',
            $worker,
            'Nama cache tidak lagi memuat SHELL_VERSION: menaikkan versi berhenti membuang cache lama.',
        );
        $this->assertMatchesRegularExpression(
            "~name\.startsWith\('nusantara-shell-'\) && name !== CACHE~",
            $this->code(),
            'activate tidak lagi membuang cache versi lain: setiap rilis meninggalkan satu salinan penuh cangkang di perangkat.',
        );
    }

    /* ------------------------------------------------------------ helpers */

    private function worker(): string
    {
        return (string) file_get_contents(public_path('app/sw.js'));
    }

    /** Isi sw.js tanpa komentar — rujukan di kepala berkas menyebut /api/*, kode tidak boleh. */
    private function code(): string
    {
        return $this->stripComments($this->worker());
    }

    /** Badan sebuah `function nama(...) { … }` tingkat atas, tanpa komentar. */
    private function functionBody(string $name): string
    {
        $code = $this->code();
        $start = strpos($code, "function {$name}(");
        $this->assertNotFalse($start, "function {$name}() tidak ada lagi di sw.js.");

        $open = strpos($code, '{', $start);
        $depth = 0;
        for ($i = $open; $i < strlen($code); $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("Kurung badan function {$name}() tidak seimbang.");
    }

    /**
     * Buang komentar // dan block, TANPA menyentuh yang berada di dalam literal
     * string ('…', "…", `…`) — sebuah path berisi // di dalam kutip adalah kode,
     * bukan komentar.
     */
    private function stripComments(string $source): string
    {
        $out = '';
        $len = strlen($source);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $source[$i];
            $next = $i + 1 < $len ? $source[$i + 1] : '';

            if ($quote !== null) {
                $out .= $c;
                if ($c === '\\') {
                    $out .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $out .= $c;

                continue;
            }

            if ($c === '/' && $next === '/') {
                while ($i < $len && $source[$i] !== "\n") {
                    $i++;
                }
                $out .= "\n";

                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $out .= ' ';

                continue;
            }

            $out .= $c;
        }

        return $out;
    }

    /** @return list<string> */
    private function shellList(): array
    {
        $this->assertSame(
            1,
            preg_match('~const SHELL = \[(.*?)\n\];~s', $this->worker(), $block),
            'Daftar SHELL tidak ditemukan di sw.js.',
        );

        preg_match_all("~'([^']+)'~", $block[1], $entries);
        $list = $entries[1];

        $this->assertSame(count($list), count(array_unique($list)), 'Ada entri SHELL kembar.');

        return $list;
    }

    /**
     * Berkas yang dimuat peramban saat aplikasi berjalan: definisi yang sama
     * dengan yang ditulis kepala sw.js. `icons/` dibaca sistem operasi (layar
     * utama, pengalih tugas), bukan halaman, jadi ia bukan cangkang; `sw.js`
     * sendiri tidak pernah masuk cache-nya sendiri.
     *
     * @return list<string>
     */
    private function shellFilesOnDisk(): array
    {
        $root = public_path('app');
        $files = [];

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($walk as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($relative === 'sw.js' || str_starts_with($relative, 'icons/')) {
                continue;
            }
            if (! in_array(strtolower($file->getExtension()), self::SHELL_EXTENSIONS, true)) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }
}
