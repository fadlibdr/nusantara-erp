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

    public function test_the_shell_version_went_up_with_the_list(): void
    {
        $this->assertSame(1, preg_match("~const SHELL_VERSION = '(\\d+)';~", (string) file_get_contents(public_path('app/sw.js')), $version));
        $this->assertGreaterThanOrEqual(47, (int) $version[1],
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
