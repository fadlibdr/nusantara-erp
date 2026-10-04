<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Layar dokumen generik (views/detail.js) sesudah redesain UX-E2 — kabelnya.
 *
 * Tidak ada runtime JS di host ini; seperti ListViewWiringTest, yang dipaku
 * adalah SUMBERNYA, pada janji yang bisa dibalik oleh perubahan kecil yang
 * tidak berbunyi apa-apa di layar. Setiap tugas paket menambah pin-nya di sini.
 */
class DetailViewWiringTest extends ErpTestCase
{
    /* ------------------------------------------------------------ E2.2 */

    /** Label Informasi: label formulir → LABELS → "Bidang lainnya (n)"; titleize() hanya di dalam lipatan. */
    public function test_information_labels_come_from_the_form_then_labels_then_bidang_lainnya(): void
    {
        $detail = $this->spa('views/detail.js');

        $label = $this->functionBody($detail, 'function fieldLabel(');
        $this->assertNotNull($label, 'fieldLabel() hilang dari views/detail.js.');
        $this->assertStringContainsString('return labels.get(key) || LABELS[key] || null;', $label,
            'Urutan label bukan lagi formulir → LABELS → null (ERRATA E-7).');

        $forms = $this->functionBody($detail, 'function formLabels(');
        $this->assertNotNull($forms);
        $this->assertStringContainsString('def.form.sections', $forms);

        $render = $this->functionBody($detail, 'export async function renderDetail(');
        $this->assertNotNull($render);
        $this->assertStringContainsString('Bidang lainnya (${unlabelled.length})', $render);
        $this->assertStringContainsString("el('details.kv-more'", $render);
        $this->assertStringContainsString("el('dt', { text: fieldLabel(fieldKey, labels) || titleize(fieldKey) })", $render);
        // Kunci yang kartu tabel/daftar gambar sendiri tidak diulang sebagai baris Informasi (WO "Billings").
        $this->assertStringContainsString("const drawnBelow = new Set([...(detail.tables || []), ...(detail.lists || [])].map((block) => block.key));", $render);
        $this->assertStringContainsString('!drawnBelow.has(fieldKey)', $render);
        $this->assertStringNotContainsString("el('dt', { text: titleize(fieldKey) })", $detail,
            'Label Informasi kembali diambil dari titleize(), bukan form label → LABELS.');

        // titleize( dipanggil tepat di: definisinya, autoValue (objek bersarang), headlineTiles (ubin,
        // E2.3), kv() dan Terkait — dihitung tanpa komentar, yang menyebut titleize() sebagai peringatan.
        $this->assertSame(5, substr_count($this->withoutComments($detail), 'titleize('),
            'titleize() dipanggil di tempat baru — label auto-Inggris punya jalan lain ke layar.');
    }

    /** LABELS dan peringatan titleize() tetap ada (ERRATA E-7: "Keep LABELS and the titleize() warning"). */
    public function test_titleize_and_labels_survive_as_the_fallback(): void
    {
        $detail = $this->spa('views/detail.js');
        $this->assertStringContainsString('function titleize(key)', $detail);
        $this->assertStringContainsString('belum ada di LABELS (detail.js) — label tampil auto-Inggris.', $detail,
            'Peringatan konsol titleize() hilang: kunci tanpa label lolos diam-diam lagi.');

        $labels = $this->labelsBlock($detail);
        foreach ([
            "dpp: 'DPP'", "ppn_amount: 'PPN'", "ppn_rate: 'Tarif PPN'",
            "required_levels: 'Tingkat persetujuan diperlukan'", "approvals_given: 'Persetujuan masuk'",
            "needs_director_approval: 'Perlu persetujuan direktur'", "closed_at: 'Ditutup'", "rfq_id: 'RFQ'",
        ] as $entry) {
            $this->assertStringContainsString($entry, $labels, "LABELS kehilangan {$entry}: medan hitungan jatuh ke \"Bidang lainnya\".");
        }
    }

