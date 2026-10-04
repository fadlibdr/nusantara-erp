<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Primitif ui.js dari handoff UI/UX (UX-C.3, README C3, ERRATA E-2) — kabelnya.
 *
 * Sebelas pembangun bersama lahir untuk menggantikan bentuk yang sampai kini
 * dirakit ulang di tiap view (.page-head 69 kali di 54 berkas, badge() 29 kali).
 * Tidak ada runtime JS di host ini, jadi yang dipaku adalah SUMBERNYA, dengan
 * disiplin yang sama dengan SidebarNavWiringTest dan LauncherWiringTest: grep atas
 * berkas yang dibaca peninjau.
 *
 * Yang dijaga, dan kegagalan yang dicegahnya:
 *  - setiap primitif ADA dan diekspor — view yang mengimpor nama yang hilang
 *    gagal saat modul dievaluasi, dan seluruh SPA berhenti di pemutar boot;
 *  - ui.js tidak memuat satu pun literal warna hex: warna datang dari kelas yang
 *    app.css petakan ke token, kalau tidak tema gelap dan cetak kehilangan satu
 *    komponen tanpa suara;
 *  - setiap kelas yang primitif tulis punya aturan di app.css — primitif yang
 *    kelasnya tidak dikenal stylesheet tergambar sebagai teks polos;
 *  - overflowMenu dibangun DI ATAS menuButton (E-2), bukan menu kedua;
 *  - statusBadge menggambar IKON + KATA (E-3 butir 6), bukan warna saja;
 *  - --attn hanya dipakai actionPanel (dan kartu beranda), tidak pernah .badge.
 */
class UiPrimitivesTest extends ErpTestCase
{
    private const PRIMITIVES = [
        'pageHeader', 'docHeader', 'statusBadge', 'actionPanel', 'tabs', 'filterChip',
        'overflowMenu', 'keyValueTile', 'stepper', 'drawer', 'errorSummary',
    ];

    /** Kelas yang ditulis tiap primitif => aturan app.css yang harus ada. */
    private const CLASSES = [
        'pageHeader' => ['.page-head {', '.page-head h1 {', '.page-head .actions {'],
        'docHeader' => ['.doc-head {', '.doc-code {', '.doc-party {'],
        'statusBadge' => ['.badge {', '.badge .lucide {'],
        'actionPanel' => ['.action-panel {', '.action-panel.attn {', '.action-panel.success {', '.action-panel.danger {'],
        'tabs' => ['.tabs {', '.tab-count {'],
        'filterChip' => ['.chip {', '.chip-x {'],
        'overflowMenu' => ['.menu-pop {', '.menu-divider {', '.menu-item.danger {', '.menu-pop.floating {'],
        'keyValueTile' => ['.kv-tile {', '.kv-tile-value {'],
        'stepper' => ['.stepper {', '.step-link {'],
        'errorSummary' => ['.error-summary {', 'button.linkish {'],
    ];

    public function test_every_primitive_is_exported_from_ui_js(): void
    {
        $ui = $this->spa('ui.js');

        foreach (self::PRIMITIVES as $name) {
            $this->assertMatchesRegularExpression(
                "/^export function {$name}\\(/m",
                $ui,
                "ui.js tidak mengekspor {$name}(): view yang mengimpornya gagal saat modul dievaluasi, dan SPA berhenti di pemutar boot.",
            );
        }
    }

