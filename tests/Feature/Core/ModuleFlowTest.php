<?php

namespace Tests\Feature\Core;

use Modules\Core\Support\ModuleCounts;
use Modules\Core\Support\SpaNav;
use Tests\ErpTestCase;

/**
 * Beranda modul sebagai halaman proses (views/module.js + FLOWS di schema.js, UX-F2.3).
 *
 * ERRATA E-8: angka tahap hanya dari kunci yang terdaftar di ModuleCounts; tahap tanpa
 * kunci tidak menampilkan angka, kunci yang gagal dimuat menulis '—'. Peta itu DATA yang
 * dibaca views/module.js; di sini dipaku bahwa datanya tidak berbohong (rute NAV nyata,
 * kunci nyata milik modul itu sendiri) dan bahwa penggambarnya jujur. Tidak ada runtime
 * JS di host ini, jadi yang dipaku adalah SUMBERNYA.
 */
class ModuleFlowTest extends ErpTestCase
{
    /** Peta berisi hanya rute NAV nyata dan kunci ModuleCounts milik modul sendiri. */
    public function test_flows_are_data_over_real_routes_and_the_modules_own_registered_key(): void
    {
        $schema = $this->spa('schema.js');
        $block = $this->flowsBlock($schema);
        $this->assertNotSame('', $block, 'export const FLOWS tidak ditemukan di schema.js.');

        // Sebelum NAV: pemindai rute NAV (NavRouteRegistryTest, SpaNav) tidak boleh menghitung rutenya.
        $this->assertLessThan(strpos($schema, 'export const NAV = ['), strpos($schema, 'export const FLOWS = {'));
        // Dan bukan di dalam MODULES: tiap barisnya dipaku bentuknya (NavRouteRegistryTest).
        $from = (int) strpos($schema, 'export const MODULES = {');
        $modules = substr($schema, $from, (int) strpos($schema, "\n};", $from) - $from);
        $this->assertStringNotContainsString('steps:', $modules);
        $this->assertStringNotContainsString('flow:', $modules);

        preg_match_all("/^  ([a-z]+): \{$/m", $block, $keys);
        $this->assertNotEmpty($keys[1]);
        foreach ($keys[1] as $key) {
            $this->assertContains($key, SpaNav::prefixes(), "FLOWS.{$key} bukan prefix grup NAV.");
        }

        preg_match_all("/route: '([^']+)'/", $block, $routes);
        foreach ($routes[1] as $route) {
            $this->assertContains($route, SpaNav::routes(), "Langkah proses menunjuk rute yang bukan baris NAV: {$route}");
        }

        // Tahap bernomor: kuncinya ADA di registri dan milik modul peta itu sendiri (§16, satu entri per prefix).
        $registry = array_map(fn (array $entry): string => (string) ($entry['key'] ?? ''), array_values(ModuleCounts::entries()));
        $registryKeys = array_keys(ModuleCounts::entries());
        foreach ($this->flowsByKey($block) as $key => $body) {
            preg_match_all("/count: '([^']+)'/", $body, $counts);
            foreach ($counts[1] as $count) {
                $this->assertContains($count, $registryKeys, "FLOWS.{$key} menyebut kunci ModuleCounts yang tidak ada: {$count}");
                $this->assertSame($key, $count, "FLOWS.{$key} meminjam kunci modul lain ({$count}); satu entri per prefix, tahap lain tanpa angka.");
            }
        }
        unset($registry);

        $this->assertStringContainsString('flow: FLOWS[prefix] || null', (string) $this->functionBody($schema, 'export function moduleFor(prefix) {'));
        $this->assertStringContainsString('urutan lazim', mb_strtolower($block) . mb_strtolower($schema), 'Peta tidak lagi menyebut dirinya urutan lazim, bukan wajib (E-8).');
    }

    /** Pengadaan membawa enam tahap, tanpa "Evaluasi", dan angka hanya di PO. */
    public function test_procurement_strip_is_the_six_stages_and_only_po_carries_a_number(): void
    {
        $block = $this->flowsBlock($this->spa('schema.js'));
        $prc = $this->flowsByKey($block)['prc'] ?? '';
        preg_match_all("/label: '([^']+)'/", $prc, $labels);
        $this->assertSame(
            ['Permintaan (PR)', 'RFQ', 'BA Negosiasi', 'Keputusan Pemenang', 'Pesanan (PO)', 'Penerimaan (GRN)'],
            $labels[1],
        );
        $this->assertSame(1, preg_match_all("/count: '/", $prc), 'Hanya satu tahap Pengadaan yang punya kunci terdaftar (PO terbuka).');
        $this->assertMatchesRegularExpression("/label: 'Pesanan \\(PO\\)', route: '[^']+', count: 'prc'/", $prc);
        $this->assertStringContainsString("'prc' =>", (string) file_get_contents(base_path('Modules/Core/Support/ModuleCounts.php')));
    }

