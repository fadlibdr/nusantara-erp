<?php

namespace Tests\Feature\Assets;

use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Modules\Assets\Enums\DeploymentStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Services\DeploymentService;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\ProjectCost;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Monthly accrual of the internal plant charge (T43).
 *
 * Before accrueMonth() existed, an open deployment contributed Rp 0 to project
 * cost for its whole life and the entire span landed in ONE row dated the
 * demobilisation day — on the live file three machines had been on site for up
 * to five months with the equipment bucket at literally zero while the RAP
 * budgeted Rp 178.031.790,79 for it, so AC, CPI, EAC and the POC preview all
 * understated. These tests pin the arithmetic that replaces the lump: one row
 * per (deployment, month), a reference key that survives BOTH the same month
 * in another year AND another machine in the same month, and a demobilisation
 * that charges exactly the days no accrual has covered yet.
 */
class PlantAccrualTest extends ErpTestCase
{
    use FinanceFixtures;

    private DeploymentService $deployments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->deployments = app(DeploymentService::class);
    }

    private function asset(string $code = 'AST-0001'): Asset
    {
        $category = AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            [
                'name' => 'Alat Berat',
                'useful_life_months_default' => 96,
                'depreciation_account_hint' => '6-3100',
                'accum_account_hint' => '1-2410',
            ],
        );

        return Asset::query()->create([
            'code' => $code,
            'name' => 'Excavator Komatsu PC200',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'depreciation_start_date' => '2025-01-01',
            'acquisition_cost' => 960000000,
            'useful_life_months' => 96,
            'salvage_value' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 960000000,
            'status' => 'available',
        ]);
    }

    /** DEP from 2026-03-02 at Rp 2.500.000/hari, as DEP/2026/III/0001 stands live. */
    private function marchDeployment(Project $project, ?float $rate = 2500000, string $assetCode = 'AST-0001', string $from = '2026-03-02'): Deployment
    {
        return $this->deployments->deploy($this->asset($assetCode), [
            'project_id' => (int) $project->id,
            'deployed_from' => $from,
            'daily_rate_internal' => $rate,
        ]);
    }

    private function monthlyRows(Deployment $deployment): Collection
    {
        return ProjectCost::query()
            ->where('reference_type', 'asset_deployment_month')
            ->whereBetween('reference_id', [
                $deployment->id * 1_000_000,
                $deployment->id * 1_000_000 + 999_999,
            ])
            ->orderBy('reference_id')
            ->get();
    }

    // ------------------------------------------------------ month arithmetic

    public function test_the_first_month_is_accrued_from_the_deployment_date_not_the_month_start(): void
    {
        $deployment = $this->marchDeployment($this->makeProject());

        $this->deployments->accrueMonth(2026, 3);

        $rows = $this->monthlyRows($deployment);
        $this->assertCount(1, $rows);
        // 2026-03-02 sampai 2026-03-31 inklusif = 30 hari x 2.500.000 = 75.000.000
        $this->assertEqualsWithDelta(75000000.0, (float) $rows[0]->amount, 0.001);
        $this->assertSame('2026-03-31', $rows[0]->cost_date->toDateString());
        $this->assertSame('equipment', $rows[0]->cost_category->value);
        $this->assertSame($deployment->id * 1_000_000 + 202603, (int) $rows[0]->reference_id);
    }

    public function test_a_full_middle_month_charges_every_calendar_day(): void
    {
        $deployment = $this->marchDeployment($this->makeProject());

        $this->deployments->accrueMonth(2026, 4);
        $this->deployments->accrueMonth(2026, 5);

        $rows = $this->monthlyRows($deployment);
        $this->assertCount(2, $rows);
        // April 30 hari, Mei 31 hari.
        $this->assertEqualsWithDelta(75000000.0, (float) $rows[0]->amount, 0.001);
        $this->assertEqualsWithDelta(77500000.0, (float) $rows[1]->amount, 0.001);
        $this->assertSame('2026-04-30', $rows[0]->cost_date->toDateString());
        $this->assertSame('2026-05-31', $rows[1]->cost_date->toDateString());
    }

    public function test_a_deployment_that_starts_after_the_month_is_not_accrued(): void
    {
        $this->marchDeployment($this->makeProject());

        $this->deployments->accrueMonth(2026, 2);

        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_deployment_without_a_rate_accrues_nothing(): void
    {
        $this->marchDeployment($this->makeProject(), null);

        $this->deployments->accrueMonth(2026, 3);

        $this->assertSame(0, ProjectCost::query()->count());
    }

    // -------------------------------------------------------- the key carries
    // -------------------------------------------- BOTH deployment AND year

    /**
     * The fence's own proposed key (deployment_id * 100 + month) gave March
     * 2026 and March 2027 the same reference_id, and record() is
     * updateOrCreate, so the second year silently OVERWROTE the first. Both
     * March rows must survive, each with its own month's day count.
     */
    public function test_the_same_calendar_month_in_two_years_produces_two_rows(): void
    {
        $this->openFiscalYear(2025);
        $deployment = $this->marchDeployment($this->makeProject(), 2500000, 'AST-0001', '2025-03-02');

        $this->deployments->accrueMonth(2025, 3);
        $this->deployments->accrueMonth(2026, 3);

        $rows = $this->monthlyRows($deployment);
        $this->assertCount(2, $rows);
        $this->assertSame($deployment->id * 1_000_000 + 202503, (int) $rows[0]->reference_id);
        $this->assertSame($deployment->id * 1_000_000 + 202603, (int) $rows[1]->reference_id);
        // 2025-03: mulai 02 = 30 hari; 2026-03: bulan penuh = 31 hari.
        $this->assertEqualsWithDelta(75000000.0, (float) $rows[0]->amount, 0.001);
        $this->assertEqualsWithDelta(77500000.0, (float) $rows[1]->amount, 0.001);
    }

    /**
     * The other collapse (year * 100 + month alone): every open deployment in
     * the same month would share one reference_id and the second machine
     * accrued would overwrite the first.
     */
    public function test_two_deployments_open_in_the_same_month_each_keep_their_own_row(): void
    {
        $project = $this->makeProject();
        $first = $this->marchDeployment($project, 2500000, 'AST-0001');
        $second = $this->marchDeployment($project, 1000000, 'AST-0002');

        $this->deployments->accrueMonth(2026, 3);

        $this->assertCount(1, $this->monthlyRows($first));
        $this->assertCount(1, $this->monthlyRows($second));
        $this->assertEqualsWithDelta(75000000.0, (float) $this->monthlyRows($first)[0]->amount, 0.001);
        $this->assertEqualsWithDelta(30000000.0, (float) $this->monthlyRows($second)[0]->amount, 0.001);
    }

    public function test_rerunning_a_month_is_idempotent(): void
    {
        $deployment = $this->marchDeployment($this->makeProject());

        $this->deployments->accrueMonth(2026, 3);
        $this->deployments->accrueMonth(2026, 3);

        $rows = $this->monthlyRows($deployment);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(75000000.0, (float) $rows[0]->amount, 0.001);
    }

    // ------------------------------------------------------------- refusals

    public function test_accrual_into_a_closed_period_is_refused_with_nothing_written(): void
    {
        $this->marchDeployment($this->makeProject());
        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'closed']);

        try {
            $this->deployments->accrueMonth(2026, 3);
            $this->fail('accrual into a closed period must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2026-03', $e->getMessage());
        }

        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_month_still_running_cannot_be_accrued(): void
    {
        $this->marchDeployment($this->makeProject());

        try {
            $this->deployments->accrueMonth((int) now()->year, (int) now()->month);
            $this->fail('a month that has not ended must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum berakhir', $e->getMessage());
        }

        $this->assertSame(0, ProjectCost::query()->count());
    }

    // ------------------------------------------------- residual at the return

    /**
     * accrued months + residual == inclusive days x rate, to the rupiah.
     * Maret-Juli = 30+30+31+30+31 = 152 hari accrued; pengembalian 2026-08-05
     * menambah 1-5 Agustus = 5 hari; total 157 hari.
     */
    public function test_the_return_charges_only_the_days_no_accrual_has_covered(): void
    {
        $deployment = $this->marchDeployment($this->makeProject());

        foreach ([3, 4, 5, 6, 7] as $month) {
            $this->deployments->accrueMonth(2026, $month);
        }

        $this->deployments->returnDeployment($deployment, '2026-08-05');

        $residual = ProjectCost::query()
            ->where('reference_type', 'asset_deployment')
            ->where('reference_id', $deployment->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(5 * 2500000.0, (float) $residual->amount, 0.001);
        $this->assertSame('2026-08-05', $residual->cost_date->toDateString());

        // Kumulatif persis: 157 hari x 2.500.000 = 392.500.000.
        $this->assertEqualsWithDelta(
            157 * 2500000.0,
            (float) ProjectCost::query()->sum('amount'),
            0.001,
        );
    }

    public function test_a_return_on_the_last_accrued_day_writes_no_residual_row(): void
    {
        $deployment = $this->marchDeployment($this->makeProject());

        foreach ([3, 4, 5, 6, 7] as $month) {
            $this->deployments->accrueMonth(2026, $month);
        }

        $returned = $this->deployments->returnDeployment($deployment, '2026-07-31');

        $this->assertSame(DeploymentStatus::Returned, $returned->status);
        $this->assertSame(0, ProjectCost::query()->where('reference_type', 'asset_deployment')->count());
        // 152 hari sudah terakru — tidak ada yang hilang, tidak ada yang dobel.
        $this->assertEqualsWithDelta(152 * 2500000.0, (float) ProjectCost::query()->sum('amount'), 0.001);
    }

    /**
     * A storeman may type a return date BEFORE months that were already
     * accrued (the machine actually left in June, the accrual ran through
     * July). The residual then goes NEGATIVE — a correction row dated the
     * return day — so the cumulative charge still equals inclusive days x
     * rate instead of silently keeping the over-accrued days.
     */
    public function test_a_backdated_return_inside_accrued_months_reverses_the_over_accrual(): void
    {
        $deployment = $this->marchDeployment($this->makeProject());

        foreach ([3, 4, 5, 6, 7] as $month) {
            $this->deployments->accrueMonth(2026, $month);
        }

        // 2026-03-02 s.d. 2026-06-15 inklusif = 30+30+31+15 = 106 hari.
        $this->deployments->returnDeployment($deployment, '2026-06-15');

        $residual = ProjectCost::query()
            ->where('reference_type', 'asset_deployment')
            ->where('reference_id', $deployment->id)
            ->firstOrFail();

        // 106 - 152 = -46 hari x 2.500.000.
        $this->assertEqualsWithDelta(-46 * 2500000.0, (float) $residual->amount, 0.001);
        $this->assertEqualsWithDelta(106 * 2500000.0, (float) ProjectCost::query()->sum('amount'), 0.001);
    }
}