    public function test_ui_js_carries_no_hex_colour_literal(): void
    {
        $code = $this->stripComments($this->spa('ui.js'));

        $this->assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,8}\b/',
            $code,
            'ui.js menulis warna hex sendiri: komponen itu akan tetap terang di tema gelap dan di kertas. Warna harus lewat kelas → token app.css.',
        );
    }

    public function test_every_class_a_primitive_writes_has_a_rule_in_the_stylesheet(): void
    {
        $css = $this->css();

        foreach (self::CLASSES as $primitive => $selectors) {
            foreach ($selectors as $selector) {
                $this->assertStringContainsString(
                    $selector,
                    $css,
                    "{$primitive}() menulis kelas yang tidak dikenal app.css ({$selector}); ia tergambar sebagai teks polos.",
                );
            }
        }
    }

    /** E-2: satu implementasi menu. */
    public function test_the_overflow_menu_is_built_on_menu_button(): void
    {
        $body = $this->functionBody($this->spa('ui.js'), 'export function overflowMenu(');

        $this->assertNotNull($body);
        $this->assertStringContainsString("menuButton('', items, { iconName: 'ellipsis'", $body,
            'overflowMenu tidak lagi memanggil menuButton: dua implementasi menu, dua perilaku Esc/klik-luar yang akan hanyut.');
        $this->assertStringNotContainsString("el('.menu-pop'", $body, 'overflowMenu menggambar popup-nya sendiri.');

        // …dan menuButton tahu pemisah + item destruktif, supaya "Hapus…" berdiri sesudah garis.
        $menu = $this->functionBody($this->spa('ui.js'), 'export function menuButton(');
        $this->assertStringContainsString("el('hr.menu-divider', { role: 'separator' })", $menu);
        $this->assertStringContainsString("item.danger ? '.danger' : ''", $menu);

        // UX-E1.9: menu ⋯ baris MENGAMBANG (lolos dari .table-wrap), dan hanya ia —
        // Buat ▾ dan Cetak ▾ tidak meneruskan opsi itu, jadi bawaan menuButton tetap false.
        $this->assertStringContainsString("chevron = true, floating = false } = {}) {", $menu);
        $this->assertStringContainsString("if (floating) pop.classList.add('floating');", $menu);
        $this->assertStringContainsString("window.addEventListener('scroll', onScroll, true);", $menu);
        $from = (int) strpos($menu, '  function close(');
        $close = substr($menu, $from, (int) strpos($menu, "\n  }\n", $from) - $from);
        $this->assertStringContainsString('function close(', $close);
        $this->assertStringContainsString("window.removeEventListener('scroll', onScroll, true);", $close,
            'close() tidak melepas pendengar gulir: setiap menu baris yang pernah dibuka menutup ulang dirinya selamanya.');
        $this->assertStringContainsString("window.removeEventListener('resize', onResize);", $close);
        $this->assertStringContainsString("floating = true } = {}) {", $body);
        // Di atas lembar onboarding ponsel (45), di bawah laci nav (50) — tinjauan E1.9: di bawah lembar,
        // "Hapus…" yang tertutup diketuk dan yang terbuka justru lembarnya.
        $this->assertMatchesRegularExpression('/\.menu-pop\.floating \{[^}]*z-index: 48;/', $this->css());
        $this->assertMatchesRegularExpression('/\.menu-pop \{[^}]*text-align: left;/', $this->css());
        $this->assertStringContainsString('chevron: false, floating });', $body, 'overflowMenu tidak meneruskan `floating` ke menuButton.');
    }

    /** E-3 butir 6: status tidak pernah hanya warna. */
    public function test_the_status_badge_draws_an_icon_beside_the_word(): void
    {
        $body = $this->functionBody($this->spa('ui.js'), 'export function statusBadge(');

        $this->assertNotNull($body);
        $this->assertStringContainsString('fmt.statusIcon(value, enumName)', $body);
        $this->assertStringContainsString('fmt.statusTone(value, enumName)', $body);
        $this->assertStringContainsString('icon(glyph, 12)', $body, 'statusBadge berhenti menggambar ikon: lencana kembali hanya warna.');

        $this->assertStringContainsString('export function statusIcon(value, enumName)', $this->spa('format.js'));
        $this->assertStringContainsString('export function enumIcon(name, value)', $this->spa('enums.js'));
        // Nama ikon yang peta kata bersama kembalikan harus ada di sprite — LauncherWiringTest
        // hanya memindai icon('...') literal, sedangkan statusIcon mengembalikan string.
        preg_match_all("/return '([a-z0-9-]+)';/", $this->functionBody($this->spa('format.js'), 'export function statusIcon('), $names);
        $sprite = $this->spriteSymbols();
        foreach (array_unique($names[1]) as $name) {
            $this->assertArrayHasKey($name, $sprite, "statusIcon mengembalikan '{$name}', yang tidak ada di sprite: lencana itu menggambar kotak kosong.");
        }
    }

    /** README C1 / ERRATA E-3 butir 3: --attn bukan warna status. */
    public function test_the_attention_accent_never_colours_a_badge(): void
    {
        $css = $this->css();

        preg_match_all('/^[^\n{]*\.badge[^\n{]*\{[^}]*--attn/m', $css, $hits);
        $this->assertSame([], $hits[0], '--attn dipakai pada .badge: aksen "perlu tindakan" menjadi warna status.');
        $this->assertStringContainsString('.action-panel.attn { border-color: var(--attn); background: var(--attn-soft); }', $css);
    }

    /** Laci ponsel: satu mekanisme, dipegang ui.drawer() dan dipakai hamburger app.js. */
    public function test_the_hamburger_opens_the_drawer_through_the_primitive(): void
    {
        $app = $this->spa('app.js');

        $this->assertMatchesRegularExpression("/import \{[^}]*\bdrawer\b[^}]*\} from '\.\/ui\.js'/", $app);
        $this->assertStringContainsString('const navDrawer = drawer();', $app);
        $this->assertStringContainsString("if (open) navDrawer.open(); else navDrawer.close();", $app,
            'setDrawer() di app.js menulis body.nav-open sendiri lagi: dua pemilik untuk satu laci.');
    }

    /** Separuh penolakan: pembacanya harus bisa bilang tidak. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertNull($this->functionBody("const x = 1;\n", 'export function nope('));
        $this->assertSame('a b', trim((string) preg_replace('/\s+/', ' ', $this->stripComments("a /* #ffffff */ b // #000\n"))));
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

    private function stripComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~^\s*//.*$~m', '', (string) preg_replace('~(?<=[\s;{}])//[^\n]*~', '', $source));
    }

    /** Badan fungsi bertingkat-atas, dari tanda tangan sampai `}` di kolom 0; null bila tidak ada. */
    private function functionBody(string $source, string $signature): ?string
    {
        $start = strpos($source, $signature);
        if ($start === false) {
            return null;
        }
        $end = strpos($source, "\n}", $start);

        return $end === false ? null : substr($source, $start, $end - $start);
    }

    /** @return array<string, true> */
    private function spriteSymbols(): array
    {
        $sprite = (string) file_get_contents(public_path('app/vendor/lucide@1.41.0/sprite.svg'));
        preg_match_all('/id="lucide-([a-z0-9-]+)"/', $sprite, $matches);

        return array_fill_keys($matches[1], true);
    }
}
