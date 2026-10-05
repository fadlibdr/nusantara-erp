<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Beranda sebagai ruang kerja (views/home.js, js/deadlines.js, UX-F2.2) — kabelnya.
 *
 * LauncherWiringTest memaku ubin, kotak cari dan aturan "— bukan 0"; berkas ini
 * memaku bagian yang ditambahkan UX-F2 di atasnya: Perlu tindakan Anda, Aksi
 * cepat, Angka, dan satu-satunya permintaan baru di halaman landing. Tidak ada
 * runtime JS di host ini, jadi yang dipaku adalah SUMBERNYA.
 */
class HomeWiringTest extends ErpTestCase
{
    /** Urutan bagian dan namanya dari README Fase F; ubin tetap ada di bawahnya. */
    public function test_the_workspace_sections_come_in_the_readme_order_above_the_tiles(): void
    {
        $home = $this->withoutComments($this->spa('views/home.js'));

        $order = ["'Perlu tindakan Anda'", "'Aksi cepat'", "'Angka'", "'Bidang kerja'"];
        $at = array_map(fn (string $title): int|false => strpos($home, $title), $order);
        $this->assertNotContains(false, $at, 'Sebuah bagian ruang kerja hilang dari home.js.');
        // Perlu tindakan → (Aksi cepat, Angka digambar oleh pemanggil berurutan) → Bidang kerja.
        $body = (string) $this->functionBody($home, 'export async function renderHome(host) {');
        $this->assertLessThan(strpos($body, 'paintQuick(quick)'), strpos($body, 'workSection()'));
        $this->assertLessThan(strpos($body, 'const numbers = el('), strpos($body, 'paintQuick(quick)'));
        $this->assertLessThan(strpos($body, "text: 'Bidang kerja'"), strpos($body, 'shortcutRows(shortcuts)'),
            'Favorit dan Terakhir dibuka harus tetap di atas ubin Bidang kerja.');
        $this->assertStringContainsString("'Terakhir dibuka'", $home);
        $this->assertStringContainsString("button('Dasbor'", $home, 'Tautan Dasbor di kepala Beranda hilang (README Fase F).');
        $this->assertStringContainsString('pageHeader({', $home, 'Kepala Beranda kembali dibangun tangan, bukan lewat pageHeader().');
    }

    /** `core/deadlines` punya SATU pemuat; hanya tiga berkas yang boleh menyebut endpoint-nya. */
    public function test_core_deadlines_has_one_loader_and_a_known_set_of_callers(): void
    {
        $naming = [];
        foreach ($this->allSpa() as $path => $source) {
            if (str_contains($this->withoutComments($source), "'core/deadlines'")) {
                $naming[] = $path;
            }
        }
        sort($naming);
        $this->assertSame(['deadlines.js', 'views/sertifikat.js', 'views/widgets/tenggat.js'], $naming,
            'Ada pemanggil baru core/deadlines: layar memakai loadDeadlines(); widget tetap safeList (UX-F3).');

        $loader = $this->spa('deadlines.js');
        $this->assertSame(1, substr_count($this->withoutComments($loader), "api.list('core/deadlines')"));
        $this->assertStringContainsString('const MAX_AGE = 60000;', $loader);
        $this->assertStringContainsString('inflight.userId !== userId', $loader, 'Permintaan yang berjalan tidak lagi hanya dibagi kepada pengguna yang sama.');
        $this->assertStringContainsString('cache.userId === currentUserId()', $loader, 'Cache tenggat tidak lagi dikunci pada pengguna.');
        $code = $this->withoutComments($loader);
        $this->assertStringNotContainsString('.catch(', $code, 'Pemuat menelan galat; tidak ada tenggat untuk tidak tahu adalah kabar baik palsu.');
        // Dua bentuk lain menelan galat sama baiknya: .then(ok, gagal) dan try/catch di dalam pemuat.
        $this->assertDoesNotMatchRegularExpression('/\.then\([^)]*,\s*\(/', $code, 'Pemuat memakai .then(ok, gagal) yang menelan galat.');
        $this->assertDoesNotMatchRegularExpression('/\bcatch\s*[({]/', $code, 'Pemuat berisi try/catch yang menelan galat.');
        $this->assertStringContainsString('if (cacheIsMine() && Date.now() - cache.at < maxAge) return Promise.resolve(cache.payload);', $code);
        $this->assertStringNotContainsString('forgetDeadlines', $code, 'Ekspor yang tidak punya pemanggil kembali.');

        $tenggat = $this->spa('views/tenggat.js');
        $this->assertStringContainsString('loadDeadlines({ maxAge: 0 })', $tenggat);
        $this->assertStringNotContainsString("api.list('core/deadlines')", $tenggat);
        foreach (['views/home.js', 'views/module.js', 'views/pekerjaan.js', 'app.js'] as $file) {
            $code = $this->withoutComments($this->spa($file));
            $this->assertStringNotContainsString("'core/deadlines'", $code, "{$file} memanggil core/deadlines sendiri.");
            $this->assertStringNotContainsString("'core/inbox'", $code, "{$file} memanggil core/inbox sendiri (inbox.js satu-satunya).");
        }
        $this->assertStringContainsString('loadDeadlines({ maxAge: DEADLINES_MAX_AGE })', $this->spa('views/home.js'));
        $this->assertStringContainsString('const DEADLINES_MAX_AGE = 15000;', $this->spa('views/home.js'));
        $this->assertStringContainsString('loadInbox()', $this->spa('views/home.js'));
    }