    /** Lipatan "Bidang lainnya" berada DI DALAM kartu Informasi, sesudah dl.kv-nya, sebelum tabel baris. */
    public function test_bidang_lainnya_lives_inside_the_information_card(): void
    {
        $render = $this->functionBody($this->spa('views/detail.js'), 'export async function renderDetail(');
        $card = strpos($render, "el('h2', { text: 'Informasi' })");
        $fold = strpos($render, "        fold,\n");
        // Tabel baris dicari SESUDAH kartu: `detail.tables || []` pertama di renderDetail adalah preload lookup.
        $tables = $card === false ? false : strpos($render, 'detail.tables || []', $card);
        $this->assertNotFalse($card);
        $this->assertNotFalse($fold);
        $this->assertNotFalse($tables);
        $this->assertTrue($card < $fold && $fold < $tables, 'Lipatan "Bidang lainnya" tidak lagi di dalam kartu Informasi.');

        $css = $this->css();
        $this->assertStringContainsString('.kv-more > summary {', $css);
        $this->assertStringContainsString('.kv-more[open] > summary', $css);
    }

    /**
     * Statis: setiap kunci yang PurchaseOrderResource kirim tersembunyi, berlabel formulir, atau ada di
     * LABELS — tidak satu pun bisa jatuh ke "Bidang lainnya" (gerbang UX-E2: DPP/PPN/tingkat dengan teks LABELS).
     */
    public function test_no_purchase_order_key_can_reach_bidang_lainnya(): void
    {
        $resource = (string) file_get_contents(base_path('Modules/Procurement/Http/Resources/PurchaseOrderResource.php'));
        preg_match_all("/^\s*'(\w+)'\s*=>/m", $resource, $m);
        $keys = array_unique($m[1]);
        $this->assertGreaterThanOrEqual(25, count($keys), 'Pembaca PurchaseOrderResource tidak menemukan kuncinya — pin ini hampa.');

        $detail = $this->spa('views/detail.js');
        preg_match('/const HIDDEN_KEYS = new Set\(\[(.*?)\n\]\);/s', $detail, $h);
        preg_match_all("/'(\w+)'/", (string) ($h[1] ?? ''), $hm);
        $hidden = array_fill_keys($hm[1], true);
        preg_match_all("/\b([a-z][a-z0-9_]*):\s*'/", $this->labelsBlock($detail), $lm);
        $labels = array_fill_keys($lm[1], true);
        preg_match_all("/\{ key: '(\w+)', label: '/", $this->resourceBlock('procurement/purchase-orders'), $fm);
        $form = array_fill_keys($fm[1], true);
        $this->assertGreaterThanOrEqual(10, count($form));

        foreach ($keys as $key) {
            $this->assertTrue(
                isset($hidden[$key]) || str_ends_with($key, '_label') || isset($form[$key]) || isset($labels[$key]),
                "Kunci {$key} dari PurchaseOrderResource akan jatuh ke \"Bidang lainnya\".",
            );
        }
    }

    /** quotation_id dibayangi quotation_code: 'Dari penawaran' tampil sekali pada kontrak. */
    public function test_the_shadowed_quotation_id_never_duplicates_dari_penawaran(): void
    {
        $detail = $this->spa('views/detail.js');
        preg_match('/const NAME_SHADOWED = \{(.*?)\n\};/s', $detail, $m);
        $block = (string) ($m[1] ?? '');
        $this->assertStringContainsString("quotation_id: 'quotation_code'", $block);
        $this->assertStringContainsString("done_by_id: 'done_by_name'", $block);
        $this->assertStringContainsString("quotation_code: 'Dari penawaran'", $this->labelsBlock($detail));
        $this->assertMatchesRegularExpression("/key: 'quotation_id', label: 'Dari penawaran'/", $this->resourceBlock('crm/contracts'));
    }

    /** Tolak memakai isian catatan wajib yang sudah ada; tidak ada aturan panjang 8 karakter di peramban (E-7). */
    public function test_reject_keeps_the_existing_required_note_and_no_length_rule(): void
    {
        $schema = $this->spa('schema.js');
        $this->assertSame(3, substr_count($schema, "fields: [{ key: 'note', label: 'Alasan penolakan', type: 'textarea', required: true }]"),
            'Isian "Alasan penolakan" wajib berubah bentuk atau jumlahnya.');
        $approval = $this->functionBody($schema, 'function approvalActions(');
        $this->assertNotNull($approval);
        foreach ([$approval, $this->spa('views/actions.js'), $this->spa('views/form.js')] as $source) {
            $this->assertDoesNotMatchRegularExpression('/min(?:L|l)ength|length\s*<\s*8|8 karakter/', $source,
                'Aturan panjang catatan penolakan dipasang di peramban — API tidak memberlakukannya (ERRATA E-7).');
        }
    }

