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
 * BENTUK, BUKAN EJAAN (verifikasi, 7 Sep 2026). Versi pertama berkas ini
 * menghitung potongan teks literal, dan empat mutasi yang nyata-nyata membocorkan
 * cache lolos hijau: pendengar `fetch` KEDUA yang menulis lewat `store.put()`
 * (tidak ada respondWith, tidak ada kata '/api' — dan di peramban ia benar-benar
 * menyajikan /api/core/dashboard/summary kepada orang berikutnya di perangkat
 * yang sama, sesudah Keluar, tanpa token); daftar izin yang dilebarkan dengan
 * klausa startsWith() kedua sementara kelima syaratnya tetap ada kata demi kata;
 * storable() yang menambah `if (response.type === 'opaque') return true;` di
 * ATAS kedua potongan yang dicari; dan activate yang berhenti menghapus di
 * .map() sementara .filter() yang di-grep tetap utuh. Karena itu sekarang:
 * badan shellRequest() dan storable() dibandingkan UTUH, tulisan cache dihitung
 * sebagai pola `\w+.put(`/`\w+.add(` (bukan nama variabel tertentu), dan DAFTAR
 * pendengar worker dipaku persis empat.
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

        /*
         * …dan TIDAK ADA syarat lain. Kelima baris di atas boleh ada semua dan
         * gerbangnya tetap melebar, karena yang dilonggarkan adalah baris
         * keenam: `if (!url.pathname.startsWith(SCOPE) && !url.pathname
         * .startsWith(alsoAllowed)) return false;` memuat syarat 3 kata demi
         * kata sambil mengizinkan /api/core/ (diukur 7 Sep 2026: mutasi itu
         * hijau di seluruh berkas ini). Karena itu badan fungsinya dibandingkan
         * UTUH. Mengubahnya dengan sengaja berarti mengubah baris ini juga —
         * itulah gunanya.
         */
        $this->assertSame(
            "{ if (request.method !== 'GET') return false; "
            ."if (request.headers.has('Authorization')) return false; "
            .'const url = new URL(request.url); '
            .'if (url.origin !== self.location.origin) return false; '
            .'if (!url.pathname.startsWith(SCOPE)) return false; '
            .'if (url.pathname === self.location.pathname) return false; '
            .'return true; }',
            $this->squash($body),
            'Badan shellRequest() bukan lagi kelima syarat itu saja. Gerbang ini adalah SATU-SATUNYA '
            .'yang memisahkan cangkang dari /api/*; setiap baris tambahan di dalamnya melebarkannya.',
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

    /**
     * Menghitung respondWith() saja tidak cukup: sebuah pendengar `fetch` KEDUA
     * bisa menyimpan jawaban tanpa pernah menjawabnya (waitUntil + put), dan
     * itulah bentuk yang paling mungkin ditulis paket berikutnya yang ingin
     * "dasbor luring". Diukur 7 Sep 2026: mutasi seperti itu HIJAU di seluruh
     * uji berkas ini, lalu di peramban ia benar-benar menyimpan
     * /api/core/dashboard/summary dan menyajikannya kepada orang berikutnya di
     * perangkat yang sama — sesudah Keluar, tanpa token.
     *
     * Karena itu yang dipaku adalah DAFTAR pendengarnya, bukan isinya.
     */
    public function test_the_worker_registers_exactly_four_listeners(): void
    {
        preg_match_all("~addEventListener\('(\w+)'~", $this->code(), $found);
        $listeners = $found[1];
        sort($listeners);

        $this->assertSame(
            ['activate', 'fetch', 'install', 'message'],
            $listeners,
            'Daftar pendengar sw.js berubah. Pendengar `fetch` kedua bisa menyimpan jawaban '
            .'tanpa satu pun respondWith(), sehingga seluruh pemeriksaan lain di berkas ini '
            .'melewatinya; pendengar baru jenis lain butuh pembacanya sendiri. Tambahkan pendengar '
            .'hanya bersama uji yang memaku apa yang boleh dilakukannya.',
        );
    }

    /* --------------------------------------------------------------- (d) */

    public function test_there_is_exactly_one_cache_write_and_it_sits_behind_the_storable_guard(): void
    {
        $code = $this->code();

        /*
         * Dihitung sebagai TULISAN, bukan sebagai ejaan. substr_count('cache.put(')
         * hanya melihat variabel yang kebetulan bernama `cache`; sebuah pendengar
         * kedua yang menamainya `store` menulis ke cache yang sama dan tetap
         * terhitung nol (diukur 7 Sep 2026: mutasi itu hijau di seluruh berkas ini).
         */
        $this->assertSame(
            1,
            preg_match_all('~\b\w+\.put\(~', $code),
            'Ada lebih dari satu tulisan .put( ke cache. Satu-satunya tulisan di jalur fetch harus '
            .'tetap satu, apa pun nama variabel cache-nya.',
        );
        $this->assertMatchesRegularExpression(
            '~if \(storable\(response\)\) \{~',
            $code,
            'Tulisan cache tidak lagi berpenjaga storable(): jawaban 206, opaque atau bukan-200 bisa masuk cache.',
        );

        /*
         * Badannya disalin SEBELUM jawabannya diserahkan ke halaman.
         *
         * Badan jawaban hanya bisa dibaca sekali. Ketika clone() dipanggil di
         * dalam `.then()` milik caches.open, ia berjalan SESUDAH `return
         * response` — dan melempar "Response body is already used" di dalam
         * waitUntil, tempat tidak ada yang melihatnya. Akibatnya seluruh 102
         * entri cangkang berhenti tersimpan dan mode luring menjadi layar
         * kosong, tanpa satu pun galat di layar (regresi yang dibuat perbaikan
         * urutan fetch-sebelum-caches.open, ditemukan verifikasi ulang P1-I,
         * 7 Sep 2026; sesudah perbaikan: 102 entri, 0 /api, 0 di luar /app/).
         */
        $this->assertMatchesRegularExpression(
            '~const \w+ = response\.clone\(\);\s*event\.waitUntil\(~',
            $code,
            'response.clone() tidak lagi dipanggil SEBELUM event.waitUntil: bila ia berjalan di dalam '
            .'.then(), badan jawabannya sudah diserahkan ke halaman dan setiap tulisan cangkang gagal diam-diam.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '~\.then\([^)]*\) => \w+\.put\([^)]*response\.clone\(\)~',
            $code,
            'response.clone() dipanggil di dalam .then() — lihat komentar di atas: itu selalu terlambat.',
        );

        // .add()/.addAll() hanya boleh muncul di install, atas daftar SHELL.
        $this->assertSame(
            1,
            preg_match_all('~\b\w+\.add(?:All)?\(~', $code),
            'Ada lebih dari satu .add(/.addAll( ke cache: satu-satunya sumber isi cache selain jalur fetch '
            .'adalah SHELL. (addAll dihitung juga sejak verifikasi ulang P1-I: menghitung .add( saja '
            .'membiarkan satu baris cache.addAll([...]) di pendengar fetch menyimpan jawaban /api.)',
        );
    }

    public function test_an_incomplete_shell_install_is_thrown_away(): void
    {
        $install = $this->code();
        $start = strpos($install, "self.addEventListener('install'");
        $this->assertNotFalse($start, 'Tidak ada lagi pendengar install di sw.js.');
        $body = substr($install, $start, strpos($install, "self.addEventListener('activate'") - $start);

        $this->assertMatchesRegularExpression(
            '~const missingCore = failed\.filter\(~',
            $body,
            'install tidak lagi memisahkan berkas INTI dari berkas layar. Membuang cache karena satu '
            .'dari 102 entri gagal berarti satu rsync yang belum selesai, satu 5xx sesaat atau satu '
            .'permintaan yang jatuh menghapus lapisan luring seluruhnya (verifikasi ulang P1-I).',
        );
        $this->assertMatchesRegularExpression(
            '~if \(missingCore\.length\) \{.*?await caches\.delete\(CACHE\);~s',
            $body,
            'Cangkang yang kehilangan berkas INTI tidak lagi dibuang. Terukur 7 Sep 2026 dengan kuota '
            .'origin 1,2 MB: 42 dari 102 entri masuk, worker tetap aktif, lalu muat ulang tanpa '
            .'jaringan berhenti selamanya di pemutar boot (body kosong, 1.532 char). Cangkang '
            .'setengah lebih buruk daripada tidak ada cangkang.',
        );

        /*
         * …dan yang hilang HANYA layar tidak membuang apa pun: berkas itu diisi
         * jalur fetch pada kunjungan daring berikutnya, sementara aplikasinya
         * tetap bisa dibuka tanpa sinyal.
         */
        $this->assertMatchesRegularExpression(
            '~if \(failed\.length && !missingCore\.length\) \{~',
            $body,
            'Kehilangan berkas non-inti tidak lagi dibedakan dari kehilangan berkas inti.',
        );

        $core = $this->arrayLiteral('CORE');
        foreach (['./', 'index.html', 'app.css', 'js/app.js', 'js/api.js', 'js/ui.js', 'js/router.js'] as $must) {
            $this->assertContains($must, $core, "CORE tidak lagi menyebut {$must}; tanpa berkas itu aplikasi tidak bisa dibuka sama sekali.");
        }
        foreach ($core as $path) {
            $this->assertContains($path, $this->arrayLiteral('SHELL'), "CORE menyebut {$path}, yang tidak ada di SHELL — ia tidak akan pernah dipasang.");
        }
    }

    /** @return list<string> isi sebuah literal array di sw.js. */
    private function arrayLiteral(string $name): array
    {
        preg_match('~const '.$name.' = \[(.*?)\];~s', $this->code(), $found);
        $this->assertNotEmpty($found, "Tidak ada literal {$name} di sw.js.");
        preg_match_all("~'([^']+)'~", $found[1], $items);

        return $items[1];
    }

    public function test_the_network_starts_before_the_cache_is_opened(): void
    {
        $body = $this->functionBody('networkFirst');

        $fetch = strpos($body, 'await fetch(request)');
        $this->assertNotFalse($fetch, 'networkFirst() tidak lagi memulai fetch(request) — strateginya bukan jaringan-dulu lagi.');

        $open = strpos($body, 'caches.open(');
        $this->assertNotFalse($open, 'networkFirst() tidak lagi menyentuh cache sama sekali.');

        $this->assertLessThan(
            $open,
            $fetch,
            'caches.open() kembali berada SEBELUM fetch() di networkFirst(): setiap permintaan cangkang '
            .'menunggu cache dibuka sebelum satu byte pun diminta. Terukur 7 Sep 2026 pada kunjungan kedua '
            .'(worker menguasai halaman, 14 putaran per varian, diselang-seling): cat pertama median '
            .'268 ms dengan urutan lama lawan 192 ms dengan urutan ini.',
        );
    }

    /* --------------------------------------------------------------- (e) */

    public function test_only_a_plain_same_origin_200_may_be_stored(): void
    {
        $body = $this->functionBody('storable');

        $this->assertStringContainsString('response.status === 200', $body, 'storable() tidak lagi menuntut 200: 206 Range dan 30x bisa masuk cache.');
        $this->assertStringContainsString("response.type === 'basic'", $body, "storable() tidak lagi menuntut type 'basic': jawaban opaque lintas asal bisa masuk cache.");

        /*
         * Sekali lagi UTUH, bukan potongan: `if (response && response.type ===
         * 'opaque') return true;` di baris pertama membiarkan kedua potongan di
         * atas tetap ada dan tetap membuka pintu untuk jawaban opaque (diukur
         * 7 Sep 2026: hijau). Penjaga sesempit ini hanya bisa dipaku sebagai
         * satu kalimat penuh.
         */
        $this->assertSame(
            "{ return Boolean(response) && response.status === 200 && response.type === 'basic'; }",
            $this->squash($body),
            'storable() bukan lagi satu pernyataan return. Setiap baris tambahan di dalamnya adalah '
            .'pintu keluar dari penjaga yang menentukan apa yang boleh disimpan.',
        );
    }

    /* --------------------------------------------------------------- (f) */

    public function test_the_code_needs_no_blocklist_because_the_allowlist_is_the_rule(): void
    {
        // Daftar SHELL dibuang lebih dulu: ia memang memuat 'js/api.js' dan
        // 'js/views/attachments.js' — nama BERKAS cangkang, bukan awalan URL
        // server. Yang diperiksa di sini adalah logikanya.
        // CORE dibuang bersama SHELL dan untuk alasan yang sama: ia menyebut 'js/api.js'.
        $code = strtolower(preg_replace('~const (?:SHELL|CORE) = \[.*?\];~s', '', $this->code()));

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
            'activate tidak lagi menyaring cache versi lain: setiap rilis meninggalkan satu salinan penuh cangkang di perangkat.',
        );
        /*
         * Penyaringnya ada di .filter(), penghapusannya di .map() — dan mutasi
         * yang mengosongkan .map() sambil membiarkan .filter() utuh HIJAU di
         * seluruh berkas ini (diukur 7 Sep 2026). Yang harus dipaku adalah
         * penghapusannya: sebuah perangkat yang menyimpan cangkang setiap rilis
         * kehabisan kuota, dan kuota yang habis adalah cangkang setengah.
         */
        $this->assertMatchesRegularExpression(
            '~\.map\(\(name\) => caches\.delete\(name\)\)~',
            $this->code(),
            'activate tidak lagi MENGHAPUS cache versi lain. Menyaringnya saja tidak membuang apa pun.',
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

    /** Satu baris tanpa indentasi: pembandingan bentuk tidak boleh jatuh karena spasi. */
    private function squash(string $code): string
    {
        return trim(preg_replace('~\s+~', ' ', $code));
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
