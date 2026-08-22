<?php

namespace Tests\Feature\Projects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Finance\Services\RevenueRecognitionService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Services\BaselineService;
use Modules\Projects\Services\EvmService;
use Modules\Projects\Services\ProgressService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Earned value management (CPI / SPI) against a frozen baseline.
 *
 * Every fixture here reproduces the live demo dataset, so the expectations are
 * the numbers erp1.pi2.co.id actually shows: BAC Rp 42.173.913.043,47 from the
 * still-unapproved RAP/2026/0001, Rp 228.240.000 of actual cost in two material
 * rows, eight leaves whose weighted rollup is exactly 55,0000%, and as_of
 * 01-08-2026 — giving SPI 0,8913 and CPI 101,6283.
 *
 * SPI 0,8913 is a credible schedule slip and matches the seeded narrative
 * ("Deviasi -7%"). CPI 101,6283 is not credible, and the point of half the
 * tests below is that the report says so out loud instead of painting a green
 * tile: four of the five budgeted cost categories have zero rupiah of actuals.
 *
 * The non-negotiable one is test_actual_cost_matches_... — where EVM and the
 * PSAK 115 engine measure the same quantity they must agree, or a user sees two
 * completion percentages and trusts neither.
 */
class EarnedValueTest extends ErpTestCase
{
    use BaselineFixtures;

    private const AS_OF = '2026-08-01';

    private EvmService $evm;

