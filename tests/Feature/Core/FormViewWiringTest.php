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
        $this->assertGreaterThanOrEqual(66, (int) $version[1],
            'SHELL_VERSION turun di bawah versi yang membawa formulir UX-E3: perangkat yang memegang cangkang lama tidak pernah mengambilnya.');
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
