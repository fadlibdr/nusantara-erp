<?php

namespace Tests\Feature\Assets;

use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Services\DeploymentService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Services\BaselineService;
use Modules\Projects\Services\EvmService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Feature\Projects\BaselineFixtures;

/**
 * The reason T43 was "the only fence producing a wrong number": EVM read the
 * equipment bucket as Rp 0 for every month a machine sat on site. The fence's
 * own arithmetic — PRJ-2026-001's RAP budgets Rp 178.031.790,79 for equipment
 * while two machines contributed nothing since March — made AC, CPI and EAC
 * all understate. This test proves the monthly accrual actually reaches
 * EvmService: the bucket EvmService reads through
 * ProjectCostService::totalsByCategory moves, and AC moves with it.
 */
class PlantAccrualEvmTest extends ErpTestCase
{
    use BaselineFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_monthly_accrual_moves_the_evm_equipment_bucket_and_ac(): void
    {
        $project = $this->grahaProject();
        $this->makeRap($project);

        $baselines = app(BaselineService::class);
        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');
        $baseline = $baselines->snapshot($project, ['effective_date' => '2026-02-02'], $maker);
        $baselines->submit($baseline, $maker);
        $baselines->approve($baseline, $checker);

        $category = AssetCategory::query()->create([
            'code' => 'CAT-ALAT',
            'name' => 'Alat Berat',
            'useful_life_months_default' => 96,
        ]);
        $asset = Asset::query()->create([
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

        $service = app(DeploymentService::class);
        $service->deploy($asset, [
            'project_id' => (int) $project->id,
            'deployed_from' => '2026-03-02',
            'daily_rate_internal' => 2500000,
        ]);

        $evm = app(EvmService::class);

        $before = $evm->report($project->refresh(), '2026-08-01');
        $this->assertSame(0.0, $before['cost_coverage']['actual_by_category']['equipment']);
        $this->assertContains('equipment', $before['cost_coverage']['empty_categories']);
        $this->assertSame(0.0, $before['measures']['ac']);

        // Maret 2026: 2026-03-02 s.d. 2026-03-31 = 30 hari x 2.500.000.
        $service->accrueMonth(2026, 3);

        $after = $evm->report($project->refresh(), '2026-08-01');
        $this->assertEqualsWithDelta(75000000.0, $after['cost_coverage']['actual_by_category']['equipment'], 0.001);
        $this->assertNotContains('equipment', $after['cost_coverage']['empty_categories']);
        $this->assertEqualsWithDelta(75000000.0, $after['measures']['ac'], 0.001);
    }
}
