<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Services\DeploymentService;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Services\CommitmentService;
use Modules\Finance\Services\ReportService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Cost control: what has been promised, and what the company's own plant costs.
 *
 * The profitability report compared budget against ACTUAL cost, and actual cost
 * appears only when a vendor bill is approved. A project manager who had already
 * signed Rp 5 miliar of purchase orders saw the budget intact until the invoices
 * landed. And company-owned plant was free in every project P&L, because the
 * internal charge was computed as a "suggestion" that nothing consumed —
 * structurally favouring renting in any make-or-buy comparison.
 */
class CommitmentAndPlantCostTest extends ErpTestCase
{
    use FinanceFixtures;

    private CommitmentService $commitments;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->commitments = app(CommitmentService::class);
        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-800',
            'name' => 'Proyek Uji Komitmen',
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    // ------------------------------------------------------- committed cost

    public function test_an_approved_purchase_order_is_committed_until_it_is_billed(): void
    {
        $po = $this->makePurchaseOrder($this->makeVendor(), ['project_id' => $this->project->id]);
        $po->forceFill(['status' => DocumentStatus::Approved])->save();

        $committed = $this->commitments->forProject($this->project->id);

        $this->assertEqualsWithDelta((float) $po->dpp, $committed['purchase_orders'], 0.01);
        $this->assertEqualsWithDelta((float) $po->dpp, $committed['total'], 0.01);
    }

    public function test_billing_a_purchase_order_converts_the_commitment_into_actual_cost(): void
    {
        $po = $this->makePurchaseOrder($this->makeVendor(), ['project_id' => $this->project->id]);
        $po->forceFill(['status' => DocumentStatus::Approved])->save();

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertEqualsWithDelta(0.0, $this->commitments->forProject($this->project->id)['total'], 0.01);
    }

    /** Nothing is promised until somebody with the authority has approved it. */
    public function test_a_draft_purchase_order_is_not_committed(): void
    {
        // makePurchaseOrder() defaults to approved, so a draft has to be asked for.
        $po = $this->makePurchaseOrder($this->makeVendor(), ['project_id' => $this->project->id]);
        $po->forceFill(['status' => DocumentStatus::Draft])->save();

        $this->assertSame(0.0, $this->commitments->forProject($this->project->id)['total']);
    }

    public function test_an_approved_subcontract_is_committed_less_its_approved_claims(): void
    {
        $spk = $this->makeSubcontract($this->makeVendor(['is_subcontractor' => true]), [
            'project_id' => $this->project->id,
        ]);
        $spk->forceFill(['status' => DocumentStatus::Approved])->save();

        $before = $this->commitments->forProject($this->project->id)['subcontracts'];
        $this->assertEqualsWithDelta((float) $spk->value, $before, 0.01);

        $claim = $this->makeProgressClaim($spk);

        $after = $this->commitments->forProject($this->project->id)['subcontracts'];
        $this->assertEqualsWithDelta((float) $spk->value - (float) $claim->gross_amount, $after, 0.01);
    }

    /**
     * Over-billing an order is a real event and a three-way-match problem
     * reported elsewhere. Shown here as a negative commitment it would quietly
     * offset a genuine commitment on another document.
     */
    public function test_an_over_billed_order_does_not_produce_a_negative_commitment(): void
    {
        $po = $this->makePurchaseOrder($this->makeVendor(), ['project_id' => $this->project->id]);
        $po->forceFill(['status' => DocumentStatus::Approved, 'dpp' => 1_000_000])->save();

        $this->approveBill($this->apBills()->create([
            'vendor_id' => $po->vendor_id,
            'project_id' => $this->project->id,
            'description' => 'Tagihan melebihi PO',
            'dpp' => 5_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-OVER',
        ]));

        $this->assertGreaterThanOrEqual(0.0, $this->commitments->forProject($this->project->id)['total']);
    }

