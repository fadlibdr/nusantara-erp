<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Cangkang UX-D — kabelnya (handoff README Fase D, ERRATA D-2 dan E-4).
 *
 * Tidak ada runtime JS di host ini; seperti SidebarNavWiringTest dan
 * LauncherWiringTest yang dipaku adalah SUMBER, tempat sebuah perubahan kecil
 * yang tidak berbunyi apa-apa bisa membalik janji paket ini:
 *
 *  (a) baris tetap (Beranda, Persetujuan) DATANG DARI NAV — izinnya tetap di
 *      schema.js dan disaring visibleNav(); shell tidak menulis satu izin pun;
 *  (b) grup panjang diplafon, tetapi rute aktif, kotak saring, dan beranda
 *      modul tetap menjangkau setiap baris;
 *  (c) kotak masuk punya SATU pemuat: lencana, widget dasbor, layar
 *      Persetujuan, dan tawaran "berikutnya" sesudah memutus — tidak ada
 *      permintaan tanpa izin approve, tidak ada jawaban milik orang lain;
 *  (d) angka yang bukan jumlah sebenarnya (meta.failed) tidak pernah tampil
 *      telanjang.
 */
class ShellWiringTest extends ErpTestCase
{
    /* --------------------------------------------------------------- (a) */

    public function test_the_pinned_rows_come_from_nav_and_the_shell_writes_no_permission(): void
    {
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString("{ label: 'Beranda', route: 'home', chrome: true, pinned: true, icon: 'home' }", $schema);
        $this->assertStringContainsString(
            "{ label: 'Persetujuan', route: 'tugas', perm: ANY_APPROVE, pinned: true, icon: 'inbox', count: 'inbox' }",
            $schema,
            'Baris Persetujuan kehilangan gerbang ANY_APPROVE-nya atau tanda baris tetapnya.',
        );

        $render = $this->functionBody($this->spa('app.js'), 'function renderNav(nav) {');
        $this->assertNotNull($render, 'renderNav() hilang dari app.js.');
        $this->assertStringContainsString('const groups = navForSession();', $render,
            'Baris tetap tidak lagi disaring visibleNav(): tautan Persetujuan bisa tergambar bagi peran tanpa izin approve.');
        $this->assertStringContainsString('group.items.filter((item) => item.pinned)', $render);
        $this->assertStringContainsString("group.items.filter((item) => !item.pinned)", $render,
            'Baris tetap digambar dua kali: di atas DAN di grupnya.');
        $this->assertStringNotContainsString('ANY_APPROVE', $render, 'renderNav menulis izinnya sendiri.');
        $this->assertDoesNotMatchRegularExpression("/session\\.can\\('/", $render, 'renderNav menulis izinnya sendiri.');
    }

    /* --------------------------------------------------------------- (b) */

    public function test_long_groups_are_capped_without_hiding_the_active_route_or_any_row_from_the_filter(): void
    {
        $app = $this->spa('app.js');
        $this->assertStringContainsString('const CAP_GROUP = 10;', $app);
        $this->assertStringContainsString('const CAP_SHOW = 8;', $app);
        $this->assertStringContainsString('el(\'span.lbl\', { text: `Semua layar ${group.label} →` })', $app,
            'Grup yang diplafon tidak lagi menawarkan jalan ke beranda modulnya.');
        $this->assertStringContainsString("href: `#/m/\${group.prefix}`", $app);

        $active = $this->functionBody($app, 'function setActiveNav(path) {');
        $this->assertNotNull($active);
        $this->assertStringContainsString("item.classList.toggle('cap-active', active && item.classList.contains('beyond-cap'))", $active,
            'Rute aktif di balik plafon tidak lagi ditandai, jadi ia tidak tergambar.');

        $css = $this->css();
        $this->assertStringContainsString('.nav:not([data-filtering="true"]) .nav-item.beyond-cap:not(.cap-active)', $css,
            'Plafon menyembunyikan baris juga saat menyaring, atau menyembunyikan rute aktif.');

        // Plafonnya hanya untuk grup yang memang panjang: lima grup hari ini.
        $long = array_filter($this->routesPerGroup(), fn (int $n): bool => $n > 10);
        ksort($long);
        $this->assertSame(['Keuangan', 'Pengadaan', 'Penjualan', 'Persediaan', 'Proyek'], array_keys($long),
            'Daftar grup di atas plafon berubah; periksa apakah plafon 10/8 masih masuk akal.');
    }

