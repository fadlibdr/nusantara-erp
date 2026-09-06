<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Tiga grafik tangan pindah ke `charts.js` (Fase 1 / P1-E).
 *
 * Sampai P1-D kurva-S, kurva EVM dan tren harga satuan adalah ~250 baris SVG
 * yang ditulis tangan di tiga berkas layar: masing-masing menghitung kisinya
 * sendiri, menjarangkan labelnya sendiri, dan mewarnai lewat kelas `.plan/.act/
 * .base/.ev` di app.css. Tiga salinan aturan yang sama adalah tiga tempat yang
 * bisa berselisih — dan mereka MEMANG berselisih: hanya kurva EVM yang
 * membiarkan sumbunya naik melewati 100 %, hanya tren harga yang tidak memaksa
 * sumbu mulai dari nol, dan tidak satu pun dari ketiganya menambatkan label
 * tepinya ke dalam svg (label "01 Jul 2026" terpotong menjadi "01 Jul 20" pada
 * lebar 1112 px, terukur 6 Sep 2026 sebelum migrasi).
 *
 * Yang dijaga uji ini adalah bahwa migrasinya TIDAK BERBALIK diam-diam:
 * sebuah `document.createElementNS` baru di salah satu berkas layar adalah
 * grafik tangan keempat, dan ia akan lahir tanpa token, tanpa <title> per
 * tanda, dan tanpa blok cetak — persis tiga hal yang P1-A dibangun untuk
 * memberikannya.
 *
 * Pemeriksaannya grep, dengan alasan yang sama seperti NavRouteRegistryTest dan
 * DashboardTileFailureTest: tidak ada runtime JS di host ini.
 */
class ChartMigrationTest extends ErpTestCase
{
    /** Berkas layar yang dulu menggambar SVG sendiri, dan grafik yang dipakainya sekarang. */
    private const MIGRATED = [
        'views/project.js' => 'sCurveChart',
        'views/evm.js' => 'evmCurve',
        'views/hargasatuan.js' => 'trendChart',
    ];

    public function test_the_three_screens_no_longer_hand_roll_svg(): void
    {
        foreach (self::MIGRATED as $file => $function) {
            $source = $this->spa($file);

            $this->assertStringNotContainsString(
                'createElementNS',
                $source,
                sprintf(
                    '%s membuat elemen SVG sendiri lagi. Grafik keempat yang ditulis tangan akan lahir tanpa token '
                    .'--chart-*, tanpa <title> per tanda, dan tanpa blok cetak — pakai charts.js, atau tambahkan '
                    .'kemampuannya di sana supaya SEMUA grafik ikut mendapatkannya.',
                    $file,
                ),
            );

            $this->assertMatchesRegularExpression(
                "/import \{[^}]*\blineChart\b[^}]*\} from '\.\.\/charts\.js';/",
                $source,
                "{$file} tidak lagi mengimpor lineChart; fungsi {$function} menggambar dengan apa?",
            );

            $this->assertStringContainsString(
                'lineChart({',
                $source,
                "{$file} mengimpor lineChart tetapi tidak memanggilnya.",
            );
        }
    }

    /**
     * Aturan ">100 % dipertahankan" milik PEMANGGIL, dan ia masih di sana.
     *
     * ROADMAP menuliskannya sebagai syarat paket ini. charts.js menerima `yMax`
     * apa adanya — jadi yang menjaga garis biaya tetap terlihat pada proyek
     * yang membelanjakan lebih dari anggarannya adalah satu baris di evm.js.
     * Menghapusnya tidak menjatuhkan apa pun kecuali uji ini: sumbu akan diam-
     * diam berhenti di 100 % dan memotong justru kurva yang paling perlu
     * dibaca.
     */
    public function test_the_evm_axis_may_still_rise_above_one_hundred_percent(): void
    {
        $source = $this->spa('views/evm.js');

        $this->assertStringContainsString(
            'const yMax = Math.max(100, Math.ceil(peak / 25) * 25);',
            $source,
            'Aturan sumbu EVM > 100 % hilang. Sumbu yang ditahan di 100 % memotong garis biaya pada proyek yang '
            .'sudah melewati anggarannya — yaitu proyek yang paling perlu terlihat.',
        );

        // …dan yMax itu benar-benar sampai ke grafik.
        $this->assertMatchesRegularExpression('/\n    yMax,\n/', $source,
            'yMax dihitung tetapi tidak diteruskan ke lineChart.');
    }