    public function test_the_shell_version_went_up_with_the_detail(): void
    {
        $this->assertSame(1, preg_match("~const SHELL_VERSION = '(\\d+)';~", (string) file_get_contents(public_path('app/sw.js')), $version));
        $this->assertGreaterThanOrEqual(65, (int) $version[1],
            'SHELL_VERSION turun di bawah versi yang membawa layar dokumen UX-E2: perangkat yang memegang cangkang lama tidak pernah mengambilnya.');
    }

    /* ------------------------------------------------------------ E2.3 */

    /** Ubin nilai kunci (.kv-tiles, keyValueTile) menggantikan strip uang .stat-row di layar dokumen. */
    public function test_the_headline_tiles_replace_the_summary_strip(): void
    {
        $detail = $this->spa('views/detail.js');
        $this->assertStringNotContainsString('function summaryStrip(', $detail);
        $this->assertStringNotContainsString("el('.stat-row'", $detail, 'Strip uang lama kembali.');
        $this->assertMatchesRegularExpression("/import \{[^}]*\bkeyValueTile\b[^}]*\} from '\.\.\/ui\.js'/", $detail);

        $render = $this->functionBody($detail, 'export async function renderDetail(');
        $panel = strpos($render, 'actionPanel({');
        $tiles = strpos($render, 'headlineTiles(record, tileKeys, labels)');
        $grid = strpos($render, "el('.detail-grid'");
        $this->assertNotFalse($panel);
        $this->assertNotFalse($tiles);
        $this->assertTrue($panel < $tiles && $tiles < $grid, 'Ubin nilai kunci tidak lagi antara panel keadaan dan kisi detail.');
    }

    /** Nilai ubin diformat per kunci: persen sebelum rupiah; kosong → null (keyValueTile mencetak —). */
    public function test_tile_values_are_formatted_per_key_not_always_rupiah(): void
    {
        $body = $this->functionBody($this->spa('views/detail.js'), 'function headlineValue(');
        $this->assertNotNull($body);
        $percent = strpos($body, 'PERCENT_KEY.test(key)');
        $rupiah = strpos($body, 'fmt.rupiah(');
        $this->assertNotFalse($percent);
        $this->assertNotFalse($rupiah);
        $this->assertTrue($percent < $rupiah, 'ppn_rate kembali tercetak "Rp 11" di PPK dan kontrak mandor.');
        $this->assertStringContainsString('return null', $body);
    }

    /** Tanpa headline, SELURUH summary menjadi ubin (bukan lima pertama); Informasi mengecualikan tepat kunci ubin. */
    public function test_headline_falls_back_to_the_whole_summary(): void
    {
        $detail = $this->spa('views/detail.js');
        $keys = $this->functionBody($detail, 'function headlineKeys(');
        $this->assertNotNull($keys);
        $this->assertStringContainsString('detail.headline || detail.summary || []', $keys);
        $this->assertStringNotContainsString('.slice(0, 5)', $detail, 'Invoice AR kehilangan Total · Sudah dibayar · Sisa dari ubinnya (PANDUAN §3.11).');
        $render = $this->functionBody($detail, 'export async function renderDetail(');
        $this->assertStringContainsString('!tileKeys.includes(fieldKey)', $render);
        $this->assertStringNotContainsString('(detail.summary || []).includes(fieldKey)', $render);
        foreach (["ppn_change:", "period_amount:", "cumulative_amount:"] as $entry) {
            $this->assertStringContainsString($entry, $this->labelsBlock($detail), "LABELS kehilangan {$entry}: ubin summary itu kembali berlabel auto-Inggris.");
        }
        $this->assertStringContainsString("value === null || value === undefined || value === '' ? '—'", $this->spa('ui.js'),
            'keyValueTile tidak lagi mencetak — untuk nilai kosong.');
    }

    /** Kunci `headline` terdokumentasi di kepala schema.js dan PO adalah pilotnya, identik dengan summary-nya. */
    public function test_the_schema_key_is_documented_and_po_is_the_pilot(): void
    {
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString('headline', substr($schema, 0, 4000), 'Komentar kepala schema.js tidak menyebut detail.headline.');
        $this->assertMatchesRegularExpression(
            "/summary: \['subtotal', 'discount_amount', 'dpp', 'ppn_amount', 'total'\],\n(?:\s*\/\/[^\n]*\n)*\s+headline: \['subtotal', 'discount_amount', 'dpp', 'ppn_amount', 'total'\],/",
            $this->resourceBlock('procurement/purchase-orders'),
        );
    }

