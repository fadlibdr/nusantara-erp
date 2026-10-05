<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Pekerjaan Saya (views/pekerjaan.js, UX-F2) — kabelnya.
 *
 * Tidak ada runtime JS di host ini; seperti DetailViewWiringTest, yang dipaku
 * adalah SUMBERNYA, pada janji yang bisa dibalik oleh perubahan kecil yang
 * tidak berbunyi apa-apa di layar. Sisi servernya (core/my-work) sudah dipaku
 * MyWorkTest; di sini kita memaku bahwa layar membaca kunci yang SAMA.
 */
class PekerjaanViewWiringTest extends ErpTestCase
{
    /** Layar ada, terdaftar di app.js, dan remah rotinya ditulis di app.js (satu-satunya yang boleh). */
    public function test_the_screen_exists_and_is_routed_from_app_js(): void
    {
        $view = $this->spa('views/pekerjaan.js');
        $app = $this->spa('app.js');

        $this->assertStringContainsString('export async function renderPekerjaan(host)', $view);
        $this->assertStringContainsString("import { renderPekerjaan } from './views/pekerjaan.js';", $app);
        $this->assertStringContainsString("route('pekerjaan', () => {", $app);
        $this->assertStringContainsString("setCrumbs(['Ringkasan', 'Pekerjaan Saya']);", $app,
            'Remah roti Pekerjaan Saya hilang; SidebarNavWiringTest membatasi penulisnya pada app.js.');
        $this->assertStringContainsString("setActiveNav('pekerjaan');", $app);
        $this->assertStringNotContainsString('setCrumbs(', $this->withoutComments($view),
            'Hanya app.js yang boleh menulis label grup NAV ke remah roti.');
    }

    /** SATU permintaan, dan hanya layar ini yang menamai endpoint-nya. */
    public function test_one_request_to_core_my_work_and_no_other_caller(): void
    {
        $view = $this->withoutComments($this->spa('views/pekerjaan.js'));
        $this->assertSame(1, substr_count($view, "api.list('core/my-work')"));
        $this->assertSame(1, preg_match_all('/\bapi\.[a-z]+\(/', $view), 'Layar ini memanggil lebih dari satu endpoint.');

        $others = [];
        foreach ($this->allSpa() as $path => $source) {
            if ($path !== 'views/pekerjaan.js' && str_contains($this->withoutComments($source), "'core/my-work'")) {
                $others[] = $path;
            }
        }
        $this->assertSame([], $others, 'core/my-work dipanggil dari tempat lain; layar ini satu-satunya pembaca.');
    }

    /** ERRATA D-3: dua tab, dengan namanya; tidak ada "Draf saya" dan tidak ada tab "Semua". */
    public function test_two_tabs_from_d3_and_no_drafts_tab(): void
    {
        $view = $this->withoutComments($this->spa('views/pekerjaan.js'));

        $this->assertStringContainsString("{ id: 'needs_fix', label: 'Perlu diperbaiki' }", $view);
        $this->assertStringContainsString("{ id: 'waiting', label: 'Menunggu orang lain' }", $view);
        $this->assertSame(2, preg_match_all("/\{ id: '[a-z_]+', label: '/", $view), 'Jumlah tab bukan dua (ERRATA D-3).');
        $this->assertStringNotContainsString('Draf saya', $view);
        $this->assertStringNotContainsString("label: 'Semua", $view);
    }

    /** Sumber yang gagal tidak pernah tampak kosong atau nol (CONVENTIONS §14, §16). */
    public function test_a_failed_source_is_never_shown_as_empty_or_as_a_zero(): void
    {
        $view = $this->withoutComments($this->spa('views/pekerjaan.js'));

        $this->assertStringContainsString('Daftar ini belum lengkap', $view);
        $this->assertStringContainsString('failed.join(', $view);
        $this->assertStringContainsString("kind: 'error'", $view);
        $this->assertStringContainsString("kind: 'done'", $view);
        $this->assertStringContainsString('errorState(error,', $view, 'Permintaan yang gagal tidak lagi memakai errorState().');

        // "done" hanya di cabang tanpa kegagalan: ternary `failed.length ? error : done`.
        $this->assertMatchesRegularExpression("/failed\\.length\\s*\\?\\s*emptyState\\([^;]*kind: 'error'[^;]*:\\s*emptyState\\([^;]*kind: 'done'/s", $view,
            'Kondisi gambar "done" tidak lagi dijaga oleh meta.failed.');

        // Jumlah tab: "—" sampai terjawab, "n+" bila daftar belum lengkap; tidak pernah `|| 0`.
        $this->assertStringContainsString('count: null', $view);
        $this->assertStringContainsString('`${countOf(bucket.id)}+`', $view);
        foreach (['count || 0', 'count ?? 0', ') || 0', '.catch(() =>'] as $bad) {
            $this->assertStringNotContainsString($bad, $view, "Pola terlarang di pekerjaan.js: {$bad}");
        }
    }