    /* --------------------------------------------------------------- (c) */

    public function test_the_inbox_has_one_loader_that_never_asks_without_an_approve_permission(): void
    {
        $inbox = $this->spa('inbox.js');
        $this->assertStringContainsString('if (!session.can(ANY_APPROVE)) return Promise.resolve(null);', $inbox);
        $this->assertSame(1, substr_count($this->code($inbox), "'core/inbox'"));

        foreach (['app.js', 'views/tugas.js', 'views/widgets/inbox.js', 'views/actions.js', 'views/dashboard.js'] as $file) {
            $this->assertStringNotContainsString("'core/inbox'", $this->code($this->spa($file)),
                "{$file} meminta core/inbox sendiri lagi: dua permintaan untuk jawaban yang sama, dan lencana yang tidak ikut berubah.");
        }
        foreach (['views/tugas.js', 'views/widgets/inbox.js', 'views/actions.js'] as $file) {
            $this->assertStringContainsString('loadInbox({ maxAge: 0 })', $this->spa($file),
                "{$file} tidak memuat jawaban segar lewat pemuat bersama.");
        }
    }

    public function test_the_inbox_answer_belongs_to_the_user_who_asked(): void
    {
        $inbox = $this->spa('inbox.js');
        $this->assertStringContainsString('const cacheIsMine = () => cache.payload !== null && cache.userId === currentUserId();', $inbox,
            'Jawaban kotak masuk tidak lagi dikunci pada penggunanya: orang kedua di tab yang sama melihat angka orang pertama.');
        $this->assertStringContainsString('if (!inflight || inflight.userId !== userId) {', $inbox);
        $this->assertStringContainsString('if (!cacheIsMine()) return null;', $this->functionBody($inbox, 'export function inboxSummary() {'));
    }

    /* --------------------------------------------------------------- (d) */

    public function test_an_incomplete_inbox_is_never_shown_as_a_bare_number(): void
    {
        $paint = $this->functionBody($this->spa('app.js'), 'function paintInboxCounts() {');
        $this->assertNotNull($paint, 'paintInboxCounts() hilang dari app.js.');
        $this->assertStringContainsString('const incomplete = summary.failed.length > 0;', $paint);
        $this->assertStringContainsString('`${summary.total}+`', $paint);
        $this->assertStringContainsString('Daftar ini belum lengkap', $paint,
            'Lencana kotak masuk yang separuh gagal tidak lagi mengatakannya (ERRATA E-4; tugas.js memakai kalimat yang sama).');
        $this->assertStringContainsString('.nav-count.incomplete', $this->css());
    }

    /** Separuh penolakan. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertNull($this->functionBody("x\n", 'function nope('));
        $stripped = $this->code("const a = 1; // 'core/inbox'\n/* 'core/inbox' */ const b = 2;");
        $this->assertStringNotContainsString('core/inbox', $stripped, 'code() lets a comment through.');
        $this->assertStringContainsString('const a = 1;', $stripped, 'code() swallows code.');
        $this->assertStringContainsString('const b = 2;', $stripped, 'code() swallows code.');
        $this->assertSame([], array_filter($this->routesPerGroup(), fn (int $n): bool => $n === 0), 'routesPerGroup() read a group with no route.');
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

    /** Sumber tanpa komentar — rujukan di komentar bukan kode. */
    private function code(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~(?<=^|[\s;{}(,])//[^\n]*~m', '', $source);
    }

    private function functionBody(string $source, string $signature): ?string
    {
        $start = strpos($source, $signature);
        if ($start === false) {
            return null;
        }
        $end = strpos($source, "\n}", $start);

        return $end === false ? null : substr($source, $start, $end - $start);
    }

    /** Jumlah rute per grup NAV, dibaca dari schema.js. @return array<string, int> */
    private function routesPerGroup(): array
    {
        $schema = $this->spa('schema.js');
        $start = (int) strpos($schema, 'export const NAV = [');
        $nav = substr($schema, $start, (int) strpos($schema, "\n];", $start) - $start);
        preg_match_all("/^    label: '([^']+)', perm: [^\n]*prefix: '[a-z]+'/m", $nav, $labels, PREG_OFFSET_CAPTURE);

        $out = [];
        foreach ($labels[1] as $i => [$label, $offset]) {
            $end = $labels[1][$i + 1][1] ?? strlen($nav);
            $out[$label] = preg_match_all("/route: '/", substr($nav, $offset, $end - $offset));
        }

        return $out;
    }
}