    /* ------------------------------------------------------------ E2.4 */

    /** Kepala dokumen lewat docHeader() DI DALAM .page-head: jenis · lencana · nomor · pihak · meta. */
    public function test_the_document_head_is_doc_header_inside_page_head(): void
    {
        $detail = $this->spa('views/detail.js');
        $render = $this->functionBody($detail, 'export async function renderDetail(');
        // Pin kompatibilitas POSITIF: harness (.page-head h1/.badge/.actions) dan SpaPageHeadStandardTest.
        $this->assertStringContainsString("el('.page-head', [", $render);
        $head = strpos($render, 'docHeader({');
        $badge = strpos($render, "statusBadge(record.status, statusEnum, { label: record.status_label || undefined })");
        $actions = strpos($render, "el('.actions', [");
        $this->assertNotFalse($head);
        $this->assertNotFalse($badge);
        $this->assertTrue($head < $badge && $badge < $actions, 'Lencana status tidak lagi di dalam docHeader() sebelum bilah aksi.');
        $this->assertStringContainsString('kind: def.label,', $render);
        $this->assertStringContainsString('context: documentMeta(def, record)', $render);
        $this->assertStringNotContainsString("el('h1', { text: title })", $detail, 'Kepala dokumen kembali dirakit tangan.');
        $this->assertStringNotContainsString("style: { display: 'flex', alignItems: 'center', gap: '9px'", $detail);
    }

