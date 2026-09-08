<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Sebuah TAHUN bukan sebuah JUMLAH (verifikasi F-2 putaran 2).
 *
 * Perender kolom `number` memakai Intl id-ID, jadi sebuah kolom tahun buku
 * mencetak "2.031". Diukur di Chromium pada #/r/finance/overhead-budgets:
 *
 *   <td class="right"><span class="num">2.032</span></td>
 *   baris lain: 'OVB/2031/0001 | 2.031 | 2 | Rp 800.000.000 | …'
 *
 * sementara panel Informasi pada halaman DETAIL dokumen yang sama mencetaknya
 * benar ("Tahun 2032") — dua bentuk untuk satu angka pada satu dokumen yang
 * seluruh identitasnya adalah tahun itu.
 *
 * Diperiksa dengan grep, pola yang sama dengan NavRouteRegistryTest: tidak ada
 * runtime JS di host ini, dan sebuah grep membaca berkas yang sama yang dibaca
 * peninjau. Yang dijaga bukan satu kolom OVB melainkan ATURANNYA, supaya kolom
 * tahun berikutnya tidak lahir dengan pemisah ribuan lagi.
 */
class SpaYearColumnTest extends ErpTestCase
{
    public function test_no_table_column_renders_a_year_with_a_thousands_separator(): void
    {
        $schema = file_get_contents(base_path('public/app/js/schema.js'));

        // Objek satu baris yang menyebut sebuah kunci tahun. `align:` adalah
        // penanda bentuk KOLOM tabel; saringan dan lapangan formulir memakai
        // bentuk yang sama tanpa align, dan keduanya bukan perender angka.
        preg_match_all("/\{[^{}\\n]*key: '([a-z_]*year)'[^{}\\n]*\}/", $schema, $matches, PREG_SET_ORDER);

        $columns = array_values(array_filter(
            $matches,
            static fn (array $match): bool => str_contains($match[0], 'align:'),
        ));

        // Sebuah regex yang diam-diam berhenti cocok akan membuat uji ini
        // menjadi no-op yang tetap melaporkan PASS.
        $this->assertNotEmpty(
            $columns,
            'Tidak satu pun kolom bertahun terbaca dari schema.js — bentuk kolomnya berubah dan uji ini '
            .'sudah tidak membacanya lagi. Perbaiki polanya sebelum mempercayai run hijau.',
        );

        foreach ($columns as $column) {
            $this->assertStringContainsString(
                "type: 'year'",
                $column[0],
                "Kolom [{$column[1]}] memakai perender angka, jadi tahun 2031 tercetak \"2.031\": {$column[0]}",
            );
        }
    }

    public function test_the_year_renderer_exists_in_the_cell_vocabulary(): void
    {
        $cells = file_get_contents(base_path('public/app/js/cells.js'));

        $this->assertStringContainsString(
            "case 'year':",
            $cells,
            'schema.js memakai type: \'year\' tetapi renderCell() tidak mengenalnya — selnya akan jatuh '
            .'ke cabang default dan mencetak nilai mentahnya.',
        );
    }
}
