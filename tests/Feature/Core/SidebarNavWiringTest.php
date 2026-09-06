<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Sidebar T2.5 — pemisah di dalam grup panjang dan satu penyaring izin untuk
 * sidebar dan palet Ctrl+K.
 *
 * Diukur 2 Sep 2026 (HASIL-UJI §1, S5): sidebar admin 14 grup / 121 tautan
 * setinggi 4,9 viewport, Proyek dan Keuangan masing-masing 20 tautan rata.
 * Angka tingginya diukur harness (S5, viewportsTall), bukan di sini — tidak
 * ada runtime JS di host ini. Yang dipaku di sini adalah dua hal yang bisa
 * hanyut diam-diam di antara tiga berkas tanpa build step:
 *
 *  - keterangan pemisah yang disepakati RECAP T2.5 (Proyek: Pelaksanaan ·
 *    Serah terima · Izin & K3 · Register; Keuangan: AR/AP · Kas · Pelaporan ·
 *    Pajak · Master), dalam urutan itu, di dalam grupnya sendiri;
 *  - search.js membaca NAV lewat visibleNav() milik schema.js — penyaring
 *    yang sama dengan sidebar. Salinan lokal di search.js akan menawarkan
 *    layar yang barisnya sendiri disembunyikan dari menu begitu salah satu
 *    salinan disunting.
 *
 * Grep, seperti NavRouteRegistryTest: membaca berkas yang sama dengan yang
 * dibaca peninjau, dan tidak bisa hanyut seperti daftar buatan tangan.
 */
class SidebarNavWiringTest extends ErpTestCase
{
    private const DIVIDERS = [
        'Proyek' => ['Pelaksanaan', 'Serah terima', 'Izin & K3', 'Register'],
        'Keuangan' => ['AR/AP', 'Kas', 'Pelaporan', 'Pajak', 'Master'],
    ];

    public function test_the_long_groups_carry_the_agreed_dividers_in_order(): void
    {
        foreach (self::DIVIDERS as $group => $captions) {
            $block = $this->groupBlock($group);

            preg_match_all("/\{ divider: '([^']+)' \}/", $block, $found);

            $this->assertSame(
                $captions,
                $found[1],
                "Grup NAV '{$group}' tidak memuat pemisah T2.5 dalam urutan yang disepakati; 20 tautan rata "
                .'kembali menjadi satu kolom tanpa keterangan.',
            );
        }
    }

    /** Every divider heads a block with at least one real item under it. */
    public function test_no_divider_is_left_over_an_empty_block(): void
    {
        foreach (array_keys(self::DIVIDERS) as $group) {
            $entries = $this->entries($this->groupBlock($group));

            foreach ($entries as $index => $entry) {
                if ($entry[0] !== 'divider') {
                    continue;
                }

                $next = $entries[$index + 1] ?? null;

                $this->assertNotNull($next, "Pemisah '{$entry[1]}' di grup '{$group}' menutup grupnya tanpa satu baris pun.");
                $this->assertSame('route', $next[0],
                    "Pemisah '{$entry[1]}' di grup '{$group}' langsung disusul pemisah lain — keterangan di atas ruang kosong.");
            }
        }
    }

    /** The regrouping under captions moved rows but must not have dropped or invented a route. */
    public function test_regrouping_kept_every_keuangan_and_proyek_route(): void
    {
        $expected = [
            'Proyek' => [
                'r/projects', 'r/projects/daily-reports', 'lapangan', 'r/projects/weekly-progress',
                'r/projects/progress-measurements', 'r/projects/contract-variations', 'evm', 'r/projects/milestones',
                'r/projects/zone-certificates', 'r/projects/bast',
                'r/projects/work-permits', 'r/projects/overtime-permits', 'r/projects/gate-passes',
                'r/projects/safety-incidents', 'r/projects/hse-daily', 'r/projects/risk-register', 'k3',
                'defects', 'varian', 'r/projects/manpower-assignments',
            ],
            'Keuangan' => [
                'r/finance/ar-invoices', 'r/finance/ap-bills', 'r/finance/payments', 'siap-tagih', 'retensi',
                'kas-kecil', 'r/finance/petty-cash-funds', 'bank-recon',
                'r/finance/journals', 'r/finance/project-costs', 'r/finance/revenue-recognition', 'periods', 'reports', 'buku-besar',
                'tax-exports', 'kalender-pajak', 'ekualisasi-pajak',
                'r/finance/accounts', 'r/finance/taxes', 'r/finance/bank-accounts',
            ],
        ];

        foreach ($expected as $group => $routes) {
            $actual = array_values(array_map(
                fn (array $entry) => $entry[1],
                array_filter($this->entries($this->groupBlock($group)), fn (array $entry) => $entry[0] === 'route'),
            ));

            $this->assertSame($routes, $actual, "Rute grup NAV '{$group}' bergeser dari 20 baris yang disepakati T2.5.");
        }
    }