    /**
     * Sumbu tren harga TIDAK dipaksa mulai dari nol.
     *
     * Tren 12.500 → 13.750 pada sumbu 0..14.000 tampak datar, padahal 10 % itu
     * yang dicari layar Riwayat Harga Satuan. Bawaan charts.js adalah domain
     * yang SELALU memuat nol, jadi tanpa yMin/yMax milik pemanggil sifat ini
     * hilang dalam migrasi tanpa satu galat pun.
     */
    public function test_the_price_trend_axis_is_not_forced_to_zero(): void
    {
        $source = $this->spa('views/hargasatuan.js');

        $this->assertStringContainsString('const yLo = Math.max(0, lo - room * 0.25);', $source);
        $this->assertStringContainsString('const yHi = hi + room * 0.25;', $source);
        $this->assertMatchesRegularExpression('/yMin: yLo,\s*\n\s*yMax: yHi,/', $source,
            'Ruang sumbu dihitung tetapi tidak diteruskan ke lineChart — sumbu jatuh ke bawaan "selalu memuat nol".');
    }

    /**
     * Legenda hidup di SATU tempat per grafik.
     *
     * charts.js menggambar legendanya di dalam svg (ikut tercetak, ikut
     * ter-skala). Blok `.legend` DOM yang ditinggalkan di sebelahnya akan
     * menggambar legenda kedua untuk grafik yang sama — dua daftar nama garis
     * yang bisa berselisih. Satu-satunya yang boleh tersisa adalah tren harga,
     * yang pembedanya per TITIK (PO vs GRN pada satu garis) dan karena itu
     * tidak bisa dinyatakan legenda per-seri.
     */
    public function test_only_the_price_trend_keeps_a_dom_legend(): void
    {
        $this->assertStringNotContainsString("el('.legend'", $this->spa('views/project.js'),
            'Kurva-S menggambar legenda DOM di samping legenda svg-nya sendiri.');
        $this->assertStringNotContainsString("el('.legend'", $this->spa('views/evm.js'),
            'Kurva EVM menggambar legenda DOM di samping legenda svg-nya sendiri.');

        $trend = $this->spa('views/hargasatuan.js');
        $this->assertStringContainsString("legend: false", $trend,
            'Tren harga memakai legenda DOM, jadi legenda svg-nya harus dimatikan — kalau tidak, keduanya tergambar.');
        $this->assertStringContainsString("el('.legend'", $trend);

        /*
         * Swatch legenda DOM memakai token GRAFIK, bukan --warning. Blok cetak
         * app.css hanya menukar token --chart-*, jadi swatch ber-`--warning`
         * tercetak dengan warna yang berbeda dari titik yang diwakilinya.
         */
        $this->assertStringNotContainsString("background: 'var(--warning)'", $trend);
        $this->assertStringContainsString("background: 'var(--chart-7)'", $trend);
    }

    /**
     * Gaya grafik tangan DIHAPUS dari app.css, bukan ditinggalkan.
     *
     * Selektor yang tidak lagi cocok dengan apa pun adalah warna yang menunggu
     * dipakai lagi oleh grafik berikutnya tanpa ada yang tahu dari mana asalnya
     * — dan warna itu BUKAN token grafik, jadi ia tidak ikut berubah di tema
     * gelap maupun di kertas.
     */
    public function test_the_hand_chart_styles_are_gone_from_the_stylesheet(): void
    {
        $css = (string) file_get_contents(public_path('app/app.css'));

        foreach (['.chart .plan', '.chart .act', '.chart .act-fill', '.chart .pt', '.chart .base', '.chart .ev',
            '.legend i.plan', '.legend i.act', '.legend i.base', '.legend i.ev'] as $selector) {
            $this->assertStringNotContainsString($selector, $css,
                "Selektor mati [{$selector}] masih di app.css — tidak ada lagi yang menggambar kelas itu.");
        }

        // …sementara pembungkus yang MASIH dipakai tetap ada.
        $this->assertStringContainsString('.chart { width: 100%;', $css);
        $this->assertStringContainsString('.legend {', $css);
        $this->assertStringContainsString('.chart-lib text {', $css);
    }

    private function spa(string $file): string
    {
        return (string) file_get_contents(public_path('app/js/'.$file));
    }
}
