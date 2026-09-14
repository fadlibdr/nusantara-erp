<?php

namespace Tests\Feature\HrPayroll;

use Tests\ErpTestCase;

/**
 * PANDUAN TIDAK BOLEH MEMBERI DUA RUMUS LEMBUR YANG BERBEDA.
 *
 * F-5 menyunting PANDUAN-PENGGUNA (+85 baris, §21) tetapi meninggalkan blok
 * lama di bab payroll utuh — seribu delapan ratus baris lebih awal, dan justru
 * bab yang dibaca orang yang MENJALANKAN payroll:
 *
 *     "Pemisahan tarif 1,5× / 2× per hari (Kepmenaker 102/2004) tidak
 *      diterapkan — tarif rata 1,5× dipakai karena rekap hanya menyimpan
 *      total jam."
 *
 * Sejak 14 September 2026 kalimat itu salah, dan angkanya bisa dihitung: data
 * yang sama (6 jam atas 3 hari, upah sebulan 11 jt) membayar 572.254,34 dengan
 * rumus lama dan 667.630,06 dengan yang berlaku. Operator yang memeriksa bruto
 * terhadap rumus di panduan akan menemukan selisih yang tidak ia mengerti dan
 * tidak punya alasan mencurigai panduannya — lalu melaporkan perhitungan yang
 * BENAR sebagai cacat, atau menerima yang salah karena panduannya membenarkan
 * keduanya.
 *
 * Berkas ini memaku kalimat-kalimat yang berhenti benar pada tanggal itu,
 * dengan cara yang sama yang sudah dipakai `AttendanceRecapOvertimeProposalTest`
 * untuk kalimat penolakan usulan rekap yang usang.
 */
class OvertimeDocsDoNotContradictEachOtherTest extends ErpTestCase
{
    /**
     * Berkas panduan yang bisa dibaca manusia, dan kalimat yang tidak boleh ada
     * lagi di dalamnya.
     *
     * @var array<string, list<string>>
     */
    private const STALE_SENTENCES = [
        'docs/PANDUAN-PENGGUNA.md' => [
            'Pemisahan tarif 1,5× / 2× per hari (Kepmenaker 102/2004) **tidak',
            'rata 1,5× dipakai karena rekap hanya menyimpan total jam',
        ],
    ];

    public function test_no_guide_still_says_the_daily_rate_split_is_not_applied(): void
    {
        foreach (self::STALE_SENTENCES as $relative => $sentences) {
            $text = (string) file_get_contents(base_path($relative));

            foreach ($sentences as $sentence) {
                $this->assertStringNotContainsString(
                    $sentence,
                    $text,
                    sprintf(
                        '%s masih memuat rumus lembur yang berhenti benar pada 14 September 2026. '
                        .'Dua bab yang memberi dua rumus berbeda untuk satu angka gaji adalah '
                        .'panduan yang membenarkan perhitungan mana pun yang kebetulan dibaca '
                        .'lebih dulu.',
                        $relative,
                    ),
                );
            }
        }
    }

    /**
     * ...dan sisi sebaliknya: bab payroll HARUS menyebut kedua jalurnya, supaya
     * yang tersisa bukan diam.
     */
    public function test_the_payroll_chapter_names_both_paths_and_points_at_the_timesheet_chapter(): void
    {
        $text = (string) file_get_contents(base_path('docs/PANDUAN-PENGGUNA.md'));

        $this->assertStringContainsString('**berapa jamnya selalu dari rekap bulanan**', $text);
        $this->assertStringContainsString('Tarif jam pertama rata', $text);
        $this->assertStringContainsString('Rincian harian** (sejak 14 Sep 2026)', $text);
    }
}