    public function test_the_palette_and_the_sidebar_share_one_permission_filter(): void
    {
        $this->assertStringContainsString('export function visibleNav(can)', $this->file('schema.js'),
            'schema.js tidak lagi mengekspor visibleNav(can); sidebar dan palet kehilangan penyaring bersamanya.');

        foreach (['app.js', 'search.js'] as $consumer) {
            $source = $this->file($consumer);

            $this->assertMatchesRegularExpression(
                "/import \{[^}]*\bvisibleNav\b[^}]*\} from '\.\/schema\.js'/",
                $source,
                "{$consumer} tidak mengimpor visibleNav dari schema.js — penyaring izinnya disalin, bukan dibagi.",
            );
            $this->assertStringNotContainsString('NAV.map(', $source,
                "{$consumer} menyaring NAV sendiri; dua salinan penyaring izin akan hanyut satu sama lain.");
        }

        $this->assertStringContainsString("label: 'Layar'", $this->file('search.js'),
            'search.js tidak lagi menggambar grup "Layar"; Ctrl+K kembali hanya mencari dokumen.');
    }

    /**
     * P1-B: the module accent is one attribute (data-accent="1..8") resolved by
     * app.css into --accent-<slot>. Three files must agree without a build
     * step: every MODULES entry names a slot 1..8, and app.css defines
     * --accent-<n>, --accent-<n>-soft and --accent-<n>-fg for each slot in all
     * four theme blocks (light root, dark media, data-theme light, data-theme
     * dark) PLUS the @media print token block (verifikasi P1-B 5 Sep 2026: a
     * dark-theme user printed --accent-7 #fbcd1a on white paper, 1,52:1) — a
     * slot missing from one block is an accent that silently falls back to
     * nothing in that theme or on paper. The mapping table itself (which group is
     * in which slot) lives in CONVENTIONS § Aksen modul and the app.css token
     * comment; the numbers there are measured by harness S21, not pinned here.
     */
    public function test_every_module_accent_slot_has_its_three_tokens_in_all_five_token_blocks(): void
    {
        preg_match_all('/^  [a-z]+: \{ accent: ([1-8]),/m', $this->file('schema.js'), $slots);

        // One MODULES entry per NAV group — counted against the group headers
        // themselves (with or without a prefix), not "> 10": verifikasi P1-B
        // 5 Sep 2026 showed a lower bound lets one group lose its slot unnoticed.
        preg_match_all("/^    label: '[^']+', perm: [^,]+(?:, prefix: '[a-z]+')?,$/m", $this->navBlock(), $groups);
        $this->assertGreaterThan(10, count($groups[0]), 'The NAV group header shape has changed; this test no longer reads it.');
        $this->assertCount(count($groups[0]), $slots[1],
            sprintf('schema.js MODULES lists %d accent slots for %d NAV groups.', count($slots[1]), count($groups[0])));

        $used = array_values(array_unique(array_map('intval', $slots[1])));
        sort($used);
        $this->assertSame(range(1, 8), $used,
            'Not every accent slot 1..8 is used by a module — a slot nobody uses is a colour nobody validated in context.');

        $css = (string) file_get_contents(public_path('app/app.css'));

        foreach (range(1, 8) as $slot) {
            foreach (["--accent-{$slot}:", "--accent-{$slot}-soft:", "--accent-{$slot}-fg:"] as $token) {
                $this->assertSame(5, preg_match_all('/'.preg_quote($token, '/').'\s*#[0-9a-f]{6};/', $css),
                    "app.css must define {$token} exactly once in each of the four theme blocks and once in the @media print block.");
            }
            $this->assertStringContainsString("[data-accent=\"{$slot}\"] { --module-accent: var(--accent-{$slot});", $css,
                "app.css has no [data-accent=\"{$slot}\"] rule; the sidebar marker and crumb for slot {$slot} carry no colour.");
        }

        $this->assertStringContainsString('## 12. Aksen modul', (string) file_get_contents(base_path('docs/CONVENTIONS.md')),
            'CONVENTIONS.md lost § Aksen modul — the group → slot mapping table has no home.');
    }

