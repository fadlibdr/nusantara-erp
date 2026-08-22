<?php

namespace Tests\Feature\Finance;

use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\BoqSection;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Enums\CostCategory;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Laba rugi proyek: pendapatan tertagih (DPP faktur yang sudah disetujui)
 * versus realisasi biaya per kategori, disandingkan dengan RAP bila ada.
 *
 *   margin     = revenue - total realised cost
 *   margin_pct = margin / revenue * 100
 *   variance   = budget - actual (null when there is no approved RAP)
 */
class ProjectProfitabilityReportTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer);
        $this->project = $this->makeProject(['contract_id' => $this->contract->id]);
    }

    /**
     * Approved AR invoice for the project; only its DPP counts as revenue.
     */
    private function bill(float $dpp, DocumentStatus $status = DocumentStatus::Approved): void
    {
        $invoice = $this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'project_id' => $this->project->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Penagihan termin',
            'dpp' => $dpp,
        ]);

        if ($status === DocumentStatus::Approved) {
            $this->approveInvoice($invoice);

            return;
        }

        $invoice->forceFill(['status' => $status])->save();
    }

    private function spend(CostCategory $category, float $amount, int $reference): void
    {
        $this->projectCosts()->record(
            (int) $this->project->id,
            '2026-03-15',
            $category,
            'ap_bill',
            $reference,
            'Realisasi '.$category->value,
            $amount,
        );
    }

    /**
     * @return array<string, array<string, mixed>> cost rows keyed by category
     */
    private function costs(array $report): array
    {
        $costs = [];

        foreach ($report['costs'] as $row) {
            $costs[$row['category']] = $row;
        }

        return $costs;
    }

    public function test_revenue_is_the_dpp_of_approved_invoices_only(): void
    {
        $this->bill(2000000000);
        $this->bill(1000000000);
        $this->bill(500000000, DocumentStatus::Draft);

        $report = $this->reports()->projectProfitability((int) $this->project->id);

        // 2.000.000.000 + 1.000.000.000 = 3.000.000.000 (draf 500.000.000 diabaikan)
        // dan PPN keluaran tidak pernah masuk pendapatan.
        $this->assertSame(3000000000.0, $report['revenue']);
    }

    public function test_margin_and_margin_pct_follow_the_realised_costs(): void
    {
        $this->bill(2000000000);
        $this->bill(1000000000);

        $this->spend(CostCategory::Material, 800000000, 1);
        $this->spend(CostCategory::Subcon, 600000000, 2);
        $this->spend(CostCategory::Labor, 100000000, 3);

        $report = $this->reports()->projectProfitability((int) $this->project->id);
        $costs = $this->costs($report);

        $this->assertSame(800000000.0, $costs['material']['actual']);
        $this->assertSame(600000000.0, $costs['subcon']['actual']);
        $this->assertSame(100000000.0, $costs['labor']['actual']);
        $this->assertSame(0.0, $costs['equipment']['actual']);
        $this->assertSame(0.0, $costs['overhead']['actual']);

        // 800.000.000 + 600.000.000 + 100.000.000 = 1.500.000.000
        $this->assertSame(1500000000.0, $report['total_cost']);
        // 3.000.000.000 - 1.500.000.000 = 1.500.000.000
        $this->assertSame(1500000000.0, $report['margin']);
        // 1.500.000.000 / 3.000.000.000 * 100 = 50,00%
        $this->assertSame(50.0, $report['margin_pct']);
    }

    public function test_costs_above_revenue_produce_a_negative_margin(): void
    {
        $this->bill(1000000000);
        $this->spend(CostCategory::Material, 1250000000, 1);

        $report = $this->reports()->projectProfitability((int) $this->project->id);

        // 1.000.000.000 - 1.250.000.000 = -250.000.000 => -25,00%
        $this->assertSame(-250000000.0, $report['margin']);
        $this->assertSame(-25.0, $report['margin_pct']);
    }

    public function test_a_project_without_revenue_reports_a_null_margin_percentage(): void
    {
        $this->spend(CostCategory::Overhead, 40000000, 1);

        $report = $this->reports()->projectProfitability((int) $this->project->id);

        $this->assertSame(0.0, $report['revenue']);
        $this->assertSame(40000000.0, $report['total_cost']);
        $this->assertSame(-40000000.0, $report['margin']);
        // Pembagian dengan nol dihindari, bukan dilaporkan sebagai 0%.
        $this->assertNull($report['margin_pct']);
    }

    public function test_budget_and_variance_are_null_without_an_approved_rap(): void
    {
        $this->bill(1000000000);
        $this->spend(CostCategory::Material, 400000000, 1);

        // RAP masih draf: tidak boleh dipakai sebagai anggaran.
        $this->makeRap(['material' => 500000000], DocumentStatus::Draft);

        $report = $this->reports()->projectProfitability((int) $this->project->id);

        $this->assertNull($report['total_budget']);

        foreach ($report['costs'] as $row) {
            $this->assertNull($row['budget'], "Category {$row['category']} should have no budget.");
            $this->assertNull($row['variance'], "Category {$row['category']} should have no variance.");
        }
    }

    public function test_an_approved_rap_supplies_the_budget_and_the_variance(): void
    {
        $this->bill(1000000000);
        $this->spend(CostCategory::Material, 400000000, 1);
        $this->spend(CostCategory::Subcon, 350000000, 2);

        $this->makeRap(['material' => 500000000, 'subcon' => 300000000]);

        $report = $this->reports()->projectProfitability((int) $this->project->id);
        $costs = $this->costs($report);

        // Material: anggaran 500.000.000 - realisasi 400.000.000 = +100.000.000 (hemat)
        $this->assertSame(500000000.0, $costs['material']['budget']);
        $this->assertSame(100000000.0, $costs['material']['variance']);
        // Subkon: 300.000.000 - 350.000.000 = -50.000.000 (boros)
        $this->assertSame(300000000.0, $costs['subcon']['budget']);
        $this->assertSame(-50000000.0, $costs['subcon']['variance']);
        // Kategori tanpa anggaran maupun realisasi: 0 - 0 = 0
        $this->assertSame(0.0, $costs['labor']['budget']);
        $this->assertSame(0.0, $costs['labor']['variance']);

        // 500.000.000 + 300.000.000 = 800.000.000
        $this->assertSame(800000000.0, $report['total_budget']);
        // 400.000.000 + 350.000.000 = 750.000.000
        $this->assertSame(750000000.0, $report['total_cost']);
    }

    public function test_every_cost_category_is_reported_even_when_untouched(): void
    {
        $report = $this->reports()->projectProfitability((int) $this->project->id);

        $this->assertSame(
            ['material', 'labor', 'subcon', 'equipment', 'overhead'],
            array_column($report['costs'], 'category'),
        );
        $this->assertSame(
            ['Material', 'Upah', 'Subkon', 'Alat', 'Overhead'],
            array_column($report['costs'], 'label'),
        );
        $this->assertSame((int) $this->project->id, $report['project_id']);
    }

    public function test_another_project_costs_and_invoices_are_excluded(): void
    {
        $other = $this->makeProject(['name' => 'Proyek lain']);

        $this->bill(1000000000);
        $this->spend(CostCategory::Material, 400000000, 1);

        $this->projectCosts()->record(
            (int) $other->id, '2026-03-15', CostCategory::Material, 'ap_bill', 99, 'Biaya proyek lain', 900000000,
        );

        $report = $this->reports()->projectProfitability((int) $this->project->id);

        $this->assertSame(400000000.0, $report['total_cost']);
        $this->assertSame(600000000.0, $report['margin']);
    }

    /**
     * A RAP (est_cost_budgets) with one line per named category. The BOQ chain
     * exists only because the schema requires it.
     *
     * @param  array<string, float>  $byCategory
     */
    private function makeRap(array $byCategory, DocumentStatus $status = DocumentStatus::Approved): CostBudget
    {
        $boq = Boq::create([
            'project_id' => $this->project->id,
            'contract_id' => $this->contract->id,
            'title' => 'RAB Gedung Kantor Graha Sentosa',
            'version' => 1,
            'status' => DocumentStatus::Approved,
            'total' => array_sum($byCategory),
        ]);

        $section = BoqSection::create([
            'boq_id' => $boq->id,
            'section_no' => '1',
            'name' => 'Pekerjaan Struktur',
            'subtotal' => array_sum($byCategory),
            'sort_order' => 1,
        ]);

        $budget = CostBudget::create([
            'boq_id' => $boq->id,
            'project_id' => $this->project->id,
            'target_margin_pct' => 15,
            'total_budget' => array_sum($byCategory),
            'status' => $status,
        ]);

        $lineNo = 0;

        foreach ($byCategory as $category => $amount) {
            $lineNo++;

            $boqItem = BoqItem::create([
                'boq_id' => $boq->id,
                'section_id' => $section->id,
                'wbs_code' => "1.{$lineNo}",
                'description' => "Pekerjaan {$category}",
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $amount,
                'amount' => $amount,
                'sort_order' => $lineNo,
            ]);

            $budget->items()->create([
                'boq_item_id' => $boqItem->id,
                'cost_category' => $category,
                'description' => "Anggaran {$category}",
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $amount,
                'amount' => $amount,
            ]);
        }

        return $budget;
    }
}
