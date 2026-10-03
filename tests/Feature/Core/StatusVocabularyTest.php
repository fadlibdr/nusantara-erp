<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Kosakata status (UX-C.4, ERRATA E-3) — kabelnya.
 *
 * Status tidak boleh hanya warna. Terukur 4 Sep 2026: 'open' hijau untuk tiket
 * dan merah untuk NCR/K3/defect, dan di fotokopi hitam-putih kedua lencana itu
 * sama persis. ERRATA E-3 menolak peta STATUS_UI datar dari README (96 enum,
 * kata yang sama bermakna berlawanan) dan memilih: ikon per enum di tuple
 * opts() yang sudah ada, statusTone() dengan urutan yang tidak berubah, dan
 * statusStrip() sebagai SATU-SATUNYA sumber kalimat. Tidak ada runtime JS di
 * host ini; yang dipaku adalah sumbernya, seperti UiPrimitivesTest.
 *
 * Yang dijaga:
 *  (a) setiap nilai dari setiap enum yang dipakai kolom status di schema.js
 *      (type: 'status', atau key: 'status' yang menyebut enum) punya ikon,
 *      dan ikonnya ada di sprite — nama yang salah menggambar NOL piksel;
 *  (b) urutan statusTone tetap: enum sendiri dulu, lalu peta kata; dan
 *      `submitted` tetap amber (documentStatus tidak mengambil alih warnanya);
 *  (c) sel status daftar dan lencana kepala dokumen memakai statusBadge;
 *  (d) statusStrip mengembalikan data dan dirender lewat actionPanel; tidak ada
 *      generator kalimat kedua (tidak ada STATUS_UI/sentence di enums.js).
 */
class StatusVocabularyTest extends ErpTestCase
{
    /** Enum status yang dipakai di luar kolom schema (layar khusus) — ikut diwajibkan berikon. */
    private const ALWAYS = ['documentStatus', 'paymentStatus', 'postingStatus', 'stockDocStatus', 'transferStatus', 'ticketStatus', 'leadStatus'];

    /* --------------------------------------------------------------- (a) */

    public function test_every_value_of_every_status_enum_has_an_icon_that_exists_in_the_sprite(): void
    {
        $enums = $this->enums();
        $sprite = $this->spriteSymbols();
        $used = array_unique(array_merge($this->statusEnumsInSchema(), self::ALWAYS));
        sort($used);

        $this->assertGreaterThanOrEqual(15, count($used), 'Pemindai schema.js menemukan terlalu sedikit enum status — polanya berubah, bukan aplikasinya.');

        $missing = [];
        foreach ($used as $name) {
            $this->assertArrayHasKey($name, $enums, "schema.js menyebut enum '{$name}' yang tidak ada di enums.js.");
            foreach ($enums[$name] as $option) {
                if ($option['icon'] === null) {
                    $missing[] = "{$name}.{$option['value']} (tanpa ikon)";
                } elseif (! isset($sprite[$option['icon']])) {
                    $missing[] = "{$name}.{$option['value']} → #lucide-{$option['icon']} (tidak ada di sprite)";
                }
            }
        }

        $this->assertSame([], $missing, "Status yang hanya warna, atau ikonnya kotak kosong:\n".implode("\n", $missing));
    }

    /* --------------------------------------------------------------- (b) */

    public function test_status_tone_precedence_is_unchanged_and_submitted_stays_amber(): void
    {
        $format = $this->spa('format.js');
        $tone = $this->functionBody($format, 'export function statusTone(');

        $this->assertNotNull($tone);
        $own = strpos($tone, 'enumTone(enumName, String(value))');
        $map = strpos($tone, "case 'submitted':");
        $this->assertNotFalse($own);
        $this->assertNotFalse($map);
        $this->assertLessThan($map, $own, 'statusTone membaca peta kata SEBELUM warna enum-nya sendiri: NCR "Terbuka" kembali hijau.');

        // statusIcon mengikuti urutan yang sama persis.
        $icon = $this->functionBody($format, 'export function statusIcon(');
        $this->assertNotNull($icon);
        $this->assertLessThan(strpos($icon, "case 'draft':"), strpos($icon, 'enumIcon(enumName, String(value))'));

        // `submitted` amber dari peta kata: documentStatus menulis ikon TANPA mengambil alih warnanya (null).
        $enums = $this->enums();
        $submitted = array_values(array_filter($enums['documentStatus'], fn (array $o): bool => $o['value'] === 'submitted'))[0];
        $this->assertNull($submitted['tone'], 'documentStatus menetapkan warna submitted sendiri; ERRATA E-3 butir 3: tetap amber dari peta kata.');
        $this->assertMatchesRegularExpression("/case 'submitted':(?:\s*case '[a-z_]+':)*\s*return 'amber';/", $tone);
        $this->assertStringNotContainsString("'attn'", $format, '--attn bukan warna status (README C1 vs C4; E-3 memutuskan C1).');
    }

    /* --------------------------------------------------------------- (c) */

