<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * Manifest PWA (P1-I) — `public/app/manifest.webmanifest` dan tautannya di
 * index.html.
 *
 * Sebuah manifest yang rusak GAGAL DENGAN DIAM: peramban tidak menggambar apa
 * pun, tidak menulis apa pun ke layar, dan aplikasi tetap berjalan sempurna —
 * yang hilang hanya "Pasang aplikasi", berbulan-bulan kemudian, di ponsel orang
 * lain. Tidak ada runtime JS di host ini, jadi yang dipaku adalah berkasnya:
 *
 *  (a) JSON-nya sah dan memuat anggota yang dibutuhkan Chromium untuk
 *      menganggap aplikasi ini bisa dipasang;
 *  (b) start_url dan scope berada DI BAWAH /app/ — scope yang melebar ke "/"
 *      akan menuntut worker yang tidak boleh selebar itu (lihat
 *      PwaServiceWorkerTest), dan start_url di luar scope membuat Chromium
 *      menolak seluruh manifest;
 *  (c) setiap ikon yang diumumkan ADA, dan ukuran yang diumumkannya sama dengan
 *      ukuran piksel berkas PNG-nya sendiri (IHDR dibaca langsung) — manifest
 *      yang menulis "512x512" di atas berkas 192 px adalah persis jenis
 *      kebohongan yang tidak pernah terlihat sampai ikonnya buram di layar utama;
 *  (d) ada ikon "any" ≥ 192 px DAN ikon "maskable";
 *  (e) index.html menautkannya, dan kedua <meta name="theme-color"> bermedia
 *      memakai nilai --surface app.css apa adanya — anti-hanyut terhadap
 *      penyetelan token berikutnya;
 *  (f) background_color/theme_color manifest = --bg/--primary tema terang.
 *
 * Yang TIDAK bisa diuji di sini dan karena itu diukur harness S27_pwa: bahwa
 * Chromium benar-benar mem-parsing berkas ini (Page.getAppManifest errors []).
 */
class PwaManifestTest extends TestCase
{
    /** Anggota yang HARUS ada; tanpa salah satunya Chromium menolak memasang. */
    private const REQUIRED_MEMBERS = ['name', 'short_name', 'start_url', 'scope', 'display', 'icons', 'theme_color', 'background_color'];

    /* --------------------------------------------------------------- (a) */

    public function test_the_manifest_is_valid_json_with_every_required_member(): void
    {
        $manifest = $this->manifest();

        foreach (self::REQUIRED_MEMBERS as $member) {
            $this->assertArrayHasKey($member, $manifest, "manifest.webmanifest kehilangan anggota '{$member}'.");
        }

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('id', $manifest['lang'] ?? null, 'Manifest tanpa lang="id": nama aplikasi dibaca peramban sebagai teks tanpa bahasa.');
        $this->assertNotSame('', trim((string) $manifest['name']));
        $this->assertNotSame('', trim((string) $manifest['short_name']));
    }

    /* --------------------------------------------------------------- (b) */

    public function test_start_url_and_scope_stay_inside_the_app_directory(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('/app/', $manifest['scope'], 'Scope manifest harus /app/ — sama dengan lingkup service worker.');
        $this->assertStringStartsWith(
            '/app/',
            (string) $manifest['start_url'],
            'start_url di luar scope: Chromium menolak SELURUH manifest, tanpa pesan di layar.',
        );
    }

    /* --------------------------------------------------------------- (c) */

