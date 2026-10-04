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

    /* ------------------------------------------------------------ E1.8 */

    /** Tab status = tampilan kedua dari saringan yang DIDEKLARASIKAN: pintu keputusan atau quickFilters, enum dari ENUMS, tanpa angka. */
    public function test_status_tabs_are_a_second_view_of_the_declared_filter(): void
    {
        $list = $this->spa('views/list.js');
        $of = $this->functionBody($list, 'function quickFilterOf(def) {');
        $this->assertNotNull($of, 'quickFilterOf() hilang dari list.js.');
        $this->assertStringContainsString("def.quickFilters ?? ((def.actions || []).some((action) => session.isDecisionDoor(action)) ? ['status'] : [])", $of);
        $this->assertStringContainsString('filter.enum && ENUMS[filter.enum]', $of);

        // Pembangun item tab: "Semua" lalu opsi enum yang SAMA dengan <option> select-nya — tanpa angka.
        $start = strpos($list, 'const quickTabs = quickFilter');
        $this->assertNotFalse($start);
        $builder = substr($list, $start, (int) strpos($list, "\n    : null;", $start) - $start);
        $this->assertStringContainsString("{ id: '', label: 'Semua', panelId: tableHost.id }", $builder);
        $this->assertStringContainsString('...ENUMS[quickFilter.enum].map((option) => ({ id: option.value, label: option.label, panelId: tableHost.id })),', $builder);
        $this->assertStringNotContainsString('count', $builder, 'Tab membawa angka: tidak ada meta per status, jadi angkanya berarti enam permintaan tambahan per muatan.');
        // "Semua" MENGHAPUS kuncinya — daftar yang belum disentuh tetap ber-URL polos (S7/S8/S43 memaku hash persis).
        $this->assertStringContainsString("if (value === '') delete ui.filters[quickFilter.key];", $builder);
        $this->assertStringContainsString('ui.page = 1;', $builder);

        $this->assertStringContainsString('host.append(...[head, quickTabs, tableHost].filter(Boolean));', $list,
            'Tab dipasang tanpa .filter(Boolean): Element.append(null) menulis teks "null" di layar tanpa tab.');
        $this->assertStringContainsString('syncQuickTabs();', (string) $this->functionBody($list, 'function renderAll() {'));
        $sync = (string) $this->functionBody($list, 'function syncQuickTabs() {');
        $this->assertStringContainsString("quickTabs.select(ui.filters[quickFilter.key] || '');", $sync);
        $this->assertStringContainsString(".tabIndex = 0;", $sync, 'Nilai URL basi meninggalkan tablist tanpa satu pun tabIndex 0 — tidak terjangkau Tab.');

        // ui.js tabs(): panah yang DITAHAN memuat sekali (bukan per ulangan — batas laju 120/menit),
        // dan panah bermodifier (Alt/Cmd+← = Kembali) bukan milik tab.
        $tabs = (string) $this->functionBody($this->spa('ui.js'), 'export function tabs(');
        $this->assertStringContainsString('if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;', $tabs);
        $this->assertLessThan(strpos($tabs, 'event.preventDefault();'), strpos($tabs, 'event.altKey'));
        $this->assertStringContainsString('select(next.dataset.tab, !event.repeat);', $tabs);
        $this->assertStringContainsString("host.addEventListener('keyup',", $tabs);
        $this->assertStringContainsString("host.addEventListener('focusout',", $tabs);
        $this->assertStringContainsString('if (fire && String(id) !== String(reported)) {', $tabs);

        // Kontrak URL tidak berubah: tab hanya menulis kunci saringan yang sudah dideklarasikan.
        $this->assertStringContainsString("const RESERVED_PARAMS = ['page', 'q', 'sort', 'dir', 'date_from', 'date_to'];", $list);
        foreach (['function stateQuery({ withPage } = {}) {', 'function seedFromUrl(key, def, ui) {', 'function syncUrl() {'] as $signature) {
            $body = $this->functionBody($list, $signature);
            $this->assertNotNull($body, "{$signature} hilang.");
            $this->assertStringNotContainsString('quick', $body, "{$signature} tahu soal tab — tab seharusnya tidak terlihat oleh kontrak URL.");
        }
    }

    /** Setiap `quickFilters:` di skema menunjuk saringan BER-ENUM di `filters:` blok resource yang sama. */
    public function test_every_declared_quick_filter_names_an_enum_filter_of_its_own_block(): void
    {
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString('quickFilters kunci saringan', $schema, 'Kunci skema quickFilters tidak terdokumentasi di kepala schema.js.');
        $enums = $this->spa('enums.js');

        $count = preg_match_all('/^    quickFilters: \[([^\]]*)\],$/m', $schema, $found, PREG_OFFSET_CAPTURE);
        $this->assertGreaterThanOrEqual(1, $count, 'Tidak ada satu pun quickFilters — finance/payments kehilangan tab statusnya.');
        // Setiap deklarasi terbaca (dokumentasi di kepala berkas menulis kuncinya tanpa titik dua).
        $this->assertSame($count, substr_count($schema, 'quickFilters:'),
            'Ada quickFilters yang ditulis dalam bentuk lain dan lolos dari pemindai ini.');

        $declaredOn = [];
        foreach ($found[0] as $i => [, $offset]) {
            // Blok resource: dari kunci tingkat-atas terdekat di atasnya ("\n  '...': {") sampai kunci berikutnya.
            $open = strrpos(substr($schema, 0, $offset), "\n  '");
            $this->assertNotFalse($open);
            $close = strpos($schema, "\n  '", $offset);
            $block = substr($schema, $open, ($close === false ? strlen($schema) : $close) - $open);
            preg_match("/^\n  '([^']+)'/", $block, $name);
            $declaredOn[] = $name[1];
            preg_match_all("/'([^']+)'/", $found[1][$i][0], $keys);
            $this->assertNotEmpty($keys[1], "{$name[1]}: quickFilters kosong.");
            $filters = $this->filtersOf($block);
            $this->assertNotNull($filters, "{$name[1]}: quickFilters tanpa `filters:` — quickFilterOf() hanya membaca def.filters.");
            foreach ($keys[1] as $key) {
                $this->assertSame(1, preg_match($this->quickFilterPattern($key), $filters, $hit),
                    "{$name[1]}: quickFilters menyebut '{$key}', tetapi `filters:` blok ini tidak punya saringan ber-enum berkunci itu.");
                $this->assertMatchesRegularExpression("/^  {$hit[1]}: opts\\(/m", $enums, "{$name[1]}: enum '{$hit[1]}' tidak ada di enums.js.");
            }
        }
        $this->assertContains('finance/payments', $declaredOn);
    }

    /** Separuh penolakan untuk pemindai blok di atas: saringan tanpa enum, atau enum yang hanya ada di kolom/formulir, ditolak. */
    public function test_the_quick_filter_reader_refuses_a_filter_without_an_enum(): void
    {
        $plain = "\n  'x/y': {\n    filters: [\n      { key: 'status', label: 'Status', type: 'text' },\n    ],\n    quickFilters: ['status'],\n";
        $this->assertSame(0, preg_match($this->quickFilterPattern('status'), (string) $this->filtersOf($plain)));

        // Enum status di FORMULIR, saringannya hanya project_id: blok utuh cocok, `filters:` tidak.
        $formOnly = "\n  'x/z': {\n    filters: [\n      { key: 'project_id', label: 'Proyek', lookup: 'projects' },\n    ],\n    quickFilters: ['status'],\n"
            ."    form: { sections: [{ fields: [\n      { key: 'status', label: 'Status', type: 'select', enum: 'documentStatus' },\n    ] }] },\n";
        $this->assertSame(1, preg_match($this->quickFilterPattern('status'), $formOnly));
        $this->assertSame(0, preg_match($this->quickFilterPattern('status'), (string) $this->filtersOf($formOnly)));
    }

    /** Tab, pil, dan angka bilah daftar tidak pernah memakai warna aksen modul — aksen bukan warna status. */
    public function test_list_tabs_and_pills_use_no_accent_colour(): void
    {
        $css = $this->css();
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $checked = 0;
        foreach ($rules as [, $selector, $body]) {
            if (! preg_match('/\.tabs\b|\.tab-count\b|\.list-tabs\b|\.list-toolbar\b|\.filter-count\b|\.list-count\b/', $selector)) {
                continue;
            }
            $checked++;
            $this->assertDoesNotMatchRegularExpression('/--(module-)?accent/', $body, 'Aturan '.trim($selector).' memakai warna aksen.');
        }
        $this->assertGreaterThanOrEqual(8, $checked, 'Pemindai aturan tidak menemukan aturan tab/bilah daftar — pin ini hampa.');
    }

    /* ------------------------------------------------------------ E1.10 */

    /** Menu ⋯ baris menggantikan tombol baris SATU banding SATU: gerbang harfiah, Buka tidak pernah sendirian, tanpa aksi dokumen. */
    public function test_the_row_menu_replaces_the_row_buttons_one_for_one(): void
    {
        $list = $this->spa('views/list.js');
        $menu = $this->functionBody($list, 'function rowMenu(row) {');
        $this->assertNotNull($menu, 'rowMenu() hilang dari list.js.');
        $this->assertNull($this->functionBody($list, 'function rowActions(row) {'), 'rowActions() kembali di samping rowMenu().');

        // Gerbang Ubah/Hapus disalin harfiah (ERRATA E-5; PANDUAN "izin DAN status").
        $this->assertStringContainsString('const canEdit = def.canEdit !== false && Boolean(def.form) && session.can(`${def.module}.update`) &&'."\n".'      (!def.editableWhen || def.editableWhen(row));', $menu);
        $this->assertStringContainsString('const canDelete = def.canDelete !== false && session.can(`${def.module}.delete`) &&'."\n".'      (!def.deletableWhen || def.deletableWhen(row));', $menu);
        // ⋯ lahir hanya dari cetak/Ubah/Hapus — Buka tidak pernah sendirian (kepadatan beku, aturan detail.js).
        $this->assertStringContainsString('if (!prints.length && !canEdit && !canDelete) return null;', $menu);
        $this->assertLessThan(strpos($menu, "label: 'Buka'"), strpos($menu, 'if (!prints.length && !canEdit && !canDelete) return null;'));
        $this->assertStringContainsString('overflowMenu(items, { title: `Aksi untuk ${codeOf(row)}` })', $menu);
        $this->assertStringContainsString('label: `${def.deleteLabel || \'Hapus\'}…`', $menu);
        $this->assertStringContainsString('items.push({ divider: true });', $menu);
        $this->assertStringContainsString('danger: true,', $menu);
        $this->assertStringContainsString("labelled(trigger, 'Aksi');", $menu);
        $this->assertStringNotContainsString('def.actions', $menu, 'Aksi dokumen (pintu keputusan, boardOnly) menyusup ke menu baris.');
        // Hapus tetap menulis kalimat konfirmasi dan jalur api.del → invalidate → toast → load yang sama.
        $this->assertStringContainsString('? Tindakan ini tidak dapat dibatalkan.`', $menu);
        $this->assertMatchesRegularExpression('/await api\.del\(`\$\{def\.api\}\/\$\{row\.id\}`\);\n\s*invalidateByPath\(def\.api\);\n\s*forgetWhole\(\);[^\n]*\n\s*toast\(`\$\{def\.labelOne\} dihapus\.`\);\n\s*load\(\);/', $menu);

        // Fokus dipindah ke ⋯ SEBELUM dialog/formulir: modal() menangkap returnFocus saat dibuka.
        // Berdampingan persis — irisan "dari onClick terdekat" bisa berlabuh di penangan butir lain.
        $this->assertMatchesRegularExpression('/trigger\.focus\(\);\n\s*startForm\(\{ def, key, row,/', $menu,
            'Fokus tidak berada di ⋯ saat Ubah membuka formulirnya — Batal menjatuhkannya ke <body>.');
        $this->assertMatchesRegularExpression('/trigger\.focus\(\);\n\s*confirmDialog\(\{/', $menu,
            'Fokus tidak berada di ⋯ saat Hapus… membuka konfirmasinya — Batal menjatuhkannya ke <body>.');

        $rows = (string) $this->functionBody($list, 'function renderRows() {');
        $this->assertStringContainsString('const menus = new Map(rows.map((row) => [row.id, rowMenu(row)]));', $rows);
        $this->assertSame(1, substr_count($rows, 'rowMenu('), 'rowMenu() dipanggil lebih dari sekali per baris.');
        $this->assertStringContainsString("el('th.right.col-min', el('span.sr-only', { text: 'Aksi' }))", $rows);
        $this->assertStringNotContainsString("width: '1%'", $rows, 'Lebar sebaris kolom aksi kembali — .col-min yang memegangnya.');
        // Klik ⋯ atau butirnya tidak membuka dokumen; butir yang dipilih sudah tercabut, maka composedPath().
        $this->assertStringContainsString("if (event.target.closest('td.pick')) return;", $rows);
        $this->assertStringContainsString('if (menuCell && event.composedPath().includes(menuCell)) return;', $rows);
        // …dan sel yang dijaganya benar-benar ada: tanpa .row-menu, menuCell null dan penjaga mati diam-diam.
        $this->assertStringContainsString("el('td.right.row-menu', menus.get(row.id))", $rows);
        $this->assertStringContainsString("const menuCell = tr.querySelector('td.row-menu');", $rows);

        // ui.js: ↓/↑ yang MEMBUKA menu tidak ikut menavigasi di dalamnya (butir kedua = "Hapus…"),
        // dan menu yatim melepaskan papan ketik pada ketukan berikutnya.
        $ui = $this->spa('ui.js');
        $opener = (int) strpos($ui, "open({ focusLast: event.key === 'ArrowUp' });");
        $this->assertGreaterThan(0, $opener);
        $this->assertStringContainsString('event.stopPropagation();', substr($ui, $opener, (int) strpos($ui, "\n  });", $opener) - $opener),
            '↓ yang membuka menu ⋯ ikut menggelembung ke onKey dan memindah fokus ke butir kedua ("Hapus…").');
        $this->assertStringContainsString("if (!wrap.isConnected) {\n      close();\n      return;\n    }", $ui);

        // Butir cetak tetap pada layar noDetail saja, dengan rantai yang dipaku DunningLetterTest.
        $prints = (string) $this->functionBody($list, 'function printRowItems(row) {');
        $this->assertStringContainsString('if (!def.noDetail) return [];', $prints);
        $this->assertStringContainsString('.filter((form) => printableFor(form, row))', $prints);
        $this->assertStringContainsString('onClick: (event, trigger) => openPrintable(printablePath(form, row), trigger),', $prints);

        $this->assertStringContainsString('table.data tbody tr.clickable td.row-menu { cursor: default; }', $this->css());
    }

    /** Tidak ada "Duplikat" (ERRATA E-5: server tidak punya aksinya) — huruf besar persis, di seluruh SPA dan skema. */
    public function test_no_duplicate_action_exists_in_the_spa(): void
    {
        $offenders = [];
        foreach ($this->spaFiles() as $path => $source) {
            if ($this->offersDuplicate($source)) {
                $offenders[] = $path;
            }
        }
        $this->assertSame([], $offenders, 'Aksi "Duplikat" muncul di SPA padahal server tidak punya endpoint-nya (ERRATA E-5).');
        $this->assertDoesNotMatchRegularExpression("/path: '[^']*(duplicate|duplikat|clone)[^']*'/i", $this->spa('schema.js'));
    }

    /** Separuh penolakan: pembacanya mengenali butir Duplikat persis seperti README menggambarnya. */
    public function test_the_duplicate_reader_recognises_the_readme_item(): void
    {
        $this->assertTrue($this->offersDuplicate("items.push({ label: 'Duplikat sebagai draf', iconName: 'copy' });"));
        $this->assertFalse($this->offersDuplicate("// duplikat dan duplicate dalam komentar huruf kecil bukan aksi"));
    }

    /* ------------------------------------------------------------ E1.11 */

    /** primaryColumn: opt-in, kolom utama tidak pernah disembunyikan dan membawa nilai hideOnNarrow sebagai .cell-fold. */
    public function test_the_primary_column_folds_the_narrow_columns_on_phones(): void
    {
        $list = $this->spa('views/list.js');
        $this->assertStringContainsString('const foldedColumns = def.primaryColumn', $list);
        $this->assertStringContainsString("const narrowOf = (column) => (column.hideOnNarrow && column.key !== def.primaryColumn ? '.hide-narrow' : '');", $list);
        $cell = (string) $this->functionBody($list, 'function bodyCell(row, column) {');
        $this->assertStringContainsString('renderCell(row, column2).textContent.trim()', $cell, 'Lipatan tidak memakai renderCell yang sama — nilai kosong tidak lagi berbunyi "—".');
        $this->assertStringContainsString("el('span.cell-fold', { title: lines.join(' · ') }, lines.map((text) => el('span', { text })))", $cell);
        $this->assertStringNotContainsString('cell-sub', $cell, 'Lipatan memakai .cell-sub: sensus S21 membuang baris yang memuatnya.');
        // th, td, dan tfoot sepakat soal kelas hide-narrow (jumlah sel per baris tetap segaris di ponsel).
        $this->assertStringNotContainsString("column.hideOnNarrow ? '.hide-narrow'", $list);
        $this->assertSame(3, substr_count($list, 'narrowOf(column)'), 'th, td, dan tfoot tidak lagi memakai satu penentu hide-narrow.');

        // Skema: setiap primaryColumn menunjuk kolom di bloknya sendiri yang BUKAN hideOnNarrow, dan
        // bloknya punya setidaknya satu kolom hideOnNarrow (tanpa itu tidak ada yang dilipat).
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString('primaryColumn kunci kolom', $schema);
        $count = preg_match_all("/^    primaryColumn: '([^']+)',$/m", $schema, $found, PREG_OFFSET_CAPTURE);
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertSame($count, substr_count($schema, 'primaryColumn:'));
        $owners = [];
        foreach ($found[1] as [$key, $offset]) {
            $open = strrpos(substr($schema, 0, $offset), "\n  '");
            $close = strpos($schema, "\n  '", $offset);
            $block = substr($schema, $open, ($close === false ? strlen($schema) : $close) - $open);
            preg_match("/^\n  '([^']+)'/", $block, $name);
            $owners[] = $name[1];
            $columns = substr($block, (int) strpos($block, "\n    columns: ["), (int) strpos($block, "\n    ],", (int) strpos($block, "\n    columns: [")) - (int) strpos($block, "\n    columns: ["));
            // Kolom bersama dari kepala schema.js (codeColumn = { key: 'code', … }) ditulis sebagai konstantanya.
            $shared = ['code' => 'codeColumn,'];
            $hit = preg_match("/^.*\\{ key: '".preg_quote($key, '/')."'.*$/m", $columns, $line)
                || (isset($shared[$key]) && preg_match('/^\\s*'.preg_quote($shared[$key], '/').'$/m', $columns, $line));
            $this->assertTrue((bool) $hit, "{$name[1]}: primaryColumn '{$key}' bukan kolom di blok ini.");
            $this->assertStringNotContainsString('hideOnNarrow', $line[0], "{$name[1]}: kolom utama sendiri hideOnNarrow.");
            $this->assertStringContainsString('hideOnNarrow: true', $columns, "{$name[1]}: tidak ada kolom hideOnNarrow untuk dilipat.");
        }
        $this->assertContains('procurement/purchase-orders', $owners);
        // Daftar kepadatan beku S21 tidak memakainya.
        $this->assertNotContains('inventory/item-categories', $owners);
        $this->assertNotContains('finance/accounts', $owners);

        $css = $this->css();
        $this->assertStringContainsString('.cell-fold { display: none; }', $css);
        $narrow = substr($css, (int) strpos($css, '  table.data .hide-narrow { display: none; }'), 1500);
        $this->assertMatchesRegularExpression('/table\.data \.cell-fold \{\s*display: block;/', $narrow, 'Lipatan tidak tampil di blok ≤ 760 px tempat kolomnya disembunyikan.');
    }

    public function test_the_shell_version_went_up_with_the_list(): void
    {
        $this->assertSame(1, preg_match("~const SHELL_VERSION = '(\\d+)';~", (string) file_get_contents(public_path('app/sw.js')), $version));
        $this->assertGreaterThanOrEqual(58, (int) $version[1],
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

    private function offersDuplicate(string $source): bool
    {
        return str_contains($source, 'Duplikat');
    }

    /** @return array<string, string> setiap berkas .js di public/app/js, rekursif. */
    private function spaFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path('app/js'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        $this->assertGreaterThan(30, count($files), 'Pembaca berkas SPA tidak menemukan berkasnya — pin ini hampa.');

        return $files;
    }

    /** Larik `filters: [` … `],` tingkat resource (indentasi 4) di dalam satu blok, atau null. */
    private function filtersOf(string $block): ?string
    {
        $start = strpos($block, "\n    filters: [");
        if ($start === false) {
            return null;
        }
        $end = strpos($block, "\n    ],", $start);

        return $end === false ? null : substr($block, $start, $end - $start);
    }

    private function quickFilterPattern(string $key): string
    {
        return "/\\{ key: '".preg_quote($key, '/')."', label: '[^']+', (?:type: '[^']+', )?enum: '([A-Za-z]+)'/";
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