    public function test_list_cells_and_the_document_head_draw_the_status_through_status_badge(): void
    {
        $cells = $this->spa('cells.js');
        $status = substr($cells, (int) strpos($cells, "case 'status':"), 600);
        $this->assertStringContainsString('return statusBadge(raw, column.enum, { label: row[`${column.key}_label`] || undefined });', $status,
            'Sel status daftar kembali memakai badge(): lencana tanpa ikon.');

        $detail = $this->spa('views/detail.js');
        $this->assertStringContainsString('statusBadge(record.status, statusEnum, { label: record.status_label || undefined })', $detail,
            'Lencana kepala dokumen kembali memakai badge().');
        $this->assertMatchesRegularExpression("/import \{[^}]*\bstatusBadge\b[^}]*\bactionPanel\b[^}]*\} from '\.\.\/ui\.js'/", $detail);
    }

    /* --------------------------------------------------------------- (d) */

    public function test_the_status_sentence_has_one_source_rendered_through_the_action_panel(): void
    {
        $detail = $this->spa('views/detail.js');
        $strip = $this->functionBody($detail, 'function statusStrip(');

        $this->assertNotNull($strip, 'statusStrip() hilang dari views/detail.js.');
        $this->assertStringContainsString('return { tone, iconName, text, sub };', $strip,
            'statusStrip() tidak lagi mengembalikan data; kalimatnya tidak bisa dipakai layar lain.');
        $this->assertStringNotContainsString("el('.alert", $strip, 'statusStrip() menggambar .alert sendiri lagi, bukan lewat actionPanel.');
        $this->assertStringContainsString("actionPanel({ tone: strip.tone, iconName: strip.iconName, title: strip.text, text: strip.sub })", $detail);

        // "Budi a.n. Sari" tetap datang dari jejak persetujuan, bukan dari kalimat statis.
        $this->assertStringContainsString('actorName(entry)', $strip);
        $this->assertStringContainsString('record.required_levels', $strip);

        // 'attn' hanya bila pintu keputusan dipegang pembaca untuk baris ini.
        $this->assertStringContainsString('session.isDecisionDoor(action)', $detail);
        $this->assertStringContainsString("tone = canDecide ? 'attn' : 'warn';", $strip);

        // Satu generator kalimat: enums.js tidak membawa kalimat atau peta STATUS_UI.
        $enums = $this->spa('enums.js');
        $this->assertStringNotContainsString('STATUS_UI', $enums);
        $this->assertStringNotContainsString('sentence', $enums);
    }

    /** Separuh penolakan. */
    public function test_the_readers_can_still_say_no(): void
    {
        $this->assertNull($this->functionBody("x\n", 'function nope('));
        $this->assertSame([], $this->parseOpts("tidak ada opts di sini"));
        $parsed = $this->parseOpts("  demo: opts([\n    ['a', 'A'], ['b', 'B', 'red'], ['c', 'C', null, 'check'], ['d', 'D', '', 'x'],\n  ]),\n");
        $this->assertSame(
            [['value' => 'a', 'tone' => null, 'icon' => null], ['value' => 'b', 'tone' => 'red', 'icon' => null],
                ['value' => 'c', 'tone' => null, 'icon' => 'check'], ['value' => 'd', 'tone' => '', 'icon' => 'x']],
            $parsed['demo'],
        );
    }

    /* ------------------------------------------------------------ helpers */

    /** @return array<string, list<array{value: string, tone: ?string, icon: ?string}>> */
    private function enums(): array
    {
        return $this->parseOpts($this->spa('enums.js'));
    }

    /** @return array<string, list<array{value: string, tone: ?string, icon: ?string}>> */
    private function parseOpts(string $source): array
    {
        preg_match_all('/^  (\w+): opts\(\[(.*?)\]\),/ms', $source, $blocks, PREG_SET_ORDER);
        $out = [];
        foreach ($blocks as $block) {
            preg_match_all("/\['([^']+)', '[^']*'(?:, (null|'[^']*'))?(?:, '([^']+)')?\]/", $block[2], $tuples, PREG_SET_ORDER);
            $out[$block[1]] = array_map(fn (array $t): array => [
                'value' => $t[1],
                'tone' => ($t[2] ?? '') === '' || $t[2] === 'null' ? null : trim($t[2], "'"),
                'icon' => ($t[3] ?? '') === '' ? null : $t[3],
            ], $tuples);
        }

        return $out;
    }

    /** Nama enum yang dipakai kolom/filter status di schema.js. @return list<string> */
    private function statusEnumsInSchema(): array
    {
        $schema = $this->spa('schema.js');
        preg_match_all("/\{[^{}]*type: 'status'[^{}]*enum: '(\w+)'[^{}]*\}/", $schema, $a);
        preg_match_all("/\{[^{}]*enum: '(\w+)'[^{}]*type: 'status'[^{}]*\}/", $schema, $b);
        preg_match_all("/\{[^{}]*key: 'status'[^{}]*enum: '(\w+)'[^{}]*\}/", $schema, $c);

        return array_values(array_unique(array_merge($a[1], $b[1], $c[1])));
    }

    private function spa(string $relative): string
    {
        return (string) file_get_contents(public_path('app/js/'.$relative));
    }

    private function functionBody(string $source, string $signature): ?string
    {
        $start = strpos($source, $signature);
        if ($start === false) {
            return null;
        }
        $end = strpos($source, "\n}", $start);

        return $end === false ? null : substr($source, $start, $end - $start);
    }

    /** @return array<string, true> */
    private function spriteSymbols(): array
    {
        $sprite = (string) file_get_contents(public_path('app/vendor/lucide@1.41.0/sprite.svg'));
        preg_match_all('/id="lucide-([a-z0-9-]+)"/', $sprite, $matches);

        return array_fill_keys($matches[1], true);
    }
}
