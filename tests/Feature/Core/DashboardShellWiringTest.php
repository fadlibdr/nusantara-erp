<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * UX-F3: cangkang kartu dasbor — persegi aksen, judul, CTA; kaki berupa satu
 * kalimat; teks kegagalan dari README.
 *
 * Pemeriksaannya grep karena host ini tidak punya runtime JS. Yang dipaku
 * adalah BARIS PENJAGANYA, bukan kata-katanya: tinjauan UX-F2 menjalankan 22
 * mutasi satu baris atas uji sumber-pin dan 19 lolos karena uji hanya
 * mencari kata yang masih ada di tempat lain berkas itu.
 */
class DashboardShellWiringTest extends ErpTestCase
{
    public function test_the_card_head_is_accent_square_title_and_a_labelled_cta(): void
    {
        $js = $this->read('views/dashboard.js');

        $this->assertStringContainsString("el('span.widget-accent', { 'aria-hidden': 'true' })", $js);
        $this->assertStringContainsString("el('h2', { id: `dash-w-\${widget.id}-title`, text: widget.title })", $js);

        // Penjaganya: tanpa `widget.route ?` tautan menuju #/undefined.
        $this->assertMatchesRegularExpression('/widget\.route\s*\?\s*el\(\'a\.widget-cta\'/', $js);

        // Nama tautan = "Buka" + judul yang SEDANG tertulis (ringkasan-uang dan proyek-progres mengganti judulnya).
        $this->assertStringContainsString(
            "'aria-labelledby': `dash-w-\${widget.id}-cta dash-w-\${widget.id}-title`",
            $js,
        );
        $this->assertStringNotContainsString("'aria-label': `Buka", $js, 'Nama beku membaca judul yang sudah diganti widgetnya.');
    }

    public function test_the_card_shell_css(): void
    {
        $css = $this->read('../app.css');

        $this->assertMatchesRegularExpression(
            '/\.card\.widget \.widget-accent \{[^}]*width: 8px; height: 8px;[^}]*var\(--module-accent\)/',
            $css,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.card\.widget > \.card-head \{[^}]*border-left/',
            $css,
            'Garis kiri 3 px pada kepala kartu digantikan persegi aksen 8 px.',
        );

        // display inline-flex mengalahkan [hidden] bawaan peramban; tanpa aturan ini setDoor(card, null) tak terlihat.
        $this->assertStringContainsString('.card.widget .widget-cta[hidden] { display: none; }', $css);

        // Sasaran sentuh: 24 px lantai, 44 px di penunjuk kasar, tanpa membuat kepala kartu tumbuh.
        $this->assertMatchesRegularExpression('/\.card\.widget \.widget-cta \{[^}]*min-height: 24px; margin-block: -4px;/', $css);
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\) \{\s*\.card\.widget \.widget-cta \{[^}]*min-height: 44px; margin-block: -12px;/', $css);

        // Kartu sempit: tombol kaki turun ke baris sendiri, bukan memeras kalimat jadi lima baris.
        $this->assertStringContainsString('.card-foot.has-sentence { justify-content: space-between; flex-wrap: wrap; }', $css);
        $this->assertStringContainsString('.card-foot.has-sentence > .btn { margin-inline-start: auto; }', $css);
    }

    public function test_foot_sentence_falls_back_to_the_plain_door(): void
    {
        $kit = $this->read('views/widgets/kit.js');

        $this->assertStringContainsString('export function footSentence(sentence, label, onClick)', $kit);
        $this->assertStringContainsString('if (!sentence) return footLink(label, onClick);', $kit,
            'Kalimat kosong harus jatuh ke footLink: kaki tidak boleh hilang.');
        $this->assertStringContainsString("el('.card-foot.has-sentence'", $kit);
    }

    public function test_set_door_hides_the_link_when_the_route_is_unknown(): void
    {
        $kit = $this->read('views/widgets/kit.js');

        $this->assertStringContainsString('export function setDoor(card, route)', $kit);
        $this->assertStringContainsString('link.hidden = !route;', $kit);
        $this->assertStringContainsString("if (route) link.setAttribute('href', `#/\${route}`);", $kit);
    }

    public function test_every_widget_with_data_ends_in_a_sentence(): void
    {
        // ringkasan-uang adalah satu-satunya pengecualian: tiga angka uang tanpa tindakan yang bisa dihitung
        // dari mereka (PROGRESS-UIUX OQ-50).
        $without = [];
        foreach ($this->widgetSources() as $id => $src) {
            if (! str_contains($src, 'footSentence(')) {
                $without[] = $id;
            }
        }

        $this->assertSame(['ringkasan-uang'], $without);
    }

