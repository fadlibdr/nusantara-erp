<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Formulir generik (views/form.js, views/actions.js, rute f/ di app.js) sesudah redesain UX-E3 — kabelnya.
 *
 * Tidak ada runtime JS di host ini; seperti ListViewWiringTest dan DetailViewWiringTest, yang dipaku
 * adalah SUMBERNYA, pada janji yang bisa dibalik oleh perubahan kecil yang tidak berbunyi apa-apa di
 * layar. Setiap tugas paket menambah pin-nya di sini.
 */
class FormViewWiringTest extends ErpTestCase
{
    /* ------------------------------------------------------------ E3.1 */

    /** Putaran konfirmasi-lanjut hidup SEKALI, diekspor, dan runAction memakainya. */
    public function test_the_confirm_resubmit_loop_lives_once_and_is_exported(): void
    {
        $actions = $this->spa('views/actions.js');
        $loop = $this->functionBody($actions, 'export async function withConfirmResubmit(');
        $this->assertNotNull($loop, 'withConfirmResubmit() hilang dari views/actions.js.');
        foreach ([
            'payload[answerKey(candidate)] === undefined',
            'keys.every((key) => candidate.test.test(key))',
            'if (rule.promptField) {',
            'payload[rule.flag] = true;',
        ] as $needle) {
            $this->assertStringContainsString($needle, $loop);
        }
        $this->assertSame(1, substr_count($actions, 'for (let attempt = 0; attempt <= rules.length; attempt += 1)'),
            'Putaran konfirmasi-lanjut digandakan — dua mesin jawaban untuk satu 422.');

        $run = $this->functionBody($actions, 'export async function runAction(');
        $this->assertNotNull($run);
        $this->assertStringContainsString('const run = () => withConfirmResubmit(action, payload, call);', $run);
        $this->assertStringContainsString('let payload = { ...(action.body || {}) };', $run);
        $this->assertStringContainsString('toastActionDone(action, code, result);', $run);
    }

    /** Kalimat sukses sebuah aksi adalah satu fungsi. */
    public function test_the_action_toast_sentence_is_one_function(): void
    {
        $actions = $this->spa('views/actions.js');
        $body = $this->functionBody($actions, 'export function toastActionDone(');
        $this->assertNotNull($body);
        $this->assertStringContainsString('action.toast ? action.toast(code, result)', $body);
        $this->assertStringContainsString('${action.label} berhasil.', $body);
        $this->assertSame(1, substr_count($actions, 'action.toast ? action.toast(code, result)'));
    }