    private BaselineService $baselines;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->evm = app(EvmService::class);
        $this->baselines = app(BaselineService::class);
        $this->project = $this->grahaProject();
    }

    // -------------------------------------------------------------- fixtures

    private function freeze(array $data = []): ProjectBaseline
    {
        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');

        $baseline = $this->baselines->snapshot($this->project, array_merge([
            'effective_date' => '2026-02-02',
        ], $data), $maker);
        $this->baselines->submit($baseline, $maker);

        return $this->baselines->approve($baseline, $checker);
    }

    /** The whole demo: RAP, baseline, and the two material cost rows. */
    private function demo(): array
    {
        $this->makeRap($this->project);
        $baseline = $this->freeze();
        $this->addDemoCosts($this->project);

        return $this->evm->report($this->project->refresh(), self::AS_OF);
    }

    // --------------------------------------------------- the three quantities

    /**
     * EV = physical% x BAC = 55,0000% x Rp 42.173.913.043,47.
     */
    public function test_earned_value_is_physical_progress_against_the_frozen_budget_at_completion(): void
    {
        $measures = $this->demo()['measures'];

        $this->assertSame(55.0, $measures['physical_pct']);
        $this->assertSame(42173913043.47, $measures['bac']);
        $this->assertSame(23195652173.91, $measures['ev']);
    }

    /**
     * PV comes from the FROZEN WBS, never from prj_projects.planned_progress_pct
     * — which reads 2,0000 on this project while its own latest weekly row
     * reads 62,0000. A number that can be quietly rewritten, and that is already
     * wrong, proves nothing in a claim.
     */
    public function test_planned_value_is_read_from_the_frozen_curve_not_from_the_project_header_planned_percentage(): void
    {
        $report = $this->demo();

        $this->assertSame(2.0, round((float) $this->project->refresh()->planned_progress_pct, 4));
        $this->assertSame(61.706, $report['measures']['planned_pct']);
        $this->assertSame(26023834782.6, $report['measures']['pv']);
        $this->assertSame('baseline_wbs', $report['curve']['planned_source']);
    }

    /**
     * THE AGREEMENT THAT IS NOT NEGOTIABLE.
     *
     * EVM's actual cost and the PSAK 115 engine's cost-to-date are the same
     * quantity read from the same table, so they must be the same rupiah. If
     * they drift, one screen says the project is 55% done and another says
     * 0,54%, and the ratio between them stops being CPI — at which point a user
     * has two completion percentages and no reason to trust either.
     */
    public function test_actual_cost_matches_the_sum_the_psak_115_engine_reads_for_the_same_contract(): void
    {
        $this->seedLedger(2026);
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        $poster = $this->userWith('fin.post', 'Akuntan');
        $recognition = app(RevenueRecognitionService::class);
        $run = $recognition->post($recognition->calculate(2026, 7, $poster), $poster);

        $line = $run->lines()->first();
        $report = $this->evm->report($this->project->refresh(), '2026-07-31');

        // Same cost ledger, same date, same rupiah.
        $this->assertSame(228240000.0, $report['measures']['ac']);
        $this->assertSame(228240000.0, round((float) $line->cost_to_date, 2));
        $this->assertSame($report['measures']['ac'], $report['poc_reconciliation']['poc_cost_to_date']);

        // Same EAC, resolved by the same rule from the same unapproved RAP.
        $this->assertSame(42173913043.47, round((float) $line->estimated_total_cost, 2));
        $this->assertSame(42173913043.47, $report['poc_reconciliation']['poc_eac']);
        $this->assertSame('rap_unapproved', $line->eac_source);
        $this->assertSame('rap_unapproved', $report['poc_reconciliation']['poc_eac_source']);

        // And therefore the same cost-to-cost percentage, to four decimals.
        $this->assertSame(round((float) $line->progress_pct, 4), $report['poc_reconciliation']['poc_pct']);
        $this->assertTrue($report['poc_reconciliation']['matches_cpi']);
    }

    /**
     * SPI 0,8913 = 23.195.652.173,91 / 26.023.834.782,60.
     * CPI 101,6283 = 23.195.652.173,91 / 228.240.000.
     * TCPI 0,4524 = (BAC - EV) / (BAC - AC).
     * EAC(EVM) Rp 414.981.818,18 = BAC / CPI, from the UNROUNDED index.
     */
    public function test_the_indices_reproduce_the_demo_dataset(): void
    {
        $measures = $this->demo()['measures'];

        $this->assertSame(-2828182608.69, $measures['sv']);
        $this->assertSame(22967412173.91, $measures['cv']);
        $this->assertSame(0.8913, $measures['spi']);
        $this->assertSame(101.6283, $measures['cpi']);
        $this->assertSame(0.4524, $measures['tcpi']);
        $this->assertSame(414981818.18, $measures['eac_evm']);
        $this->assertSame(41758931225.29, $measures['vac']);
        $this->assertSame(186741818.18, $measures['etc']);
    }

    // ------------------------------------------------ division by zero rules

    public function test_the_schedule_index_is_null_before_the_baseline_curve_starts_instead_of_dividing_by_zero(): void
    {
        $this->makeRap($this->project);
        $this->freeze(['effective_date' => '2026-01-01']);

        $measures = $this->evm->report($this->project, '2026-01-15')['measures'];

        $this->assertSame(0.0, $measures['pv']);
        $this->assertNull($measures['spi']);
        $this->assertSame('no_planned_value', $measures['spi_status']);
        $this->assertSame('Belum ada nilai rencana pada tanggal ini', $measures['spi_note']);
    }

    /**
     * EV > 0 with AC = 0 is an unrecorded cost, not infinite efficiency.
     * Printing infinity here would tell a PM they are doing brilliantly.
     */
    public function test_the_cost_index_is_null_when_no_cost_has_been_recorded_instead_of_dividing_by_zero(): void
    {
        $this->makeRap($this->project);
        $this->freeze();

        $measures = $this->evm->report($this->project, self::AS_OF)['measures'];

        $this->assertSame(0.0, $measures['ac']);
        $this->assertGreaterThan(0.0, $measures['ev']);
        $this->assertNull($measures['cpi']);
        $this->assertSame('no_cost_recorded', $measures['cpi_status']);
        $this->assertNull($measures['eac_evm']);
        $this->assertNull($measures['vac']);
        $this->assertNull($measures['etc']);
    }

    /**
     * Money spent for nothing earned is a real, meaningful zero — and the worst
     * case this report exists to catch. Suppressing it would hide it.
     */
    public function test_a_project_that_spent_money_without_earning_anything_reports_a_cost_index_of_zero_not_null(): void
    {
        $this->makeRap($this->project);
        $this->project->wbsTasks()->update(['progress_pct' => 0]);
        $this->freeze();

        // Every budgeted category is fully realised — its entire RAP amount is
        // on the books — so the cost base clears the coverage floor and the
        // zero is trustworthy. Rp 42.173.913.043,47 spent, nothing earned.
        foreach (self::RAP_CATEGORIES as $category => $amount) {
            $this->addCost($this->project, '2026-05-31', $amount, $category);
        }

        $measures = $this->evm->report($this->project->refresh(), self::AS_OF)['measures'];

        $this->assertSame(0.0, $measures['ev']);
        $this->assertSame(42173913043.47, $measures['ac']);
        $this->assertSame(0.0, $measures['cpi']);
        $this->assertSame('ok', $measures['cpi_status']);
        $this->assertTrue($measures['cpi_reliable']);
        // A CPI of zero yields no meaningful forecast, so the forecast is null
        // rather than a division by zero dressed up as a number.
        $this->assertNull($measures['eac_evm']);
        $this->assertNull($measures['cv_pct']);
    }

    public function test_the_to_complete_index_is_null_once_the_budget_is_exhausted(): void
    {
        $this->makeRap($this->project);
        $this->freeze();
        $this->addCost($this->project, '2026-06-30', self::RAP_TOTAL + 1_000_000);

        $measures = $this->evm->report($this->project->refresh(), self::AS_OF)['measures'];

        $this->assertNull($measures['tcpi']);
        $this->assertSame('budget_exhausted', $measures['tcpi_status']);
        $this->assertSame('Anggaran sudah habis', $measures['tcpi_note']);
    }

    /**
     * json_encode() throws on INF and NAN, so one unguarded division turns the
     * worst project in the portfolio into an HTTP 500 — the one project whose
     * report somebody urgently needs.
     */
    public function test_the_payload_never_contains_infinity_or_nan_so_the_json_response_cannot_fail_to_encode(): void
    {
        $this->makeRap($this->project);
        $this->project->wbsTasks()->update(['progress_pct' => 0]);
        $this->freeze(['effective_date' => '2026-01-01']);

        foreach (['2026-01-15', '2026-02-02', self::AS_OF] as $asOf) {
            $this->assertPayloadIsFinite($this->evm->report($this->project->refresh(), $asOf));
        }

        $this->addCost($this->project, '2026-05-31', self::RAP_TOTAL * 2);
        $this->assertPayloadIsFinite($this->evm->report($this->project->refresh(), self::AS_OF));
        $this->assertPayloadIsFinite($this->evm->portfolio(self::AS_OF));
    }

    private function assertPayloadIsFinite(array $payload): void
    {
        array_walk_recursive($payload, function ($value, $key): void {
            if (is_float($value)) {
                $this->assertTrue(is_finite($value), "[{$key}] is INF or NAN");
            }
        });

        $this->assertIsString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------ scope drift

    /**
     * The weights come from the baseline; only the progress percentages are
     * live. Reweighting the WBS after the plan was agreed cannot earn a rupiah.
     */
    public function test_earned_value_uses_the_frozen_weights_so_a_reweighted_wbs_cannot_inflate_it(): void
    {
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        // C.1 is 4,06% complete and 11,89% of the job. Move almost all the
        // weight onto the packages that are finished and the live rollup jumps.
        $this->project->wbsTasks()->where('wbs_code', 'C.1')->update(['weight_pct' => 0.1]);
        $this->project->wbsTasks()->where('wbs_code', 'A.1')->update(['weight_pct' => 12.5892]);
        app(ProgressService::class)->recalcWbsRollups($this->project->refresh());

        $report = $this->evm->report($this->project->refresh(), self::AS_OF);

        $this->assertSame(55.0, $report['measures']['physical_pct']);
        $this->assertSame(23195652173.91, $report['measures']['ev']);
        // Both numbers are shown, with the reason, rather than one silently
        // replacing the other.
        $this->assertNotSame(
            $report['scope_drift']['live_progress_pct'],
            $report['scope_drift']['baseline_progress_pct'],
        );
    }

    public function test_scope_added_after_the_baseline_earns_nothing_until_a_new_baseline_is_approved(): void
    {
        $this->makeRap($this->project);
        $this->freeze();

        $parent = $this->project->wbsTasks()->where('wbs_code', 'C')->first();
        $this->project->wbsTasks()->create([
            'parent_id' => $parent->id,
            'wbs_code' => 'C.9',
            'name' => 'Pekerjaan tambah — genset cadangan',
            'weight_pct' => 20,
            'planned_start' => '2026-04-01',
            'planned_end' => '2026-12-31',
            'progress_pct' => 100,
            'sort_order' => 9,
        ]);

        $report = $this->evm->report($this->project->refresh(), self::AS_OF);

        $this->assertSame(55.0, $report['measures']['physical_pct']);
        $this->assertSame(['C.9'], $report['scope_drift']['tasks_added']);
        $this->assertStringContainsString('C.9', implode(' ', $report['warnings']));
        $this->assertStringContainsString('belum menghasilkan nilai', implode(' ', $report['warnings']));
    }

    /**
     * Deleting a work package is not a way to complete it. B.4 is 15,8559% of
     * the job at 60% done, so removing it takes 9,51354 points off:
     * 55,0000 - 9,5135 = 45,4865.
     */
    public function test_scope_deleted_after_the_baseline_still_counts_as_unearned_and_is_named_in_the_warnings(): void
    {
        $this->makeRap($this->project);
        $this->freeze();

        $this->project->wbsTasks()->where('wbs_code', 'B.4')->delete();

        $report = $this->evm->report($this->project->refresh(), self::AS_OF);

        $this->assertSame(45.4865, $report['measures']['physical_pct']);
        $this->assertSame(['B.4'], $report['scope_drift']['tasks_removed']);
        $this->assertStringContainsString('B.4', implode(' ', $report['warnings']));
    }

    // ------------------------------------------------------- honest about CPI

    /**
     * The live demo exactly: fin_project_costs holds only `material` for this
     * project while the RAP budgets labor Rp 4.976.334.666,79, subcon
     * Rp 10.821.807.652,16, equipment Rp 178.031.790,79 and overhead
     * Rp 2.850.191.399,22. Four categories with a budget and no actuals, which
     * is why a CPI of 101,63 must never render as a green tile.
     */
    public function test_the_cost_index_is_flagged_unreliable_while_budgeted_categories_have_no_actuals(): void
    {
        $report = $this->demo();

        $this->assertSame(101.6283, $report['measures']['cpi']);
        $this->assertFalse($report['measures']['cpi_reliable']);
        $this->assertSame('cost_incomplete', $report['measures']['cpi_status']);
        $this->assertSame(
            ['labor', 'subcon', 'equipment', 'overhead'],
            $report['cost_coverage']['empty_categories'],
        );
        $this->assertStringContainsString('Upah, Subkon, Alat dan Overhead', $report['cost_coverage']['warning']);
        // SPI is unaffected — physical progress and the plan are both complete.
        $this->assertSame(0.8913, $report['measures']['spi']);
    }

    /**
     * The Rp 4.000 attack. One token row of Rp 1.000 in each empty category
     * used to flip cpi_reliable to true by bare existence — turning a CPI of
     * 144x (0,69% of budget consumed for 55% of the work) into a green
     * audited-looking tile. Coverage is a ratio now: every budgeted category
     * must reach projects.cpi_coverage_min_pct (50%) of its expected-to-date
     * budget, and Rp 1.000 against the Rp 3,07 miliar of Upah that should
     * already be on the books moves nothing.
     */
    public function test_token_amounts_in_every_empty_category_do_not_make_the_cost_index_reliable(): void
    {
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        foreach (['labor', 'subcon', 'equipment', 'overhead'] as $category) {
            $this->addCost($this->project, '2026-07-31', 1_000, $category);
        }

        $report = $this->evm->report($this->project->refresh(), self::AS_OF);

        $this->assertFalse($report['measures']['cpi_reliable']);
        $this->assertSame('cost_incomplete', $report['measures']['cpi_status']);
        // No category is EMPTY any more — that is exactly the attack — but all
        // five sit far below the floor of their running budget (61,706% of the
        // RAP by this date), and the flag now reads coverage, not existence.
        $this->assertSame([], $report['cost_coverage']['empty_categories']);
        $this->assertSame(
            ['material', 'labor', 'subcon', 'equipment', 'overhead'],
            $report['cost_coverage']['under_covered_categories'],
        );
        $this->assertStringContainsString('anggaran yang seharusnya sudah terpakai', $report['cost_coverage']['warning']);
    }

    /**
     * And the flag genuinely comes up once every category covers the floor:
     * 40% of each category's RAP amount against 61,706% expected-to-date is
     * 64,82% coverage — above the 50% threshold in every category.
     */
    public function test_the_cost_index_is_reliable_once_every_category_covers_the_floor_of_its_running_budget(): void
    {
        $this->makeRap($this->project);
        $this->freeze();

        foreach (self::RAP_CATEGORIES as $category => $amount) {
            $this->addCost($this->project, '2026-07-31', round($amount * 0.4, 2), $category);
        }

        $report = $this->evm->report($this->project->refresh(), self::AS_OF);

        $this->assertTrue($report['measures']['cpi_reliable']);
        $this->assertSame('ok', $report['measures']['cpi_status']);
        $this->assertSame([], $report['cost_coverage']['under_covered_categories']);
        $this->assertNull($report['cost_coverage']['warning']);
        // The judgement the screen prints: realisasi mencakup 64,82% dari
        // anggaran yang seharusnya sudah terpakai (40 / 61,706).
        $this->assertSame(50.0, $report['cost_coverage']['coverage_min_pct']);
        $this->assertEqualsWithDelta(64.82, $report['cost_coverage']['coverage_pct'], 0.01);
    }

    // -------------------------------------------------------------- the bridge

    /**
     * physical% / cost-to-cost% = (EV/BAC) / (AC/EAC) = CPI x (EAC/BAC).
     *
     * The ratio is computed from the UNROUNDED fractions. Dividing the two
     * DISPLAYED four-decimal percentages instead gives 55,0000 / 0,5412 =
     * 101,6260 against a CPI of 101,6283, and a reader concludes the identity
     * is approximate when it is exact.
     */
    public function test_the_ratio_of_physical_to_cost_to_cost_percentage_equals_the_cost_index_times_eac_over_bac(): void
    {
        $report = $this->demo();
        $bridge = $report['poc_reconciliation'];
        $measures = $report['measures'];

        $this->assertSame(55.0, $bridge['physical_pct']);
        $this->assertSame(0.5412, $bridge['poc_pct']);
        $this->assertSame(1.0, $bridge['eac_to_bac_ratio']);
        $this->assertSame($measures['cpi'], $bridge['ratio']);
        $this->assertTrue($bridge['matches_cpi']);

        $naive = round($bridge['physical_pct'] / $bridge['poc_pct'], 4);
        $this->assertSame(101.626, $naive);
        $this->assertNotSame($naive, $bridge['ratio']);

        $this->assertStringContainsString('Rasio keduanya = CPI x (EAC/BAC)', $bridge['explanation']);
    }

    public function test_the_reconciliation_quotes_the_latest_posted_psak_115_run_when_one_exists(): void
    {
        $this->seedLedger(2026);
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        $poster = $this->userWith('fin.post', 'Akuntan');
        $recognition = app(RevenueRecognitionService::class);
        $run = $recognition->post($recognition->calculate(2026, 7, $poster), $poster);

        $bridge = $this->evm->report($this->project->refresh(), '2026-07-31')['poc_reconciliation'];

        $this->assertSame('posted_run', $bridge['poc_source']);
        $this->assertSame($run->code, $bridge['poc_run_code']);
        $this->assertSame('2026-07-31', $bridge['poc_as_of']);
    }

    /** The live demo today: fin_revenue_recognition_runs is empty. */
    public function test_the_reconciliation_falls_back_to_a_live_percentage_and_says_so_when_no_run_is_posted(): void
    {
        $bridge = $this->demo()['poc_reconciliation'];

        $this->assertSame('no_posted_run', $bridge['poc_source']);
        $this->assertNull($bridge['poc_run_code']);
        $this->assertSame(self::AS_OF, $bridge['poc_as_of']);
        $this->assertSame(0.5412, $bridge['poc_pct']);
        $this->assertSame('contract', $bridge['cost_base_scope']);
        $this->assertSame([$this->project->id], $bridge['contract_project_ids']);
    }

    /**
     * The SQLite boundary row — Rp 18.740.000 dated exactly on the cut-off day,
     * which the column stores as '2026-07-05 00:00:00' — is counted by BOTH
     * sides, because RevenueRecognitionService::computeLine now compares through
     * whereDate too (its docblock names June's Rp 196.270.346 payroll as the
     * proof) and EVM mirrors it. This test used to pin the divergence and its
     * "mesin PSAK 115" accusation; keeping that pinned would have preserved a
     * warning blaming Finance for a bug that no longer exists.
     */
    public function test_a_cost_dated_exactly_on_the_as_of_date_is_counted_by_both_evm_and_the_psak_115_cost_base(): void
    {
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        $report = $this->evm->report($this->project->refresh(), '2026-07-05');

        $this->assertSame(228240000.0, $report['measures']['ac']);
        $this->assertSame(228240000.0, $report['poc_reconciliation']['poc_cost_to_date']);
        $this->assertSame([], $report['poc_reconciliation']['boundary_day_amounts']);
        $this->assertStringNotContainsString('mesin PSAK 115', implode(' ', $report['warnings']));
        // Same cost base on both sides, so the CPI identity holds again.
        $this->assertTrue($report['poc_reconciliation']['matches_cpi']);
    }

    // ------------------------------------------------- superseded baselines

    /**
     * A report against a superseded revision is how an extension-of-time claim
     * reads the old plan, so it is answered — carrying the superseded marker,
     * an Indonesian warning, and a deviation block that still names the plan
     * governing TODAY. Before this, ?baseline_id= labelled the old revision
     * current_baseline_code with is_rebaselined=false and zero deltas: a saved
     * PDF stating, machine-readably, that a project re-baselined by
     * Rp 17,8 miliar never was.
     */
    public function test_a_report_against_a_superseded_baseline_says_so_and_still_names_the_current_plan(): void
    {
        $this->makeRap($this->project);
        $first = $this->freeze();
        $second = $this->freeze([
            'effective_date' => '2026-06-01',
            'reason' => 'Addendum I — BAC dinaikkan menjadi Rp 60 miliar.',
            'bac_override' => 60_000_000_000,
        ]);

        $report = $this->evm->report($this->project->refresh(), self::AS_OF, $first->id);

        $this->assertSame($first->code, $report['baseline']['code']);
        $this->assertFalse($report['baseline']['is_current']);
        $this->assertNotNull($report['baseline']['superseded_at']);
        $this->assertStringContainsString('sudah digantikan revisi 1', implode(' ', $report['warnings']));

        $deviation = $report['baseline_deviation'];
        $this->assertSame($second->code, $deviation['current_baseline_code']);
        $this->assertSame(1, $deviation['current_revision_no']);
        $this->assertTrue($deviation['is_rebaselined']);
        // Rp 60.000.000.000 - Rp 42.173.913.043,47.
        $this->assertSame(17826086956.53, $deviation['bac_delta']);

        // …and the live report carries the opposite marker, no warning.
        $current = $this->evm->report($this->project->refresh(), self::AS_OF);
        $this->assertTrue($current['baseline']['is_current']);
        $this->assertNull($current['baseline']['superseded_at']);
        $this->assertStringNotContainsString('sudah digantikan', implode(' ', $current['warnings']));
    }

    /**
     * When revision 0 was rejected there is no approved original to measure
     * against — the deltas are null with the reason stated, never zeroes from
     * quietly comparing the current baseline to itself.
     */
    public function test_the_deviation_reports_nulls_with_the_reason_when_revision_zero_was_never_approved(): void
    {
        $this->makeRap($this->project);
        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');

        $rejected = $this->baselines->snapshot($this->project, ['effective_date' => '2026-02-02'], $maker);
        $this->baselines->submit($rejected, $maker);
        $this->baselines->reject($rejected, $checker, 'Kurva belum disepakati MK.');

        $approved = $this->baselines->snapshot($this->project, [
            'effective_date' => '2026-03-01',
            'reason' => 'Pengajuan ulang setelah kurva disepakati MK.',
        ], $maker);
        $this->baselines->submit($approved, $maker);
        $this->baselines->approve($approved, $checker);

        $deviation = $this->evm->report($this->project->refresh(), self::AS_OF)['baseline_deviation'];

        $this->assertNull($deviation['original_baseline_code']);
        $this->assertNull($deviation['bac_delta']);
        $this->assertNull($deviation['planned_finish_delta_days']);
        $this->assertSame($approved->code, $deviation['current_baseline_code']);
        $this->assertStringContainsString('revisi 0 tidak pernah disetujui', $deviation['note']);
    }

    // ------------------------------------------- posted runs and the report date

    /**
     * The posted run quoted beside a report must belong to a period that had
     * CLOSED by the report date. Newest-run-wins with no bound used to put
     * July's Rp 228.240.000 cost-to-date and 0,5412% beside a March AC of
     * Rp 209.500.000 — four months of money that did not exist on the report
     * date, two cut-off dates in one column of one page.
     */
    public function test_a_back_dated_report_never_quotes_a_psak_115_run_posted_for_a_later_period(): void
    {
        $this->seedLedger(2026);
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        $poster = $this->userWith('fin.post', 'Akuntan');
        $recognition = app(RevenueRecognitionService::class);
        $recognition->post($recognition->calculate(2026, 7, $poster), $poster);

        $bridge = $this->evm->report($this->project->refresh(), '2026-03-31')['poc_reconciliation'];

        // Falls through to the live recompute at the report's own cut-off.
        $this->assertSame('no_posted_run', $bridge['poc_source']);
        $this->assertNull($bridge['poc_run_code']);
        $this->assertSame('2026-03-31', $bridge['poc_as_of']);
        $this->assertSame(209500000.0, $bridge['poc_cost_to_date']);
        $this->assertTrue($bridge['matches_cpi']);

        // A mid-month report is the same case in miniature: July's run measures
        // 31-07, which has not happened yet on 05-07.
        $mid = $this->evm->report($this->project->refresh(), '2026-07-05')['poc_reconciliation'];
        $this->assertSame('no_posted_run', $mid['poc_source']);
        $this->assertSame('2026-07-05', $mid['poc_as_of']);
    }

    /**
     * A run from an EARLIER period is quotable — that is the normal month-end
     * state — but its cut-off differs from the report's, so the CPI identity
     * fails and the failure names its reason. The old warning condition
     * (count($projectIds) > 1) silenced every single-project mismatch, which is
     * the demo's only shape; and the bridge sentence used to keep asserting
     * "Rasio keduanya = CPI x (EAC/BAC)" against its own matches_cpi=false.
     */
    public function test_a_run_from_an_earlier_period_fails_the_identity_with_the_reason_named_not_asserted(): void
    {
        $this->seedLedger(2026);
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        $poster = $this->userWith('fin.post', 'Akuntan');
        $recognition = app(RevenueRecognitionService::class);
        $run = $recognition->post($recognition->calculate(2026, 6, $poster), $poster);

        $report = $this->evm->report($this->project->refresh(), '2026-07-31');
        $bridge = $report['poc_reconciliation'];

        $this->assertSame('posted_run', $bridge['poc_source']);
        $this->assertSame('2026-06-30', $bridge['poc_as_of']);
        $this->assertSame(209500000.0, $bridge['poc_cost_to_date']);
        $this->assertSame(228240000.0, $bridge['ac']);
        $this->assertFalse($bridge['matches_cpi']);

        // The warning names the run and the two cut-off dates…
        $this->assertStringContainsString($run->code, implode(' ', $report['warnings']));
        $this->assertStringContainsString('tanggal potong yang berbeda', implode(' ', $report['warnings']));
        // …and the sentence no longer asserts the identity it denies.
        $this->assertStringContainsString('tidak sama dengan CPI x (EAC/BAC)', $bridge['explanation']);
        $this->assertStringNotContainsString('Rasio keduanya = CPI x (EAC/BAC)', $bridge['explanation']);
    }

    // ---------------------------------------------------------------- as_of

    public function test_the_as_of_date_comes_from_the_server_and_a_future_as_of_is_refused(): void
    {
        $this->makeRap($this->project);
        $this->freeze();

        $report = $this->evm->report($this->project);
        $this->assertSame(now()->toDateString(), $report['as_of']);
        $this->assertSame('server', $report['as_of_source']);

        try {
            $this->evm->report($this->project, now()->addDay()->toDateString());
            $this->fail('A future as_of was accepted.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak boleh di masa depan', $e->getMessage());
        }

        $this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}/evm?as_of=".now()->addWeek()->toDateString())
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of');
    }

    public function test_a_report_dated_before_the_baseline_takes_effect_is_refused_rather_than_answered_with_zeroes(): void
    {
        $this->makeRap($this->project);
        $this->freeze(['effective_date' => '2026-03-01']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/mendahului tanggal berlaku baseline/');

        $this->evm->report($this->project, '2026-02-10');
    }

    // ------------------------------------------------------------ empty state

    public function test_a_project_without_an_approved_baseline_returns_a_named_empty_state_not_an_error(): void
    {
        $report = $this->evm->report($this->project, self::AS_OF);

        $this->assertSame('no_baseline', $report['state']);
        $this->assertNull($report['measures']);
        $this->assertStringContainsString('belum punya baseline', $report['message']);

        $this->actingAs($this->adminUser())
            ->getJson("/api/projects/{$this->project->id}/evm?as_of=".self::AS_OF)
            ->assertOk()
            ->assertJsonPath('data.state', 'no_baseline');
    }

    /** A short list reads as a bug; an incomplete one reads as an answer. */
    public function test_the_portfolio_lists_every_project_including_those_with_no_baseline(): void
    {
        $bankArtha = Project::query()->create([
            'code' => 'PRJ-2026-002',
            'name' => 'Instalasi ELV & Data Center Bank Artha Nusantara',
            'type' => 'system_integration',
            'status' => 'active',
            'contract_value' => 9_800_000_000,
        ]);

        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        $rows = collect($this->evm->portfolio(self::AS_OF)['rows'])->keyBy('code');

        $this->assertCount(2, $rows);
        $this->assertSame(101.6283, $rows['PRJ-2026-001']['cpi']);
        $this->assertFalse($rows['PRJ-2026-001']['cpi_reliable']);
        $this->assertNull($rows[$bankArtha->code]['cpi']);
        $this->assertNull($rows[$bankArtha->code]['baseline_code']);
        $this->assertSame('no_baseline', $rows[$bankArtha->code]['state']);
    }

    // ------------------------------------------------------------- read-only

    /**
     * EVM reads Finance and must never write to it. In particular EAC(EVM) —
     * Rp 414.981.818,18 here — must never reach the POC engine's EAC, which is
     * management's reviewed judgement and is deliberately preserved across
     * recalculation.
     */
    public function test_the_report_writes_nothing_to_the_finance_tables(): void
    {
        $this->seedLedger(2026);
        $this->makeRap($this->project);
        $this->freeze();
        $this->addDemoCosts($this->project);

        $poster = $this->userWith('fin.post', 'Akuntan');
        $recognition = app(RevenueRecognitionService::class);
        $recognition->post($recognition->calculate(2026, 7, $poster), $poster);

        $before = $this->financeSnapshot();

        $report = $this->evm->report($this->project->refresh(), '2026-07-31');

        $this->assertSame($before, $this->financeSnapshot());
        // The two forecasts sit side by side under different names, and the
        // payload states which one the ledger actually uses.
        $this->assertNotSame($report['measures']['eac_evm'], $report['poc_reconciliation']['poc_eac']);
        $this->assertSame('poc_eac', $report['poc_reconciliation']['eac_used_by_ledger']);
    }

    /** Row counts plus a checksum of every money column that could move. */
    private function financeSnapshot(): array
    {
        $snapshot = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if (! str_starts_with($table, 'fin_')) {
                continue;
            }

            $snapshot[$table] = (int) DB::table($table)->count();
        }

        $snapshot['costs_sum'] = round((float) DB::table('fin_project_costs')->sum('amount'), 2);
        $snapshot['recognition_sum'] = round((float) DB::table('fin_revenue_recognition_lines')->sum('revenue_cumulative'), 2);
        $snapshot['recognition_eac'] = round((float) DB::table('fin_revenue_recognition_lines')->sum('estimated_total_cost'), 2);
        $snapshot['journal_lines_debit'] = round((float) DB::table('fin_journal_lines')->sum('debit'), 2);

        return $snapshot;
    }
}
