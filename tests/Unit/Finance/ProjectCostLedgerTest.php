<?php

namespace Tests\Unit\Finance;

use LogicException;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\ProjectCost;
use Tests\ErpTestCase;

/**
 * The realisasi ledger behind projectProfitability(). Its one hard rule is
 * idempotency per source document: re-approving or re-posting the same bill
 * must never double-count the cost.
 */
class ProjectCostLedgerTest extends ErpTestCase
{
    use FinanceFixtures;

    public function test_recording_the_same_reference_twice_updates_instead_of_duplicating(): void
    {
        $this->projectCosts()->record(7, '2026-03-10', CostCategory::Material, 'ap_bill', 12, 'Semen', 50000000);
        $this->projectCosts()->record(7, '2026-03-10', CostCategory::Material, 'ap_bill', 12, 'Semen (revisi)', 60000000);

        $this->assertSame(1, ProjectCost::query()->count());
        // Only the newest amount survives: 60.000.000, not 50 + 60 = 110.000.000.
        $this->assertSame(60000000.0, $this->projectCosts()->totalsByCategory(7)['material']);
        $this->assertSame('Semen (revisi)', ProjectCost::query()->first()->description);
    }

    public function test_the_same_reference_in_a_different_category_is_a_separate_row(): void
    {
        $this->projectCosts()->record(7, '2026-03-10', CostCategory::Material, 'ap_bill', 12, 'Material', 50000000);
        $this->projectCosts()->record(7, '2026-03-10', CostCategory::Labor, 'ap_bill', 12, 'Upah', 20000000);

        $totals = $this->projectCosts()->totalsByCategory(7);

        $this->assertSame(2, ProjectCost::query()->count());
        $this->assertSame(50000000.0, $totals['material']);
        $this->assertSame(20000000.0, $totals['labor']);
    }

    public function test_totals_by_category_sums_every_row_and_zero_fills_the_rest(): void
    {
        $this->projectCosts()->record(7, '2026-03-10', CostCategory::Subcon, 'ap_bill', 1, 'Opname 1', 100000000);
        $this->projectCosts()->record(7, '2026-04-10', CostCategory::Subcon, 'ap_bill', 2, 'Opname 2', 75000000);
        $this->projectCosts()->record(8, '2026-04-10', CostCategory::Subcon, 'ap_bill', 3, 'Proyek lain', 999000000);

        $totals = $this->projectCosts()->totalsByCategory(7);

        // 100.000.000 + 75.000.000 = 175.000.000; proyek 8 tidak ikut.
        $this->assertSame(175000000.0, $totals['subcon']);
        $this->assertSame(
            ['material', 'labor', 'subcon', 'equipment', 'overhead'],
            array_keys($totals),
        );
        $this->assertSame(0.0, $totals['material']);
        $this->assertSame(0.0, $totals['labor']);
        $this->assertSame(0.0, $totals['equipment']);
        $this->assertSame(0.0, $totals['overhead']);
    }

    public function test_amounts_are_stored_rounded_to_two_decimals(): void
    {
        $this->projectCosts()->record(7, '2026-03-10', CostCategory::Equipment, 'issue', 5, 'Sewa alat', 1234567.891);

        $this->assertSame(1234567.89, $this->projectCosts()->totalsByCategory(7)['equipment']);
    }

    public function test_a_project_without_costs_reports_zero_for_every_category(): void
    {
        $this->assertSame(
            ['material' => 0.0, 'labor' => 0.0, 'subcon' => 0.0, 'equipment' => 0.0, 'overhead' => 0.0],
            $this->projectCosts()->totalsByCategory(404),
        );
    }

    // ------------------------------------------------- periode fiskal tertutup

    /**
     * Temuan T30. Every caller but one posts a journal in the same transaction
     * with the same date, so assertPeriodOpen() was guarding this ledger by
     * accident. DeploymentService's internal plant charge posts no journal at
     * all, so a demobilisation back-dated into a signed-off month wrote cost
     * into it silently — and a month a posted PSAK 115 run has measured can
     * never be reopened to repair it.
     */
    public function test_a_cost_dated_in_a_closed_period_is_refused(): void
    {
        $this->openFiscalYear(2026);
        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        try {
            $this->projectCosts()->record(
                7, '2026-06-15', CostCategory::Equipment, 'asset_deployment', 1, 'Pemakaian AST-0001', 265000000,
            );
            $this->fail('a cost row dated in a closed period must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2026-06', $e->getMessage());
            $this->assertStringContainsString('biaya proyek', $e->getMessage());
        }

        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_cost_dated_in_an_open_period_is_recorded(): void
    {
        $this->openFiscalYear(2026);
        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        $this->projectCosts()->record(
            7, '2026-07-08', CostCategory::Equipment, 'asset_deployment', 1, 'Pemakaian AST-0001', 265000000,
        );

        $this->assertSame(265000000.0, $this->projectCosts()->totalsByCategory(7)['equipment']);
    }

    /**
     * A MISSING period is not a refusal: an installation with no fiscal
     * calendar has no rule to consult, and refusing there would break every
     * caller that never asked for one — including the Inventory backfill
     * migration and the rest of this class.
     */
    public function test_a_cost_is_recorded_when_no_fiscal_calendar_exists_at_all(): void
    {
        $this->assertSame(0, FiscalPeriod::query()->count());

        $this->projectCosts()->record(7, '2026-03-10', CostCategory::Material, 'ap_bill', 12, 'Semen', 50000000);

        $this->assertSame(50000000.0, $this->projectCosts()->totalsByCategory(7)['material']);
    }
}
