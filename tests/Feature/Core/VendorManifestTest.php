<?php

namespace Tests\Feature\Core;

use DOMDocument;
use Tests\TestCase;

/**
 * Aturan "tanpa CDN, tanpa npm saat runtime" (ROADMAP-HASHMICRO §5 keputusan #2)
 * sebagai uji, bersama manifest pustaka vendor di public/app/vendor/VENDOR.md.
 *
 * SPA ini vanilla ES modules tanpa build step; satu-satunya kode pihak ketiga
 * adalah berkas statis di public/app/vendor/<lib>@<ver>/ yang tercatat sha256-nya.
 * Tanpa uji ini, sebuah `<script src="https://cdn…">` yang ditambahkan untuk
 * "coba cepat" akan lolos review, memuat kode yang tidak ada di repositori,
 * dan diam-diam mati saat erp1 dipakai tanpa internet keluar. Uji ini adalah
 * grep — pola yang sama dengan NavRouteRegistryTest dan DashboardTileFailureTest:
 * tidak ada runtime JS di host ini, dan grep atas berkas yang dibaca reviewer
 * tidak bisa basi seperti daftar yang dirawat tangan.
 *
 * Lima hal yang dipaku:
 *  (a) setiap berkas di public/app/vendor (kecuali VENDOR.md) ada di tabel Berkas
 *      VENDOR.md dengan sha256 yang sama, dan tidak ada baris tabel yang
 *      menunjuk berkas yang hilang;
 *  (b) tidak ada pemuat (script src, link href, use/image/img/iframe/… href|src|srcset,
 *      import, import(), new URL, fetch, url(), @import, "src": di manifest) yang
 *      menunjuk http(s):// atau //host di *.html *.htm *.xhtml *.js *.mjs *.css
 *      *.svg *.webmanifest *.json mana pun di bawah public/app — tanpa allowlist.
 *      Atribut berisi DAFTAR kandidat (srcset, imagesrcset) dibaca utuh — juga bila
 *      nilainya melintasi baris — dan SETIAP kandidatnya diperiksa (srcsetCandidates),
 *      bukan hanya yang pertama: "x.png 1x, //evil.example/x.png 2x" memuat kandidat
 *      kedua di layar 2× (verifikasi P1-A putaran 2, mutasi y5b).
 *      Literal http(s) yang BUKAN pemuat harus lolos aturan data yang tepat di
 *      isDataLiteral(); literal //host di dalam tanda kutip/backtick diperlakukan
 *      sama (`s.src = '//cdn…'` adalah alamat, bukan komentar) — hanya //host tanpa
 *      kutip yang dianggap komentar JS/pembagian;
 *  (c) jumlah gzip -9 seluruh public/app/vendor ≤ 60 KB (angkanya dicetak);
 *  (d) sprite Lucide adalah XML sah, setiap <symbol> ber-id "lucide-…" + viewBox,
 *      tanpa <script> dan tanpa URL selain xmlns;
 *  (e) Sortable.min.js identik byte demi byte dengan sha manifestnya dan diawali
 *      banner rilisnya — bukan bukti kecocokan dengan registry (itu langkah tangan
 *      di VENDOR.md).
 *
 * SPA_ROOT (env) mengalihkan akar pindaian ke salinan coretan — dipakai untuk
 * membuktikan uji ini merah dulu (script CDN palsu, byte yang diubah) tanpa
 * menyentuh pohon kerja.
 */
class VendorManifestTest extends TestCase
{
    private const GZIP_CEILING_BYTES = 60 * 1024;

    /**
     * Semua jenis berkas yang bisa memuat sumber daya: modul .mjs, .svg (<script
     * href>, <image href>, <use href>), manifest PWA (.webmanifest/.json — P1-I;
     * ikon "src" eksternal), dan kedua ekstensi halaman lain yang dilayani peramban
     * sebagai HTML (.htm, .xhtml). Verifikasi P1-A: hanya html/js/css yang dipindai, jadi
     * js/x.mjs berisi import('https://cdn…') dan favicon.svg berisi <script href>
     * lolos (mutasi m27/m28); putaran 2: extra.htm berisi <script src="https://cdn…">
     * lolos (mutasi x3).
     */
    private const SCANNED_EXTENSIONS = ['html', 'htm', 'xhtml', 'js', 'mjs', 'css', 'svg', 'webmanifest', 'json'];