    /** Dua sumber, dua kegagalan: tidak pernah "tidak ada yang perlu ditindak" untuk "tidak tahu". */
    public function test_a_failed_source_is_one_honest_line_and_never_the_done_state(): void
    {
        $home = $this->withoutComments($this->spa('views/home.js'));

        $this->assertStringNotContainsString('.catch(() =>', $home);
        $settle = (string) $this->functionBody($home, 'const settle = async (key, load) => {');
        $this->assertStringContainsString("state[key] = 'failed';", $settle, 'Kegagalan sebuah sumber tidak lagi ditandai.');
        $this->assertStringContainsString('Persetujuan tidak dapat dimuat sekarang', $home);
        $this->assertStringContainsString('Tenggat tidak dapat dimuat sekarang.', $home);
        $this->assertStringContainsString('Daftar persetujuan ini belum lengkap', $home, 'meta.failed inbox tidak lagi dikatakan di Beranda.');

        $paint = (string) $this->functionBody($home, 'function paintWork(sub, content, state) {');
        $this->assertNotSame('', $paint);
        // Keadaan "done" hanya bila KEDUA sumber sudah menjawab dan tidak ada catatan kegagalan — dipaku
        // sebagai baris utuh, bukan sekadar kehadiran kata-katanya (tinjauan UX-F2: mutasi satu baris lolos).
        $this->assertStringContainsString('const settled = answered(state.inbox) && answered(state.due);', $paint);
        $this->assertStringContainsString('if (settled && !cards.length && !notes.length) {', $paint);
        $this->assertSame(1, substr_count($paint, "kind: 'done'"));
        $this->assertStringContainsString("const failedOf = (value) => value === 'failed';", $paint);
        foreach ([
            "if (failedOf(state.inbox)) notes.push(",
            "if (failedOf(state.due)) notes.push(",
            "if (inboxMeta.failed && inboxMeta.failed.length) notes.push(",
            "if (dueMeta.skipped > 0) notes.push(",
            "if (undatedCount > 0) notes.push(",
        ] as $line) {
            $this->assertStringContainsString($line, $paint, "Baris kegagalan hilang atau berubah: {$line}");
        }
        // Hitungan di bawah judul: '—' untuk sumber yang gagal atau belum menjawab.
        $this->assertStringContainsString("'— dokumen menunggu keputusan Anda'", $paint);
        $this->assertStringContainsString("'— tenggat sudah lewat'", $paint);
        $this->assertStringContainsString('failedOf(state.due) || !answered(state.due) ?', $paint);
        // Angka tenggat = jumlah BARIS (count tiap temuan), sama dengan layar Tenggat; bukan jumlah jenis temuan.
        $this->assertStringContainsString('overdue.reduce((sum, finding) => sum + rowsIn(finding), 0)', $paint);
        $this->assertStringNotContainsString('overdue.length', $paint);
        // Hanya yang SUDAH LEWAT menjadi kartu; menipis tidak ada di sini, dan "tanpa tanggal" hanya menjadi catatan.
        $this->assertStringContainsString("finding.tier === 'lewat'", $paint);
        $this->assertStringNotContainsString("'menipis'", $paint);
        $this->assertSame(1, substr_count($paint, "'tanpa_tanggal'"), "'tanpa_tanggal' dipakai di luar hitungan catatannya.");
        // Fokus papan ketik sebuah tombol kartu tidak hilang saat sumber kedua menggambar ulang.
        $this->assertStringContainsString('document.activeElement', $paint);
        $this->assertStringContainsString('again.focus()', $paint);

        // Kartu tenggat yang jatuh tempo HARI INI tidak ditulis "lewat 0 hari".
        $card = (string) $this->functionBody($home, 'function deadlineCard(finding) {');
        $this->assertStringContainsString('oldest.days === 0', $card);
        $this->assertStringContainsString('jatuh tempo hari ini', $card);

        // Seluruh permintaan core/modules gagal → null → ubin dan Angka menulis '—'.
        $render = (string) $this->functionBody($home, 'export async function renderHome(host) {');
        $this->assertMatchesRegularExpression('/\} catch \{\s*counts = null;\s*\}/', $render, 'Kegagalan core/modules tidak lagi menjadi counts = null.');
    }

