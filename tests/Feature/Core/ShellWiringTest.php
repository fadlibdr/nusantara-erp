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

    /* --------------------------------------------------------------- (e) */

    /** Buat ▾ (UX-D.4): tombol Tambah yang dikumpulkan — predikatnya SATU dengan daftar. */
    public function test_the_create_menu_and_the_list_share_one_create_predicate(): void
    {
        $create = $this->spa('create.js');
        $this->assertStringContainsString('export function canCreateResource(def) {', $create);
        $this->assertStringContainsString("session.can(def.createPerm || `\${def.module}.create`)", $create);
        $this->assertStringContainsString(".filter(([, def]) => canCreateResource(def) && canView(def))", $create,
            'Buat ▾ menawarkan formulir untuk daftar yang tidak boleh dibuka pembacanya.');

        $list = $this->spa('views/list.js');
        $this->assertStringContainsString('const canCreate = canCreateResource(def);', $list,
            'list.js menulis predikat Tambah-nya sendiri lagi: Buat ▾ dan tombol Tambah bisa berselisih.');
        $this->assertStringNotContainsString('def.canCreate !== false && Boolean(def.form)', $list);

        $app = $this->spa('app.js');
        $this->assertStringContainsString("menuButton('Buat', createMenuItems,", $app);
        $this->assertStringContainsString('wrap.hidden = creatable().length === 0;', $app,
            'Buat ▾ tergambar bagi pembaca yang tidak boleh membuat apa pun.');
        $this->assertStringContainsString('startCreate', $app);
        $this->assertStringContainsString("return startForm({", $this->functionBody($create, 'export function startCreate(') ?? '',
            'Buat ▾ membuka formulirnya lewat jalur lain dari tombol Tambah (halaman vs dialog bisa berselisih).');
    }

    /** Tema pindah ke dialog Akun (UX-D.4); bilah atas tidak lagi membawa tombolnya. */
    public function test_the_theme_lives_in_the_account_dialog(): void
    {
        $app = $this->spa('app.js');
        $this->assertStringNotContainsString("title: 'Ganti tema'", $app);
        $this->assertStringNotContainsString('function cycleTheme(', $app);
        $menu = $this->functionBody($app, 'function openUserMenu(user) {');
        $this->assertNotNull($menu);
        $this->assertStringContainsString('themeControl(),', $menu);
        $this->assertStringContainsString('.theme-pick .check-row', $this->css(),
            'Pilihan tema tidak berbagi bentuk (dan sasaran sentuh 40 px) dengan Kepadatan.');
    }

    /* --------------------------------------------------------------- (f) */

    /** Bilah bawah (UX-D.5): dari baris tetap NAV, laci yang sama, di bawah lembar dan laci. */
    public function test_the_bottom_bar_is_built_from_nav_and_never_covers_the_drawer_or_the_sheet(): void
    {
        $app = $this->spa('app.js');
        $items = $this->functionBody($app, 'function bottomNavItems() {');
        $this->assertNotNull($items, 'bottomNavItems() hilang dari app.js.');
        $this->assertStringContainsString('navForSession().flatMap((group) => group.items.filter((item) => item.pinned))', $items,
            'Bilah bawah tidak lagi membaca baris tetap NAV: ia menulis daftar (dan izin) sendiri.');
        $this->assertStringNotContainsString('ANY_APPROVE', $items);
        $this->assertStringContainsString("shellDrawer.toggle()", $items, 'Menu bilah bawah membuka laci lain dari hamburger.');
        $this->assertStringContainsString("'data-drawer-toggle': ''", $items);
        $this->assertStringContainsString("closest('.menu-toggle, [data-drawer-toggle]')", $app,
            'Sentuhan pada tombol Menu bilah bawah dianggap "di luar laci" dan menutupnya lagi.');
        $this->assertStringContainsString("document.querySelectorAll('.menu-toggle, [data-drawer-toggle]')", $this->spa('ui.js'));

        $css = $this->css();
        $start = strpos($css, '/* ================================================== bilah bawah UX-D.5 */');
        $this->assertNotFalse($start);
        $block = substr($css, $start);
        $this->assertMatchesRegularExpression('/\.bottom-nav \{\s*position: fixed; left: 0; right: 0; bottom: 0; z-index: 20;/', $block);
        // 20 < kain laci 40 < lembar onboarding 45 < laci 50 — angka-angka itu dibaca dari aturannya sendiri.
        $this->assertStringContainsString('content: ""; position: fixed; inset: var(--header-h) 0 0 0; z-index: 40;', $css);
        $this->assertMatchesRegularExpression('/\.onboarding-dock\[data-mode="mobile"\] \{\s*top: auto; left: 0; right: 0; bottom: 0; z-index: 45;/', $css);
        $this->assertStringContainsString('position: fixed; inset: var(--header-h) auto 0 0; z-index: 50;', $css);
        $this->assertStringContainsString('.onboarding-dock[data-mode="mobile"] { bottom: calc(var(--bottom-nav-h) + env(safe-area-inset-bottom)); padding-bottom: 0; }', $block,
            'Lembar onboarding tidak lagi berdiri di atas bilah bawah: salah satunya menutupi yang lain.');
        $this->assertStringContainsString('.toasts { bottom: calc(var(--bottom-nav-h) + 12px + env(safe-area-inset-bottom)); }', $block);
        $this->assertStringContainsString('@media print { .bottom-nav { display: none !important; } }', $block);
    }

    /* --------------------------------------------------------------- (g) */

    /** Rel ikon (UX-D.6, ERRATA E-4): milik perangkat, tablet selalu rel, label tidak pernah hilang. */
    public function test_the_icon_rail_is_per_device_always_on_tablets_and_keeps_every_label_accessible(): void
    {
        $app = $this->spa('app.js');
        $this->assertStringContainsString("const RAIL_KEY = 'nusantara_erp_nav_rail';", $app);
        $this->assertStringContainsString("localStorage.setItem(RAIL_KEY,", $app);
        $this->assertStringNotContainsString("prefs.set('nav.rail'", $app, 'Keadaan rel menjadi preferensi server; E-4 menuntut per perangkat.');
        $this->assertStringNotContainsString("'nav.rail'", (string) file_get_contents(base_path('Modules/Core/Support/UserPreferences.php')),
            'UserPreferences::keys() mendapat kunci rel — E-4: tidak ada perubahan pada daftar kunci server.');
        $this->assertStringContainsString("window.matchMedia('(min-width: 761px) and (max-width: 1023px)')", $app);
        $this->assertStringContainsString('return tabletQuery.matches || (wideQuery.matches && railChosen());', $app,
            'Tablet tidak lagi selalu rel, atau desktop menjadi rel tanpa dipilih.');

        // Di rel, ikon grup modul membuka beranda modulnya; grup pintasan membuka menu lengkap.
        $group = $this->functionBody($app, 'function navGroupNode(nav, group, favorites, stored) {');
        $this->assertNotNull($group);
        $this->assertStringContainsString("if (group.prefix) { navigate(`m/\${group.prefix}`); return; }", $group);
        $this->assertStringContainsString('shellDrawer.open();', $group);

        $css = $this->css();
        $start = strpos($css, '/* ===================================================== rel ikon UX-D.6 */');
        $this->assertNotFalse($start, 'Blok CSS rel ikon hilang.');
        $rail = substr($css, $start);
        $this->assertStringContainsString('body.rail .shell { grid-template-columns: var(--rail-w) minmax(0, 1fr); }', $rail);
        // Label disembunyikan SECARA VISUAL (klip), tidak pernah display:none: ia nama aksesibelnya.
        $this->assertMatchesRegularExpression('/\.nav-group-label,\s*body\.rail:not\(\.nav-open\) \.nav-pinned \.lbl \{\s*position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect\(0 0 0 0\);/', $rail);
        $this->assertDoesNotMatchRegularExpression('/(\.nav-group-label|\.lbl)[^{]*\{[^}]*display: none/', $rail,
            'Rel ikon menyembunyikan label dengan display:none: tautannya kehilangan nama aksesibel.');
        $this->assertStringContainsString("node.querySelector('a').title = item.label;", $app, 'Baris tetap di rel tanpa tooltip.');
        $this->assertStringContainsString("groupNode.querySelector('button').title = group.label;", $app, 'Ikon grup di rel tanpa tooltip.');
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