    /** docHeader() menggambar nomor sebagai h1.doc-code; ukurannya tetap --fs-page (tidak ada override 26 px, OQ-25). */
    public function test_doc_header_renders_the_code_as_the_page_h1(): void
    {
        $ui = $this->functionBody($this->spa('ui.js'), 'export function docHeader(');
        $this->assertNotNull($ui);
        $this->assertStringContainsString("el('h1.doc-code.mono'", $ui);
        $css = $this->css();
        foreach (['.doc-head {', '.doc-kind {', '.doc-code {', '.doc-party {'] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
        $this->assertMatchesRegularExpression('/\.page-head h1 \{[^}]*font-size: var\(--fs-page\)/', $css);
        $this->assertStringNotContainsString('.page-head h1.doc-code', $css, 'Ukuran kode dokumen dipaksa lepas dari --fs-page (OQ-25 belum diputus).');
    }

    /** Pihak dibaca seperti renderCell membacanya: pluck pada kolom teks '*.name'. */
    public function test_party_is_read_the_way_render_cell_reads_it(): void
    {
        $body = $this->functionBody($this->spa('views/detail.js'), 'function partyText(');
        $this->assertNotNull($body);
        $this->assertStringContainsString('pluck(record, one.key)', $body);
        $this->assertStringContainsString(".endsWith('.name')", $body);
    }

    /** Sapuan gaya sebaris detail.js: 8 → 2 (kerangka skeleton dan satu ukuran huruf riwayat). */
    public function test_detail_js_inline_styles_went_down(): void
    {
        $detail = $this->spa('views/detail.js');
        $this->assertLessThanOrEqual(2, preg_match_all('/style:\s*\{/', $detail),
            'detail.js kembali memasang gaya sebaris; plafon SpaInlineStyleBudget tidak punya ruang.');
        $this->assertStringNotContainsString("style: { flex: '1' }", $detail);
        $this->assertStringContainsString('.card-body > ul.plain-list {', $this->css());
    }

    /** Bilah tiga zona tidak tersentuh (pin kompatibilitas di balik OQ-23). */
    public function test_the_three_zone_bar_is_untouched(): void
    {
        $render = $this->functionBody($this->spa('views/detail.js'), 'export async function renderDetail(');
        $positions = array_map(static fn (string $needle) => strpos($render, $needle), [
            "el('.zone.navigasi'", "el('.zone.keluaran'", "el('.zone.keputusan', decisions)", '...notePanels,',
        ]);
        foreach ($positions as $position) {
            $this->assertNotFalse($position);
        }
        $this->assertSame($positions, array_values(array_filter($positions, static fn ($p) => $p !== false)));
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Urutan zona bilah aksi berubah.');
    }

    /* ------------------------------------------------------------ E2.5 */

    /** detail.next: tautan tampilan-saja, hanya di panel success, disaring visibleNav (saringan bilah sisi). */
    public function test_detail_next_is_display_only_and_gated_by_the_sidebar_filter(): void
    {
        $detail = $this->spa('views/detail.js');
        $this->assertMatchesRegularExpression("/import \{[^}]*\bvisibleNav\b[^}]*\} from '\.\.\/schema\.js'/", $detail);
        $gate = $this->functionBody($detail, 'function canOpenRoute(');
        $this->assertNotNull($gate);
        $this->assertStringContainsString('visibleNav((perm) => session.can(perm))', $gate);
        $this->assertDoesNotMatchRegularExpression("/\.view'/", $gate, 'Izin dikarang di view, bukan visibleNav (aturan dasar 2).');
        $this->assertStringNotContainsString('RESOURCES[', $gate);
        $link = $this->functionBody($detail, 'function nextLink(');
        $this->assertNotNull($link);
        foreach (["strip.tone !== 'success'", "el('a.btn'", 'href: `#/${next.route}`', 'Buka ${next.label}', "icon('arrow-right'"] as $needle) {
            $this->assertStringContainsString($needle, $link);
        }
        foreach (['api.post', 'runAction(', 'postAction('] as $needle) {
            $this->assertStringNotContainsString($needle, $link, 'Tautan lanjutan bukan lagi tampilan-saja.');
        }
    }

    /** Tautan lanjutan tidak pernah mengklaim dokumen lanjutannya belum ada; PO adalah satu-satunya pemakai. */
    public function test_the_follow_up_link_never_claims_the_document_is_missing(): void
    {
        $link = (string) $this->functionBody($this->spa('views/detail.js'), 'function nextLink(');
        foreach (['belum ada', 'belum dibuat', 'belum diterima'] as $claim) {
            $this->assertStringNotContainsString($claim, $link);
        }
        $schema = $this->spa('schema.js');
        $this->assertMatchesRegularExpression("/next: \{ label: 'Penerimaan Barang \(GRN\)', route: 'r\/inventory\/goods-receipts' \},/", $this->resourceBlock('procurement/purchase-orders'));
        $this->assertSame(1, substr_count($schema, 'next: { label:'));
        $this->assertStringContainsString("route: 'r/inventory/goods-receipts' }", $schema, 'NAV tidak punya layar GRN: saringan tidak punya sasaran.');
        $this->assertStringContainsString('next      { label, route }', substr($schema, 0, 4000));
    }

    /** Ikon panah ada di sprite Lucide yang di-vendor; tautan bergaya tombol tidak digarisbawahi saat hover. */
    public function test_the_arrow_icon_exists_and_the_link_is_not_underlined(): void
    {
        $sprite = (string) file_get_contents(public_path('app/vendor/lucide@1.41.0/sprite.svg'));
        $this->assertStringContainsString('id="lucide-arrow-right"', $sprite);
        $this->assertStringContainsString('.action-panel-actions a.btn:hover { text-decoration: none; }', $this->css());
    }

    /** Separuh penolakan. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertNull($this->functionBody("x\n", 'function nope('));
        $this->assertSame('', $this->labelsBlock("const LABELS = {\n};\n"));
        $this->assertSame(0, substr_count($this->withoutComments("// titleize(\n/* titleize() */\n"), 'titleize('));
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

    /** Sumber tanpa komentar blok maupun baris — supaya `titleize()` di komentar tidak terhitung panggilan. */
    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~//[^\n]*~', '', $source);
    }

    /** Isi `const LABELS = { … };` tanpa komentarnya. */
    private function labelsBlock(string $detail): string
    {
        preg_match('/^const LABELS = \{(.*?)^\};/sm', $detail, $m);
        $block = (string) ($m[1] ?? '');
        $block = (string) preg_replace('~/\*.*?\*/~s', '', $block);

        return trim((string) preg_replace('~//[^\n]*~', '', $block));
    }

    /** Satu entri RESOURCES, supaya kabel tetangganya tidak memuaskan sebuah pin. */
    private function resourceBlock(string $key): string
    {
        $schema = $this->spa('schema.js');
        $start = strpos($schema, "'{$key}': {");
        $this->assertNotFalse($start, "RESOURCES['{$key}'] hilang dari schema.js.");
        $end = strpos($schema, "\n  '", $start + 1);

        return substr($schema, $start, $end === false ? null : $end - $start);
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
