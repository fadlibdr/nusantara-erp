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

    /* ------------------------------------------------------------ E1.4 */

    /** Bilah alat dibangun sekali; renderAll hanya menyegarkannya, dan kotak Cari yang sedang diketik tidak ditimpa. */
    public function test_the_toolbar_is_built_once_and_only_refreshed(): void
    {
        $list = $this->spa('views/list.js');
        $render = $this->functionBody($list, 'function renderAll() {');
        $this->assertNotNull($render, 'renderAll() hilang dari list.js.');
        $this->assertStringNotContainsString('clear(tableHost)', $render,
            'renderAll membuang bilahnya lagi: kotak Cari yang sedang diketik dicabut di setiap muatan.');
        $this->assertStringContainsString('syncToolbar();', $render);
        $this->assertStringContainsString('clear(listBody);', $render);
        $this->assertStringNotContainsString('function filterBar(', $list);

        // Dipasang SEKALI, di luar renderAll. (UX-E1.5: baris chip pindah ke DALAM bilah alat.)
        $this->assertStringContainsString('tableHost.append(toolbar, viewRow, listBody);', $list);
        $this->assertStringNotContainsString('tableHost.append(toolbar', $render);

        $sync = $this->functionBody($list, 'function syncToolbar() {');
        $this->assertNotNull($sync);
        $this->assertStringContainsString('if (document.activeElement !== searchInput) searchInput.value = ui.q;', $sync);

        // Jeda Cari yang tertunda dibatalkan oleh setiap jalan "berhenti menyaring".
        foreach (['function resetFilters() {', 'function applyView(view) {'] as $signature) {
            $body = $this->functionBody($list, $signature);
            $this->assertNotNull($body, "{$signature} hilang.");
            $this->assertStringContainsString('cancelSearch();', $body, "{$signature} tidak membatalkan ketikan Cari yang masih menunggu jedanya.");
        }
    }

    /** Kontrol saringan di popover Filter: tetap di bawah `.filters`, hidup selagi tertutup, Escape kedua miliknya. */
    public function test_the_filter_controls_live_in_a_popover_that_respects_the_combobox(): void
    {
        $list = $this->spa('views/list.js');
        $this->assertStringContainsString("const filterGrid = el('.filters.filter-grid');", $list);

        $paint = $this->functionBody($list, 'function paintFilters() {');
        $this->assertNotNull($paint);
        // Dibangun ulang hanya bila keadaan saringannya berpindah, dengan popup combobox lama ditutup lebih dulu.
        $this->assertStringContainsString('if (next === builtFilters) return;', $paint);
        $this->assertLessThan(strpos($paint, 'clear(filterGrid);'), strpos($paint, 'filterCombos.forEach((combo) => combo.closePopup());'));
        $this->assertStringContainsString('filterGrid.appendChild(field(filter.label, buildFilter(filter)));', $paint);

        $popover = $this->functionBody($list, 'function listPopover({ wrap, toggle, panel, onClose }) {');
        $this->assertNotNull($popover, 'listPopover() hilang dari list.js.');
        // Fase gelembung: combobox menelan Escape pertamanya sendiri.
        $this->assertStringContainsString("document.addEventListener('keydown', onKey);", $popover);
        $this->assertDoesNotMatchRegularExpression("/addEventListener\('keydown', onKey, (true|\{)/", $popover,
            'Escape popover didengar di fase tangkap: Escape pertama membuang popover bersama daftar combobox-nya.');
        $this->assertStringContainsString('if (modalDepth() > 0) return;', $popover);
        // Daftar combobox menempel di <body>: memilih darinya bukan "klik di luar".
        $this->assertStringContainsString("target.closest('.combo-pop')", $popover);
        $this->assertStringContainsString("document.addEventListener('pointerdown', onOutside, true);", $popover);

        $this->assertStringNotContainsString('combo.node.style.width', $list, 'Lebar combobox sebaris kembali — kisi popover yang memegangnya.');

        $css = $this->css();
        $this->assertStringContainsString('.filter-pop[hidden] { display: none; }', $css);
        $this->assertStringContainsString('.filter-count[hidden] { display: none; }', $css);
        $this->assertStringContainsString('.list-toolbar .btn[hidden], .filter-wrap[hidden] { display: none; }', $css);
        // Satu literal 11 px untuk dua bilah, bukan salinan kedua (CONVENTIONS §13a).
        $this->assertStringContainsString(".filters, .list-toolbar {\n  display: flex; gap: 8px; flex-wrap: wrap; align-items: center;\n  padding: 11px 12px;", $css);
        // Kertas: kontrol bilah alat tidak tercetak. (UX-E1.5: chip saringan di dalamnya tercetak; UX-E1.6:
        // jumlah barisnya juga — yang disembunyikan adalah anak-anak bilah selain keduanya.)
        $this->assertStringContainsString('.list-toolbar > :not(.chips):not(.list-count) { display: none !important; }', $css);
        // Teks kotak Cari mulai sesudah ikonnya — aturan umum input[type=text] tidak lagi menang.
        $this->assertStringContainsString('.search input[type=text] { width: 100%; padding-left: 32px; }', $css);
    }

    /* ------------------------------------------------------------ E1.5 */

    /** Chip saringan aktif di dalam bilah alat lewat filterChip(); "Hapus semua" menggantikan Reset. */
    public function test_active_filters_are_filter_chips_inside_the_toolbar(): void
    {
        $list = $this->spa('views/list.js');
        $chips = $this->functionBody($list, 'function paintChips() {');
        $this->assertNotNull($chips, 'paintChips() hilang dari list.js.');
        $this->assertStringContainsString('filterChip({ label: chip.label, value: chip.text, onRemove: chip.clear })', $chips);
        // Baris tersembunyi hanya bila TIDAK ADA yang menyaring: nilai seed tanpa chip tetap punya jalan keluar.
        $this->assertStringContainsString('chipRow.hidden = !isFiltered();', $chips);
        $this->assertStringContainsString("button('Hapus semua', { size: 'sm', variant: 'ghost', onClick: resetFilters })", $chips);
        $this->assertSame(1, substr_count($chips, 'button('), 'Baris chip memuat tombol .btn selain "Hapus semua".');
        $this->assertStringNotContainsString("button('Reset'", $list, 'Tombol Reset kembali ke bilah alat.');

        // Urutan di bilah alat: Cari, Filter, chip, lalu Ekspor CSV di ujung kanan.
        $this->assertMatchesRegularExpression(
            "/el\\('\\.list-toolbar', \\[\\s*el\\('\\.search'[^\\n]*\\n\\s*filterWrap,\\n\\s*chipRow,\\n\\s*el\\('span\\.spacer'\\),\\n\\s*csvButton,/",
            $list,
        );

        $css = $this->css();
        $this->assertStringContainsString('.list-toolbar .chips { padding: 0; min-width: 0; }', $css);
        // Kertas: chip tercetak sebagai keterangan, kontrolnya tidak. (UX-E1.6 menambah jumlah baris ke
        // yang tercetak, dan bilah tanpa chip DAN tanpa angka tetap tidak dicetak.)
        $this->assertStringContainsString('.list-toolbar > :not(.chips):not(.list-count) { display: none !important; }', $css);
        $this->assertStringContainsString('.chips .chip-x, .chips .btn { display: none !important; }', $css);
        $this->assertStringContainsString('.list-toolbar:not(:has(> .chips:not([hidden]))):not(:has(> .list-count:not(:empty))) { display: none !important; }', $css);
    }

    /* ------------------------------------------------------------ E1.6 */

    /** "N <label> · tersaring" dari meta.total saja — tidak pernah dari baris halaman, tidak pernah 0 bawaan. */
    public function test_the_count_reads_the_server_total_only(): void
    {
        $list = $this->spa('views/list.js');
        $count = $this->functionBody($list, 'function countText() {');
        $this->assertNotNull($count, 'countText() hilang dari list.js.');
        $this->assertStringContainsString("if (typeof meta.total !== 'number') return '';", $count);
        $this->assertStringContainsString("if (loading || error || !payload) return '';", $count);
        // ' · tersaring' hanya sesudah cabang tak-tersaring kembali (UX-E1.7 menambah "dari M" di antaranya).
        $this->assertStringContainsString('if (!isFiltered()) return text;', $count);
        $this->assertLessThan(strpos($count, 'return `${text} · tersaring`;'), strpos($count, 'if (!isFiltered()) return text;'),
            "' · tersaring' harus berada di cabang isFiltered().");
        $this->assertStringNotContainsString('rows.length', $count, 'Jumlah dibaca dari baris HALAMAN ini, bukan dari total server.');
        $this->assertStringNotContainsString('?? 0', $count, 'Jumlah yang tidak diketahui menjadi 0.');
        $this->assertStringNotContainsString('|| 0', $count, 'Jumlah yang tidak diketahui menjadi 0.');

        // Anak terakhir bilah alat, live region sopan, disegarkan oleh syncToolbar().
        $this->assertStringContainsString("const listCount = el('span.list-count', { 'aria-live': 'polite' });", $list);
        $this->assertMatchesRegularExpression("/csvButton,\\n\\s*listCount,\\n\\s*\\]\\);/", $list);
        // UX-E1.7: penulisannya pindah ke syncCount(), yang juga dipanggil jawaban "dari M".
        $sync = $this->functionBody($list, 'function syncToolbar() {');
        $this->assertStringContainsString('syncCount();', (string) $sync);
        $this->assertStringContainsString('const count = countText();', (string) $this->functionBody($list, 'function syncCount() {'));

        $this->assertStringContainsString('.list-count { color: var(--muted); font-size: var(--fs-secondary); white-space: nowrap; }', $this->css());
    }

    /* ------------------------------------------------------------ E1.7 */

    /** "N dari M": M dari SATU permintaan tak tersaring per pengguna × layar × menit, hanya bila M ≥ N. */
    public function test_the_unfiltered_total_is_one_cached_request_per_user(): void
    {
        $list = $this->spa('views/list.js');
        // Kunci membawa id pengguna: logout() tidak memuat ulang halaman.
        $this->assertStringContainsString('const unfilteredKey = (key) => `${(session.user || {}).id ?? \'?\'}:${key}`;', $list);
        $this->assertStringContainsString('const UNFILTERED_TTL_MS = 60 * 1000;', $list);

        $fetch = $this->functionBody($list, 'function fetchWhole() {');
        $this->assertNotNull($fetch, 'fetchWhole() hilang dari list.js.');
        $this->assertStringContainsString('if (!isFiltered()) return;', $fetch);
        $this->assertLessThan(strpos($fetch, 'api.list(def.api, { per_page: 1, page: 1 })'), strpos($fetch, 'if (!isFiltered()) return;'),
            'Permintaan tak tersaring berjalan untuk daftar yang tidak tersaring.');
        $this->assertStringContainsString('known.pending || Date.now() - known.at < UNFILTERED_TTL_MS', $fetch);
        $this->assertStringContainsString('.catch(() => {', $fetch);
        $this->assertStringNotContainsString('toast', $fetch, 'Kegagalan M bersuara — ia harus diam.');

        // Dipanggil hanya sesudah muatan terbaru yang berhasil.
        $load = (string) $this->functionBody($list, 'async function load() {');
        $this->assertStringContainsString('if (!failure && stillOnScreen()) fetchWhole();', $load);

        $count = (string) $this->functionBody($list, 'function countText() {');
        $this->assertStringContainsString("typeof whole.total === 'number' && whole.total >= meta.total", $count);
        $this->assertStringNotContainsString('dari 0', $count);

        // Perubahan milik orangnya sendiri membuang M.
        $this->assertStringContainsString("onClick: () => { forgetWhole(); load(); }", $list);
        $this->assertStringContainsString('forgetWhole();', (string) $this->functionBody($list, 'function afterCreate(saved) {'));
        $this->assertMatchesRegularExpression('/await api\\.del\\(`\\$\\{def\\.api\\}\\/\\$\\{row\\.id\\}`\\);\\n\\s*invalidateByPath\\(def\\.api\\);\\n\\s*forgetWhole\\(\\);/', $list);
    }

    public function test_the_shell_version_went_up_with_the_list(): void
    {
        $this->assertSame(1, preg_match("~const SHELL_VERSION = '(\\d+)';~", (string) file_get_contents(public_path('app/sw.js')), $version));
        $this->assertGreaterThanOrEqual(52, (int) $version[1],
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
