<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\BudgetRealisationService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * F-2 / T2.2 — anggaran vs realisasi per proyek × bulan.
 *
 * TIGA HAL YANG DIUJI, dan tidak satu pun di antaranya aritmetika biasa:
 *
 *  1. Anggaran bulanan adalah TURUNAN dan mengaku turunan. Ia bukan angka yang
 *     diketik siapa pun: RAP × bobot fase baseline, dan kalimat penurunannya
 *     ikut dalam muatan supaya layar tidak perlu mengarangnya sendiri.
 *  2. Tanpa baseline TIDAK ADA anggaran bulanan — selnya null dan barisnya
 *     menyebut sebabnya. Meratakan RAP per dua belas bulan akan mencetak
 *     rencana yang tidak pernah disetujui siapa pun, dan itu tepat jenis
 *     karangan yang dilarang paket ini.
 *  3. Bulan tanpa realisasi = null, BUKAN 0. "Rp 0" pada bulan depan terbaca
 *     "anggaran terjaga"; yang benar adalah "belum ada apa-apa yang tercatat".
 */
class BudgetMonthlyTest extends ErpTestCase
{
    private function project(string $code): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => 'Proyek '.$code,
            'type' => 'construction',
            'status' => 'active',
            'contract_value' => 1_000_000_000,
        ]);
    }

    private function approvedRap(Project $project, float $amount): CostBudget
    {
        $boq = Boq::query()->create([
            'project_id' => $project->id,
            'title' => 'RAB '.$project->code,
            'status' => DocumentStatus::Approved,
        ]);
        $section = $boq->sections()->create(['section_no' => 'A', 'name' => 'Struktur']);
        $item = $boq->items()->create([
            'section_id' => $section->id,
            'wbs_code' => 'A.1',
            'description' => 'Paket struktur',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $amount,
            'amount' => $amount,
        ]);

        /** @var CostBudget $rap */
        $rap = CostBudget::query()->create([
            'boq_id' => $boq->id,
            'project_id' => $project->id,
            'target_margin_pct' => 10,
            'status' => DocumentStatus::Approved,
        ]);

        $rap->items()->create([
            'boq_item_id' => $item->id,
            'cost_category' => 'material',
            'description' => 'Paket struktur',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $amount,
            'amount' => $amount,
        ]);

        return $rap;
    }

    /**
     * Baseline disetujui dengan dua paket daun 50/50: Jan–Feb dan Mar–Apr 2026,
     * jadi bobot bulanannya bisa dihitung tangan (25 % per bulan).
     */
    private function approvedBaseline(Project $project, string $code = 'BSL/2026/0001'): int
    {
        $baselineId = (int) DB::table('prj_baselines')->insertGetId([
            'code' => $code,
            'project_id' => $project->id,
            'revision_no' => 0,
            'status' => DocumentStatus::Approved->value,
            'effective_date' => '2026-01-01',
            'bac' => 1_000_000_000,
            'bac_source' => 'rap_approved',
            'planned_start' => '2026-01-01',
            'planned_finish' => '2026-04-30',
            'planned_duration_days' => 120,
            'curve_source' => 'wbs',
            'leaf_task_count' => 2,
            'leaf_weight_total' => 100,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['A.1', 'Paket pertama', 50, '2026-01-01', '2026-02-28'],
            ['A.2', 'Paket kedua', 50, '2026-03-01', '2026-04-30'],
        ] as $index => [$wbs, $name, $weight, $start, $end]) {
            DB::table('prj_baseline_tasks')->insert([
                'baseline_id' => $baselineId,
                'wbs_code' => $wbs,
                'name' => $name,
                'is_leaf' => true,
                'weight_pct' => $weight,
                'planned_start' => $start,
                'planned_end' => $end,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $baselineId;
    }

    private function cost(Project $project, string $date, float $amount): void
    {
        ProjectCost::query()->create([
            'project_id' => $project->id,
            'cost_date' => $date,
            'cost_category' => 'material',
            'description' => 'Realisasi '.$date,
            'amount' => $amount,
        ]);
    }

    private function monthly(Project $project): array
    {
        return app(BudgetRealisationService::class)->monthly($project->id);
    }

    /** @return array<string, array<string, mixed>> baris per periode */
    private function rowsByPeriod(array $payload): array
    {
        $rows = [];

        foreach ($payload['rows'] as $row) {
            $rows[$row['period']] = $row;
        }

        return $rows;
    }

    // ----------------------------------------------------------- yang turunan

    public function test_the_monthly_budget_is_rap_times_the_baseline_phase_weighting_and_says_so(): void
    {
        $project = $this->project('PRJ-2026-921');
        $rap = $this->approvedRap($project, 400_000_000);
        $this->approvedBaseline($project);

        $payload = $this->monthly($project);

        $this->assertTrue($payload['derived']);
        $this->assertStringContainsString('Anggaran bulanan = RAP '.$rap->code, $payload['derivation']);
        $this->assertStringContainsString('bobot fase baseline BSL/2026/0001', $payload['derivation']);
        $this->assertStringContainsString('TURUNAN', $payload['derivation']);

        $rows = $this->rowsByPeriod($payload);

        /*
         * Bobotnya dibagi menurut HARI, bukan menurut bulan yang sama besar —
         * kurva PlannedCurve yang sama dengan EVM. Paket pertama (50 %) berjalan
         * 1 Jan–28 Feb = 59 hari, jadi Januari memikul 31/59 × 50 % = 26,2712 %
         * dan Februari 28/59 × 50 % = 23,7288 %; paket kedua (50 %) berjalan
         * 1 Mar–30 Apr = 61 hari → 25,4098 % dan 24,5902 %.
         *
         * Bobotnya diambil dari persentase KUMULATIF kurva yang dibulatkan 4
         * desimal — angka yang sama persis dengan yang dilaporkan EVM, bukan
         * pecahan tak terbatas — jadi Januari adalah 26,2712 % × Rp 400 jt =
         * Rp 105.084.800 dan bukan Rp 105.084.745,76. Selisih Rp 54 itu
         * ditulis di sini dengan sadar: yang menjadi otoritas adalah kurva,
         * satu kurva, dan sebuah perubahan padanya harus GAGAL di baris ini
         * alih-alih diam-diam menggeser anggaran setiap bulan.
         */
        $expected = [
            '2026-01' => 105_084_800.0,
            '2026-02' => 94_915_200.0,
            '2026-03' => 101_639_200.0,
            '2026-04' => 98_360_800.0,
        ];

        foreach ($expected as $period => $budget) {
            $this->assertSame('turunan', $rows[$period]['budget_state'], "[{$period}]");
            $this->assertEqualsWithDelta($budget, $rows[$period]['budget'], 0.02, "[{$period}]");
        }

        // Dan yang paling penting: bulan-bulannya MENJUMLAH tepat sebesar RAP.
        $this->assertSame(400000000.0, $payload['totals']['budget']);
    }

    public function test_a_project_without_a_baseline_is_ruled_and_shows_no_monthly_budget(): void
    {
        $project = $this->project('PRJ-2026-922');
        $this->approvedRap($project, 400_000_000);
        $this->cost($project, '2026-03-10', 25_000_000);

        $payload = $this->monthly($project);

        $this->assertFalse($payload['derived']);
        $this->assertNull($payload['baseline_code']);
        $this->assertStringContainsString('belum punya baseline yang disetujui', $payload['derivation']);
        $this->assertStringContainsString('meratakan RAP', $payload['derivation']);

        $rows = $this->rowsByPeriod($payload);

        $this->assertNull($rows['2026-03']['budget'], 'tanpa baseline tidak ada anggaran bulanan untuk dicetak');
        $this->assertSame('tanpa_baseline', $rows['2026-03']['budget_state']);
        $this->assertNull($rows['2026-03']['variance']);
        // …realisasinya tetap angka: itu yang sungguh tercatat.
        $this->assertSame(25000000.0, $rows['2026-03']['actual']);
        $this->assertNull($payload['totals']['budget']);
    }

    public function test_a_month_without_realisation_is_null_never_zero(): void
    {
        $project = $this->project('PRJ-2026-923');
        $this->approvedRap($project, 400_000_000);
        $this->approvedBaseline($project);
        $this->cost($project, '2026-02-10', 30_000_000);

        $rows = $this->rowsByPeriod($this->monthly($project));

        $this->assertSame(30000000.0, $rows['2026-02']['actual']);

        foreach (['2026-01', '2026-03', '2026-04'] as $period) {
            $this->assertNull($rows[$period]['actual'], "[{$period}] bulan tanpa biaya harus null, bukan 0");
            $this->assertNull($rows[$period]['variance'], "[{$period}] selisih tanpa realisasi tidak bisa dihitung");
        }
    }

    /**
     * Biaya yang mendarat di bulan yang TIDAK ada dalam rentang baseline tetap
     * muncul — dengan sel anggaran yang digaris dan sebabnya sendiri. Membuang
     * barisnya akan menyembunyikan biaya yang sungguh terjadi.
     */
    public function test_realisation_outside_the_baseline_window_still_shows_with_a_ruled_budget_cell(): void
    {
        $project = $this->project('PRJ-2026-924');
        $this->approvedRap($project, 400_000_000);
        $this->approvedBaseline($project);
        $this->cost($project, '2026-09-05', 12_000_000);

        $rows = $this->rowsByPeriod($this->monthly($project));

        $this->assertArrayHasKey('2026-09', $rows);
        $this->assertNull($rows['2026-09']['budget']);
        $this->assertSame('di_luar_rentang_baseline', $rows['2026-09']['budget_state']);
        $this->assertSame(12000000.0, $rows['2026-09']['actual']);
    }

    public function test_a_baseline_without_an_approved_rap_has_weights_but_no_budget(): void
    {
        $project = $this->project('PRJ-2026-925');
        $this->approvedBaseline($project);

        $payload = $this->monthly($project);
        $rows = $this->rowsByPeriod($payload);

        $this->assertFalse($payload['derived']);
        $this->assertStringContainsString('belum punya RAP yang disetujui', $payload['derivation']);
        $this->assertNotNull($rows['2026-01']['weight_pct'], 'bobot fase ADA — yang tidak ada adalah totalnya');
        $this->assertNull($rows['2026-01']['budget']);
        $this->assertSame('tanpa_rap', $rows['2026-01']['budget_state']);
    }

    public function test_the_endpoint_serves_the_same_payload_behind_fin_view(): void
    {
        $project = $this->project('PRJ-2026-926');
        $this->approvedRap($project, 400_000_000);
        $this->approvedBaseline($project);

        Sanctum::actingAs($this->adminUser());

        $payload = $this->getJson("/api/finance/budget/projects/{$project->id}/monthly")->assertOk()->json('data');

        $this->assertTrue($payload['derived']);
        $this->assertCount(4, $payload['rows']);
        $this->assertStringContainsString('TURUNAN', $payload['derivation']);
    }
}