    /**
     * Pengenal namespace W3C yang diserahkan ke createElementNS()/xmlns: URL
     * hanya sebagai nama, tidak pernah diambil oleh peramban.
     *
     * @var list<string>
     */
    private const NAMESPACE_IRIS = ['http://www.w3.org/2000/svg', 'http://www.w3.org/1999/xlink'];

    /**
     * Rujukan http(s) di sebuah baris: bentuk berskema `https://…` (huruf besar
     * ikut — `HTTPS://cdn…` sama saja bagi peramban) DAN bentuk relatif-protokol
     * `//cdn.jsdelivr.net/…` — persis potongan salin-tempel yang uji ini ada untuk
     * menangkapnya; bentuk kedua hanya dihitung bila didahului pemuat (LOADER_BEFORE_URL)
     * ATAU tanda kutip/backtick (literal string — lalu tunduk pada aturan data seperti
     * kembarannya yang ber-https), karena `//` tanpa kutip adalah awalan komentar JS
     * atau pembagian. Verifikasi P1-A (5 Sep 2026): pola lama `https?://` tanpa /i
     * meloloskan keduanya; putaran 2: `s.src = '//cdn…'`, import(`//cdn…`) dan
     * srcset="//…" lolos karena semua //host tanpa pemuat dilewati.
     */
    private const URL_PATTERN = '~(?:https?://[^\s\'"`)<>]+|//[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:[:/?#][^\s\'"`)<>]*)?)~i';

    /**
     * Konteks tepat sebelum sebuah URL yang berarti peramban akan MEMUATNYA.
     * Setiap alternatif berakhir tepat di awal URL (…$), jadi `<a href=` atau
     * `href:` milik el('a') tidak cocok — itu tautan yang diklik orang, bukan
     * sumber daya halaman.
     */
    private const LOADER_BEFORE_URL = '~(?:'
        .'<script\b[^>]*\bsrc\s*=\s*["\']?'          // <script src="…
        .'|<link\b[^>]*\bhref\s*=\s*["\']?'         // <link href="…
        // elemen HTML/SVG lain yang mengambil sumber daya lewat src/href/xlink:href/data
        .'|<(?:script|use|image|img|iframe|source|embed|object|video|audio|track|base)\b[^>]*\b(?:src|srcset|href|xlink:href|data)\s*=\s*["\']?'
        .'|"(?:src|href|url)"\s*:\s*"'                // "src": "…  (manifest PWA / JSON)
        // pemuat JS menerima backtick juga: import(`//cdn…`) sama hidupnya dengan import('…')
        .'|\bimport\s*\(\s*["\'`]'                   // import('…
        .'|\bimport\b[^;]*\bfrom\s*["\'`]'          // import x from '…
        .'|\bimport\s*["\'`]'                        // import '…  (efek samping)
        .'|\bnew\s+URL\s*\(\s*["\'`]'                // new URL('…
        .'|\bfetch\s*\(\s*["\'`]'                    // fetch('…
        .'|\burl\s*\(\s*["\']?'                      // url(… (CSS)
        .'|@import\s*(?:url\s*\(\s*)?["\']?'         // @import '…
        .')$~i';

    /**
     * Nilai atribut DAFTAR URL — srcset (<img>, <source>) dan imagesrcset (<link rel=preload
     * as=image>) — dalam empat bentuk penulisan: atribut HTML `srcset="…"` (kutip ganda/
     * tunggal, atau tanpa kutip sampai spasi/>), properti JS `img.srcset = '…'`, kunci
     * el('img', { srcset: '…' }) (ui.js el() memanggil setAttribute), dan
     * setAttribute('srcset', '…'). Nilai berkutip boleh melintasi baris. Dicocokkan atas
     * ISI BERKAS utuh, bukan per baris: pindaian per baris hanya melihat konteks tepat
     * sebelum sebuah URL, sehingga kandidat ke-2+ (didahului ', ') lolos sebagai "komentar".
     * Grup: 1 nama atribut, 2–5 nilai (salah satu terisi).
     */
    private const LIST_ATTRIBUTE_VALUE = '~\b(srcset|imagesrcset)\b["\']?\s*[=:,]\s*(?:"([^"]*)"|\'([^\']*)\'|`([^`]*)`|([^\s>"\'`]+))~i';

