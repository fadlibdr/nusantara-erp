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
        $this->assertGreaterThanOrEqual(68, (int) $version[1],
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
            'present = presentModal, submit = null }',
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
        $this->assertStringContainsString('for (const entry of control.entries())', $form);
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
