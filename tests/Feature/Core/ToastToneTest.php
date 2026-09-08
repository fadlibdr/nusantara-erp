<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * SETIAP NADA TOAST YANG DIPANGGIL PUNYA WARNANYA DI STYLESHEET
 * (verifikasi F-1, 7 Sep 2026).
 *
 * toast() merakit kelasnya sebagai `.toast.${tone}` (ui.js). Sebuah nada yang
 * tidak dikenal stylesheet tidak menghasilkan galat apa pun — ia menghasilkan
 * toast TANPA warna. Terukur di Chromium pada layar Tugas Saya: toast
 * kegagalan setujui massal dipanggil dengan tone 'error' (satu-satunya ejaan
 * itu di seluruh SPA; 21 pemanggil lain memakai 'err'), dan borderLeftColor-nya
 * rgb(94, 104, 116) — identik dengan toast tanpa kelas, berdiri di sebelah
 * toast keberhasilan yang hijau, sementara .toast.err adalah rgb(198, 40, 40).
 *
 * Uji ini menemukan empat kejadian LAIN dari cacat yang sama, yang sudah ada
 * sebelum F-1: tone 'warn' di board, tutupproyek, laporanbebas dan onboarding.
 * Keempatnya diperbaiki dengan mendefinisikan warnanya, bukan dengan menulis
 * ulang maksud penulisnya — "Perpindahan ditolak" memang peringatan.
 *
 * Daftar yang dibolehkan DITURUNKAN DARI STYLESHEET, bukan ditulis di sini:
 * menambahkan `.toast.error` ke app.css juga menghijaukan uji ini, dan itu
 * memang perbaikan yang sah.
 */
class ToastToneTest extends ErpTestCase
{
    /**
     * KALIMAT PENOLAKANNYA TIDAK DIAWALI NAMA KOLOM.
     *
     * Penolakan pipeline memulangkan SATU kunci galat (`status`) berisi kalimat
     * utuh; ApiError#details merakit "field: msg", dan toastError dulu hanya
     * membuang awalan itu untuk MEMBANDINGKAN judul, bukan untuk badan
     * toast-nya. Terukur kata demi kata di results-phase-2.json
     * (S30_pipeline_crm.drag_to_won.toasts[1]): "status: Prospek LEAD-0003
     * tidak bisa dipindahkan ke Menang lewat tahap: …" — kata pertama yang
     * dibaca sales adalah nama kolom basis data.
     */
    public function test_a_single_error_toast_does_not_lead_with_the_field_key(): void
    {
        $ui = (string) file_get_contents(public_path('app/js/ui.js'));

        $this->assertStringContainsString('details.length === 1 ? [firstDetail] : details', $ui,
            'toast 422 berkunci tunggal masih diawali nama kolomnya');
    }

    /** @return list<string> */
    private function tonesTheStylesheetDefines(): array
    {
        $css = (string) file_get_contents(public_path('app/app.css'));

        preg_match_all('/\.toast\.([a-z][a-z0-9-]*)\s*\{/', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<array{file: string, tone: string}> */
    private function tonesTheCodePasses(): array
    {
        $found = [];

        foreach ($this->spaJsFiles() as $path) {
            $source = (string) file_get_contents($path);
            $offset = 0;

            while (($at = strpos($source, 'toast(', $offset)) !== false) {
                $offset = $at + 6;

                // Pemanggilan berakhir di ");" berikutnya. Cukup untuk
                // pemanggilan satu-dua baris, yang adalah bentuk setiap
                // pemanggil toast() di SPA ini.
                $end = strpos($source, ');', $at);
                $call = substr($source, $at, ($end === false ? 400 : $end - $at));

                if (preg_match("/tone:\s*'([a-z0-9-]+)'/", $call, $m) === 1) {
                    $found[] = ['file' => str_replace(base_path().'/', '', $path), 'tone' => $m[1]];
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function spaJsFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(public_path('app/js'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_stylesheet_defines_the_four_tones_toast_is_built_around(): void
    {
        $defined = $this->tonesTheStylesheetDefines();

        sort($defined);
        $this->assertSame(['err', 'info', 'ok', 'warn'], $defined);
    }

    public function test_no_toast_asks_for_a_tone_the_stylesheet_never_defines(): void
    {
        $defined = $this->tonesTheStylesheetDefines();
        $passed = $this->tonesTheCodePasses();

        $this->assertNotEmpty($passed, 'the scanner found no toast tones at all — it stopped scanning');

        foreach ($passed as $use) {
            $this->assertContains(
                $use['tone'],
                $defined,
                "{$use['file']} raises a toast with tone '{$use['tone']}', which app.css does not colour — "
                .'it renders in the neutral colour, indistinguishable from a styleless toast',
            );
        }
    }
}