    /**
     * P1-B putaran 2 — remah roti di ponsel tidak boleh kosong.
     *
     * Aturan ≤ 760 px app.css menyembunyikan penanda akar (<span>), chevron dan
     * remah layar sekaligus, dengan andaian setiap rantai berakar pada nama grup
     * NAV dan karenanya menyisakan a.crumb-module. Tujuh layar tidak: RESOURCES
     * yang tidak ada di NAV berakar penanda 'ERP' milik groupLabelFor(), dan di
     * ponsel header-nya jadi KOSONG (diukur 6 Sep 2026: anak ['SPAN','svg','B'],
     * teks tampak '', tinggi remah 0 px di header 56 px, pada #/r/projects/baselines,
     * #/r/projects/defects, #/r/inventory/issue-returns, #/r/inventory/purchase-returns,
     * #/r/hr/certificates, #/r/finance/petty-cash-vouchers, #/r/finance/kasbon).
     *
     * Yang dipaku di sini adalah kontraknya, bukan pikselnya (piksel = harness S21
     * crumb_walk, 131 rute): pembuat remah menuliskan bentuk rantai ke
     * #crumbs[data-root], setiap aturan penyembunyi ponsel MENYEBUT data-root itu,
     * dan hanya ADA SATU pembuat remah — salinan kedua (dulu views/kaskecil.js)
     * tidak akan menulis data-root dan diam-diam jatuh ke perilaku ketiga.
     */
    public function test_the_mobile_breadcrumb_rules_are_scoped_to_the_chain_shape(): void
    {
        $crumbs = $this->file('crumbs.js');

        $this->assertStringContainsString('export function setCrumbs(', $crumbs,
            'public/app/js/crumbs.js no longer exports setCrumbs — the single breadcrumb builder is gone.');
        $this->assertStringContainsString("host.dataset.root = module ? 'module' : 'screen';", $crumbs,
            'setCrumbs no longer writes #crumbs[data-root]; the ≤ 760 px rules cannot tell a module-rooted chain '
            .'from one rooted on the "ERP" placeholder, and the seven screens outside NAV go back to an empty header.');

        // Satu pembuat: siapa pun yang memegang #crumbs sendiri merakit bentuk lain. Menimpa TEKS remah
        // terakhir (document.querySelector('#crumbs b') di detail.js/custom.js/kaskecil.js) tetap boleh —
        // yang dilarang adalah mengambil host-nya dan mengisinya sendiri, seperti crumbs() lama kaskecil.js.
        $builders = [];
        foreach ($this->spaScripts() as $path => $source) {
            if (str_contains($source, "getElementById('crumbs')")) {
                $builders[] = $path;
            }
        }
        $this->assertSame(['js/crumbs.js'], $builders,
            'More than one file builds the breadcrumb: '.implode(', ', $builders).'. A second builder writes no '
            .'data-root and no aria-current, so the mobile rules and the screen reader both see a shape they do not know.');

        $css = (string) file_get_contents(public_path('app/app.css'));

        // Penyembunyi remah layar/dokumen HARUS bersyarat data-root="module".
        foreach (['a.crumb-screen { display: none; }', '> b:not(:first-child) { position: absolute;'] as $rule) {
            preg_match_all('/^\s*(\S[^\n]*?'.preg_quote($rule, '/').')/m', $css, $found);
            $this->assertNotEmpty($found[1], "app.css lost the mobile rule '{$rule}'.");
            foreach ($found[1] as $line) {
                $this->assertStringContainsString('[data-root="module"]', $line,
                    "app.css hides the screen crumb unconditionally ({$rule}); on a chain without a module crumb "
                    .'that leaves the mobile header blank.');
            }
        }

        // ...dan rantai tanpa remah modul harus punya aturan yang MENAMPILKAN remah layarnya.
        $this->assertMatchesRegularExpression('/\[data-root="screen"\][^\n]*(a\.crumb-screen|> b)[^\n]*\n?[^\n]*text-overflow: ellipsis/', $css,
            'app.css has no [data-root="screen"] rule keeping the screen crumb visible (ellipsised) on mobile.');
    }

