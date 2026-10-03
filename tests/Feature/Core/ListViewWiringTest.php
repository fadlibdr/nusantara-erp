<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Layar daftar generik (views/list.js) sesudah redesain UX-E1 — kabelnya.
 *
 * Tidak ada runtime JS di host ini; seperti ShellWiringTest, yang dipaku adalah
 * SUMBERNYA, pada janji yang bisa dibalik oleh perubahan kecil yang tidak
 * berbunyi apa-apa di layar. Setiap tugas paket menambah pin-nya di sini.
 */
class ListViewWiringTest extends ErpTestCase
{
    /* ------------------------------------------------------------ E1.1 */

    /** Kepala daftar lewat pageHeader(), deskripsi opsional, Tambah satu-satunya tombol utama. */
    public function test_the_list_head_is_built_by_page_header_with_the_optional_description(): void
    {
        $list = $this->spa('views/list.js');
        $this->assertStringContainsString('pageHeader({', $list);
        $this->assertStringContainsString('title: def.label,', $list);
        $this->assertStringContainsString('description: def.description,', $list);
        $this->assertStringNotContainsString("el('.page-head'", $list, 'Kepala daftar kembali dirakit tangan.');

        // Aksi koleksi tanpa `variant`: Tambah harus tetap satu-satunya .btn.primary
        // (onboarding menyorotnya; preventive-schedules mendeklarasikan 'primary').
        $start = strpos($list, '...(def.collectionActions || [])');
        $this->assertNotFalse($start);
        $chain = substr($list, $start, (int) strpos($list, "button('', { iconName: 'refresh'", $start) - $start);
        $this->assertStringContainsString('.filter((action) => session.canAct(action))', $chain);
        $this->assertStringNotContainsString('variant', $chain, 'Aksi koleksi meneruskan variant: Tambah tidak lagi satu-satunya tombol utama.');

        // Kunci skema terdokumentasi, dan percontohannya ada di luar `columns:` (ReportableResourcesTest
        // membaca `api:` dalam 400 byte dari tiap resource).
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString('description  satu kalimat di bawah judul daftar', $schema);
        $this->assertMatchesRegularExpression("/lookupSource: 'purchaseOrders',\\n    description: '[^']+',\\n/", $schema);
    }

    /* ------------------------------------------------------------ E1.2 */

    /** Hanya jawaban permintaan terbaru yang melukis; pemulihan sort tetap ada dan ikut bernomor. */
    public function test_only_the_newest_list_response_paints(): void
    {
        $load = $this->functionBody($this->spa('views/list.js'), 'async function load() {');
        $this->assertNotNull($load, 'load() hilang dari list.js.');
        $this->assertStringContainsString('const seq = ++loadSeq;', $load);
        $this->assertGreaterThanOrEqual(2, substr_count($load, 'if (seq !== loadSeq) return;'),
            'Jawaban usang bisa melukis lagi: kotak Cari dan tab status menampilkan daftar milik keadaan yang sudah ditinggalkan.');
        // Penjaga di cabang catch berdiri SEBELUM pemulihan sort 422, dan pemulihannya tetap memanggil load() lagi.
        $catch = substr($load, (int) strpos($load, 'catch (caught)'));
        $this->assertLessThan(strpos($catch, 'caught.status === 422'), strpos($catch, 'if (seq !== loadSeq) return;'));
        $this->assertStringContainsString('return load();', $catch);
        $this->assertStringContainsString("tidak tersedia — daftar memakai urutan bawaan.", $catch);
    }

    /* ------------------------------------------------------------ E1.3 */

    /** Panah urut di SETIAP kolom yang bisa diurutkan, dari sprite, dengan nama literal; kertas hanya mencetak yang aktif. */
    public function test_every_sortable_header_carries_a_sprite_arrow(): void
    {
        $list = $this->spa('views/list.js');
        $cell = $this->functionBody($list, 'function headerCell(column, sortable) {');
        $this->assertNotNull($cell, 'headerCell() hilang dari list.js.');

        // Tiga nama literal — LauncherWiringTest hanya bisa memeriksa nama yang tertulis di sumber.
        $this->assertStringContainsString(
            "!active ? icon('arrow-up-down', 12) : ui.dir === 'desc' ? icon('arrow-down', 12) : icon('arrow-up', 12)",
            $cell,
        );
        $this->assertStringNotContainsString('▲', $list, 'Penanda teks lama kembali.');
        $this->assertStringNotContainsString('▼', $list, 'Penanda teks lama kembali.');

        // Hanya kolom yang server izinkan yang menjadi tombol (dan membawa panah); siklus dan halaman 1 tetap.
        $this->assertStringContainsString('if (!sortable.includes(column.key)) {', $cell);
        $this->assertLessThan(strpos($cell, "el('button.th-sort'"), strpos($cell, 'if (!sortable.includes(column.key)) {'));
        $this->assertStringContainsString("el('button.th-sort', { type: 'button', title: 'Urutkan' }", $cell);
        $this->assertStringContainsString("th.setAttribute('aria-sort', ui.dir === 'desc' ? 'descending' : 'ascending');", $cell);
        $this->assertStringContainsString('ui.page = 1;', $cell);

        $css = $this->css();
        $this->assertStringContainsString('table.data th .th-sort .lucide { flex: none; }', $css);
        $this->assertStringContainsString('table.data th:not([aria-sort]) button.th-sort .lucide { display: none; }', $css);
        $print = strrpos(substr($css, 0, (int) strpos($css, 'table.data th:not([aria-sort]) button.th-sort .lucide')), '@media print {');
        $between = substr($css, (int) $print, (int) strpos($css, 'table.data th:not([aria-sort]) button.th-sort .lucide') - (int) $print);
        $this->assertSame(substr_count($between, '{') - 1, substr_count($between, '}'),
            'Aturan cetak panah urut tidak lagi berada di dalam blok @media print — panah kolom diam ikut tercetak sebagai kontrol mati.');
    }

    public function test_the_shell_version_went_up_with_the_list(): void
    {
        $this->assertSame(1, preg_match("~const SHELL_VERSION = '(\\d+)';~", (string) file_get_contents(public_path('app/sw.js')), $version));
        $this->assertGreaterThanOrEqual(48, (int) $version[1],
            'SHELL_VERSION turun di bawah versi yang membawa daftar UX-E1: perangkat yang memegang cangkang lama tidak pernah mengambilnya.');
    }

    /** Separuh penolakan. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertNull($this->functionBody("x\n", 'function nope('));
    }

    /* ------------------------------------------------------------ helpers */

    private function spa(string $relative): string
    {
        return (string) file_get_contents(public_path('app/js/'.$relative));
    }

    private function css(): string
    {
        return (string) file_get_contents(public_path('app/app.css'));
    }

    /** Badan fungsi bersarang/bertingkat-atas: dari tanda tangan sampai `}` pada indentasinya sendiri. */
    private function functionBody(string $source, string $signature): ?string
    {
        $start = strpos($source, $signature);
        if ($start === false) {
            return null;
        }
        $indent = str_repeat(' ', $start - (int) strrpos(substr($source, 0, $start), "\n") - 1);
        $end = strpos($source, "\n{$indent}}", $start);

        return $end === false ? null : substr($source, $start, $end - $start);
    }
}
