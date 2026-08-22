<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Tests\ErpTestCase;

/**
 * Temuan #78 — analitik win-rate tender.
 *
 * won_at, lost_at dan lost_reason sudah dicatat sejak QuotationService lahir,
 * dan tidak satu pun layar/endpoint mengagregasinya: manajemen tidak bisa
 * menjawab 'berapa persen tender kita menang, dan kenapa kita kalah' padahal
 * datanya lengkap. Endpoint ini murni membaca yang sudah ada — win-rate per
 * kuartal keputusan, nilai menang vs kalah, dan alasan kalah terbanyak.
 *
 * Kuartal diambil dari TANGGAL KEPUTUSAN (won_at / lost_at), bukan tanggal
 * penawaran dibuat: pertanyaan manajemen adalah "kuartal lalu kita menang
 * berapa", dan penawaran yang menggantung dua kuartal harus dihitung saat
 * nasibnya diputuskan, bukan saat dokumennya diketik.
 */
class PipelineReportTest extends ErpTestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::query()->create([
            'name' => 'PT Graha Sentosa Propertindo',
            'is_pkp' => true,
            'status' => 'active',
        ]);
    }

    // -------------------------------------------------------------- fixtures

    private function quotation(array $attributes = []): Quotation
    {
        return Quotation::query()->create(array_merge([
            'customer_id' => $this->customer->id,
            'title' => 'Penawaran '.str()->random(6),
            'scope_type' => 'construction',
            'status' => DocumentStatus::Approved,
            'dpp' => 1_000_000_000,
            'total' => 1_110_000_000,
        ], $attributes));
    }

    private function won(float $dpp, string $on): Quotation
    {
        return $this->quotation(['dpp' => $dpp, 'won_at' => $on.' 10:00:00']);
    }

    private function lost(float $dpp, string $on, ?string $reason): Quotation
    {
        return $this->quotation([
            'dpp' => $dpp,
            'lost_at' => $on.' 10:00:00',
            'lost_reason' => $reason,
            'status' => DocumentStatus::Closed,
        ]);
    }

    /** Two decided quarters plus one quotation still in play. */
    private function seedPipeline(): void
    {
        // Q1 2026: 2 menang (1 M + 2 M), 1 kalah (3 M — harga).
        $this->won(1_000_000_000, '2026-01-15');
        $this->won(2_000_000_000, '2026-03-02');
        $this->lost(3_000_000_000, '2026-02-10', 'Harga terlalu tinggi');

        // Q2 2026: 1 menang (4 M), 3 kalah (1 M + 1 M harga, 2 M spesifikasi).
        $this->won(4_000_000_000, '2026-05-20');
        $this->lost(1_000_000_000, '2026-04-08', 'Harga terlalu tinggi');
        $this->lost(1_000_000_000, '2026-06-25', 'Harga terlalu tinggi');
        $this->lost(2_000_000_000, '2026-05-11', 'Spesifikasi tidak terpenuhi');

        // Masih berjalan — bukan menang, bukan kalah, tidak masuk win-rate.
        $this->quotation(['dpp' => 9_000_000_000]);
    }

    private function report(): array
    {
        return $this->actingAs($this->adminUser())
            ->getJson('/api/crm/reports/pipeline')
            ->assertOk()
            ->json('data');
    }

    // ---------------------------------------------------------- per kuartal

    public function test_win_rate_is_reported_per_decision_quarter(): void
    {
        $this->seedPipeline();

        $quarters = collect($this->report()['quarters'])->keyBy('quarter');

        $q1 = $quarters['2026-Q1'];
        $this->assertSame(2, $q1['won_count']);
        $this->assertSame(1, $q1['lost_count']);
        // 1 M + 2 M = 3 M menang; 3 M kalah.
        $this->assertEqualsWithDelta(3_000_000_000, $q1['won_value'], 0.01);
        $this->assertEqualsWithDelta(3_000_000_000, $q1['lost_value'], 0.01);
        // 2 / (2 + 1) = 66,7%.
        $this->assertEqualsWithDelta(66.7, $q1['win_rate'], 0.05);

        $q2 = $quarters['2026-Q2'];
        $this->assertSame(1, $q2['won_count']);
        $this->assertSame(3, $q2['lost_count']);
        $this->assertEqualsWithDelta(4_000_000_000, $q2['won_value'], 0.01);
        // 1 M + 1 M + 2 M = 4 M.
        $this->assertEqualsWithDelta(4_000_000_000, $q2['lost_value'], 0.01);
        // 1 / 4 = 25%.
        $this->assertEqualsWithDelta(25.0, $q2['win_rate'], 0.05);
    }

    public function test_quarters_come_out_in_calendar_order(): void
    {
        $this->won(1_000_000_000, '2026-04-15');
        $this->won(1_000_000_000, '2026-01-15');
        $this->lost(1_000_000_000, '2025-11-03', 'Harga terlalu tinggi');

        $this->assertSame(
            ['2025-Q4', '2026-Q1', '2026-Q2'],
            array_column($this->report()['quarters'], 'quarter'),
        );
    }

    // -------------------------------------------------------- alasan kalah

    public function test_lose_reasons_are_ranked_by_frequency(): void
    {
        $this->seedPipeline();

        $reasons = $this->report()['lose_reasons'];

        $this->assertSame('Harga terlalu tinggi', $reasons[0]['reason']);
        $this->assertSame(3, $reasons[0]['count']);
        // 3 M + 1 M + 1 M = 5 M nilai yang hilang karena harga.
        $this->assertEqualsWithDelta(5_000_000_000, $reasons[0]['value'], 0.01);

        $this->assertSame('Spesifikasi tidak terpenuhi', $reasons[1]['reason']);
        $this->assertSame(1, $reasons[1]['count']);
    }

    /**
     * markLost() accepts a null reason from the API, so old rows exist without
     * one — they must still be counted, labelled honestly, or the top-reason
     * ranking silently under-reports exactly the sloppiest records.
     */
    public function test_a_lost_quotation_without_a_reason_is_still_counted(): void
    {
        $this->lost(1_000_000_000, '2026-02-10', null);

        $reasons = $this->report()['lose_reasons'];

        $this->assertSame('Tidak dicatat', $reasons[0]['reason']);
        $this->assertSame(1, $reasons[0]['count']);
    }

    // -------------------------------------------------------------- totals

    public function test_the_totals_summarise_the_whole_pipeline(): void
    {
        $this->seedPipeline();

        $totals = $this->report()['totals'];

        $this->assertSame(3, $totals['won_count']);
        $this->assertSame(4, $totals['lost_count']);
        // 3 / (3 + 4) = 42,9%.
        $this->assertEqualsWithDelta(42.9, $totals['win_rate'], 0.05);
        $this->assertEqualsWithDelta(7_000_000_000, $totals['won_value'], 0.01);
        $this->assertEqualsWithDelta(7_000_000_000, $totals['lost_value'], 0.01);
        // Satu penawaran 9 M masih menggantung — pipeline berjalan, bukan nol.
        $this->assertSame(1, $totals['undecided_count']);
        $this->assertEqualsWithDelta(9_000_000_000, $totals['undecided_value'], 0.01);
    }

    /**
     * Penawaran yang DITOLAK INTERNAL (maker-checker menolak dokumennya)
     * tidak pernah sampai ke meja tender: bukan menang, bukan kalah, dan
     * jelas bukan 'Masih berjalan'. Menghitungnya sebagai undecided
     * menggelembungkan pipeline hidup dengan kertas mati — manajemen membaca
     * nilai 'berjalan' yang tidak akan pernah diputuskan siapa pun.
     */
    public function test_an_internally_rejected_quotation_is_not_still_in_play(): void
    {
        $this->seedPipeline();
        $this->quotation(['dpp' => 5_000_000_000, 'status' => DocumentStatus::Rejected]);

        $totals = $this->report()['totals'];

        // Hanya penawaran 9 M dari seedPipeline yang benar-benar menggantung.
        $this->assertSame(1, $totals['undecided_count']);
        $this->assertEqualsWithDelta(9_000_000_000, $totals['undecided_value'], 0.01);

        // Yang ditolak internal tersebut namanya sendiri, bukan menghilang.
        $this->assertSame(1, $totals['rejected_count']);
        $this->assertEqualsWithDelta(5_000_000_000, $totals['rejected_value'], 0.01);

        // Win-rate tidak berubah: penolakan internal bukan keputusan tender.
        $this->assertSame(3, $totals['won_count']);
        $this->assertSame(4, $totals['lost_count']);
    }

    /** An empty pipeline reports honestly: no quarters, win rate null — not 0%. */
    public function test_an_empty_pipeline_reports_nothing_not_zero(): void
    {
        $report = $this->report();

        $this->assertSame([], $report['quarters']);
        $this->assertSame([], $report['lose_reasons']);
        $this->assertNull($report['totals']['win_rate']);
    }

    // ------------------------------------------------------------ the gate

    /** Angka penjualan perusahaan — crm.view yang membukanya. */
    public function test_the_report_is_gated_by_crm_view(): void
    {
        $outsider = User::query()->create([
            'name' => 'Tanpa Akses',
            'email' => 'outsider@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->getJson('/api/crm/reports/pipeline')
            ->assertForbidden();
    }
}
