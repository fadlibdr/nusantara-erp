<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * Anggaran gaya sebaris di lapisan view (UI-2).
 *
 * `el()` menerima `style` sebagai objek dan menjalankan
 * `Object.assign(node.style, value)` (public/app/js/ui.js). Hasilnya gaya
 * sebaris, yang MENGALAHKAN setiap aturan stylesheet dan setiap token. Selama
 * ia tumbuh, pekerjaan token tidak akan terlihat di layar.
 *
 * Uji ini adalah PLAFON, bukan larangan: angkanya boleh turun, tidak boleh
 * naik. Bentuknya sama dengan plafon gzip di VendorManifestTest — sebuah
 * pagar yang bergerak satu arah.
 *
 * YANG SENGAJA TIDAK DILARANG: warna. Diukur 15 Sep 2026, dari 122 kemunculan
 * `color:` di lapisan view NOL yang ditulis mentah — semuanya `var(--…)`.
 * Melarang warna berarti melarang sesuatu yang sudah benar. Utangnya ada di
 * GEOMETRI dan UKURAN HURUF: 127 `fontSize:` tanpa satu pun token.
 */
class SpaInlineStyleBudgetTest extends TestCase
{
    /**
     * Plafon hari ini. Turunkan setiap kali sebuah berkas disapu; JANGAN
     * pernah dinaikkan — kalau sebuah paket butuh gaya sebaris baru, yang
     * kurang adalah kelasnya, bukan plafonnya.
     */
    private const CEILING = 591;

    /** Berkas yang sudah disapu bersih dan harus tetap nol. */
    private const SWEPT = ['cashflow.js'];

    private function viewFiles(): array
    {
        $dir = public_path('app/js/views');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $files[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    private function countInline(string $source): int
    {
        return preg_match_all('/style:\s*\{/', $source);
    }

    public function test_inline_styles_in_the_view_layer_never_grow(): void
    {
        $files = $this->viewFiles();
        $this->assertNotEmpty($files, 'Tidak ada berkas view yang terbaca — jalur uji ini salah.');

        $perFile = [];
        $total = 0;
        foreach ($files as $name => $source) {
            $n = $this->countInline($source);
            $total += $n;
            if ($n > 0) {
                $perFile[$name] = $n;
            }
        }
        arsort($perFile);

        fwrite(STDERR, sprintf(
            "\nSpaInlineStyleBudgetTest: %d objek `style: {` di public/app/js/views (plafon %d)\n",
            $total,
            self::CEILING,
        ));
        foreach (array_slice($perFile, 0, 8, true) as $name => $n) {
            fwrite(STDERR, sprintf("  %-28s %d\n", $name, $n));
        }

        $this->assertLessThanOrEqual(
            self::CEILING,
            $total,
            "Gaya sebaris di lapisan view bertambah menjadi {$total} (plafon ".self::CEILING."). "
            .'Gaya sebaris mengalahkan setiap token, jadi yang kurang adalah KELASNYA, bukan plafonnya — '
            .'pakai primitif yang ada (.stack .row .cluster .grid-auto .tone-pos .tone-neg .strong .col-min) '
            .'atau tambahkan satu kelas komponen di app.css.',
        );
    }

    public function test_a_swept_file_does_not_grow_a_second_crop(): void
    {
        $files = $this->viewFiles();

        foreach (self::SWEPT as $name) {
            $this->assertArrayHasKey($name, $files, "Berkas tersapu {$name} tidak ada lagi — perbarui daftar SWEPT.");
            $this->assertSame(
                0,
                $this->countInline($files[$name]),
                "views/{$name} sudah disapu bersih di UI-2a dan harus tetap nol. "
                .'Menumbuhkannya kembali menghapus pekerjaan yang sudah dibayar.',
            );
        }
    }

    public function test_the_primitives_the_message_points_at_actually_exist(): void
    {
        // Pesan kegagalan di atas menyuruh memakai primitif ini. Kalau salah
        // satunya hilang dari app.css, pesan itu menyuruh orang memakai kelas
        // yang tidak menggambar apa pun — dan kegagalannya tidak akan terlihat.
        $css = (string) file_get_contents(public_path('app/app.css'));

        foreach (['.stack', '.row', '.cluster', '.grid-auto', '.tone-pos', '.tone-neg', '.strong', '.col-min'] as $class) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($class, '/').'[\s,{.]/m',
                $css,
                "Primitif {$class} disebut pesan kegagalan uji ini tetapi tidak ada di app.css.",
            );
        }
    }
}