    /** Kandidat srcset yang diambil dari luar: berskema http(s) atau relatif-protokol ke host bertitik. */
    private const EXTERNAL_CANDIDATE = '~^(?:https?://|//[a-z0-9-]+(?:\.[a-z0-9-]+)+)~i';

    /**
     * Tag el() yang MEMUAT sumber daya lewat kunci EL_LOADER_KEYS — ui.js el() memanggil
     * setAttribute, jadi el('link', { href: 'https://cdn…' }) sama hidupnya dengan <link href>.
     * Dinilai pada pemanggilan el() terdalam yang melingkupi URL (elContext), betapa pun ia
     * bersarang di el('a', …).
     */
    private const EL_LOADER_TAGS = ['link', 'script', 'iframe', 'img', 'source', 'video', 'audio', 'embed', 'object', 'track', 'use', 'image'];

    private const EL_LOADER_KEYS = ['src', 'href', 'srcset', 'xlink:href', 'data', 'poster'];

    /* --------------------------------------------------------------- (a) */

    public function test_every_vendored_file_is_in_the_manifest_with_its_current_sha256(): void
    {
        $manifest = $this->manifest();
        $this->assertNotEmpty($manifest, 'VENDOR.md tidak punya baris tabel Berkas yang bisa dibaca.');

        foreach ($this->vendorFiles() as $relative) {
            $this->assertArrayHasKey($relative, $manifest, "{$relative} ada di public/app/vendor tetapi tidak tercatat di VENDOR.md.");
            $this->assertSame(
                $manifest[$relative],
                hash_file('sha256', $this->vendorRoot().'/'.$relative),
                "sha256 {$relative} berbeda dari VENDOR.md — berkas vendor diubah tanpa memperbarui manifest.",
            );
        }
    }

    public function test_no_manifest_row_points_at_a_missing_file(): void
    {
        foreach (array_keys($this->manifest()) as $relative) {
            $this->assertFileExists($this->vendorRoot().'/'.$relative, "VENDOR.md mencatat {$relative} tetapi berkasnya tidak ada.");
        }
    }

    /* --------------------------------------------------------------- (b) */

