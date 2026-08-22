<?php

namespace Modules\Projects\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Support\Erp;
use Modules\Core\Support\Money;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\ProjectCostService;
use Modules\Projects\Enums\IndexStatus;
use Modules\Projects\Models\BaselineTask;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Models\WbsTask;
use Modules\Projects\Support\PlannedCurve;

/**
 * Earned value management (CPI / SPI) against a frozen baseline.
 *
 * STRICTLY READ-ONLY AGAINST FINANCE. It SELECTs fin_project_costs and
 * fin_revenue_recognition_lines and it CALLS ProjectCostService::totalsByCategory,
 * which is explicitly permitted. It never writes a Finance row and never calls
 * JournalService. In particular EAC(EVM) = BAC / CPI never feeds the POC
 * engine's EAC — that stays management's override, then the RAP, then para 45.
 * Overwriting a reviewed EAC with a statistical one would silently destroy the
 * judgement RevenueRecognitionService::calculate goes out of its way to keep
 * across recalculation, so the two forecasts are reported side by side under
 * different names and a note says which one the ledger actually uses.
 *
 * THE THREE QUANTITIES, in terms of this schema:
 *   PV = PlannedCurve::cumulativePct(frozen leaves, as_of) / 100 x BAC
 *   EV = physical% / 100 x BAC, physical% being the sum of live leaf progress
 *        against FROZEN baseline weights — the same arithmetic
 *        ProgressService::recalcProjectProgress performs, so EVM and the
 *        project header cannot disagree in the fourth decimal
 *   AC = SUM(fin_project_costs.amount) for this project, whereDate <= as_of
 *
 * whereDate, not a raw `<=`. Proven on the live file: on SQLite the `date`
 * column holds '2026-07-05 00:00:00', so `cost_date <= '2026-07-05'` is a
 * string compare that returns FALSE and drops the Rp 18.740.000 inventory issue
 * booked that day. RevenueRecognitionService::computeLine reads the same table
 * through the same whereDate (its docblock names June's Rp 196.270.346 payroll
 * as the row that forced the fix), so EVERY cost comparison in this class —
 * AC and the poc_reconciliation cost base alike — uses whereDate and the two
 * engines read the same rupiah for the same cut-off.
 */
class EvmService
{
    public function __construct(
        private readonly BaselineService $baselines,
        private readonly ProjectCostService $costs,
    ) {}

