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

    private function file(string $relative): string
    {
        $path = public_path('app/js/'.$relative);

        $this->assertFileExists($path, "public/app/js/{$relative} is missing.");

        return (string) file_get_contents($path);
    }
}