    /** Penggambar: tahap tanpa kunci tanpa angka, kunci gagal '—', tautan hanya bila layarnya boleh dibuka. */
    public function test_the_strip_never_invents_a_number_and_links_only_what_the_reader_may_open(): void
    {
        $module = $this->withoutComments($this->spa('views/module.js'));
        $flow = (string) $this->functionBody($module, 'function paintFlow(host, module, payload) {');
        $this->assertNotSame('', $flow);

        $this->assertStringContainsString('let count;', $flow, 'count harus mulai undefined (tanpa angka), bukan 0.');
        $this->assertStringContainsString('if (step.count && payload === null) {', $flow);
        $this->assertMatchesRegularExpression('/if \(step\.count && payload === null\) \{\s*count = null;/', $flow, 'Permintaan gagal tidak lagi menjadi null → "—" pada tahap bernomor.');
        $this->assertStringContainsString('entry.count === null || entry.count === undefined ? null : entry.count', $flow);
        $this->assertStringContainsString('open.has(step.route) ? `#/${step.route}` : undefined', $flow, 'Tahap yang tidak boleh dibuka masih bertautan.');
        $this->assertStringContainsString("visibleNav((perm) => session.can(perm))", $flow, 'Tautan tahap tidak memakai penyaring visibleNav().');
        foreach (['count || 0', 'count ?? 0', 'count) || 0', 'count : 0', '?? 0'] as $bad) {
            $this->assertStringNotContainsString($bad, $module, "Pola terlarang di module.js: {$bad}");
        }
        // Jawaban angka yang tiba memperbarui angkanya DI TEMPAT: mengganti stepper membuang fokus papan ketik.
        $this->assertStringNotContainsString('host.replaceChildren()', $flow);
        $this->assertStringContainsString("node.textContent = step.count === null ? '—' : String(step.count);", $flow);
        $this->assertStringContainsString('if (module.flow) paintFlow(flowHost, module, undefined);', $module);
        $this->assertStringContainsString('if (module.flow && flowHost.isConnected) paintFlow(flowHost, module, payload);', $module);
        $this->assertStringContainsString("import { el, svgIcon, icon, emptyState, stepper } from '../ui.js';", $module);
    }

    /** Perlu perhatian lewat pemuat bersama, dua kegagalan terpisah, 'done' hanya bila keduanya menjawab. */
    public function test_attention_reads_the_shared_loaders_and_is_never_done_on_failure(): void
    {
        $module = $this->withoutComments($this->spa('views/module.js'));

        $this->assertStringContainsString('loadDeadlines({ maxAge: DEADLINES_MAX_AGE })', $module);
        $this->assertStringContainsString('loadInbox()', $module);
        $this->assertStringNotContainsString("'core/deadlines'", $module);
        $this->assertStringNotContainsString("'core/inbox'", $module);
        $this->assertStringNotContainsString('.catch(() =>', $module);

        $paint = (string) $this->functionBody($module, 'function paintAttention(content, state, prefix, moduleLabel) {');
        $this->assertNotSame('', $paint);
        $this->assertStringContainsString("finding.tier !== 'tanpa_tanggal'", $paint, 'Temuan tanpa tanggal bukan "perlu perhatian".');
        $this->assertStringContainsString('groupPrefixFor(row.resource) === prefix', $paint, 'Baris persetujuan tidak lagi disaring ke modul ini.');
        $this->assertStringContainsString('prefixOfLink(finding.link) === prefix', $paint);
        // Baris utuh, bukan kehadiran kata-katanya (tinjauan UX-F2: tujuh mutasi satu baris lolos dari pin teks).
        $this->assertStringContainsString('const settled = state.inbox !== undefined && state.due !== undefined;', $paint);
        $this->assertStringContainsString('if (settled && !rows.length && !notes.length) {', $paint);
        $this->assertSame(1, substr_count($paint, "kind: 'done'"));
        foreach ([
            "if (state.inbox === 'failed') notes.push('Persetujuan tidak dapat dimuat sekarang.');",
            "if (state.due === 'failed') notes.push('Tenggat tidak dapat dimuat sekarang.');",
            "if (inboxMeta.failed && inboxMeta.failed.length) notes.push(",
            "if (dueMeta.skipped > 0) notes.push(",
            "if (undated > 0) notes.push(",
        ] as $line) {
            $this->assertStringContainsString($line, $paint, "Baris kegagalan hilang atau berubah: {$line}");
        }
        $this->assertStringContainsString('Daftar persetujuan ini belum lengkap', $paint);

        // Tautan temuan dengan saringan (`r/crm/activities?state=open`) tetap sampai ke modulnya.
        $this->assertStringContainsString(".split('?')[0]", (string) $this->functionBody($module, 'function prefixOfLink(link) {'));
        // Grup Ringkasan tidak memiliki dokumen/tautan tenggat: saringan per modul selalu "kosong" di sana.
        $this->assertStringContainsString("if (prefix !== 'ringkasan') host.appendChild(attentionSection(prefix, module.label));", $module);
        $this->assertStringContainsString('loadDeadlines({ maxAge: DEADLINES_MAX_AGE })', $module);

        // Judul bagian baru memakai kelasnya sendiri: `.module-section` berarti "melabeli kisinya" (S21).
        $this->assertStringContainsString("'Perlu perhatian'", $module);
        $this->assertStringContainsString('`Semua layar ${module.label}`', $module);
        $this->assertStringContainsString('`Alur ${module.label}`', $module);
        $this->assertSame(0, preg_match_all('/h2\.module-section\b[^{]*(?:Perlu perhatian|Semua layar|Alur )/', $module));
    }