    public function test_no_cdn_loader_anywhere_under_app(): void
    {
        $violations = [];
        $scanned = 0;

        foreach ($this->scannedFiles() as $path) {
            $scanned++;
            $relative = substr($path, strlen($this->appRoot()) + 1);
            $content = (string) file_get_contents($path);

            /* Atribut daftar dulu, atas isi berkas utuh: setiap kandidat diputuskan di sini,
               dan rentang nilainya dilewati pindaian per baris di bawah supaya kandidat pertama
               tidak dilaporkan dua kali (sekali sebagai pemuat, sekali sebagai kandidat). */
            $handled = [];
            foreach ($this->listAttributeValues($content) as [$attribute, $value, $start]) {
                $handled[] = [$start, $start + strlen($value)];
                if ($this->isCommentLine($this->lineContaining($content, $start))) {
                    continue; // aturan 3 isDataLiteral(): docblock yang mengutip srcset bukan pemuat
                }
                foreach ($this->srcsetCandidates($value) as $index => [$candidate, $at]) {
                    if (preg_match(self::EXTERNAL_CANDIDATE, $candidate)) {
                        $violations[] = sprintf('%s:%d memuat %s dari luar (pemuat: %s, kandidat ke-%d dari %d)', $relative, substr_count($content, "\n", 0, $start + $at) + 1, $candidate, $attribute, $index + 1, count($this->srcsetCandidates($value)));
                    }
                }
            }

            $lineStart = 0;
            foreach (file($path) as $number => $line) {
                $lineOffset = $lineStart;
                $lineStart += strlen($line);
                if (! preg_match_all(self::URL_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($matches[0] as [$url, $offset]) {
                    $absolute = $lineOffset + $offset;
                    if (array_filter($handled, fn ($range) => $absolute >= $range[0] && $absolute < $range[1]) !== []) {
                        continue; // di dalam nilai srcset/imagesrcset: sudah diputuskan per kandidat di atas
                    }
                    $before = substr($line, 0, $offset);
                    $el = $this->elContext($before);
                    if (preg_match(self::LOADER_BEFORE_URL, $before)) {
                        $violations[] = sprintf('%s:%d memuat %s dari luar (pemuat: %s)', $relative, $number + 1, $url, trim(substr($before, -40)));
                    } elseif ($el !== null && in_array($el['tag'], self::EL_LOADER_TAGS, true) && in_array($el['key'], self::EL_LOADER_KEYS, true)) {
                        $violations[] = sprintf("%s:%d memuat %s dari luar (pemuat: el('%s', { %s }) — ui.js el() memanggil setAttribute)", $relative, $number + 1, $url, $el['tag'], $el['key']);
                    } elseif (str_starts_with($url, '//') && ! preg_match('~["\'`]$~', $before)) {
                        continue; // relatif-protokol tanpa pemuat DAN tanpa kutip: `//` komentar JS atau pembagian, bukan alamat
                    } elseif (! $this->isDataLiteral($url, $before, $line, $el)) {
                        $violations[] = sprintf('%s:%d literal %s tidak dikenal aturan data isDataLiteral() — bukan pemuat, tetapi bukan pula namespace W3C, tautan <a>/href:, atau komentar; tambahkan aturan yang tepat bila memang data', $relative, $number + 1, $url);
                    }
                }
            }
        }

        $this->assertGreaterThan(10, $scanned, 'Pindaian tidak menemukan berkas SPA — akar salah?');
        $this->assertSame([], $violations, "Rujukan http(s) di public/app:\n - ".implode("\n - ", $violations));
    }

    /**
     * Aturan data: sebuah literal http(s) yang bukan pemuat boleh ada HANYA bila
     *  1. ia namespace W3C (NAMESPACE_IRIS) sebagai string utuh — pengenal, bukan alamat;
     *  2. ia tujuan tautan yang diklik orang: HTML `<a … href="…"` pada baris yang sama, atau
     *     nilai langsung kunci `href` di argumen objek PERTAMA pemanggilan el('a…', { … })
     *     TERDALAM yang melingkupinya (elContext — pindaian kurung berimbang, bukan regex).
     *     `href:` polos meloloskan el('link', { rel: 'stylesheet', href }) yang, karena ui.js
     *     el() memanggil setAttribute, adalah pemuat stylesheet sungguhan (verifikasi P1-A,
     *     mutasi m12); batas ')' regex putaran 2 ([^;)]*) meloloskan el('link') sebagai argumen
     *     ANAK — el('a', {href:'#'}, el('link', { href })) tanpa ')' di antaranya — dan menolak
     *     el('a', { onclick: () => go(), href }) yang sah (putaran 2, mutasi y6b/y24). Kini:
     *     urutan kunci bebas, kunci boleh berkutip, tag boleh membawa .kelas/#id, dan
     *     el('div', {}, el('a', { href })) sah; el(tag, { href }) dengan tag bukan literal, href
     *     di objek bersarang ({ dataset: { href } }) atau di argumen selain yang pertama tetap
     *     gagal. Sebaliknya, el(EL_LOADER_TAGS, { EL_LOADER_KEYS }) ke http(s):// atau //host
     *     adalah PEMUAT betapa pun dalamnya ia bersarang di el('a') — diputuskan di pemindai
     *     sebelum sampai ke sini; `<link href` HTML ditangkap LOADER_BEFORE_URL; atau
     *  3. barisnya komentar JS/CSS (//, *, /*) — docblock yang mengutip alamat.
     * Selain itu gagal, supaya setiap URL baru di SPA diputuskan sadar.
     *
     * @param  array{tag: string, key: string}|null  $el  elContext($before)
     */
    private function isDataLiteral(string $url, string $before, string $line, ?array $el): bool
    {
        $quotedWhole = preg_match('~["\']$~', $before) && preg_match('~^'.preg_quote($url, '~').'["\']~', substr($line, strlen($before)));
        if ($quotedWhole && in_array($url, self::NAMESPACE_IRIS, true)) {
            return true;
        }
        if (preg_match('~xmlns(?::\w+)?\s*=\s*["\']$~', $before) && in_array($url, self::NAMESPACE_IRIS, true)) {
            return true;
        }
        if (preg_match('~<a\b[^>]*\bhref\s*=\s*["\']$~i', $before)) {
            return true;
        }
        if ($el !== null && $el['tag'] === 'a' && $el['key'] === 'href') {
            return true;
        }

        return $this->isCommentLine($line);
    }

    /**
     * Pemanggilan el() TERDALAM yang melingkupi posisi tepat setelah $before, bila URL di sana
     * adalah nilai langsung sebuah kunci di argumen objek PERTAMA el('tag…', { … }) itu.
     * Pindaian kurung berimbang atas $before, bukan regex: string berkutip ('"`, dengan escape)
     * dilompati — string terakhir yang tidak tertutup adalah kutip pembuka URL itu sendiri —
     * '(' '{' '[' ditumpuk, penutupnya membuang, dan koma pada kedalaman objek mencatat awal
     * pasangan kunci-nilai yang sedang berjalan (koma di dalam () => go() atau fmt(x, {…}) yang
     * sudah tertutup tidak terhitung). Syarat: tumpukan berakhir '(' lalu '{', '(' itu didahului
     * \bel, di antara keduanya tepat satu literal tag berkutip (boleh .kelas/#id) + koma, dan
     * sisa setelah koma terakhir pada '{' berbentuk `kunci: <kutip>`. Tidak memahami komentar
     * atau regex JS di baris yang sama — bila tidak yakin, null, dan literalnya gagal (aturan
     * "tambah aturan, bukan allowlist").
     *
     * @return array{tag: string, key: string}|null tag dan kunci dalam huruf kecil
     */
    private function elContext(string $before): ?array
    {
        $stack = [];
        $length = strlen($before);
        for ($i = 0; $i < $length; $i++) {
            $c = $before[$i];
            if ($c === '"' || $c === "'" || $c === '`') {
                for ($j = $i + 1; $j < $length && $before[$j] !== $c; $j++) {
                    if ($before[$j] === '\\') {
                        $j++;
                    }
                }
                if ($j >= $length) {
                    break; // string tidak tertutup = kutip pembuka URL yang sedang diperiksa
                }
                $i = $j;
            } elseif ($c === '(' || $c === '{' || $c === '[') {
                $stack[] = ['open' => $c, 'at' => $i, 'comma' => null];
            } elseif ($c === ')' || $c === '}' || $c === ']') {
                array_pop($stack);
            } elseif ($c === ',' && $stack !== []) {
                $stack[array_key_last($stack)]['comma'] = $i;
            }
        }

        $depth = count($stack);
        if ($depth < 2 || $stack[$depth - 1]['open'] !== '{' || $stack[$depth - 2]['open'] !== '(') {
            return null;
        }
        [$paren, $brace] = [$stack[$depth - 2], $stack[$depth - 1]];
        if (! preg_match('~\bel\s*$~', substr($before, 0, $paren['at']))) {
            return null;
        }
        if (! preg_match('~^\s*(["\'])([a-z][a-z0-9]*)(?:[.#][^"\']*)?\1\s*,\s*$~i', substr($before, $paren['at'] + 1, $brace['at'] - $paren['at'] - 1), $tag)) {
            return null;
        }
        if (! preg_match('~^\s*(["\']?)([\w:-]+)\1\s*:\s*["\'`]$~', substr($before, ($brace['comma'] ?? $brace['at']) + 1), $key)) {
            return null;
        }

        return ['tag' => strtolower($tag[2]), 'key' => strtolower($key[2])];
    }

    private function isCommentLine(string $line): bool
    {
        return (bool) preg_match('~^\s*(?://|\*|/\*)~', $line);
    }

    /** Baris (tanpa \n) yang memuat offset $at di $content. */
    private function lineContaining(string $content, int $at): string
    {
        $from = strrpos(substr($content, 0, $at), "\n");
        $from = $from === false ? 0 : $from + 1;
        $to = strpos($content, "\n", $at);

        return substr($content, $from, $to === false ? null : $to - $from);
    }

    /**
     * Semua nilai atribut daftar (LIST_ATTRIBUTE_VALUE) di sebuah berkas.
     *
     * @return list<array{string, string, int}> [nama atribut (huruf kecil), nilai, offset nilai dalam $content]
     */
    private function listAttributeValues(string $content): array
    {
        preg_match_all(self::LIST_ATTRIBUTE_VALUE, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $values = [];
        foreach ($matches as $match) {
            foreach ([2, 3, 4, 5] as $group) {
                if (isset($match[$group]) && $match[$group][0] !== null) {
                    $values[] = [strtolower($match[1][0]), $match[$group][0], $match[$group][1]];
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * Pecah nilai srcset menjadi kandidat URL-nya seperti peramban (HTML § parse a srcset
     * attribute, disederhanakan): lewati spasi dan koma; URL = deretan tanpa spasi; bila URL
     * berakhir koma, koma itu pemisah (tanpa deskriptor); selain itu deskriptor ("1x",
     * "800w") berlanjut sampai koma berikutnya. Jadi "x.png 1x,//h/x 2x", "x.png 1x, //h/x 2x"
     * dan URL data: berisi koma (tanpa spasi) semuanya dibaca benar.
     *
     * @return list<array{string, int}> [URL kandidat, offset dalam $value]
     */
    private function srcsetCandidates(string $value): array
    {
        $candidates = [];
        $length = strlen($value);
        $position = 0;
        while ($position < $length) {
            while ($position < $length && (ctype_space($value[$position]) || $value[$position] === ',')) {
                $position++;
            }
            if ($position >= $length) {
                break;
            }
            $start = $position;
            while ($position < $length && ! ctype_space($value[$position])) {
                $position++;
            }
            $token = substr($value, $start, $position - $start);
            $url = rtrim($token, ',');
            if ($url === $token) {
                $comma = strpos($value, ',', $position);
                $position = $comma === false ? $length : $comma + 1;
            }
            if ($url !== '') {
                $candidates[] = [$url, $start];
            }
        }

        return $candidates;
    }

    /* --------------------------------------------------------------- (c) */

    public function test_vendor_gzip_total_is_within_the_ceiling(): void
    {
        $total = 0;
        $perFile = [];
        foreach ($this->vendorFiles() as $relative) {
            $bytes = strlen(gzencode((string) file_get_contents($this->vendorRoot().'/'.$relative), 9));
            $perFile[] = sprintf('%s %d B', $relative, $bytes);
            $total += $bytes;
        }

        fwrite(STDERR, sprintf("\nVendorManifestTest: gzip -9 public/app/vendor = %d byte (plafon %d)\n  %s\n", $total, self::GZIP_CEILING_BYTES, implode("\n  ", $perFile)));

        $this->assertGreaterThan(0, $total, 'Tidak ada berkas vendor terukur.');
        $this->assertLessThanOrEqual(
            self::GZIP_CEILING_BYTES,
            $total,
            sprintf('gzip public/app/vendor = %d byte, melewati plafon %d byte (ROADMAP-HASHMICRO Fase 1: vendor ≤ 60 KB).', $total, self::GZIP_CEILING_BYTES),
        );
    }

    /* --------------------------------------------------------------- (d) */

    public function test_lucide_sprite_is_xml_and_every_symbol_is_a_lucide_id_with_a_viewbox(): void
    {
        $sprites = array_values(array_filter($this->vendorFiles(), fn ($f) => preg_match('~^lucide@[^/]+/sprite\.svg$~', $f)));
        $this->assertCount(1, $sprites, 'Tepat satu sprite Lucide yang diharapkan: '.implode(', ', $sprites));

        $xml = (string) file_get_contents($this->vendorRoot().'/'.$sprites[0]);
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded, 'sprite.svg bukan XML sah: '.implode('; ', $errors));

        $symbols = $document->getElementsByTagName('symbol');
        $this->assertGreaterThan(0, $symbols->length);
        $ids = [];
        foreach ($symbols as $symbol) {
            $id = $symbol->getAttribute('id');
            $this->assertMatchesRegularExpression('~^lucide-[a-z0-9-]+$~', $id, "id simbol '{$id}' tidak berawalan lucide-");
            $this->assertSame('0 0 24 24', $symbol->getAttribute('viewBox'), "simbol {$id} tanpa viewBox 24×24");
            $this->assertNotContains($id, $ids, "id simbol ganda: {$id}");
            $ids[] = $id;
        }

        $this->assertSame(0, $document->getElementsByTagName('script')->length, 'sprite.svg mengandung <script>');
        preg_match_all(self::URL_PATTERN, $xml, $urls);
        $this->assertSame([], array_values(array_diff(array_unique($urls[0]), self::NAMESPACE_IRIS)), 'sprite.svg merujuk URL selain namespace W3C (termasuk bentuk //host dan huruf besar)');
    }

    /* --------------------------------------------------------------- (e) */

    public function test_sortable_min_js_is_byte_identical_to_its_manifest_sha(): void
    {
        $manifest = $this->manifest();
        $paths = array_values(array_filter(array_keys($manifest), fn ($f) => preg_match('~^sortablejs@[^/]+/Sortable\.min\.js$~', $f)));
        $this->assertCount(1, $paths, 'Tepat satu Sortable.min.js yang diharapkan di manifest: '.implode(', ', $paths));

        $path = $this->vendorRoot().'/'.$paths[0];
        $this->assertFileExists($path);
        /* Uji ini hanya membandingkan berkas dengan sha di VENDOR.md (dan banner rilisnya);
           ia TIDAK melihat registry — 1 byte diubah + sha manifest ikut diperbarui tetap hijau
           (verifikasi P1-A, mutasi m29). Verifikasi terhadap tarball registry adalah langkah
           tangan di VENDOR.md § Cara memperbarui. */
        $this->assertSame($manifest[$paths[0]], hash('sha256', (string) file_get_contents($path)), 'Sortable.min.js berbeda dari sha manifest — perbarui manifest hanya dari tarball yang diverifikasi terhadap registry (VENDOR.md § Cara memperbarui); uji ini tidak memeriksa registry.');
        $this->assertStringStartsWith('/*! Sortable ', (string) file_get_contents($path, false, null, 0, 13), 'Sortable.min.js tidak diawali banner rilisnya.');
    }

    /* ---------------------------------------------------------- pembantu */

    private function appRoot(): string
    {
        return rtrim((string) (getenv('SPA_ROOT') ?: public_path('app')), '/');
    }

    private function vendorRoot(): string
    {
        return $this->appRoot().'/vendor';
    }

    /** @return array<string, string> jalur relatif ke vendor/ => sha256 dari VENDOR.md */
    private function manifest(): array
    {
        $path = $this->vendorRoot().'/VENDOR.md';
        $this->assertFileExists($path, 'public/app/vendor/VENDOR.md hilang.');

        $rows = [];
        foreach (file($path) as $line) {
            if (preg_match('~^\|\s*`([^`]+)`\s*\|\s*`([0-9a-f]{64})`\s*\|~', $line, $m)) {
                $this->assertArrayNotHasKey($m[1], $rows, "VENDOR.md mencatat {$m[1]} dua kali.");
                $rows[$m[1]] = $m[2];
            }
        }

        return $rows;
    }

    /** @return list<string> jalur relatif ke vendor/, VENDOR.md dikecualikan */
    private function vendorFiles(): array
    {
        $root = $this->vendorRoot();
        $this->assertDirectoryExists($root);

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($relative !== 'VENDOR.md') {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> jalur absolut berkas SCANNED_EXTENSIONS di bawah public/app (termasuk vendor/) */
    private function scannedFiles(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->appRoot(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (in_array(strtolower($file->getExtension()), self::SCANNED_EXTENSIONS, true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