    /**
     * Kepala dan kaki menunjuk ke layar yang SAMA. Katalog hanya mengenal `route` (indeks Laporan untuk tiga
     * widget); kakinya menaut ke tab tertentu. Setiap tautan dalam di kaki harus dipasang juga di kepala.
     */
    public function test_a_deep_foot_link_is_also_the_head_link(): void
    {
        preg_match_all("/id: '([a-z0-9-]+)',.*?route: '([^']+)'/s", $this->read('views/widgets/registry.js'), $entries, PREG_SET_ORDER);
        $this->assertGreaterThan(15, count($entries), 'Katalog tidak terbaca — uji ini tidak membuktikan apa pun.');

        $deep = 0;
        foreach ($entries as [, $id, $route]) {
            $src = $this->widgetSources()[$id] ?? null;
            $this->assertNotNull($src, "Berkas widget [$id] tidak ada.");

            preg_match_all("/navigate\('(".preg_quote($route, '/')."\?[^']+)'\)/", $src, $targets);
            foreach (array_unique($targets[1]) as $target) {
                $deep++;
                $this->assertStringContainsString(
                    "setDoor(card, '$target');",
                    $src,
                    "Widget [$id] menaut ke [$target] di kakinya, tetapi kepalanya masih menunjuk ke [$route].",
                );
            }
        }

        $this->assertSame(3, $deep, 'Jumlah tautan dalam berubah (ar-aging, ap-aging, proyeksi-kas) — perbarui uji ini dengan sadar.');
    }

    public function test_ringkasan_uang_follows_the_block_the_server_sent(): void
    {
        $src = $this->widgetSources()['ringkasan-uang'];

        // Sumber jatuh: pintunya tidak diketahui, tautan disembunyikan.
        $this->assertStringContainsString('setDoor(card, null);', $src);
        // Berhasil: kepala menunjuk pintu yang sama dengan kakinya (atau tidak ada).
        $this->assertStringContainsString('setDoor(card, door ? door.route : null);', $src);
        // Judul tidak lagi terbaca "piutang & hutang" berhuruf kecil untuk peran tanpa prj.view.
        $this->assertStringContainsString('heading.textContent = text.charAt(0).toUpperCase() + text.slice(1);', $src);
    }

    public function test_the_cash_sentence_never_speaks_without_a_known_lowest_point(): void
    {
        $src = $this->widgetSources()['proyeksi-kas'];

        // Number(null) === 0: tanpa penjaga ini kalimat "tidak turun di bawah nol" ditulis di atas angka yang tidak ada.
        $this->assertStringContainsString(
            "const lowestKnown = lowest.balance !== null && lowest.balance !== undefined && Number.isFinite(Number(lowest.balance));",
            $src,
        );
        $this->assertMatchesRegularExpression('/!lowestKnown\s*\?\s*\'\'/', $src);
        // Peringatan mengalahkan kabar baik.
        $this->assertMatchesRegularExpression('/warnings\.length\s*\?\s*\'Proyeksi memuat peringatan/', $src);
    }

    public function test_the_payroll_sentences_only_name_real_document_statuses(): void
    {
        $src = $this->widgetSources()['payroll'];
        preg_match('/const NEXT_STEP = \{(.*?)\};/s', $src, $block);
        $this->assertNotEmpty($block, 'NEXT_STEP tidak ditemukan.');
        preg_match_all("/^\s+([a-z_]+): '/m", $block[1], $keys);

        $enum = (string) file_get_contents(base_path('Modules/Core/Enums/DocumentStatus.php'));
        preg_match_all("/case \w+ = '([a-z_]+)';/", $enum, $cases);

        $this->assertNotSame([], $keys[1]);
        $this->assertSame([], array_diff($keys[1], $cases[1]), 'NEXT_STEP menyebut status yang bukan DocumentStatus.');
        $this->assertStringContainsString("NEXT_STEP[latest.status] ? `Run terbaru \${latest.code} \${NEXT_STEP[latest.status]}` : ''", $src);
    }

    public function test_the_progress_sentence_uses_the_bar_threshold_and_respects_the_window(): void
    {
        $src = $this->widgetSources()['proyek-progres'];

        // Satu ambang untuk batang amber dan kalimat: dua ambang menaruh "tertinggal" di atas batang hijau.
        $this->assertStringContainsString(
            'const behind = (row) => Number(row.actual_progress_pct || 0) + 0.01 < Number(row.planned_progress_pct || 0);',
            $src,
        );
        $this->assertStringContainsString("progressBar(actual, behind(row) ? 'amber' : 'green')", $src);
        $this->assertStringContainsString('active.length && behind(active[0])', $src);
        // Selisih seperti tabelnya; Math.round menulis "tertinggal 0 poin" untuk -0,3.
        $this->assertStringContainsString('tertinggal ${fmt.percent(-active[0].deviation)} dari rencana', $src);
        // "Tidak ada yang tertinggal" hanya dari peringkat yang menyapu seluruh proyek.
        $this->assertStringContainsString("active.length && !truncated ? 'Tidak ada proyek berjalan yang tertinggal", $src);
    }

    public function test_the_failure_text_is_the_readmes(): void
    {
        $kit = $this->read('views/widgets/kit.js');

        $this->assertStringContainsString(
            "message: 'Tidak dapat dimuat — angkanya tidak diketahui, jangan dibaca sebagai nol.'",
            $kit,
        );
        $this->assertStringContainsString('}, retry));', $kit, 'failedBody harus tetap memberi tombol Coba lagi.');
    }

    /** @return array<string, string> id => sumber, hanya berkas ber-build() */
    private function widgetSources(): array
    {
        $out = [];
        foreach (glob(public_path('app/js/views/widgets/*.js')) as $path) {
            $src = (string) file_get_contents($path);
            if (str_contains($src, 'export async function build(')) {
                $out[basename($path, '.js')] = $src;
            }
        }

        return $out;
    }

    private function read(string $relative): string
    {
        $path = $relative === '../app.css' ? 'app/app.css' : 'app/js/'.$relative;

        return (string) file_get_contents(public_path($path));
    }
}
