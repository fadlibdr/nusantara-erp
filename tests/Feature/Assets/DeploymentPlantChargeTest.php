<?php

namespace Tests\Feature\Assets;

use LogicException;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Enums\DeploymentStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Services\DeploymentService;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\ProjectCost;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The internal plant charge a demobilisation writes into the project cost
 * ledger, and the fiscal period it is allowed to land in.
 *
 * chargeProject() deliberately posts NO journal — an internal charge is an
 * allocation between the company and its own project, already recognised at
 * company level as depreciation on 6-3100 — which made it the ONE
 * ProjectCostService caller with no assertPeriodOpen() beside it. A storeman
 * recording on 2026-07-08 the machine that actually left site on 2026-06-15
 * therefore wrote Rp 265.000.000 of equipment cost into a June whose books had
 * been closed and whose PSAK 115 run had been posted and reported: the June
 * trial balance was untouched, project profitability for June gained cost the
 * June ledger never carried, and June could never be reopened to repair it.
 */
class DeploymentPlantChargeTest extends ErpTestCase
{
    use FinanceFixtures;

    private DeploymentService $deployments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->deployments = app(DeploymentService::class);
    }

    private function asset(): Asset
    {
        $category = AssetCategory::query()->create([
            'code' => 'CAT-'.str()->random(4),
            'name' => 'Alat Berat',
            'useful_life_months_default' => 96,
            'depreciation_account_hint' => '6-3100',
            'accum_account_hint' => '1-2410',
        ]);

        return Asset::query()->create([
            'code' => 'AST-0001',
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

    /** DEP dated 2026-03-02 at Rp 2.500.000/day against project 1, as on the live dataset. */
    private function activeDeployment(?float $rate = 2500000): Deployment
    {
        return $this->deployments->deploy($this->asset(), [
            'project_id' => (int) $this->makeProject()->id,
            'deployed_from' => '2026-03-02',
            'daily_rate_internal' => $rate,
        ]);
    }

    // ------------------------------------------------------------ the charge

    public function test_demobilisation_charges_the_project_for_the_days_the_plant_was_on_site(): void
    {
        $deployment = $this->activeDeployment();

        // 2026-03-02 sampai 2026-07-08 inklusif = 129 hari.
        // 129 x 2.500.000 = 322.500.000
        $returned = $this->deployments->returnDeployment($deployment, '2026-07-08');

        $this->assertSame(DeploymentStatus::Returned, $returned->status);
        $this->assertSame(AssetStatus::Available, $returned->asset->fresh()->status);

        /** @var ProjectCost $cost */
        $cost = ProjectCost::query()
            ->where('reference_type', 'asset_deployment')
            ->where('reference_id', $deployment->id)
            ->firstOrFail();

        $this->assertSame('equipment', $cost->cost_category->value);
        $this->assertSame('2026-07-08', $cost->cost_date->toDateString());
        $this->assertEqualsWithDelta(322500000.0, (float) $cost->amount, 0.01);
    }

    // ----------------------------------------------------- periode yang ditutup

    public function test_a_demobilisation_back_dated_into_a_closed_month_is_refused(): void
    {
        $deployment = $this->activeDeployment();
        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        try {
            $this->deployments->returnDeployment($deployment, '2026-06-15');
            $this->fail('a demobilisation dated in a closed period must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2026-06', $e->getMessage());
            $this->assertStringContainsString($deployment->code, $e->getMessage());
        }

        // Nothing half-applied: the deployment is still active, the asset is
        // still deployed, and no cost row exists.
        $fresh = $deployment->fresh();
        $this->assertSame(DeploymentStatus::Active, $fresh->status);
        $this->assertNull($fresh->returned_at);
        $this->assertSame(AssetStatus::Deployed, $fresh->asset->fresh()->status);
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_the_same_demobilisation_dated_in_an_open_month_still_posts(): void
    {
        $deployment = $this->activeDeployment();
        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        // Tanggal kejadian yang sebenarnya tidak dapat dibukukan lagi, jadi
        // demobilisasi dicatat pada hari pencatatannya — Juli masih terbuka.
        $returned = $this->deployments->returnDeployment($deployment, '2026-07-08');

        $this->assertSame(DeploymentStatus::Returned, $returned->status);
        $this->assertSame(1, ProjectCost::query()->count());
    }

    /**
     * A deployment with no daily rate writes nothing to the cost ledger and has
     * no accounting effect at all, so refusing the storeman's paperwork over a
     * period rule that does not concern it would be a refusal with no defect
     * behind it.
     */
    public function test_a_demobilisation_without_a_rate_is_allowed_into_a_closed_month(): void
    {
        $deployment = $this->activeDeployment(null);
        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        $returned = $this->deployments->returnDeployment($deployment, '2026-06-15');

        $this->assertSame(DeploymentStatus::Returned, $returned->status);
        $this->assertSame('2026-06-15', $returned->returned_at->toDateString());
        $this->assertSame(0, ProjectCost::query()->count());
    }
}
