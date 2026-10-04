<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * Standar kepala halaman (UI-3c).
 *
 * `.page-head` dibangun 69 kali di 54 berkas dan TIDAK ada pembangun bersama —
 * `ui.js` tidak mengekspor satu pun helper untuk itu. Menyatukan ke-69 situs itu
 * bukan pekerjaan paket cangkang; ia menyentuh setiap layar dan milik UI-4/UI-6.
 *
 * Yang bisa dipaku sekarang adalah standarnya sendiri, supaya ia menyebar dan
 * tidak pernah mundur:
 *
 *  1. tipografinya memakai skala UI-1, bukan angka mentah;
 *  2. bilah aksinya punya perilaku ponsel — sampai UI-3c `.page-head` tidak
 *     punya SATU pun aturan responsif di seluruh app.css;
 *  3. bilah tiga-zona hanya punya SATU produsen, dan angka itu jadi LANTAI:
 *     boleh naik, tidak boleh turun.
 */
class SpaPageHeadStandardTest extends TestCase
{
    /**
     * Produsen `.zone` hari ini. Ini LANTAI, bukan target.
     *
     * Diukur 16 Sep 2026: `views/detail.js` saja, tiga zona (navigasi ·
     * keluaran · keputusan). Ke-40 bilah aksi lain menaruh tombol rata di
     * `.actions`, dan 22 kepala halaman tidak punya `.actions` sama sekali.
     * Setiap layar yang mengadopsi zona menaikkan angka ini; yang menurunkannya
     * membuang standar yang sudah dibayar.
     */
    private const ZONE_FLOOR = 3;

    private function css(): string
    {
        return (string) file_get_contents(public_path('app/app.css'));
    }

    /** @return array<string, string> */
    private function spaScripts(): array
    {
        $dir = public_path('app/js');
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'js') {
                continue;
            }
            $path = substr($file->getPathname(), strlen($dir) + 1);
            if (str_starts_with($path, 'vendor/')) {
                continue;
            }
            $out[$path] = (string) file_get_contents($file->getPathname());
        }
        ksort($out);

        return $out;
    }

    public function test_the_page_head_type_comes_from_the_ui1_scale(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.page-head h1 \{[^}]*font-size: var\(--fs-page\)/',
            $css,
            'Judul kepala halaman kembali memakai angka px mentah. --fs-page ada sejak UI-1 justru untuk ini; '
            .'kalau kepala halaman memakai literal sendiri, menyetel skalanya tidak menggerakkan judul halaman.',
        );

        $this->assertMatchesRegularExpression(
            '/\.page-head \.desc \{[^}]*font-size: var\(--fs-body\)/',
            $css,
            'Deskripsi kepala halaman kembali memakai angka px mentah alih-alih --fs-body.',
        );
    }

    public function test_the_action_bar_has_a_phone_behaviour(): void
    {
        $css = $this->css();
        $start = strpos($css, '@media (max-width: 760px)');
        $this->assertNotFalse($start, 'Breakpoint 760 px hilang dari app.css.');

        $mobile = substr($css, $start);
        $this->assertStringContainsString(
            '.page-head .actions:has(> .zone)',
            $mobile,
            'Bilah aksi kepala halaman tidak lagi punya perilaku ponsel. Tanpa aturan itu ia membungkus sebanyak '
            .'yang perlu: terukur 16 Sep 2026 pada 390 px, #/d/projects/1 dengan delapan tombol rata menghasilkan '
            .'kepala halaman setinggi 256 px — hampir sepertiga layar sebelum satu baris isi pun tergambar.',
        );
        $this->assertStringContainsString(
            'overflow-x: auto',
            $mobile,
            'Bilah aksi yang ramai tidak lagi menggulung mendatar di ponsel.',
        );
        // Luapan bilah yang menggulung harus tumpah ke KANAN (bisa digulir), bukan ke kiri titik awal
        // gulir: bilah berzona dengan catatan lipat rapat kanan, dan flex-end biasa membuang Kembali,
        // Cetak, Setujui dan Tolak ke x < 0 di 390 px (S49, 4 Okt 2026).
        $this->assertStringContainsString(
            '.page-head .actions:has(.action-note) { justify-content: flex-end; justify-content: safe flex-end; }',
            $css,
            'Bilah aksi dengan catatan lipat kembali rapat kanan TANPA `safe`: di ponsel tombolnya tumpah ke kiri dan tak terjangkau.',
        );
    }

    public function test_the_three_zone_action_bar_never_loses_ground(): void
    {
        $zones = 0;
        $producers = [];
        foreach ($this->spaScripts() as $name => $source) {
            $n = preg_match_all("/el\(\s*'\.zone[.'\s]/", $source);
            if ($n > 0) {
                $zones += $n;
                $producers[] = $name;
            }
        }

        fwrite(STDERR, sprintf(
            "\nSpaPageHeadStandardTest: %d zona di %s (lantai %d)\n",
            $zones,
            $producers ? implode(', ', $producers) : '—',
            self::ZONE_FLOOR,
        ));

        $this->assertGreaterThanOrEqual(
            self::ZONE_FLOOR,
            $zones,
            "Bilah aksi tiga-zona menyusut menjadi {$zones} zona (lantai ".self::ZONE_FLOOR.'). '
            .'Zona adalah satu-satunya hal yang memisahkan "Kembali" dari "Setujui" di bilah yang sama; '
            .'angka ini boleh NAIK saat layar lain mengadopsinya, tidak boleh turun.',
        );
    }

    public function test_the_zone_css_the_bar_depends_on_still_exists(): void
    {
        // Produsennya satu berkas. Kalau aturannya hilang, ketiga zona itu
        // tergambar sebagai tiga div polos dan tidak ada yang gagal.
        $css = $this->css();

        $this->assertStringContainsString(
            '.page-head .actions .zone { display: flex;',
            $css,
            'Aturan .zone hilang; bilah aksi dokumen kembali jadi satu deret tombol tanpa pemisah.',
        );
        $this->assertStringContainsString(
            '.page-head .actions .zone + .zone',
            $css,
            'Pemisah antar-zona hilang; ketiga zona tergambar menyatu.',
        );
    }
}