    /**
     * Setiap layar r/<key> berakar pada MODULnya, dan tidak ada view yang
     * mengeja label modul itu sendiri.
     *
     * Tujuh resource tidak ada di sidebar (dibuka dari layar lain): lima di
     * schema.js — projects/baselines, projects/defects, inventory/issue-returns,
     * inventory/purchase-returns, hr/certificates — dan dua didaftarkan
     * kaskecil.js saat modulnya dimuat (finance/petty-cash-vouchers,
     * finance/kasbon). Sampai 6 Sep 2026 groupLabelFor() mengembalikan penanda
     * 'ERP' untuk ketujuhnya, jadi crumbs.js menandai daftarnya
     * data-root='screen' sementara HALAMAN DOKUMEN kasbon mengeja 'Keuangan'
     * sendiri dan menjadi data-root='module': daftar dan dokumennya berganti
     * subjek di header ponsel (diukur 390×844: '#/r/finance/kasbon' → 'Kasbon',
     * '#/d/finance/kasbon/1' → 'Keuangan').
     *
     * Dua syarat yang menutupnya, keduanya bisa hanyut tanpa build step:
     * modul setiap resource harus prefix grup NAV yang nyata (kalau tidak,
     * groupLabelFor jatuh ke 'ERP' lagi), dan tidak ada view yang boleh
     * menuliskan label grup sebagai remah pertama — label itu hanya ada di NAV.
     */
    public function test_every_resource_screen_is_rooted_in_a_real_module(): void
    {
        $prefixes = $this->navPrefixes();
        $this->assertGreaterThanOrEqual(14, count($prefixes),
            'Only '.count($prefixes).' NAV group prefixes were read from schema.js; the reader lost the shape.');

        $resources = $this->resourceModules();
        $this->assertGreaterThan(90, count($resources),
            'Only '.count($resources).' RESOURCES entries were read; the reader lost the table.');

        // Layar yang ADA di sidebar berakar pada grup NAV-nya, apa pun prefix izinnya
        // (core/notification-deliveries memakai izin core.* di dalam grup Sistem/iam —
        // sah, dan remahnya tetap 'Sistem'). Yang harus punya modul sungguhan adalah
        // layar di LUAR sidebar: hanya modul itu yang tersisa sebagai akar.
        $navRoutes = $this->navRoutes();
        $outsideNav = array_filter($resources, fn ($module, $key) => ! in_array('r/'.$key, $navRoutes, true),
            ARRAY_FILTER_USE_BOTH);

        $this->assertNotEmpty($outsideNav, 'The reader found no resource outside NAV; the extraction is broken.');

        foreach ($outsideNav as $key => $module) {
            $this->assertContains($module, $prefixes,
                "RESOURCES['{$key}'] is not in the sidebar and belongs to module '{$module}', which is not a NAV "
                ."group prefix. groupLabelFor() then falls back to the 'ERP' placeholder and the screen's "
                .'breadcrumb roots on nothing — while its document page keeps whatever root its own view wrote.');
        }

        // Ketujuh layar di luar NAV memang ada — kalau daftarnya menyusut, hilangnya bukan karena
        // uji ini melewatkannya.
        foreach (['projects/baselines', 'projects/defects', 'inventory/issue-returns',
            'inventory/purchase-returns', 'hr/certificates', 'finance/petty-cash-vouchers',
            'finance/kasbon'] as $outside) {
            $this->assertArrayHasKey($outside, $resources, "RESOURCES['{$outside}'] is gone from the SPA.");
        }

        $labels = $this->navGroupLabels();
        foreach ($this->spaScripts() as $path => $source) {
            if ($path === 'js/app.js') {
                continue;   // layar khusus non-resource merakit rantainya sendiri di satu tempat
            }
            preg_match_all("/setCrumbs\(\s*\[\s*'([^']+)'/", $source, $found);
            foreach ($found[1] as $first) {
                $this->assertNotContains($first, $labels,
                    "{$path} spells the module label '{$first}' into setCrumbs(). The label lives in NAV only "
                    .'(moduleFor(prefix).label); a second copy drifts the moment a group is renamed, and that is '
                    .'exactly how the kasbon list and its document page came to disagree.');
            }
        }
    }