    /**
     * The full report for one project.
     */
    public function report(Project $project, ?string $asOf = null, ?int $baselineId = null): array
    {
        $asOf = $this->resolveAsOf($asOf);
        $baseline = $this->baselines->currentFor($project, $baselineId);

        if ($baseline === null || $baseline->project_id !== $project->id) {
            // NOT a 404 and NOT a 500. The screen has to render a "Bekukan
            // baseline" call to action, and a project with no plan is an
            // ordinary state of the world rather than an error.
            return [
                'as_of' => $asOf,
                'as_of_source' => 'server',
                'state' => 'no_baseline',
                'message' => "Proyek {$project->code} belum punya baseline yang disetujui. "
                    .'Bekukan baseline lebih dulu agar SPI dan CPI punya rencana untuk dibandingkan.',
                'project' => $this->projectPayload($project),
                'baseline' => null,
                'measures' => null,
                'warnings' => [],
            ];
        }

        if ($asOf < $baseline->effective_date->toDateString()) {
            throw new LogicException(sprintf(
                'Tanggal laporan %s mendahului tanggal berlaku baseline %s — belum ada rencana untuk dibandingkan.',
                $asOf, $baseline->effective_date->toDateString(),
            ));
        }

        $warnings = $baseline->warnings();

        // A report against a superseded revision is legitimate — it is how an
        // extension-of-time claim reads the old plan — but it must SAY so, in
        // the payload and in the warnings, or a saved PDF of the old plan is
        // indistinguishable from the live report.
        if ($baseline->superseded_at !== null) {
            $superseding = $baseline->supersededBy;
            $warnings[] = sprintf(
                'Baseline %s sudah digantikan %s pada %s; angka di bawah bukan rencana yang berlaku.',
                $baseline->code,
                $superseding !== null
                    ? "revisi {$superseding->revision_no} ({$superseding->code})"
                    : 'revisi yang lebih baru',
                $baseline->superseded_at->format('d-m-Y'),
            );
        }

        $frozen = $baseline->tasks()->get();
        $frozenLeaves = $frozen->where('is_leaf', true);

        [$physicalPct, $scope] = $this->physicalProgress($project, $frozenLeaves);
        $warnings = array_merge($warnings, $scope['warnings']);

        $bac = (float) $baseline->bac;
        $plannedPct = PlannedCurve::cumulativePct($frozenLeaves, $asOf);
        $ac = $this->actualCost($project, $asOf);

        $coverage = $this->costCoverage($project, $baseline, $ac, $plannedPct);
        $measures = $this->measures($bac, $plannedPct, $physicalPct, $ac, $coverage['cpi_reliable']);

        if ($coverage['warning'] !== null) {
            $warnings[] = $coverage['warning'];
        }

        $reconciliation = $this->pocReconciliation($project, $baseline, $asOf, $physicalPct, $measures, $warnings);

        return [
            'as_of' => $asOf,
            'as_of_source' => 'server',
            'state' => 'ok',
            'project' => $this->projectPayload($project),
            'baseline' => $this->baselinePayload($baseline),
            'measures' => $measures,
            'cost_coverage' => $coverage,
            'poc_reconciliation' => $reconciliation,
            'baseline_deviation' => $this->baselineDeviation($project, $baseline),
            'curve' => $this->curve($project, $baseline, $asOf, $plannedPct, $physicalPct, $ac, $warnings),
            'scope_drift' => [
                'tasks_removed' => $scope['tasks_removed'],
                'tasks_added' => $scope['tasks_added'],
                'live_progress_pct' => round((float) $project->actual_progress_pct, 4),
                'baseline_progress_pct' => $physicalPct,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * One row per project. Projects with no current baseline are INCLUDED with
     * nulls, so the portfolio list is complete rather than silently short —
     * PRJ-2026-002 appears that way today and its absence would read as a bug.
     */
    public function portfolio(?string $asOf = null): array
    {
        $asOf = $this->resolveAsOf($asOf);
        $rows = [];

        foreach (Project::query()->orderBy('code')->get() as $project) {
            $baseline = $this->baselines->currentFor($project);

            if ($baseline === null || $asOf < $baseline->effective_date->toDateString()) {
                $rows[] = $this->emptyPortfolioRow($project, $baseline);

                continue;
            }

            $frozenLeaves = $baseline->tasks()->where('is_leaf', true)->get();
            [$physicalPct] = $this->physicalProgress($project, $frozenLeaves);

            $bac = (float) $baseline->bac;
            $plannedPct = PlannedCurve::cumulativePct($frozenLeaves, $asOf);
            $ac = $this->actualCost($project, $asOf);
            $coverage = $this->costCoverage($project, $baseline, $ac, $plannedPct);
            $measures = $this->measures($bac, $plannedPct, $physicalPct, $ac, $coverage['cpi_reliable']);

            $rows[] = [
                'project_id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'baseline_code' => $baseline->code,
                'revision_no' => (int) $baseline->revision_no,
                'as_of' => $asOf,
                'bac' => $measures['bac'],
                'pv' => $measures['pv'],
                'ev' => $measures['ev'],
                'ac' => $measures['ac'],
                'planned_pct' => $measures['planned_pct'],
                'physical_pct' => $measures['physical_pct'],
                'spi' => $measures['spi'],
                'spi_status' => $measures['spi_status'],
                'cpi' => $measures['cpi'],
                'cpi_status' => $measures['cpi_status'],
                'cpi_reliable' => $measures['cpi_reliable'],
                'sv' => $measures['sv'],
                'cv' => $measures['cv'],
            ];
        }

        return ['as_of' => $asOf, 'as_of_source' => 'server', 'rows' => $rows];
    }

    // ---------------------------------------------------------------- inputs

    /**
     * AS-OF COMES FROM THE SERVER. The dashboard was writing "per 1 Agustus
     * 2026" from the browser clock while the server said 28 July; an EVM report
     * keyed off a skewed PC clock manufactures schedule variance out of nothing.
     * A future as_of is REFUSED rather than clamped: it compares a plan that is
     * not yet due against today's progress and invents a delay.
     */
    private function resolveAsOf(?string $asOf): string
    {
        $today = now()->toDateString();

        if ($asOf === null || trim($asOf) === '') {
            return $today;
        }

        $asOf = Carbon::parse($asOf)->toDateString();

        if ($asOf > $today) {
            throw new LogicException(
                'Tanggal laporan tidak boleh di masa depan — laporan masa depan membandingkan rencana '
                .'yang belum jatuh tempo dengan progres hari ini dan mengarang keterlambatan.'
            );
        }

        return $asOf;
    }

    /**
     * Physical progress against FROZEN weights, plus what drifted since.
     *
     * A reweighted WBS cannot inflate earned value: the weights come from the
     * baseline, only the progress percentages are live. The consequence is
     * deliberate and shown on screen — after a WBS change
     * prj_projects.actual_progress_pct (live weights) and this figure (frozen
     * weights) can differ, and the report prints both with the reason rather
     * than letting a reweighting quietly earn money.
     *
     * @param  Collection<int, BaselineTask>  $frozenLeaves
     * @return array{0: float, 1: array{tasks_removed: list<string>, tasks_added: list<string>, warnings: list<string>}}
     */
    private function physicalProgress(Project $project, $frozenLeaves): array
    {
        $live = $project->wbsTasks()->get();
        $byId = $live->keyBy('id');
        $byCode = $live->keyBy('wbs_code');

        $earned = 0.0;
        $removed = [];
        $matchedIds = [];

        foreach ($frozenLeaves as $leaf) {
            $task = ($leaf->wbs_task_id !== null ? $byId->get($leaf->wbs_task_id) : null)
                ?? $byCode->get($leaf->wbs_code);

            if ($task === null) {
                // Scope removed after freezing. It earns nothing — deleting a
                // work package is not a way to complete it.
                $removed[] = $leaf->wbs_code;

                continue;
            }

            $matchedIds[] = $task->id;
            $earned += (float) $task->progress_pct * (float) $leaf->weight_pct / 100;
        }

        $added = $live
            ->filter(fn (WbsTask $task): bool => ! in_array($task->id, $matchedIds, true))
            ->filter(fn (WbsTask $task): bool => ! $live->contains(fn (WbsTask $other): bool => $other->parent_id === $task->id))
            ->pluck('wbs_code')
            ->values()
            ->all();

        $warnings = [];

        if ($removed !== []) {
            $warnings[] = sprintf(
                'Tugas %s ada di baseline tetapi sudah tidak ada di WBS; bobotnya dihitung 0%% (belum diperoleh).',
                implode(', ', $removed),
            );
        }

        if ($added !== []) {
            $warnings[] = sprintf(
                'Tugas %s ada di WBS tetapi tidak ada di baseline; lingkup baru belum menghasilkan nilai '
                .'sampai baseline baru disetujui.',
                implode(', ', $added),
            );
        }

        return [round($earned, 4), ['tasks_removed' => $removed, 'tasks_added' => $added, 'warnings' => $warnings]];
    }

    private function actualCost(Project $project, string $asOf): float
    {
        return round((float) ProjectCost::query()
            ->where('project_id', $project->id)
            ->whereDate('cost_date', '<=', $asOf)
            ->sum('amount'), 2);
    }

    // -------------------------------------------------------------- measures

    /**
     * Every ratio here is nullable and paired with a status. See IndexStatus
     * for why: json_encode() throws on INF and NAN, so one unguarded division
     * turns the worst project in the portfolio into an HTTP 500.
     */
    private function measures(float $bac, float $plannedPct, float $physicalPct, float $ac, bool $cpiReliable): array
    {
        $pv = round($plannedPct / 100 * $bac, 2);
        $ev = round($physicalPct / 100 * $bac, 2);

        $sv = round($ev - $pv, 2);
        $cv = round($ev - $ac, 2);

        $spi = $pv > 0 ? $this->finite($ev / $pv) : null;
        $spiStatus = $pv > 0 ? IndexStatus::Ok : IndexStatus::NoPlannedValue;

        // AC = 0 with EV > 0 is an unrecorded cost, not infinite efficiency.
        // EV = 0 with AC > 0 is a real, meaningful zero and stays visible.
        $cpiRaw = $ac > 0 ? $ev / $ac : null;
        $cpi = $cpiRaw === null ? null : $this->finite($cpiRaw);
        $cpiStatus = $ac > 0
            ? ($cpiReliable ? IndexStatus::Ok : IndexStatus::CostIncomplete)
            : IndexStatus::NoCostRecorded;

        // EAC from the UNROUNDED index. Dividing BAC by a CPI already rounded
        // to four decimals moves the demo's forecast by Rp 160.000.
        $eac = ($cpiRaw !== null && $cpiRaw > 0) ? round($bac / $cpiRaw, 2) : null;
        $vac = $eac === null ? null : round($bac - $eac, 2);
        $etc = $eac === null ? null : round($eac - $ac, 2);

        $remainingBudget = round($bac - $ac, 2);
        $tcpi = $remainingBudget > 0 ? $this->finite(($bac - $ev) / $remainingBudget) : null;
        $tcpiStatus = $remainingBudget > 0 ? IndexStatus::Ok : IndexStatus::BudgetExhausted;

        return [
            'bac' => round($bac, 2),
            'planned_pct' => $plannedPct,
            'pv' => $pv,
            'physical_pct' => $physicalPct,
            'ev' => $ev,
            'ac' => $ac,
            'sv' => $sv,
            'sv_pct' => $pv > 0 ? $this->finite($sv / $pv * 100, 2) : null,
            'cv' => $cv,
            'cv_pct' => $ev > 0 ? $this->finite($cv / $ev * 100, 2) : null,
            'spi' => $spi,
            'spi_status' => $spiStatus->value,
            'spi_note' => $spiStatus->note(),
            'cpi' => $cpi,
            'cpi_status' => $cpiStatus->value,
            'cpi_reliable' => $ac > 0 && $cpiReliable,
            'cpi_note' => $ac > 0 && ! $cpiReliable
                ? 'Biaya aktual belum lengkap — CPI belum dapat dipercaya.'
                : $cpiStatus->note(),
            'eac_evm' => $eac,
            'vac' => $vac,
            'etc' => $etc,
            'tcpi' => $tcpi,
            'tcpi_status' => $tcpiStatus->value,
            'tcpi_note' => $tcpiStatus->note(),
        ];
    }

    /**
     * CPI IS ALLOWED TO SAY "DO NOT TRUST ME".
     *
     * The demo's honest CPI is 101,63 and no construction project has ever run
     * at 101x cost efficiency. One query shows why: fin_project_costs holds only
     * `material` for PRJ-2026-001 — Rp 228.240.000 — while its RAP budgets
     * labor Rp 4.976.334.667, subcon Rp 10.821.807.652, overhead
     * Rp 2.850.191.399 and equipment Rp 178.031.791. Four categories with a
     * budget and zero rupiah of actuals, because payroll and subcontract claims
     * do not yet reach that project's cost ledger.
     *
     * The realisasi figures come from ProjectCostService::totalsByCategory —
     * called, not copied, so this screen and the profitability screen can never
     * disagree about which categories are empty. They are all-dates totals on
     * purpose: a category with zero rupiah over all time certainly has zero at
     * as_of, so the flag is never weakened by the wider window.
     *
     * THE FLAG IS A COVERAGE JUDGEMENT, NOT AN EXISTENCE TEST. The rule: every
     * budgeted category's realisasi must reach at least
     * projects.cpi_coverage_min_pct (default 50%) of that category's
     * EXPECTED-TO-DATE budget — its RAP amount x the report's planned_pct.
     * A bare "no category is empty" test was defeated for Rp 4.000: one token
     * row of Rp 1.000 in each of labor/subcon/equipment/overhead turned the
     * demo's CPI of 144x — 0,69% of budget consumed for 55% of the work — from
     * amber "cost_incomplete" into a green audited-looking number. Under the
     * coverage rule those tokens sit at 0,00003% of the Rp 3,07 miliar of
     * labour that should already be on the books, and the flag stays down.
     */
    private function costCoverage(Project $project, ProjectBaseline $baseline, float $ac, float $plannedPct): array
    {
        $actual = $this->costs->totalsByCategory($project->id);

        $budget = [];

        if ($baseline->cost_budget_id !== null) {
            $budget = DB::table('est_cost_budget_items')
                ->where('cost_budget_id', $baseline->cost_budget_id)
                ->selectRaw('cost_category, SUM(amount) as total')
                ->groupBy('cost_category')
                ->pluck('total', 'cost_category')
                ->map(fn ($value): float => round((float) $value, 2))
                ->all();
        }

        $minCoveragePct = Erp::float('projects.cpi_coverage_min_pct', 50.0);

        $budgetByCategory = [];
        $empty = [];
        $covered = [];
        $underCovered = [];
        $expectedToDate = 0.0;
        $actualOnBudgeted = 0.0;

        foreach (CostCategory::cases() as $category) {
            $budgeted = round((float) ($budget[$category->value] ?? 0), 2);
            $budgetByCategory[$category->value] = $budgeted;

            if ($budgeted <= 0) {
                continue;
            }

            $expected = round($budgeted * $plannedPct / 100, 2);
            $expectedToDate += $expected;
            $categoryActual = round((float) ($actual[$category->value] ?? 0), 2);
            $actualOnBudgeted += $categoryActual;

            if ($categoryActual <= 0) {
                $empty[] = $category;
            } else {
                $covered[] = $category;
            }

            if ($expected > 0 && $categoryActual < round($expected * $minCoveragePct / 100, 2)) {
                $underCovered[] = $category;
            }
        }

        $coveragePct = $expectedToDate > 0
            ? $this->finite($actualOnBudgeted / $expectedToDate * 100, 2)
            : null;

        $warning = null;

        if ($empty !== [] && $ac > 0) {
            $coveredBudget = array_sum(array_map(
                fn (CostCategory $category): float => $budgetByCategory[$category->value],
                $covered,
            ));

            $warning = sprintf(
                'Realisasi biaya baru mencakup %s (%s dari anggaran %s). %s dianggarkan tetapi belum tercatat '
                .'sama sekali, sehingga CPI belum menggambarkan efisiensi biaya yang sebenarnya.',
                $covered === [] ? 'sebagian kategori' : $this->joinLabels($covered),
                Money::format($ac, false),
                Money::format($coveredBudget, false),
                $this->joinLabels($empty),
            );
        } elseif ($underCovered !== [] && $ac > 0 && $coveragePct !== null) {
            $warning = sprintf(
                'Realisasi biaya baru mencakup %s%% dari anggaran yang seharusnya sudah terpakai (%s dari %s); '
                .'%s masih di bawah ambang %s%% dari anggaran berjalannya, sehingga CPI belum menggambarkan '
                .'efisiensi biaya yang sebenarnya.',
                number_format($coveragePct, 2, ',', '.'),
                Money::format($actualOnBudgeted, false),
                Money::format(round($expectedToDate, 2), false),
                $this->joinLabels($underCovered),
                number_format($minCoveragePct, 0, ',', '.'),
            );
        }

        return [
            'actual_by_category' => $actual,
            'actual_by_category_scope' => 'all_dates',
            'budget_by_category' => $budgetByCategory,
            'budget_source' => $baseline->cost_budget_code,
            'empty_categories' => array_map(fn (CostCategory $category): string => $category->value, $empty),
            'under_covered_categories' => array_map(fn (CostCategory $category): string => $category->value, $underCovered),
            // "Realisasi mencakup X% dari anggaran yang seharusnya sudah
            // terpakai" — the screen can print the judgement, not just a flag.
            'expected_to_date_budget' => round($expectedToDate, 2),
            'coverage_pct' => $coveragePct,
            'coverage_min_pct' => $minCoveragePct,
            'cpi_reliable' => $empty === [] && $underCovered === [],
            'warning' => $warning,
        ];
    }

    /**
     * THE TWO PERCENTAGES, and why they are allowed to differ.
     *
     * physical% / cost-to-cost% = (EV/BAC) / (AC/EAC) = (EV/AC) x (EAC/BAC)
     *                           = CPI x EAC/BAC
     *
     * On the demo 55,0000% / 0,5412% = 101,63 and CPI is 101,6283 with
     * EAC = BAC. The two completion percentages are not a contradiction to be
     * hidden — their ratio IS the cost performance index, and if they ever
     * agreed it would mean CPI = 1. The ratio is computed from the UNROUNDED
     * fractions; dividing the two displayed 4-decimal percentages gives
     * 101,6260 against a CPI of 101,6283 and a reader concludes the identity is
     * approximate when it is exact.
     *
     * @param  list<string>  $warnings
     */
    private function pocReconciliation(
        Project $project,
        ProjectBaseline $baseline,
        string $asOf,
        float $physicalPct,
        array $measures,
        array &$warnings,
    ): array {
        $contract = $project->contract()->first();
        $bac = (float) $baseline->bac;

        // Finance sums costs per CONTRACT across all its projects (soft-deleted
        // ones included — they carry real costs); EVM measures ONE project. On
        // the demo the mapping is 1:1 so the two coincide, and this block says
        // so rather than assuming it.
        $projectIds = $contract === null
            ? [$project->id]
            : DB::table('prj_projects')->where('contract_id', $contract->id)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // Only runs whose period had fully CLOSED by the report date are
        // quotable. Newest-run-wins with no bound put July's Rp 228.240.000
        // cost-to-date beside a March AC on a back-dated report — four months
        // of money that did not exist on the report date, two cut-off dates in
        // one column. A mid-month as_of quotes the PREVIOUS month's run: the
        // month's own run measures its last day, which has not happened yet.
        $asOfDay = Carbon::parse($asOf);
        $lastClosed = $asOfDay->isLastOfMonth() ? $asOfDay : $asOfDay->copy()->startOfMonth()->subDay();

        $posted = $contract === null ? null : DB::table('fin_revenue_recognition_lines as l')
            ->join('fin_revenue_recognition_runs as r', 'r.id', '=', 'l.run_id')
            ->where('l.contract_id', $contract->id)
            ->where('r.status', PostingStatus::Posted->value)
            ->whereRaw('(r.period_year * 100 + r.period_month) <= ?', [
                $lastClosed->year * 100 + $lastClosed->month,
            ])
            ->orderByDesc('r.period_year')->orderByDesc('r.period_month')
            ->select('l.*', 'r.code as run_code', 'r.period_year', 'r.period_month')
            ->first();

        if ($posted !== null) {
            $pocPct = round((float) $posted->progress_pct, 4);
            $pocEac = (float) $posted->estimated_total_cost;
            $pocEacSource = (string) $posted->eac_source;
            $pocCost = (float) $posted->cost_to_date;
            $pocSource = 'posted_run';
            $pocRunCode = (string) $posted->run_code;
            $pocAsOf = sprintf('%04d-%02d-%02d', $posted->period_year, $posted->period_month,
                (int) cal_days_in_month(CAL_GREGORIAN, (int) $posted->period_month, (int) $posted->period_year));
        } else {
            // Nothing posted (which is the case in the live demo today), so the
            // identical expression is recomputed live and labelled as such —
            // the number is never passed off as audited. whereDate, exactly as
            // RevenueRecognitionService::computeLine reads the same table: a
            // raw `<=` here dropped the Rp 18.740.000 issue dated on the
            // cut-off day itself and then blamed Finance for the difference.
            $pocCost = round((float) DB::table('fin_project_costs')
                ->whereIn('project_id', $projectIds)
                ->whereDate('cost_date', '<=', $asOf)
                ->sum('amount'), 2);

            [$pocEac, $pocEacSource] = $this->estimateTotalCost($projectIds, $pocCost);

            $price = round((float) ($contract->value ?? $project->contract_value), 2);
            $pocPct = $pocEac !== null && $pocEac > 0
                ? round(min(1.0, $pocCost / $pocEac) * 100, 4)
                : ($price > 0 ? round(min(1.0, $pocCost / $price) * 100, 4) : 0.0);
            $pocSource = 'no_posted_run';
            $pocRunCode = null;
            $pocAsOf = $asOf;
        }

        $pocFraction = ($pocEac !== null && $pocEac > 0) ? $pocCost / $pocEac : null;
        $ratio = ($pocFraction !== null && $pocFraction > 0)
            ? $this->finite(($physicalPct / 100) / $pocFraction)
            : null;
        $eacToBac = ($pocEac !== null && $bac > 0) ? $this->finite($pocEac / $bac, 6) : null;

        $cpiRaw = $measures['ac'] > 0 ? $measures['ev'] / $measures['ac'] : null;
        $identity = ($cpiRaw !== null && $eacToBac !== null) ? $this->finite($cpiRaw * $eacToBac) : null;
        $matches = $ratio !== null && $cpiRaw !== null && $eacToBac !== null
            && abs($ratio - $cpiRaw * $eacToBac) < 0.0005;

        // A failed identity ALWAYS gets its reason named — a reader shown
        // matches_cpi=false with no explanation has two percentages and no way
        // to trust either. On the demo's 1:1 contract-to-project mapping the
        // old count($projectIds) > 1 condition silenced every one of these.
        if (! $matches && $ratio !== null) {
            if (count($projectIds) > 1) {
                $warnings[] = sprintf(
                    'Kontrak %s memiliki %d proyek; PSAK 115 menjumlahkan biaya seluruh kontrak sedangkan EVM '
                    .'mengukur satu proyek, sehingga rasio kedua persentase bukan lagi CPI.',
                    $contract?->code ?? '—', count($projectIds),
                );
            } elseif ($pocSource === 'posted_run' && $pocAsOf !== $asOf) {
                $warnings[] = sprintf(
                    'Run PSAK 115 %s berhenti pada %s sedangkan laporan ini per %s; kedua persentase membaca '
                    .'basis biaya pada tanggal potong yang berbeda, sehingga rasionya bukan CPI.',
                    $pocRunCode, $pocAsOf, $asOf,
                );
            } else {
                $warnings[] = sprintf(
                    'Persentase PSAK 115 dan CPI membaca basis biaya yang berbeda (%s vs %s); '
                    .'rasio kedua persentase bukan CPI.',
                    Money::format($pocCost, false), Money::format($measures['ac'], false),
                );
            }
        }

        return [
            'physical_pct' => $physicalPct,
            'poc_pct' => $pocPct,
            'poc_source' => $pocSource,
            'poc_run_code' => $pocRunCode,
            'poc_as_of' => $pocAsOf,
            'poc_eac' => $pocEac === null ? null : round($pocEac, 2),
            'poc_eac_source' => $pocEacSource,
            'poc_cost_to_date' => round($pocCost, 2),
            'ac' => $measures['ac'],
            'cost_base_scope' => $contract === null ? 'project' : 'contract',
            'contract_project_ids' => $projectIds,
            // Empty by construction since both engines compare through
            // whereDate (RevenueRecognitionService.php:344 is the other half of
            // the fix). The key survives because the SPA contract reads it —
            // it used to name the rows the engines disagreed about.
            'boundary_day_amounts' => [],
            'ratio' => $ratio,
            'eac_to_bac_ratio' => $eacToBac,
            'matches_cpi' => $matches,
            'eac_used_by_ledger' => 'poc_eac',
            'explanation' => $this->bridgeSentence(
                $physicalPct, $pocPct, $ratio, $matches, $identity, $pocCost, $measures['ac'], $pocAsOf, $asOf,
            ),
        ];
    }

    /**
     * The sentence only asserts the CPI identity when matches_cpi holds. It
     * used to print "Rasio keduanya = CPI x (EAC/BAC) = 110,72" beside a CPI
     * tile reading 101,63 whenever the two cost bases differed — an equality
     * the same JSON object's own matches_cpi flag denied two keys above.
     */
    private function bridgeSentence(
        float $physicalPct,
        float $pocPct,
        ?float $ratio,
        bool $matches,
        ?float $identity,
        float $pocCost,
        float $ac,
        string $pocAsOf,
        string $asOf,
    ): string {
        $sentence = sprintf(
            'Dua persentase ini mengukur hal yang berbeda dan memang harus berbeda. %s%% adalah progres fisik '
            .'berbobot WBS terhadap baseline; %s%% adalah persentase penyelesaian PSAK 115 (biaya kumulatif '
            .'dibagi EAC) yang dipakai buku besar untuk mengakui pendapatan.',
            number_format($physicalPct, 2, ',', '.'),
            number_format($pocPct, 2, ',', '.'),
        );

        if ($ratio === null) {
            return $sentence.' Rasio keduanya belum dapat dihitung karena salah satu penyebutnya nol.';
        }

        if ($matches) {
            return $sentence.sprintf(
                ' Rasio keduanya = CPI x (EAC/BAC) = %s — selama CPI tidak sama dengan 1, kedua angka wajib berbeda.',
                number_format($ratio, 2, ',', '.'),
            );
        }

        return $sentence.sprintf(
            ' Rasio keduanya (%s) tidak sama dengan CPI x (EAC/BAC)%s karena basis biaya kedua angka '
            .'berbeda: %s per %s pada sisi PSAK 115, %s per %s pada sisi EVM.',
            number_format($ratio, 2, ',', '.'),
            $identity === null ? '' : ' = '.number_format($identity, 2, ',', '.'),
            Money::format($pocCost, false),
            $pocAsOf,
            Money::format($ac, false),
            $asOf,
        );
    }

    /**
     * The same resolution order as RevenueRecognitionService::estimateTotalCost,
     * read-only, so the recomputed percentage is the one that engine would post.
     *
     * @param  list<int>  $projectIds
     * @return array{0: float|null, 1: string}
     */
    private function estimateTotalCost(array $projectIds, float $costToDate): array
    {
        $total = 0.0;
        $anyUnapproved = false;
        $found = false;

        foreach ($projectIds as $projectId) {
            $rap = DB::table('est_cost_budgets')
                ->where('project_id', $projectId)
                ->whereNull('deleted_at')
                ->whereIn('status', ['approved', 'submitted', 'draft'])
                ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first();

            if ($rap === null || (float) $rap->total_budget <= 0) {
                continue;
            }

            $found = true;
            $total += (float) $rap->total_budget;
            $anyUnapproved = $anyUnapproved || $rap->status !== 'approved';
        }

        if (! $found) {
            return [null, 'none'];
        }

        return [round(max($total, $costToDate), 2), $anyUnapproved ? 'rap_unapproved' : 'rap_approved'];
    }

    /**
     * CURRENT baseline against revision 0 — the evidence pack an extension-of-
     * time claim or a liquidated-damages defence is actually built from.
     *
     * Current is resolved fresh (BaselineService::currentFor), never taken from
     * the baseline being reported against: a report requested for a superseded
     * revision used to label that old revision current_baseline_code with
     * is_rebaselined=false and zero deltas — a machine-readable statement that
     * a project re-baselined by Rp 17,8 miliar and 184 days never was. And when
     * revision 0 was never approved there is no original to measure against, so
     * the deltas are null with the reason stated — not zeroes from quietly
     * comparing a baseline to itself.
     */
    private function baselineDeviation(Project $project, ProjectBaseline $baseline): array
    {
        $current = $this->baselines->currentFor($project) ?? $baseline;
        $original = $this->baselines->originalFor($project);

        if ($original === null) {
            return [
                'original_baseline_code' => null,
                'original_revision_no' => null,
                'original_reason' => null,
                'original_reference_no' => null,
                'current_baseline_code' => $current->code,
                'current_revision_no' => (int) $current->revision_no,
                'is_rebaselined' => (int) $current->revision_no > 0,
                'bac_delta' => null,
                'contract_value_delta' => null,
                'planned_finish_delta_days' => null,
                'original_planned_finish' => null,
                'planned_finish' => $current->planned_finish->toDateString(),
                'contract_finish' => $current->contract_finish?->toDateString(),
                'note' => 'Baseline revisi 0 tidak pernah disetujui, sehingga deviasi terhadap rencana awal '
                    .'tidak dapat dihitung.',
            ];
        }

        return [
            'original_baseline_code' => $original->code,
            'original_revision_no' => (int) $original->revision_no,
            'original_reason' => $original->reason,
            'original_reference_no' => $original->reference_no,
            'current_baseline_code' => $current->code,
            'current_revision_no' => (int) $current->revision_no,
            'is_rebaselined' => (int) $current->revision_no > 0,
            'bac_delta' => round((float) $current->bac - (float) $original->bac, 2),
            'contract_value_delta' => round((float) $current->contract_value - (float) $original->contract_value, 2),
            'planned_finish_delta_days' => (int) $original->planned_finish->diffInDays($current->planned_finish, false),
            'original_planned_finish' => $original->planned_finish->toDateString(),
            'planned_finish' => $current->planned_finish->toDateString(),
            'contract_finish' => $current->contract_finish?->toDateString(),
            'note' => null,
        ];
    }

    /**
     * The chart: frozen planned points, plus whatever actual history exists.
     *
     * prj_weekly_progress is the only physical history this schema stores, so
     * it supplies actual_pct and is labelled as its source. It is NOT the
     * planned series: those 8 rows stop at 29-03-2026 on a project running to
     * 2027, they hold no forward plan at all, and planned_pct there is
     * rewritable through an updateOrCreate. The frozen curve is the plan.
     *
     * @param  list<string>  $warnings
     */
    private function curve(
        Project $project,
        ProjectBaseline $baseline,
        string $asOf,
        float $plannedPct,
        float $physicalPct,
        float $ac,
        array &$warnings,
    ): array {
        $bac = (float) $baseline->bac;
        $weeks = $project->weeklyProgress()->reorder()->orderBy('period_end')->get();
        $costs = ProjectCost::query()->where('project_id', $project->id)->orderBy('cost_date')->get();

        $actualAt = function (string $date) use ($weeks): ?float {
            $row = $weeks->last(fn ($week): bool => $week->period_end !== null
                && $week->period_end->toDateString() <= $date);

            return $row === null ? null : round((float) $row->actual_pct, 4);
        };

        $costAt = fn (string $date): float => round((float) $costs
            ->filter(fn (ProjectCost $cost): bool => $cost->cost_date !== null && $cost->cost_date->toDateString() <= $date)
            ->sum('amount'), 2);

        $points = [];

        foreach ($baseline->points()->get() as $point) {
            $date = $point->period_end->toDateString();
            $past = $date <= $asOf;
            $actual = $past ? $actualAt($date) : null;

            $points[] = [
                'period_end' => $date,
                'planned_pct' => round((float) $point->planned_pct, 4),
                'planned_value' => round((float) $point->planned_value, 2),
                'actual_pct' => $actual,
                'earned_value' => $actual === null ? null : round($actual / 100 * $bac, 2),
                'actual_cost' => $past ? $costAt($date) : null,
                'is_as_of' => false,
            ];
        }

        // The FINAL point is always as_of, computed from the authoritative
        // frozen-weight physical percentage rather than from the weekly report.
        $points = array_values(array_filter($points, fn (array $point): bool => $point['period_end'] !== $asOf));
        $points[] = [
            'period_end' => $asOf,
            'planned_pct' => $plannedPct,
            'planned_value' => round($plannedPct / 100 * $bac, 2),
            'actual_pct' => $physicalPct,
            'earned_value' => round($physicalPct / 100 * $bac, 2),
            'actual_cost' => $ac,
            'is_as_of' => true,
        ];

        usort($points, fn (array $a, array $b): int => $a['period_end'] <=> $b['period_end']);

        $reason = null;

        if ($weeks->isEmpty()) {
            $reason = 'Belum ada laporan progres mingguan, sehingga kurva aktual hanya berisi titik per tanggal laporan.';
        } else {
            $latest = $actualAt($asOf);

            if ($latest !== null && abs($latest - $physicalPct) > 0.01) {
                $warnings[] = sprintf(
                    'Laporan mingguan terakhir mencatat progres %s%% sedangkan rollup bobot baseline '
                    .'menghasilkan %s%%; angka EVM memakai rollup WBS.',
                    number_format($latest, 2, ',', '.'),
                    number_format($physicalPct, 2, ',', '.'),
                );
            }
        }

        return [
            'planned_source' => 'baseline_wbs',
            'actual_pct_source' => 'weekly_report',
            'reason' => $reason,
            'points' => $points,
        ];
    }

    // ---------------------------------------------------------------- payload

    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'status' => $project->status?->value,
            'contract_value' => round((float) $project->contract_value, 2),
        ];
    }

    private function baselinePayload(ProjectBaseline $baseline): array
    {
        return [
            'id' => $baseline->id,
            'code' => $baseline->code,
            'revision_no' => (int) $baseline->revision_no,
            'status' => $baseline->status->value,
            'status_label' => $baseline->status->label(),
            'effective_date' => $baseline->effective_date->toDateString(),
            'approved_at' => $baseline->approved_at?->toIso8601String(),
            // The superseded marker. Without it a saved report against an old
            // revision is machine-indistinguishable from the live plan.
            'is_current' => $baseline->isCurrent(),
            'superseded_at' => $baseline->superseded_at?->toIso8601String(),
            'bac' => round((float) $baseline->bac, 2),
            'bac_source' => $baseline->bac_source->value,
            'bac_source_label' => $baseline->bac_source->label(),
            'cost_budget_code' => $baseline->cost_budget_code,
            'cost_budget_status' => $baseline->cost_budget_status,
            'contract_code' => $baseline->contract_code,
            'contract_value' => round((float) $baseline->contract_value, 2),
            'planned_start' => $baseline->planned_start->toDateString(),
            'planned_finish' => $baseline->planned_finish->toDateString(),
            'contract_finish' => $baseline->contract_finish?->toDateString(),
            'planned_duration_days' => (int) $baseline->planned_duration_days,
            'leaf_task_count' => (int) $baseline->leaf_task_count,
            'leaf_weight_total' => round((float) $baseline->leaf_weight_total, 4),
            'reason' => $baseline->reason,
            'reference_type' => $baseline->reference_type,
            'reference_no' => $baseline->reference_no,
        ];
    }

    private function emptyPortfolioRow(Project $project, ?ProjectBaseline $baseline): array
    {
        return [
            'project_id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'baseline_code' => null,
            'revision_no' => null,
            'as_of' => null,
            'bac' => null, 'pv' => null, 'ev' => null, 'ac' => null,
            'planned_pct' => null, 'physical_pct' => null,
            'spi' => null, 'spi_status' => null,
            'cpi' => null, 'cpi_status' => null, 'cpi_reliable' => false,
            'sv' => null, 'cv' => null,
            'state' => $baseline === null ? 'no_baseline' : 'before_effective_date',
        ];
    }

    /**
     * NOTHING in the payload is ever INF or NAN. json_encode() fails outright
     * on either, and a failed encode is an HTTP 500 on the one report somebody
     * needed. Every division in this class comes through here.
     */
    private function finite(float $value, int $decimals = 4): ?float
    {
        return is_finite($value) ? round($value, $decimals) : null;
    }

    /** @param  list<CostCategory>  $categories */
    private function joinLabels(array $categories): string
    {
        $labels = array_map(fn (CostCategory $category): string => $category->label(), $categories);

        if (count($labels) <= 1) {
            return implode('', $labels);
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' dan '.$last;
    }
}
