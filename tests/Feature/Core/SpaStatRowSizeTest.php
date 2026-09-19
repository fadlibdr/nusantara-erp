<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * Satu baris ubin KPI memakai satu ukuran angka (CONVENTIONS §17, UI-6d).
 *
 * Mengapa uji PHP dan bukan skenario harness: aturannya berlaku pada SUMBER,
 * dan harness hanya bisa melihat layar yang bisa ia CAPAI. Sensus peramban UI-6d
 * menyinggahi 13 rute sementara `.stat-row` dibangun di puluhan berkas view;
 * enam baris campur lolos justru karena layarnya butuh keadaan (rekening dipilih,
 * periode dipilih) atau data yang tidak ada di basis data demo. S53 tetap ada dan
 * mengukur yang tergambar; uji ini menutup yang tidak tergambar.
 *
 * Aturannya, dan batasnya:
 *
 *   - Di dalam SATU `.stat-row`, semua `.value` memakai ukuran yang sama —
 *     semuanya `.value` (22 px) atau semuanya `.value.sm` (16 px). Dua ukuran
 *     bersebelahan tidak terbaca sebagai hierarki, ia terbaca sebagai kelalaian.
 *   - Uji ini TIDAK memaksa ukuran mana yang dipakai antar-layar. Itu keputusan
 *     nilai untuk seluruh aplikasi (7 baris seragam 16 px lawan 4 seragam 22 px
 *     saat aturan ini ditulis) dan belum diambil.
 *   - Uang yang tidak muat bukan alasan mengecilkan ubinnya: pakai
 *     `fmt.rupiahShort()` pada ukuran yang sama dan taruh angka penuhnya di
 *     baris konteks.
 */
class SpaStatRowSizeTest extends TestCase
{
    /** @return array<string, string> */
    private function viewSources(): array
    {
        $dir = public_path('app/js/views');
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'js') {
                continue;
            }
            $out[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
        }
        ksort($out);

        return $out;
    }

    /**
     * Blok `el('.stat-row', …)` dengan kurung seimbang, supaya baris bersarang
     * tidak tercampur dengan tetangganya.
     *
     * @return list<array{file: string, line: int, body: string}>
     */
    private function statRows(): array
    {
        $rows = [];
        foreach ($this->viewSources() as $name => $src) {
            $offset = 0;
            while (($start = strpos($src, "el('.stat-row'", $offset)) !== false) {
                $depth = 0;
                $end = strlen($src);
                for ($i = $start; $i < strlen($src); $i++) {
                    if ($src[$i] === '(') {
                        $depth++;
                    } elseif ($src[$i] === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }
                $rows[] = [
                    'file' => $name,
                    'line' => substr_count(substr($src, 0, $start), "\n") + 1,
                    'body' => substr($src, $start, $end - $start),
                ];
                $offset = $end;
            }
        }

        return $rows;
    }

    public function test_one_stat_row_uses_one_value_size(): void
    {
        $rows = $this->statRows();
        $this->assertNotEmpty($rows, 'Tidak ada .stat-row yang terbaca — jalur pemindaian uji ini salah.');

        $mixed = [];
        $seen = 0;
        foreach ($rows as $row) {
            // Setiap selektor el() di dalam blok ini yang memuat `.value`,
            // termasuk kedua cabang ternary `cond ? '.value.sm' : '.value'`.
            preg_match_all("/el\(\s*[`'\"]([^`'\"]*\.value[^`'\"]*)/", $row['body'], $m);
            $selectors = $m[1] ?? [];
            if (count($selectors) < 2) {
                continue;
            }
            $seen++;

            $sizes = [];
            foreach ($selectors as $selector) {
                $sizes[str_contains($selector, '.sm') ? 'sm' : 'lg'] = true;
            }
            if (count($sizes) > 1) {
                $mixed[] = sprintf('%s:%d (%d nilai)', $row['file'], $row['line'], count($selectors));
            }
        }

        fwrite(STDERR, sprintf(
            "\nSpaStatRowSizeTest: %d baris .stat-row berisi ≥2 nilai diperiksa, %d campur\n",
            $seen,
            count($mixed),
        ));

        // Penjaga anti-uji-hampa: kalau pemindaiannya berhenti menemukan baris,
        // uji ini lulus tanpa menguji apa pun. Diukur 19 Sep 2026: 44 baris.
        $this->assertGreaterThanOrEqual(
            38,
            $seen,
            "Hanya {$seen} baris .stat-row ber-≥2 nilai yang terbaca (diharapkan ≥ 38). "
            .'Pemindaiannya tidak sampai — pola el() berubah, atau berkasnya pindah.',
        );

        $this->assertSame(
            [],
            $mixed,
            "Baris ubin KPI yang mencampur .value (22 px) dan .value.sm (16 px):\n  "
            .implode("\n  ", $mixed)
            ."\n\nDua ukuran bersebelahan dalam satu baris tidak terbaca sebagai hierarki. "
            .'Kalau angkanya uang dan tidak muat, pakai fmt.rupiahShort() pada ukuran yang SAMA '
            .'dan taruh angka penuhnya di baris konteks (.delta) atau title — bukan mengecilkan ubinnya. '
            .'CONVENTIONS §17.',
        );
    }
}