    /** README: dua dokumen teratas; yang paling lama di atas; tombol dua kali sumbernya sendiri. */
    public function test_two_approvals_and_the_oldest_overdue_first(): void
    {
        $home = $this->withoutComments($this->spa('views/home.js'));

        $this->assertStringContainsString('const WORK_INBOX_MAX = 2;', $home);
        $this->assertStringContainsString('const WORK_DEADLINE_MAX = 3;', $home);
        $this->assertStringContainsString('.sort((a, b) => b.age - a.age)', $home, 'Kartu tidak lagi diurut dari yang paling lama.');
        $this->assertStringContainsString("'Tinjau & putuskan'", $home);
        $this->assertStringContainsString("'Tindak lanjuti'", $home);
        $this->assertStringContainsString('href: row.link', $home, 'Kartu persetujuan tidak lagi menuju halaman dokumennya.');
        $this->assertStringContainsString('Semua persetujuan →', $home);
        $this->assertStringContainsString('Semua tenggat →', $home);
        // items[].days bertanda: yang paling lama = yang paling negatif.
        $this->assertStringContainsString('-item.days', $home);
    }

    /** Aksi cepat = sumber Buat ▾; tidak ada izin yang ditulis di sini. */
    public function test_quick_actions_reuse_the_create_menu_source(): void
    {
        $home = $this->withoutComments($this->spa('views/home.js'));

        $this->assertStringContainsString("import { createEntries, startCreate } from '../create.js';", $home);
        $this->assertStringContainsString("createEntries('home')", $home);
        $this->assertStringContainsString('const QUICK_MAX = 6;', $home);
        $this->assertStringContainsString('onclick: () => startCreate(entry)', $home);
        $this->assertDoesNotMatchRegularExpression('/\.(create|update|approve)[\'"`]/', $home, 'home.js menulis izinnya sendiri; semuanya lewat create.js dan visibleNav().');
        $this->assertStringContainsString('redrawWhenPrefsLand(quick,', $home, 'Aksi cepat tidak lagi digambar ulang saat preferensi tiba (Terakhir dibuka).');
        $this->assertStringContainsString('if (prefs.loaded()) return;', (string) $this->functionBody($home, 'function redrawWhenPrefsLand(node, redraw) {'),
            'Setiap kunjungan Beranda kembali meninggalkan pendengar erp:prefs-loaded yatim.');
    }

    /** Angka: paling banyak empat, ringkasan dilewati, '—' bila tidak diketahui, nama dari cermin lokal. */
    public function test_numbers_are_at_most_four_and_unknown_is_a_dash(): void
    {
        $home = $this->withoutComments($this->spa('views/home.js'));
        $numbers = (string) $this->functionBody($home, 'function paintNumbers(host, groups, byPrefix) {');

        $this->assertStringContainsString('const NUMBERS_MAX = 4;', $home);
        $this->assertStringContainsString("group.prefix !== 'ringkasan'", $numbers, 'Angka mengulang hitungan lonceng (ringkasan).');
        // Modul yang disembunyikan dari Beranda (launcher.hidden) tidak mengambil slot Angka.
        $this->assertSame(2, substr_count($home, 'groups.filter((group) => !hidden.includes(group.prefix))'));
        $this->assertStringContainsString('byPrefix === null', $numbers);
        $this->assertStringContainsString('(MODULES[group.prefix] || {}).kpi', $numbers, 'Tanpa jawaban, nama angka tidak lagi dibaca dari cermin MODULES.kpi.');
        $this->assertStringContainsString('? entry.count : null', $numbers);
        $this->assertStringContainsString('keyValueTile({', $numbers, "keyValueTile menulis '—' untuk null; pembuat sendiri tidak boleh.");
        foreach (['count || 0', 'count ?? 0', 'count) || 0', 'count : 0'] as $bad) {
            $this->assertStringNotContainsString($bad, $home, "Pola terlarang di home.js: {$bad}");
        }
    }

    /** Bagian tambahan tidak menambah gaya sebaris, dan CSS-nya hanya token. */
    public function test_no_inline_styles_and_the_css_is_tokens_only(): void
    {
        $this->assertSame(0, preg_match_all('/style:\s*\{/', $this->spa('views/home.js')));
        $this->assertSame(0, preg_match_all('/style:\s*\{/', $this->spa('deadlines.js')));

        $css = (string) file_get_contents(public_path('app/app.css'));
        $start = (int) strpos($css, '/* UX-F2.2 — Beranda sebagai ruang kerja');
        $this->assertGreaterThan(0, $start, 'Blok CSS Beranda ruang kerja hilang.');
        $block = (string) preg_replace('~/\*.*?\*/~s', '', substr($css, $start));
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $block, 'Literal warna di blok Beranda.');
        $this->assertStringContainsString('.home-card[data-tone="attn"] { border-left-color: var(--attn); }', $block);
        $this->assertStringContainsString('.home-card[data-tone="danger"] { border-left-color: var(--danger); }', $block);
        $this->assertDoesNotMatchRegularExpression('/\.badge[^{]*--attn/', $block, '--attn hanya untuk actionPanel dan kartu beranda, bukan lencana.');
        $this->assertStringContainsString('@media (max-width: 760px)', $block);
    }

    private function spa(string $relative): string
    {
        return (string) file_get_contents(public_path("app/js/{$relative}"));
    }

    /** @return array<string, string> */
    private function allSpa(): array
    {
        $dir = public_path('app/js');
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $path = substr($file->getPathname(), strlen($dir) + 1);
                if (! str_starts_with($path, 'vendor/')) {
                    $out[$path] = (string) file_get_contents($file->getPathname());
                }
            }
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