    /** The number a PM can act on: budget less spent AND less promised. */
    public function test_the_profitability_report_carries_commitments(): void
    {
        $po = $this->makePurchaseOrder($this->makeVendor(), ['project_id' => $this->project->id]);
        $po->forceFill(['status' => DocumentStatus::Approved])->save();

        $report = app(ReportService::class)->projectProfitability($this->project->id);

        $this->assertArrayHasKey('committed', $report);
        $this->assertArrayHasKey('budget_remaining', $report);
        $this->assertEqualsWithDelta((float) $po->dpp, $report['committed']['total'], 0.01);
        // A commitment is not a cost and must never be added into one.
        $this->assertSame(0.0, $report['total_cost']);
    }

    // -------------------------------------------------------- plant charge

    private function deployedAsset(float $dailyRate = 2_500_000): Asset
    {
        $category = AssetCategory::query()->create([
            'code' => 'ALAT-'.str()->random(4),
            'name' => 'Alat Berat',
            'useful_life_months_default' => 96,
            'depreciation_account_hint' => '6-3100',
            'accum_account_hint' => '1-2410',
        ]);

        return Asset::query()->create([
            'code' => 'AST-'.str()->random(5),
            'name' => 'Excavator',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'depreciation_start_date' => '2025-01-01',
            'acquisition_cost' => 960_000_000,
            'useful_life_months' => 96,
            'salvage_value' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 960_000_000,
            'status' => 'available',
        ]);
    }

    public function test_returning_plant_charges_the_project_for_the_days_it_was_there(): void
    {
        $service = app(DeploymentService::class);
        $asset = $this->deployedAsset();

        $deployment = $service->deploy($asset, [
            'project_id' => $this->project->id,
            'deployed_from' => '2026-03-01',
            'daily_rate_internal' => 2_500_000,
        ]);

        $service->returnDeployment($deployment, '2026-03-10');

        $cost = DB::table('fin_project_costs')
            ->where('project_id', $this->project->id)
            ->where('cost_category', 'equipment')
            ->first();

        $this->assertNotNull($cost, 'company plant must not be free to the project');
        // 1–10 March inclusive is ten days on site.
        $this->assertEqualsWithDelta(25_000_000, (float) $cost->amount, 0.01);
    }

    /** A machine on site for one day was there for a day, not zero days. */
    public function test_a_same_day_deployment_is_charged_one_day(): void
    {
        $service = app(DeploymentService::class);
        $deployment = $service->deploy($this->deployedAsset(), [
            'project_id' => $this->project->id,
            'deployed_from' => '2026-03-01',
            'daily_rate_internal' => 2_500_000,
        ]);

        $service->returnDeployment($deployment, '2026-03-01');

        $amount = (float) DB::table('fin_project_costs')
            ->where('project_id', $this->project->id)->value('amount');

        $this->assertEqualsWithDelta(2_500_000, $amount, 0.01);
    }

    public function test_a_deployment_with_no_internal_rate_charges_nothing(): void
    {
        $service = app(DeploymentService::class);
        $deployment = $service->deploy($this->deployedAsset(), [
            'project_id' => $this->project->id,
            'deployed_from' => '2026-03-01',
            'daily_rate_internal' => 0,
        ]);

        $service->returnDeployment($deployment, '2026-03-10');

        $this->assertSame(0, DB::table('fin_project_costs')
            ->where('project_id', $this->project->id)->count());
    }

    /**
     * The internal charge is an allocation, not a transaction with anybody. The
     * money already left when the machine was bought and is recognised as
     * depreciation; posting it again would count the same asset twice.
     */
    public function test_the_plant_charge_does_not_touch_the_general_ledger(): void
    {
        $service = app(DeploymentService::class);
        $before = JournalLine::query()->count();

        $deployment = $service->deploy($this->deployedAsset(), [
            'project_id' => $this->project->id,
            'deployed_from' => '2026-03-01',
            'daily_rate_internal' => 2_500_000,
        ]);
        $service->returnDeployment($deployment, '2026-03-10');

        $this->assertSame($before, JournalLine::query()->count());
    }
}