    /** Setiap tautan temuan tenggat dari server menunjuk rute NAV nyata atau `m/<prefix>` — termasuk yang bersaringan. */
    public function test_every_deadline_link_resolves_to_a_nav_route_or_a_module_page(): void
    {
        $source = (string) file_get_contents(base_path('Modules/Core/Support/WatchedDeadlines.php'));
        preg_match_all("/'link'\\s*=>\\s*'([^']+)'/", $source, $m);
        $this->assertGreaterThan(15, count($m[1]), 'Pembaca tautan WatchedDeadlines tidak menemukan entri.');
        // `r/<kunci>` yang bukan baris NAV tetap sampai ke modulnya lewat RESOURCES[kunci].module (groupPrefixFor).
        preg_match_all('/^  \'([a-z0-9\-\/]+)\': \{$/m', $this->spa('schema.js'), $keys);
        $unresolved = [];
        foreach (array_unique($m[1]) as $link) {
            $path = explode('?', $link)[0];
            $ok = in_array($path, SpaNav::routes(), true)
                || (str_starts_with($path, 'r/') && in_array(substr($path, 2), $keys[1], true))
                || (str_starts_with($path, 'm/') && in_array(substr($path, 2), SpaNav::prefixes(), true));
            if (! $ok) {
                $unresolved[] = $link;
            }
        }
        $this->assertSame([], $unresolved, 'Tautan tenggat yang tidak bisa dikaitkan module.js ke modulnya (prefixOfLink): beranda modul akan menulis "tidak ada yang perlu perhatian".');
    }

    /** Bintang kartu beranda modul akhirnya bergaya: ukuran, reset, dan keadaan `.on`. */
    public function test_the_module_star_is_styled_and_the_new_blocks_are_tokens_only(): void
    {
        $css = (string) file_get_contents(public_path('app/app.css'));
        $this->assertStringContainsString('.module-cell > .star.on { color: var(--primary); background: var(--primary-soft); }', $css);
        $this->assertStringContainsString('.module-cell > .star:hover, .module-cell > .star:focus-visible', $css);

        $start = (int) strpos($css, '/* UX-F2.3 — beranda modul sebagai halaman proses');
        $this->assertGreaterThan(0, $start);
        $block = (string) preg_replace('~/\*.*?\*/~s', '', substr($css, $start));
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $block, 'Literal warna di blok beranda modul.');
        $this->assertDoesNotMatchRegularExpression('/data-accent|--module-accent|--accent-/', $block, 'Aksen modul hanya di kepala (CONVENTIONS §12).');
        $this->assertSame(0, preg_match_all('/style:\s*\{/', $this->spa('views/module.js')));
    }

    private function spa(string $relative): string
    {
        return (string) file_get_contents(public_path("app/js/{$relative}"));
    }

    private function flowsBlock(string $schema): string
    {
        $start = strpos($schema, 'export const FLOWS = {');
        if ($start === false) {
            return '';
        }

        return substr($schema, $start, (int) strpos($schema, "\n};", $start) - $start);
    }

    /** @return array<string, string> */
    private function flowsByKey(string $block): array
    {
        preg_match_all("/^  ([a-z]+): \{\n(.*?)\n  \},?$/ms", $block, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $one) {
            $out[$one[1]] = $one[2];
        }

        return $out;
    }

    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~(?<!:)//[^\n]*~', '', $source);
    }

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