    /** The refused half: the readers say no to a caption and a route that do not exist. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertStringNotContainsString("{ divider: 'Pemisah Yang Tidak Ada' }", $this->groupBlock('Proyek'));
        $this->assertStringNotContainsString("route: 'r/projects/tabel-yang-tidak-ada'", $this->groupBlock('Proyek'));

        // ...and a group block really is one group: Keuangan's captions never leak into Proyek.
        $this->assertStringNotContainsString("{ divider: 'AR/AP' }", $this->groupBlock('Proyek'));
        $this->assertStringContainsString("{ divider: 'AR/AP' }", $this->groupBlock('Keuangan'));
    }

    /**
     * NAV entries of one group in source order, as [kind, value] pairs —
     * kind is 'divider' (caption) or 'route'.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function entries(string $block): array
    {
        preg_match_all("/\{ divider: '([^']+)' \}|route: '([^']+)'/", $block, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match) => isset($match[2]) && $match[2] !== '' ? ['route', $match[2]] : ['divider', $match[1]],
            $matches,
        );
    }

    /** The `items: [...]` block of one NAV group, found by its label inside the NAV block only. */
    private function groupBlock(string $label): string
    {
        $nav = $this->navBlock();
        $start = strpos($nav, "label: '{$label}', perm:");

        $this->assertNotFalse($start, "Grup NAV '{$label}' tidak ditemukan di schema.js.");

        $items = strpos($nav, 'items: [', $start);
        $end = strpos($nav, "\n    ],", $items);

        $this->assertNotFalse($end, "Blok items grup NAV '{$label}' tidak ditutup seperti grup lainnya.");

        return substr($nav, $items, $end - $items);
    }

    /** Only the NAV block, so a RESOURCES key is never mistaken for a menu entry. */
    private function navBlock(): string
    {
        $schema = $this->file('schema.js');
        $start = strpos($schema, 'export const NAV = [');

        $this->assertNotFalse($start, 'NAV could not be found in schema.js; this test can no longer check anything.');

        return substr($schema, $start);
    }

    /** @return list<string> prefix setiap grup NAV. */
    private function navPrefixes(): array
    {
        preg_match_all("/prefix: '([a-z]+)'/", $this->navBlock(), $found);

        return array_values(array_unique($found[1]));
    }

    /** @return list<string> rute r/… yang ada di sidebar. */
    private function navRoutes(): array
    {
        preg_match_all("/route: '(r\/[^']+)'/", $this->navBlock(), $found);

        return array_values(array_unique($found[1]));
    }

    /** @return list<string> label setiap grup NAV. */
    private function navGroupLabels(): array
    {
        preg_match_all("/label: '([^']+)', perm: /", $this->navBlock(), $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * Setiap entri RESOURCES → prefix modulnya: tabel schema.js DAN entri yang
     * didaftarkan view saat dimuat (kaskecil.js menambah tiga).
     *
     * @return array<string, string>
     */
    private function resourceModules(): array
    {
        $out = [];

        $schema = $this->file('schema.js');
        $table = substr($schema, (int) strpos($schema, 'export const RESOURCES'));
        preg_match_all("/\n  '([^']+)': \{(.{0,400}?)module: '([a-z]+)'/s", $table, $found, PREG_SET_ORDER);
        foreach ($found as $entry) {
            $out[$entry[1]] = $entry[3];
        }

        foreach ($this->spaScripts() as $source) {
            preg_match_all("/RESOURCES\['([^']+)'\] = \{(.{0,400}?)module: '([a-z]+)'/s", $source, $extra, PREG_SET_ORDER);
            foreach ($extra as $entry) {
                $out[$entry[1]] = $entry[3];
            }
        }

        return $out;
    }

    private function file(string $relative): string
    {
        $path = public_path('app/js/'.$relative);

        $this->assertFileExists($path, "public/app/js/{$relative} is missing.");

        return (string) file_get_contents($path);
    }

    /**
     * Every SPA script, keyed by its path relative to public/app/ — the whole
     * tree, so a builder added in a new view file is read too.
     *
     * @return array<string, string>
     */
    private function spaScripts(): array
    {
        $root = public_path('app');
        $files = [];

        foreach (['js/*.js', 'js/views/*.js'] as $glob) {
            foreach (glob($root.'/'.$glob) ?: [] as $path) {
                $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
            }
        }

        $this->assertGreaterThan(30, count($files), 'Only '.count($files).' SPA scripts were found; the reader lost the tree.');

        return $files;
    }
}
