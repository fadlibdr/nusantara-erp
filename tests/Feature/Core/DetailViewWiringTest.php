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

        // titleize( dipanggil tepat di: definisinya, autoValue (objek bersarang), summaryStrip (ubin
        // ringkasan, sampai E2.3 menggantinya), kv() dan Terkait — dihitung tanpa komentar, yang
        // menyebut titleize() berkali-kali sebagai peringatan.
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
        $this->assertGreaterThanOrEqual(62, (int) $version[1],
            'SHELL_VERSION turun di bawah versi yang membawa layar dokumen UX-E2: perangkat yang memegang cangkang lama tidak pernah mengambilnya.');
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