    public function test_every_announced_icon_exists_at_the_size_it_announces(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $path = public_path('app/'.$icon['src']);
            $this->assertFileExists($path, "Ikon manifest {$icon['src']} tidak ada di public/app.");

            if (! str_ends_with($path, '.png')) {
                continue;
            }

            [$width, $height] = $this->pngSize($path);
            $this->assertSame(
                "{$width}x{$height}",
                $icon['sizes'],
                "{$icon['src']} berukuran {$width}x{$height} px, sedangkan manifest mengumumkan {$icon['sizes']}.",
            );
        }
    }

    /* --------------------------------------------------------------- (d) */

    public function test_there_is_an_installable_icon_and_a_maskable_one(): void
    {
        $icons = $this->manifest()['icons'];

        $installable = array_filter($icons, function (array $icon): bool {
            if (! str_ends_with($icon['src'], '.png') || ! str_contains($icon['purpose'] ?? 'any', 'any')) {
                return false;
            }

            return $this->pngSize(public_path('app/'.$icon['src']))[0] >= 192;
        });

        $this->assertNotEmpty(
            $installable,
            'Tidak ada ikon PNG "any" ≥ 192 px: Chromium tidak menganggap aplikasi ini bisa dipasang.',
        );

        $maskable = array_filter($icons, fn (array $icon): bool => str_contains($icon['purpose'] ?? '', 'maskable'));
        $this->assertNotEmpty(
            $maskable,
            'Tidak ada ikon maskable: peluncur Android akan memotong ikon persegi menjadi lingkaran, memakan lambangnya.',
        );
    }

    /* --------------------------------------------------------------- (e) */

    public function test_index_html_links_the_manifest_and_paints_the_browser_chrome_from_the_surface_token(): void
    {
        $html = (string) file_get_contents(public_path('app/index.html'));

        $this->assertMatchesRegularExpression(
            '~<link\s+rel="manifest"\s+href="manifest\.webmanifest"~',
            $html,
            'index.html tidak menautkan manifest: berkasnya ada, dan tidak pernah dibaca peramban.',
        );

        $this->assertSame(
            2,
            preg_match_all('~<meta\s+name="theme-color"\s+media="\(prefers-color-scheme:\s*(light|dark)\)"\s+content="(#[0-9a-f]{6})"~i', $html, $metas, PREG_SET_ORDER),
            'Harus ada TEPAT DUA <meta name="theme-color"> bermedia (terang dan gelap): satu nilai akan salah di separuh perangkat.',
        );

        $found = [];
        foreach ($metas as $meta) {
            $found[strtolower($meta[1])] = strtolower($meta[2]);
        }

        $this->assertSame(
            ['light' => $this->cssToken('light', '--surface'), 'dark' => $this->cssToken('dark', '--surface')],
            $found,
            'theme-color harus --surface kedua tema app.css: bilah peramban duduk persis di atas .header, dan .header berlatar --surface.',
        );
    }

    /* --------------------------------------------------------------- (f) */

    public function test_the_splash_colours_are_the_light_theme_tokens(): void
    {
        $manifest = $this->manifest();

        // Manifest hanya boleh punya SATU nilai masing-masing — tidak ada media
        // query di dalamnya — jadi keduanya nilai tema TERANG, dan itu memang
        // yang dilihat pemakai bertema gelap sebagai kilatan splash. Konsekuensi
        // itu ditulis di LAPORAN-PAKET-HM-P1-I § yang belum diverifikasi.
        $this->assertSame($this->cssToken('light', '--bg'), strtolower((string) $manifest['background_color']));
        $this->assertSame($this->cssToken('light', '--primary'), strtolower((string) $manifest['theme_color']));
    }

    /* ------------------------------------------------------------ helpers */

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $raw = (string) file_get_contents(public_path('app/manifest.webmanifest'));
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded, 'manifest.webmanifest bukan JSON yang sah: '.json_last_error_msg());

        return $decoded;
    }

    /**
     * Lebar & tinggi PNG dari chunk IHDR — 8 byte tanda tangan, 4 byte panjang,
     * 4 byte tipe, lalu dua uint32 big-endian.
     *
     * @return array{0: int, 1: int}
     */
    private function pngSize(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 24);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($head, 0, 8), basename($path).' bukan berkas PNG.');
        $this->assertSame('IHDR', substr($head, 12, 4), basename($path).' tidak diawali chunk IHDR.');

        return [unpack('N', substr($head, 16, 4))[1], unpack('N', substr($head, 20, 4))[1]];
    }

    /** Nilai token dari blok `:root[data-theme="<tema>"]` app.css. */
    private function cssToken(string $theme, string $token): string
    {
        $css = (string) file_get_contents(public_path('app/app.css'));

        $this->assertSame(
            1,
            preg_match('~:root\[data-theme="'.$theme.'"\]\s*\{(.*?)\n\}~s', $css, $block),
            "Blok :root[data-theme=\"{$theme}\"] tidak ditemukan di app.css.",
        );
        $this->assertSame(
            1,
            preg_match('~'.preg_quote($token, '~').':\s*(#[0-9a-f]{6})~i', $block[1], $value),
            "Token {$token} tidak ada di blok tema {$theme}.",
        );

        return strtolower($value[1]);
    }
}