    /** Pin yang dipegang uji lain pada actions.js tetap berdiri (supaya commit E3 berikutnya tidak memindahkannya diam-diam). */
    public function test_the_pins_other_tests_hold_on_actions_js_still_stand(): void
    {
        $actions = $this->spa('views/actions.js');
        foreach ([
            'const printTab = action.printForm && row ? openPrintTab() : null;',
            'showPrintable(printTab, `core/print/forms/${action.printForm}/',
            'typeof action.confirm === \'function\' ? action.confirm(row) : action.confirm',
            '.filter((action) => session.canAct(action))',
            'if (action.opens) {',
            '.filter((action) => !action.boardOnly)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $actions, "Pin actions.js hilang: {$needle}");
        }
    }

    public function test_the_shell_version_went_up_with_the_form(): void
    {
        $this->assertSame(1, preg_match("~const SHELL_VERSION = '(\\d+)';~", (string) file_get_contents(public_path('app/sw.js')), $version));
        $this->assertGreaterThanOrEqual(73, (int) $version[1],
            'SHELL_VERSION turun di bawah versi yang membawa formulir UX-E3: perangkat yang memegang cangkang lama tidak pernah mengambilnya.');
    }

    /* ------------------------------------------------------------ E3.2 */

    /** Simpan & ajukan hanya lewat `submit` yang diberi rute halaman; form.js tidak mengimpor actions.js dan tidak menulis ulang putarannya. */
    public function test_simpan_dan_ajukan_exists_only_on_the_page_and_reuses_the_one_loop(): void
    {
        $form = $this->spa('views/form.js');
        $this->assertStringNotContainsString("from './actions.js'", $form, 'form.js mengimpor actions.js — siklus modul (actions.js mengimpor form.js).');
        $this->assertStringNotContainsString('for (let attempt', $form, 'form.js menulis ulang putaran konfirmasi-lanjut aksi.');
        $open = $this->functionBody($form, 'export async function openForm(');
        $this->assertNotNull($open);
        foreach ([
            'present = presentModal, submit = null, fetched = false }',
            'const canSubmit = Boolean(submit && submit.action) && !prefill && !endpoint',
            "button('Simpan & ajukan', { variant: 'primary', type: 'submit', iconName: 'send' })",
            'const result = await submit.run(saved);',
            'submit.done(code, result);',
        ] as $needle) {
            $this->assertStringContainsString($needle, $open);
        }
        $app = $this->spa('app.js');
        $this->assertStringContainsString("import { postAction, toastActionDone, withConfirmResubmit } from './views/actions.js';", $app);
        $route = $this->functionBody($app, "route('f/*'");
        $this->assertNotNull($route);
        $this->assertStringContainsString("action.key === 'submit'", $route);
        $this->assertStringContainsString('!action.fields && !action.confirm && !action.inlineNote', $route);
        $this->assertStringContainsString('session.canAct(submitAction)', $route);
        $this->assertStringContainsString('withConfirmResubmit(submitAction, payload, () => postAction(submitAction, saved, def, payload))', $route);
        $this->assertStringContainsString("        submit,\n      });", $route);
        $this->assertStringNotContainsString('submit', (string) $this->functionBody($app, 'function offerDrafts('),
            'offerDrafts memberi opsi submit ke openForm — Simpan & ajukan di luar rute f/*.');
    }

    /** Pengajuan yang ditolak mengatakannya dengan kalimat server, mendarat di halaman dokumen, dan tidak pernah mengulang POST pembuatan. */
    public function test_a_refused_submit_says_so_and_never_repeats_the_create(): void
    {
        $open = (string) $this->functionBody($this->spa('views/form.js'), 'export async function openForm(');
        $this->assertStringContainsString('tersimpan sebagai draf — belum diajukan: ${reason}', $open);
        $this->assertStringContainsString('tersimpan sebagai draf — belum diajukan: pengajuan dibatalkan.', $open);
        $this->assertSame(1, substr_count($open, 'api.post('), 'POST pembuatan kedua lahir di openForm — Simpan & ajukan bisa mencetak dua dokumen.');
        $this->assertStringContainsString(".map((messages) => [].concat(messages)[0]).join(' ')", $open);
        $after = strpos($open, 'async function submitAfterSave(');
        $sentence = strpos($open, 'tersimpan sebagai draf — belum diajukan: ${reason}', $after);
        $close = strpos($open, 'dialog.close();', $sentence);
        $saved = strpos($open, 'if (onSaved) onSaved(saved);', $close);
        $this->assertTrue($after !== false && $sentence !== false && $close !== false && $saved !== false,
            'Sesudah penolakan, formulir tidak ditutup lalu onSaved(saved) dipanggil — pembaca tertinggal di formulir yang dokumennya sudah ada.');
        $this->assertStringContainsString("{ tone: 'warn', timeout: 0 }", $open);
    }

    /** Pemanggil dialog tidak mengenal opsi baru; label Simpan tetap kecuali di samping Simpan & ajukan. */
    public function test_the_modal_callers_do_not_know_the_new_options(): void
    {
        foreach (['views/list.js', 'views/detail.js', 'views/actions.js', 'create.js', 'views/custom.js', 'views/rfq.js'] as $file) {
            $source = $this->spa($file);
            $offset = 0;
            while (($at = strpos($source, 'openForm({', $offset)) !== false) {
                $call = substr($source, $at, 400);
                $this->assertDoesNotMatchRegularExpression('/\bsubmit\s*[,:]/', $call, "{$file} memberi opsi submit ke openForm.");
                $offset = $at + 10;
            }
        }
        $open = (string) $this->functionBody($this->spa('views/form.js'), 'export async function openForm(');
        $this->assertStringContainsString("const saveLabel = canSubmit && isDraftLike ? 'Simpan draf' : (isEdit ? 'Simpan Perubahan' : 'Simpan');", $open);
        $this->assertStringContainsString("const siblings = [save, saveSubmit].filter((node) => node && node !== trigger);", $open,
            'Tombol kembaran tidak dinonaktifkan selama simpan berjalan — dua POST pembuatan mungkin.');
    }

    /** Kaki formulir lengket adalah anak terakhir .form-page; kepala halaman tinggal judul; CSS-nya hanya token. */
    public function test_the_form_footer_is_the_last_child_of_the_page(): void
    {
        $presenter = (string) $this->functionBody($this->spa('app.js'), 'function formPagePresenter(');
        $this->assertStringContainsString("host.appendChild(el('.page-head', [el('div', [el('h1', { text: spec.title })])]));", $presenter);
        $this->assertStringContainsString("[spec.body, el('.form-foot', spec.footer)]", $presenter);
        $this->assertStringNotContainsString("el('.zone', spec.footer)", $presenter);
        $css = $this->css();
        $this->assertStringContainsString('.form-foot {', $css);
        $this->assertMatchesRegularExpression('/\.form-foot \{[^}]*position: fixed; left: var\(--sidebar-w\); right: 0; bottom: 0;/', $css,
            'Kaki formulir tidak lagi fixed terhadap jendela — sticky di dalam .main (overflow-y:auto tanpa tinggi) jatuh ke dasar dokumen (S58, 4 Okt 2026).');
        $this->assertStringContainsString('body.rail .form-foot { left: var(--rail-w); }', $css);
        $this->assertStringContainsString('.form-page { padding-bottom: calc(var(--btn-h) + 2 * var(--space-3) + var(--space-4)); }', $css);
        $this->assertStringContainsString('.overflow-menu, .form-foot { display: none !important; }', $css, 'Kaki formulir ikut tercetak.');
        $this->assertMatchesRegularExpression('/\.form-foot \{ left: 0; bottom: calc\(var\(--bottom-nav-h\) \+ env\(safe-area-inset-bottom\)\);/', $css,
            'Di ≤ 760 px kaki formulir tidak berdiri di atas bilah bawah.');
    }

    /** Tidak ada nama izin yang ditulis di formulir. */
    public function test_no_permission_is_spelled_in_the_form(): void
    {
        $form = $this->spa('views/form.js');
        $this->assertDoesNotMatchRegularExpression("/can\('[a-z]+\.[a-z]+'\)/", $form);
        $this->assertStringNotContainsString("'prc.update'", $form);
    }

    /* ------------------------------------------------------------ E3.3 */

    /** Baris kosong berikutnya mengikuti baris terakhir yang terisi; isFilled didefinisikan sebelum dipakai. */
    public function test_an_empty_row_follows_the_last_filled_row(): void
    {
        $lines = (string) $this->functionBody($this->spa('views/form.js'), 'function buildLines(');
        $this->assertStringContainsString('if (controls[controls.length - 1] === entry && isFilled(entry)) addRow();', $lines);
        // Baris yang baru lahir membawa `default` (qty: 1) — hanya baris yang disentuh orang yang terisi.
        $this->assertStringContainsString('const isFilled = (entry) => entry.touched && hasValue(entry.read());', $lines);
        $this->assertStringContainsString("row.addEventListener('input', touch, true);", $lines);
        $this->assertStringContainsString("touched: Object.values(data || {}).some((value) => value !== null && value !== undefined && value !== ''),", $lines);
        $this->assertStringContainsString("row.addEventListener('input', appendIfLast);", $lines);
        $this->assertStringContainsString("row.addEventListener('change', appendIfLast);", $lines);
        $this->assertTrue(strpos($lines, 'const isFilled =') < strpos($lines, 'function addRow('), 'isFilled dipakai sebelum didefinisikan.');
    }

    /** Baris kosong tetap tidak pernah sampai ke server; Tambah baris dan min baris tetap. */
    public function test_empty_rows_still_never_reach_the_server(): void
    {
        $form = $this->spa('views/form.js');
        $lines = (string) $this->functionBody($form, 'function buildLines(');
        $this->assertStringContainsString('const entries = () => controls.filter((entry) => isFilled(entry));', $lines);
        $this->assertStringContainsString('read: () => entries().map((entry) => entry.read()),', $lines);
        $this->assertStringContainsString("button('Tambah baris', { size: 'sm', iconName: 'plus', onClick: () => addRow() })", $lines);
        $this->assertStringContainsString('Array.from({ length: lineDef.min || 1 })', $lines);
        $this->assertStringContainsString('control.entries().forEach((entry, index) => {', $form, 'Pemeriksaan wajib-isi tidak lagi berjalan atas entries() — baris kosong ikut diperiksa.');
    }

    /* ------------------------------------------------------------ E3.4 */

    /** Ringkasan galat dirakit primitif errorSummary() dan melompat ke kolomnya; dipasang sebagai anak pertama badan formulir. */
    public function test_the_error_summary_is_built_by_the_primitive_and_jumps_to_the_field(): void
    {
        $form = $this->spa('views/form.js');
        $this->assertMatchesRegularExpression("/import \{[^}]*\berrorSummary\b[^}]*\} from '\.\.\/ui\.js'/", $form);
        $show = (string) $this->functionBody($form, 'function showSummary(');
        $this->assertStringContainsString('errorSummary(items, { onJump: (fieldKey) => focusField(fieldKey) })', $show);
        $this->assertStringContainsString('body.prepend(summaryNode);', $show);
        $focus = (string) $this->functionBody($form, 'function focusField(');
        foreach (["scrollIntoView({ block: 'center', behavior: 'smooth' })", 'focus({ preventScroll: true })', "toggleAttribute('open', true)"] as $needle) {
            $this->assertStringContainsString($needle, $focus);
        }
        $open = (string) $this->functionBody($form, 'export async function openForm(');
        $this->assertStringContainsString('showSummary(summaryItems);', $open, 'Pemeriksaan wajib-isi tidak lagi mengisi ringkasan.');
        $this->assertStringContainsString('showSummary(items);', $open, '422 tidak lagi mengisi ringkasan.');
        $this->assertStringContainsString('if (firstPainted) focusField(firstPainted);', $open, 'Galat 422 pertama tidak lagi difokuskan.');
    }

    /** Aturan toast dan fokus galat pertama di klien tetap sebyte pun; ringkasan dibersihkan tiap percobaan. */
    public function test_the_toast_rules_and_first_error_focus_are_byte_identical(): void
    {
        $form = $this->spa('views/form.js');
        $this->assertSame(2, substr_count($form, "toast('Periksa isian yang ditandai.', { tone: 'err' });"));
        $this->assertStringContainsString("toastError({ message: painted ? 'Periksa isian yang ditandai.' : error.message, details: unmapped });", $form);
        $this->assertStringContainsString("const first = invalid[0].input || invalid[0].node;\n      first.scrollIntoView({ block: 'center', behavior: 'smooth' });\n      first.focus({ preventScroll: true });", $form);
        $save = (string) $this->functionBody($form, 'async function attemptSave(');
        $this->assertTrue(strpos($save, 'clearSummary();') < strpos($save, 'await withBusy(trigger'), 'Ringkasan lama tidak dibersihkan sebelum percobaan baru.');
    }

    /** Warna catatan hidup adalah kelas, bukan gaya sebaris. */
    public function test_live_note_colour_is_a_class_not_an_inline_style(): void
    {
        $form = $this->spa('views/form.js');
        $this->assertStringNotContainsString('note.style.color', $form);
        $this->assertStringContainsString("note.classList.toggle('tone-warn'", $form);
        $this->assertStringContainsString("note.classList.toggle('tone-neg'", $form);
        $css = $this->css();
        $this->assertStringContainsString('.field .help.tone-warn { color: var(--warning); }', $css);
        $this->assertStringContainsString('.field .help.tone-neg { color: var(--danger); }', $css);
    }

    /* ------------------------------------------------------------ E3.5 */

    /** Navigasi bagian memakai primitif stepper(), hanya bila langkahnya ≥ 2; dialog tidak membacanya. */
    public function test_section_nav_uses_the_stepper_primitive_only_when_there_are_two_or_more_steps(): void
    {
        $form = $this->spa('views/form.js');
        $this->assertMatchesRegularExpression("/import \{[^}]*\bstepper\b[^}]*\} from '\.\.\/ui\.js'/", $form);
        $open = (string) $this->functionBody($form, 'export async function openForm(');
        $this->assertStringContainsString("const numbered = sections.filter(", $open);
        $this->assertStringContainsString("+ lineDefs.length > 1;", $open);
        $this->assertStringContainsString("el('nav.form-nav', { 'aria-label': 'Bagian formulir' }, stepper(", $open);
        $this->assertStringContainsString("const nav = numbered\n    ? el('nav.form-nav'", $open, 'Navigasi bagian dipasang juga pada formulir satu langkah.');
        $ui = (string) $this->functionBody($this->spa('ui.js'), 'export function stepper(');
        $this->assertStringContainsString("el('button.step-link', { type: 'button', onclick: step.onClick, title }, body)", $ui);
        $this->assertStringContainsString('${step.error ? \'.has-error\' : \'\'}', $ui);
        $presenter = (string) $this->functionBody($this->spa('app.js'), 'function formPagePresenter(');
        $this->assertStringContainsString('${spec.nav ? \'.has-nav\' : \'\'}', $presenter);
        $this->assertStringContainsString("el('.form-main', [spec.body, el('.form-foot', spec.footer)])", $presenter);
        $modal = (string) $this->functionBody($this->spa('ui.js'), 'export function modal(');
        $this->assertStringNotContainsString('nav', $modal, 'Dialog mulai membaca navigasi bagian.');
    }

    /** "Lanjutan" adalah <details>; wajib-isi di dalamnya tetap diperiksa dan lipatannya dibuka saat ada galat. */
    public function test_lanjutan_is_a_details_whose_required_fields_are_still_checked(): void
    {
        $form = $this->spa('views/form.js');
        $open = (string) $this->functionBody($form, 'export async function openForm(');
        $this->assertStringContainsString("el('details.form-section.advanced', [", $open);
        $this->assertStringContainsString("if (bad && step.node.tagName === 'DETAILS') step.node.toggleAttribute('open', true);", $open);
        $required = substr($open, strpos($open, 'for (const { spec, control } of fieldControls) {'), 700);
        $this->assertStringNotContainsString("closest('details')", $required, 'Wajib-isi melewati lipatan yang tertutup.');
        $this->assertStringNotContainsString('.open', $required);
        foreach (['markSteps();'] as $needle) {
            $this->assertGreaterThanOrEqual(3, substr_count($open, $needle), 'markSteps() tidak dipanggil di tiap jalur penandaan (bersihkan, wajib-isi, 422).');
        }
    }

    /** PO adalah pilot `advanced`: bagian Lanjutan membawa objek medan yang sama persis. */
    public function test_po_declares_lanjutan_with_the_same_field_objects(): void
    {
        $schema = $this->spa('schema.js');
        $po = $this->resourceBlock('procurement/purchase-orders');
        $this->assertMatchesRegularExpression("/title: 'Lanjutan', advanced: true,/", $po);
        foreach ([
            "{ key: 'payment_term_days', label: 'Termin bayar (hari)', type: 'number', help: 'Kosongkan untuk memakai termin vendor.' }",
            "{ key: 'delivery_address', label: 'Alamat pengiriman', type: 'textarea', span: 2 }",
            "{ key: 'discount_amount', label: 'Diskon', type: 'currency', default: 0 }",
            "{ key: 'expected_date', label: 'Perkiraan kirim', type: 'date', required: true }",
        ] as $literal) {
            $this->assertSame(1, substr_count($po, $literal), "Medan PO berubah bentuk: {$literal}");
        }
        $this->assertSame(1, substr_count($schema, 'advanced: true,'), 'advanced dipakai di luar pilot PO.');
        $this->assertStringContainsString('section { title, help, fields[], advanced }', substr($schema, 0, 4000));
    }

    /** Titik galat dan navigasi lengket adalah aturan CSS di ≥ 1024 px; gaya sebaris form.js turun 13 → 11. */
    public function test_error_dots_and_sticky_nav_are_css_rules_and_inline_styles_went_down(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('.form-nav { display: none; }', $css);
        $at = strpos($css, '@media (min-width: 1024px) {');
        $block = substr($css, $at, 1600);
        $this->assertStringContainsString('.form-nav { display: block; position: sticky; top: var(--space-4); }', $block);
        $this->assertStringContainsString('.form-nav .step.has-error .step-index::after {', $block);
        $form = $this->spa('views/form.js');
        $this->assertLessThanOrEqual(11, preg_match_all('/style:\s*\{/', $form));
        $this->assertStringNotContainsString("style: { marginBottom: '12px' }", $form);
        $this->assertStringContainsString('.form-section > .alert.info { margin-bottom: var(--space-3); }', $css);
        $this->assertStringContainsString('.form-note.alert.info { margin-top: var(--space-4); }', $css);
    }

    /* ------------------------------------------------------------ E3.6 */

    /** DPP hidup adalah rumus server (Subtotal − min(Diskon, Subtotal)) dan tidak lebih — tarif PPN tidak ditebak di peramban. */
    public function test_dpp_is_the_servers_formula_and_nothing_more(): void
    {
        $form = $this->spa('views/form.js');
        $lines = (string) $this->functionBody($form, 'function buildLines(');
        $this->assertStringContainsString('Math.min(Number(headerValues()[discountField]) || 0, sum)', $lines);
        $this->assertStringContainsString("text: 'DPP: '", $lines);
        $this->assertStringContainsString("'PPN dan total dihitung server saat disimpan.'", $lines);
        $this->assertStringNotContainsString('ppn_rate', $this->withoutComments($lines), 'Tarif PPN dibaca/ditebak di peramban — PoService::recalcTotals yang memutuskannya dari status PKP vendor.');
        $this->assertDoesNotMatchRegularExpression('/\*\s*(11|12)\b|\b(11|12)\s*\//', $this->withoutComments($lines), 'Tarif PPN dikarang di peramban.');
        $po = $this->resourceBlock('procurement/purchase-orders');
        $this->assertMatchesRegularExpression("/total: \(row\) => Number\(row\.qty \|\| 0\) \* Number\(row\.unit_price \|\| 0\),\n(?:\s*\/\/[^\n]*\n)*\s+discountField: 'discount_amount',/", $po);
        $this->assertStringContainsString('discountField', substr($this->spa('schema.js'), 0, 4000));
        $this->assertStringContainsString('refresh: refreshTotals,', $lines);
        $open = (string) $this->functionBody($form, 'export async function openForm(');
        $this->assertStringContainsString("lineControls.some(({ def: lineDef }) => lineDef.discountField)", $open);
    }

    /** Kaki tabel baris dan nada saldo tanpa gaya sebaris; gaya sebaris form.js 11 → 8. */
    public function test_lines_total_has_no_inline_style(): void
    {
        $form = $this->spa('views/form.js');
        $this->assertStringContainsString("el('div.lines-total')", $form);
        $this->assertStringNotContainsString("justifyContent: 'flex-end', gap: '18px'", $form);
        $this->assertStringContainsString('el(`span.${balanced ? \'tone-pos\' : \'tone-neg\'}`', $form);
        $this->assertLessThanOrEqual(8, preg_match_all('/style:\s*\{/', $form));
        $css = $this->css();
        $this->assertStringContainsString('.lines-total {', $css);
        $this->assertMatchesRegularExpression('/\.lines-total \{[^}]*gap: var\(--space-4\)/', $css);
        $this->assertStringContainsString('.lines-help { margin-bottom: var(--space-2); }', $css);
    }

    /** Kartu baris di ponsel adalah CSS di atas tabel yang sama; tinggi input tabel meja tetap beku 31 px. */
    public function test_mobile_cards_are_css_over_the_same_table(): void
    {
        $lines = (string) $this->functionBody($this->spa('views/form.js'), 'function buildLines(');
        $this->assertStringContainsString("'data-label': column.label", $lines);
        $this->assertStringContainsString("'data-label': 'Jumlah'", $lines);
        $css = $this->css();
        $this->assertStringContainsString('table.lines td[data-label]::before {', $css);
        $this->assertStringContainsString('table.lines thead { display: none; }', $css);
        $this->assertStringContainsString('table.lines input, table.lines select { height: 31px; font-size: 12.5px; }', $css, 'Tinggi input tabel baris meja (beku, S21) berubah.');
    }

    /* ------------------------------------------------------------ E3.7 */

    /** "Tinggalkan" menyimpan draf yang dijanjikan dialognya; "Buang isian" di dialog tetap membuang. */
    public function test_tinggalkan_keeps_the_draft_its_dialog_promises(): void
    {
        $presenter = (string) $this->functionBody($this->spa('app.js'), 'function formPagePresenter(');
        $this->assertStringContainsString('Isiannya tetap tersimpan sebagai draf di peramban ini.', $presenter);
        $this->assertStringContainsString('spec.onClose({ keepDraft });', $presenter);
        $this->assertStringContainsString('finish(true);', $presenter, 'Dialog berjanji draf bertahan, onClose membuangnya.');
        $open = (string) $this->functionBody($this->spa('views/form.js'), 'export async function openForm(');
        $this->assertStringContainsString('onClose: ({ keepDraft = false } = {}) => {', $open);
        $this->assertStringContainsString('if (keepDraft) { persistDraft(); return; }', $open);
        $this->assertStringContainsString('if (!draftRemovalSuspended()) removeDraft(key, draftRowId);', $open);
        $modal = (string) $this->functionBody($this->spa('ui.js'), 'export function modal(');
        $this->assertMatchesRegularExpression('/\bonClose\(\)/', $modal, 'Dialog "Buang isian" tidak lagi membuang (onClose dipanggil dengan argumen).');
    }

    /** "Pulihkan" lewat startForm: sumber daya berbaris dipulihkan di halamannya; rute f/ menerima /new dan memberi dokumen yang sudah diambil. */
    public function test_pulihkan_and_the_f_route_go_through_the_same_doors(): void
    {
        $app = $this->spa('app.js');
        $this->assertMatchesRegularExpression("/import \{ openForm, startForm, formIsPaged \} from '\.\/views\/form\.js';/", $app);
        $offer = (string) $this->functionBody($app, 'function offerDrafts(');
        $this->assertStringContainsString('startForm({', $offer);
        $this->assertStringNotContainsString('openForm({', $offer);
        // Sesi habis DI halaman formulir: hash sama → navigate() diam; rute di-resolve ulang (S4).
        $this->assertStringContainsString('if (formIsPaged(RESOURCES[key]) && currentPath() === target) {', $offer);
        $this->assertStringContainsString('resolve();', $offer);
        $route = (string) $this->functionBody($app, "route('f/*'");
        $this->assertStringContainsString("if (id === 'new') id = null;", $route);
        $this->assertStringContainsString('fetched: Boolean(row),', $route);
        $form = $this->spa('views/form.js');
        $this->assertStringContainsString('fetched = false', $form);
        $this->assertStringContainsString('if (isEdit && lineDefs.length && !fetched) {', $form);
        foreach (glob(public_path('app/js/views/*.js')) as $file) {
            $this->assertStringNotContainsString('fetched:', (string) file_get_contents($file), basename($file).' memberi fetched: — hanya rute f/ yang sudah mengambil dokumennya.');
        }
    }

    /** form.layout mengalahkan aturan baris, dan belum ada yang memakainya tanpa keputusan pemilik. */
    public function test_form_layout_overrides_the_lines_rule(): void
    {
        $paged = (string) $this->functionBody($this->spa('views/form.js'), 'export function formIsPaged(');
        $this->assertTrue(strpos($paged, "return form.layout === 'page';") < strpos($paged, '(form.lines || []).length > 0 || fields > 8'));
        $schema = $this->spa('schema.js');
        $this->assertStringContainsString('form.layout', substr($schema, 0, 4000));
        $this->assertSame(0, preg_match_all("/layout: '(page|modal)'/", $schema), 'Sebuah formulir memakai layout tanpa keputusan pemilik.');
    }

    /** Halaman memfokuskan medan nyata pertamanya seperti dialog. */
    public function test_the_page_focuses_its_first_real_input_like_the_modal(): void
    {
        $presenter = (string) $this->functionBody($this->spa('app.js'), 'function formPagePresenter(');
        $this->assertStringContainsString('.form-page input:not([type=hidden]):not([disabled]):not([readonly])', $presenter);
        $this->assertStringContainsString('first.focus({ preventScroll: true })', $presenter);
        $this->assertStringContainsString('.modal-body input:not([type=hidden]):not([disabled]):not([readonly])', (string) $this->functionBody($this->spa('ui.js'), 'export function modal('));
    }

    /* ------------------------------------------------------------ E3.8 */

    /** Aturan tata letak README E3: layout → berbaris → lebih dari delapan medan; prefill/endpoint tetap dialog. */
    public function test_form_is_paged_follows_the_handoff_rule_and_start_form_keeps_the_dialog_callers(): void
    {
        $form = $this->spa('views/form.js');
        $paged = (string) $this->functionBody($form, 'export function formIsPaged(');
        $this->assertStringContainsString("if (form.layout) return form.layout === 'page';", $paged);
        $this->assertStringContainsString('return (form.lines || []).length > 0 || fields > 8;', $paged);
        $start = (string) $this->functionBody($form, 'export function startForm(');
        $this->assertStringContainsString('if (!prefill && !endpoint && formIsPaged(def)) {', $start);
    }

    /** Aturan "> 8 medan" memindahkan TEPAT 20 formulir tanpa baris ke halaman — sensus statis schema.js = ux-e3-form-census.json. */
    public function test_the_rule_moves_exactly_the_census_forms(): void
    {
        $schema = $this->spa('schema.js');
        $long = [];
        foreach (array_slice(preg_split("/\n  '/", $schema), 1) as $chunk) {
            $key = substr($chunk, 0, (int) strpos($chunk, "'"));
            $formAt = strpos($chunk, "\n    form: {");
            if ($formAt === false) {
                continue;
            }
            $formEnd = strpos($chunk, "\n    },", $formAt);
            $block = substr($chunk, $formAt, $formEnd === false ? null : $formEnd - $formAt);
            $linesAt = strpos($block, 'lines: [');
            $head = $linesAt === false ? $block : substr($block, 0, $linesAt);
            $fields = preg_match_all("/\bkey: '/", $head); // sama dengan sensus ux-e3-form-census.json untuk 92/92 formulir
            if ($linesAt === false && $fields > 8) {
                $long[] = $key;
            }
        }
        sort($long);
        $expected = ['assets/assets', 'crm/contract-change-orders', 'crm/customers', 'crm/guarantees', 'crm/leads', 'finance/ap-bills', 'finance/ar-invoices',
            'hr/attendance-recaps', 'hr/employees', 'inventory/items', 'procurement/vendor-documents', 'procurement/vendor-evaluations', 'procurement/vendors',
            'projects', 'projects/defects', 'projects/risk-register', 'projects/safety-incidents', 'projects/work-permits', 'quality/ncr', 'servicedesk/tickets'];
        $this->assertSame($expected, $long, 'Daftar formulir panjang berubah — medan ke-9 pada formulir dialog memindahkannya ke halaman; perbarui pin ini dengan sadar.');
    }

    /** Separuh penolakan. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertNull($this->functionBody("x\n", 'function nope('));
        $this->assertSame(0, substr_count($this->withoutComments("// style: {\n/* style: { */\n"), 'style: {'));
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

    /** Satu entri RESOURCES, supaya kabel tetangganya tidak memuaskan sebuah pin. */
    private function resourceBlock(string $key): string
    {
        $schema = $this->spa('schema.js');
        $start = strpos($schema, "'{$key}': {");
        $this->assertNotFalse($start, "RESOURCES['{$key}'] hilang dari schema.js.");
        $end = strpos($schema, "\n  '", $start + 1);

        return substr($schema, $start, $end === false ? null : $end - $start);
    }

    /** Sumber tanpa komentar blok maupun baris. */
    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~//[^\n]*~', '', $source);
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