    /** E-3 butir 4 dan 5: kalimat dari statusStrip() (satu sumber), petunjuk statis dari enums.js. */
    public function test_the_sentence_has_one_source_and_the_hint_is_static_data(): void
    {
        $view = $this->withoutComments($this->spa('views/pekerjaan.js'));
        $detail = $this->spa('views/detail.js');
        $enums = $this->spa('enums.js');

        $this->assertStringContainsString("import { statusStrip } from './detail.js';", $view);
        $this->assertStringContainsString('statusStrip(def, traceRecord(row), false)', $view);
        $this->assertStringContainsString('export function statusStrip(', $detail,
            'statusStrip() tidak lagi diekspor; Pekerjaan Saya akan menulis kalimatnya sendiri.');
        foreach (['Ditolak ', 'menunggu persetujuan', 'Diajukan '] as $sentence) {
            $this->assertStringNotContainsString($sentence, $view, "pekerjaan.js menulis kalimat keadaan sendiri: {$sentence}");
        }

        $this->assertStringContainsString("import { enumHint } from '../enums.js';", $view);
        $this->assertStringContainsString('export function enumHint(name, value)', $enums);
        $hints = $this->functionBody($enums, 'const HINTS = {');
        $this->assertNotNull($hints);
        $this->assertSame(2, preg_match_all("/rejected: '[^']+', submitted: '[^']+'/", (string) $hints),
            'HINTS tidak lagi memuat tepat dua enum (documentStatus, paymentStatus) dengan dua nilai.');
        $this->assertDoesNotMatchRegularExpression('/\$\{|`/', (string) $hints, 'Petunjuk statis memuat interpolasi; nama dan tingkat datang dari server.');
    }

    /** Kunci baris yang dibaca layar ada semuanya di MyWork.php (kontrak dengan UX-F1). */
    public function test_every_row_key_the_view_reads_is_written_by_my_work(): void
    {
        $view = $this->withoutComments($this->spa('views/pekerjaan.js'));
        $server = (string) file_get_contents(base_path('Modules/Core/Support/MyWork.php'));

        preg_match_all('/\brow\.([a-z_]+)/', $view, $m);
        $read = array_values(array_unique($m[1]));
        sort($read);
        $this->assertSame(
            ['amount', 'bucket', 'code', 'days_waiting', 'decided_at', 'decided_by', 'label', 'link', 'note', 'resource', 'status', 'submitted_at', 'title'],
            $read,
            'Kunci baris yang dibaca layar berubah; periksa MyWork.php dan MyWorkTest.',
        );
        foreach ($read as $key) {
            $this->assertStringContainsString("'{$key}'", $server, "MyWork.php tidak menulis kunci '{$key}' yang dibaca layar.");
        }
    }

    /** Anggaran gaya sebaris di ambang: layar baru tanpa satu pun, dan CSS-nya hanya token. */
    public function test_no_inline_styles_and_the_css_is_tokens_only(): void
    {
        $this->assertSame(0, preg_match_all('/style:\s*\{/', $this->spa('views/pekerjaan.js')));

        $css = $this->css();
        $start = (int) strpos($css, '/* UX-F2 — Pekerjaan Saya');
        $this->assertGreaterThan(0, $start, 'Blok CSS Pekerjaan Saya hilang.');
        $block = substr($css, $start);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $block, 'Literal warna di blok Pekerjaan Saya.');
        $this->assertStringContainsString('.work-row[data-bucket="needs_fix"] { border-left-color: var(--danger); }', $block);
        $this->assertStringContainsString('@media (max-width: 760px)', $block, 'Baris Pekerjaan Saya tidak punya aturan ponsel.');
    }

    private function spa(string $relative): string
    {
        return (string) file_get_contents(public_path("app/js/{$relative}"));
    }

    private function css(): string
    {
        return (string) file_get_contents(public_path('app/app.css'));
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
