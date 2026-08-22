<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Services\DeploymentService;
use Modules\Finance\Enums\PeriodStatus;
use Modules\Finance\Models\PeriodEvent;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The plant_accrued checklist item (T43): the close is where an unaccrued
 * month becomes unrepairable, so the close is where it must be named.
 *
 * ProjectCostService refuses cost rows dated inside a closed period, which is
 * exactly right — and it means a month that closes with a machine still on
 * site and no accrual row keeps equipment at Rp 0 for ever. A nightly cron
 * can miss a month silently; the checklist cannot, because the closer has to
 * read it. WARN, not BLOCK: the accrual is a management allocation that never
 * touches the general ledger, so a closer who accepts the understatement in
 * writing may proceed — the override is kept permanently in the event row.
 */
class PeriodClosePlantAccrualTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->seedLedger(2026);
        $this->closeEverythingBefore(2026, 6);
    }

    /**
     * useful_life_months = 0 on purpose: a depreciable asset would flip the
     * depreciation_present item into an expectation and drag a second warning
     * through every close in this suite.
     */
    private function makePlantAsset(): int
    {
        $categoryId = DB::table('ast_categories')->insertGetId([
            'code' => 'CAT-SEWA',
            'name' => 'Alat Berat',
            'useful_life_months_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('ast_assets')->insertGetId([
            'code' => 'AST-0001',
            'name' => 'Excavator Komatsu PC200',
            'category_id' => $categoryId,
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => 960000000,
            'salvage_value' => 0,
            'useful_life_months' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 960000000,
            'status' => 'deployed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeDeployment(array $attributes = []): Deployment
    {
        return Deployment::query()->create(array_merge([
            'asset_id' => $this->makePlantAsset(),
            'project_id' => $this->makeProject()->id,
            'deployed_from' => '2026-03-02',
            'daily_rate_internal' => 2500000,
            'status' => 'active',
        ], $attributes));
    }

    // ------------------------------------------------------------- the item

    public function test_no_open_deployment_means_no_accrual_is_expected(): void
    {
        $this->period(2026, 6);

        $this->assertItem(2026, 6, 'plant_accrued', 'warn', 'na');
    }

    public function test_an_unaccrued_open_deployment_is_a_warning_that_names_the_month_cost(): void
    {
        $this->period(2026, 6);
        $deployment = $this->makeDeployment();

        $item = $this->assertItem(2026, 6, 'plant_accrued', 'warn', 'fail');

        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString($deployment->code, $item['detail']);
        // Juni penuh = 30 hari x 2.500.000 = 75.000.000.
        $this->assertStringContainsString('75.000.000', $item['detail']);
        $this->assertStringContainsString('ast:accrue-plant', $item['detail']);
    }

    public function test_the_warning_clears_once_the_month_is_accrued(): void
    {
        $this->period(2026, 6);
        $this->makeDeployment();

        app(DeploymentService::class)->accrueMonth(2026, 6);

        $this->assertItem(2026, 6, 'plant_accrued', 'warn', 'ok');
    }

    public function test_a_deployment_without_an_internal_rate_is_not_an_expectation(): void
    {
        $this->period(2026, 6);
        $this->makeDeployment(['daily_rate_internal' => null]);

        $this->assertItem(2026, 6, 'plant_accrued', 'warn', 'na');
    }

    /**
     * A machine already returned settled its whole span at demobilisation
     * (the residual row); accruing it now would double count, so it is not
     * something the closer can or should still do.
     */
    public function test_a_returned_deployment_is_not_counted(): void
    {
        $this->period(2026, 6);
        $this->makeDeployment(['status' => 'returned', 'returned_at' => '2026-06-20']);

        $this->assertItem(2026, 6, 'plant_accrued', 'warn', 'na');
    }

    public function test_a_month_before_the_deployment_started_expects_nothing(): void
    {
        $this->makeDeployment();

        $this->assertItem(2026, 2, 'plant_accrued', 'warn', 'na');
    }

    // ------------------------------------------------------------- the close

    public function test_closing_with_unaccrued_plant_requires_the_named_override(): void
    {
        $period = $this->period(2026, 6);
        $this->makeDeployment();

        try {
            $this->periods()->close($period, $this->closerUser());
            $this->fail('an unacknowledged plant_accrued warning must refuse the close');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Peringatan berikut belum diakui', $e->getMessage());
            $this->assertStringContainsString('akrual alat', $e->getMessage());
        }

        $this->assertSame(PeriodStatus::Open, $period->fresh()->status);

        $closed = $this->periods()->close(
            $period,
            $this->closerUser(),
            'Alat masih di lapangan; akrual Juni menyusul keputusan tarif internal.',
            ['plant_accrued'],
        );

        $this->assertSame(PeriodStatus::Closed, $closed->status);
        $this->assertSame(['plant_accrued'], PeriodEvent::query()->latest('id')->firstOrFail()->overrides);
    }
}
