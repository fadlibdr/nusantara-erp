<?php

namespace Modules\Assets\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Assets\Enums\AssetStatus;
use Modules\Assets\Enums\DeploymentStatus;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\Deployment;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\ProjectCostService;

/**
 * Mobilisasi/demobilisasi of equipment to project sites, plus the internal
 * equipment charge (days x daily rate) that project costing carries for it:
 * accrued month by month while the machine is on site (accrueMonth), with the
 * demobilisation charging only the residual days no accrual has covered.
 * This module writes the project cost ledger and never posts a journal.
 */
class DeploymentService
{
    /**
     * reference_type of one month's accrual row in fin_project_costs. The
     * demobilisation residual keeps the original 'asset_deployment', so the
     * two shapes stay distinguishable in the cost ledger.
     */
    public const MONTH_REFERENCE = 'asset_deployment_month';

    /**
     * The idempotency key of one (deployment, month) accrual row.
     *
     * IT CARRIES BOTH THE DEPLOYMENT AND THE YEAR, and neither half is
     * optional. ProjectCostService::record() is updateOrCreate on
     * (reference_type, reference_id, cost_category), so the obvious
     * deployment_id * 100 + month gives March 2026 and March 2027 the same
     * reference_id and the second year silently OVERWRITES the first — the
     * project loses a month of plant with nothing to show it had gone.
     * Dropping the deployment instead, year * 100 + month, is worse: every
     * open deployment in the same month collapses onto one row, so the second
     * machine accrued overwrites the first. Multiplying by 1.000.000 leaves
     * year * 100 + month (max 999.912) room of its own, and the result fits
     * the unsignedBigInteger reference_id column for any realistic id.
     *
     * PeriodCloseService::itemPlantAccrued repeats this arithmetic in SQL;
     * a change here must change that whereRaw too.
     */
    public static function monthReferenceId(int $deploymentId, int $year, int $month): int
    {
        return $deploymentId * 1_000_000 + $year * 100 + $month;
    }

    /**
     * Deploy an available asset to a project (mobilisasi).
     */
    public function deploy(Asset $asset, array $data): Deployment
    {
        if ($asset->status !== AssetStatus::Available) {
            throw new LogicException(
                "Asset {$asset->code} is {$asset->status->value}; only available assets can be deployed."
            );
        }

        return DB::transaction(function () use ($asset, $data): Deployment {
            $deployment = $asset->deployments()->create([
                'project_id' => (int) $data['project_id'],
                'deployed_from' => $data['deployed_from'] ?? now()->toDateString(),
                'planned_until' => $data['planned_until'] ?? null,
                'daily_rate_internal' => $data['daily_rate_internal'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => DeploymentStatus::Active,
            ]);

            $asset->forceFill([
                'status' => AssetStatus::Deployed,
                'current_project_id' => (int) $data['project_id'],
            ])->save();

            return $deployment->load('asset');
        });
    }

    /**
     * Accrue one month of internal plant charge for every deployment still on
     * site during that month (T43): one fin_project_costs row per (deployment,
     * month), dated the month end, days-on-site-in-that-month x daily rate.
     * Idempotent — record() is updateOrCreate on the month reference key, so
     * re-running a month rewrites the same rows with the same figures.
     *
     * EXPLICIT PER-MONTH RUNS, WITH THE PERIOD GATE AS ARBITER. Accounting
     * here is forward-only, and catch-up accrual walks a fine line: accruing
     * an OPEN deployment's past months creates cost rows dated in those
     * months, which is legitimate exactly as long as the fiscal calendar says
     * the month is still open — ProjectCostService::record() refuses a closed
     * one, and this method refuses the same thing up front so a whole batch
     * fails with one sentence instead of half-writing. A month that closed
     * unaccrued is NOT repaired by backdating (that would change books
     * somebody signed); its plant cost surfaces at demobilisation, dated the
     * return day, exactly as the pre-accrual shape always did. The period
     * close checklist (plant_accrued) exists so that state is a written
     * override, never an accident.
     *
     * A month that has not ENDED is refused too: accruing the full month on
     * the 8th books days that have not happened, and a machine returned on
     * the 15th would then need a negative correction that never had to exist.
     *
     * Only ACTIVE deployments accrue. A returned deployment settled its whole
     * span at demobilisation — total days minus whatever was accrued while it
     * was open — so accruing it afterwards would double count.
     *
     * @return array<int, array{deployment_id: int, code: string, project_id: int, days: int, amount: float}>
     */
    public function accrueMonth(int $year, int $month): array
    {
        if ($month < 1 || $month > 12) {
            // Carbon::create would silently overflow month 13 into January of
            // the NEXT year and the accrual would land in a month nobody named.
            throw new LogicException("Bulan {$month} tidak dikenal — gunakan 1 sampai 12.");
        }

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($monthEnd->gte(Carbon::today())) {
            throw new LogicException(sprintf(
                'Bulan %04d-%02d belum berakhir — akrual alat dijalankan setelah bulan berakhir, '
                .'agar hari yang belum terjadi tidak ikut terbebankan.',
                $year,
                $month,
            ));
        }

        $period = FiscalPeriod::forDate($monthEnd);

        if ($period !== null && ! $period->isOpen()) {
            throw new LogicException(sprintf(
                'Periode fiskal %04d-%02d sudah ditutup; akrual pemakaian alat tidak dapat dicatat ke dalamnya. '
                .'Biaya alat mobilisasi terbuka bulan itu baru akan muncul pada tanggal demobilisasi.',
                $year,
                $month,
            ));
        }

        return DB::transaction(function () use ($year, $month, $monthStart, $monthEnd): array {
            // Locked re-read inside the transaction: a demobilisation running
            // concurrently marks the row returned; whichever transaction takes
            // the row lock second sees the other's truth instead of a stale
            // instance, so a machine cannot be both accrued AND settled in
            // full by its residual.
            $deployments = Deployment::query()
                ->with('asset')
                ->where('status', DeploymentStatus::Active->value)
                ->whereDate('deployed_from', '<=', $monthEnd->toDateString())
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $costs = app(ProjectCostService::class);
            $rows = [];

            foreach ($deployments as $deployment) {
                $rate = round((float) ($deployment->daily_rate_internal ?? 0), 2);

                if ($rate <= 0 || $deployment->project_id === null) {
                    continue; // no rate or no project: nothing to charge, same stance as the return
                }

                $from = $deployment->deployed_from->greaterThan($monthStart)
                    ? $deployment->deployed_from->copy()->startOfDay()
                    : $monthStart->copy();

                // Inclusive of both ends, the same day-counting rule as the
                // residual — the per-month day counts partition the deployment's
                // span, so accrued months + residual is exact to the rupiah.
                $days = (int) $from->diffInDays($monthEnd) + 1;
                $amount = round($days * $rate, 2);

                $costs->record(
                    (int) $deployment->project_id,
                    $monthEnd->toDateString(),
                    CostCategory::Equipment,
                    self::MONTH_REFERENCE,
                    self::monthReferenceId((int) $deployment->id, $year, $month),
                    sprintf(
                        'Akrual pemakaian %s — %04d-%02d, %d hari @ %s/hari',
                        $deployment->asset?->code ?? 'alat',
                        $year,
                        $month,
                        $days,
                        number_format($rate, 0, ',', '.'),
                    ),
                    $amount,
                );

                $rows[] = [
                    'deployment_id' => (int) $deployment->id,
                    'code' => $deployment->code,
                    'project_id' => (int) $deployment->project_id,
                    'days' => $days,
                    'amount' => $amount,
                ];
            }

            return $rows;
        });
    }

    /**
     * Return a deployed asset from site (demobilisasi).
     *
     * The internal plant charge this raises is dated on the RETURN date, and a
     * return date is operator-supplied with no upper bound — a storeman
     * recording on 2026-07-08 the machine that actually left on 2026-06-15
     * types the day it left. If that month has been closed the charge would
     * land in books somebody has signed off, so the whole demobilisation is
     * refused up front (ProjectCostService::record() refuses it again inside
     * the transaction; this one exists so the operator is told BEFORE the
     * asset is marked available rather than watching a rollback).
     */
    public function returnDeployment(Deployment $deployment, ?string $returnedAt = null, ?string $notes = null): Deployment
    {
        if ($deployment->status !== DeploymentStatus::Active) {
            throw new LogicException("Deployment {$deployment->code} is already {$deployment->status->value}.");
        }

        $returnDate = Carbon::parse($returnedAt ?? now()->toDateString());

        if ($returnDate->lt($deployment->deployed_from)) {
            throw new LogicException(
                "Return date {$returnDate->toDateString()} is before deployment start {$deployment->deployed_from->toDateString()}."
            );
        }

        $this->assertChargeablePeriodOpen($deployment, $returnDate);

        return DB::transaction(function () use ($deployment, $returnDate, $notes): Deployment {
            // Locked re-read: the status check above ran on an instance that
            // may be stale by now — a second demobilisation racing this one,
            // or an accrual run adding rows the residual must subtract. The
            // row lock serialises both against accrueMonth's own re-read.
            /** @var Deployment $deployment */
            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();

            if ($deployment->status !== DeploymentStatus::Active) {
                throw new LogicException("Deployment {$deployment->code} is already {$deployment->status->value}.");
            }

            $deployment->forceFill([
                'returned_at' => $returnDate->toDateString(),
                'status' => DeploymentStatus::Returned,
                'notes' => $notes !== null ? trim(($deployment->notes ?? '')."\n".$notes) : $deployment->notes,
            ])->save();

            $asset = $deployment->asset;
            $asset->forceFill([
                'status' => AssetStatus::Available,
                'current_project_id' => null,
            ])->save();

            $this->chargeProject($deployment, $returnDate);

            return $deployment->load('asset');
        });
    }

    /**
     * Charge the project the RESIDUAL of the plant it used: the whole span's
     * days x daily_rate_internal, minus every month accrueMonth() already put
     * on the books. With the accrual current, this row is just the return
     * month's tail of days; with no accrual ever run it is the whole span —
     * the pre-accrual shape, which is still what a deployment whose months
     * closed unaccrued must produce (see accrueMonth on why those months are
     * never backfilled). Either way, accrued months + residual == inclusive
     * days x rate, to the rupiah — nothing lost, nothing double counted.
     *
     * It is written to the PROJECT COST ledger and deliberately not to the
     * general ledger. An internal charge is an allocation between the company and
     * its own project, not a transaction with anybody: the money already left
     * when the machine was bought, and that is recognised as depreciation on
     * 6-3100. Posting it again as an expense would count the same asset twice at
     * company level. Moving it — crediting an internal-hire contra account — is
     * the fuller treatment, and it needs a rate policy and an account this chart
     * does not yet have, so it is left to whoever sets that policy.
     *
     * The consequence to be aware of when reading reports: project cost includes
     * this allocation and the trial balance does not, so the two will differ by
     * exactly the internal plant charge. That is correct, and it is why the
     * report labels the source.
     *
     * THE RESIDUAL IS SIGNED. A storeman may record on the 8th the machine
     * that actually left mid-June, after June and July were already accrued;
     * the true span is then SHORTER than what is on the books, and the
     * residual comes out negative — a correction row dated the return day, in
     * whatever month that day lies, which is the forward-only way to say "the
     * accrual overshot". Swallowing it instead would leave the project
     * permanently overcharged by the days the machine was not there. A
     * residual of exactly zero writes nothing: there is no row to justify.
     *
     * (History, kept because the live figures made the case: before
     * accrueMonth() existed this method wrote the ENTIRE span in one row at
     * demobilisation. Three machines on site since March/May 2026 held the
     * equipment bucket of both live projects at Rp 0 against an RAP equipment
     * budget of Rp 178.031.790,79 — Rp 585.000.000 unaccrued in total — so
     * every as-at-a-date reading, EvmService's AC/CPI/EAC and the recomputed
     * POC alike, understated until the day the machines came back. Those live
     * rows stay unaccrued until someone runs `ast:accrue-plant` for each open
     * back month; the period-close checklist now names them month by month.)
     */
    private function chargeProject(Deployment $deployment, Carbon $returnDate): void
    {
        $residual = $this->residualCharge($deployment, $returnDate);

        if (abs($residual) < 0.005 || $deployment->project_id === null) {
            return;
        }

        $rate = round((float) ($deployment->daily_rate_internal ?? 0), 2);
        $days = (int) $deployment->deployed_from->diffInDays($returnDate) + 1;
        $accrued = round($days * $rate - $residual, 2);

        $description = sprintf(
            'Pemakaian %s — %d hari @ %s/hari',
            $deployment->asset?->code ?? 'alat',
            $days,
            number_format($rate, 0, ',', '.'),
        );

        if (abs($accrued) >= 0.005) {
            $description .= sprintf(', sisa setelah akrual bulanan %s', number_format($accrued, 0, ',', '.'));
        }

        app(ProjectCostService::class)->record(
            (int) $deployment->project_id,
            $returnDate->toDateString(),
            CostCategory::Equipment,
            'asset_deployment',
            (int) $deployment->id,
            $description,
            $residual,
        );
    }

    /**
     * The signed residual a demobilisation on $returnDate would write, or 0.0
     * when there is nothing to charge at all (no rate, no project).
     *
     * Days are inclusive of both ends: a machine that arrives and leaves the
     * same day was on site for a day, and charging zero for it would make
     * every short mobilisation free. The subtraction reads the accrual rows'
     * AMOUNTS, not their day counts, so whatever is actually on the books is
     * what gets netted — a re-run that rewrote a row is automatically
     * honoured.
     */
    private function residualCharge(Deployment $deployment, Carbon $returnDate): float
    {
        $rate = round((float) ($deployment->daily_rate_internal ?? 0), 2);

        if ($rate <= 0 || $deployment->project_id === null) {
            return 0.0;
        }

        $days = (int) $deployment->deployed_from->diffInDays($returnDate) + 1;

        $accrued = round((float) ProjectCost::query()
            ->where('reference_type', self::MONTH_REFERENCE)
            ->whereBetween('reference_id', [
                $deployment->id * 1_000_000,
                $deployment->id * 1_000_000 + 999_999,
            ])
            ->where('cost_category', CostCategory::Equipment->value)
            ->sum('amount'), 2);

        return round($days * $rate - $accrued, 2);
    }

    /**
     * Refuse a demobilisation whose plant charge would land in a closed month.
     *
     * Only when there IS a charge: a deployment with no daily rate or no
     * project — or one whose accrued months already cover the span to the
     * rupiah — writes nothing to the cost ledger, has no accounting effect at
     * all, and refusing it would be refusing a storeman's paperwork over a
     * period rule that does not concern it. (The residual is recomputed
     * inside the transaction; this pre-check exists so the operator is told
     * BEFORE the asset is marked available rather than watching a rollback.)
     */
    private function assertChargeablePeriodOpen(Deployment $deployment, Carbon $returnDate): void
    {
        if (abs($this->residualCharge($deployment, $returnDate)) < 0.005) {
            return;
        }

        $period = FiscalPeriod::forDate($returnDate);

        if ($period === null || $period->isOpen()) {
            return;
        }

        throw new LogicException(sprintf(
            'Periode fiskal %04d-%02d sudah ditutup; demobilisasi %s bertanggal %s tidak dapat membebankan '
            .'pemakaian alat ke dalamnya.',
            $period->year,
            $period->month,
            $deployment->code,
            $returnDate->toDateString(),
        ));
    }

    /**
     * Utilization report: days deployed per asset per project within an
     * optional window, with the suggested internal charge per deployment
     * (days x daily_rate_internal) and totals per project.
     */
    public function utilization(?int $projectId = null, ?string $from = null, ?string $to = null): array
    {
        $windowFrom = $from !== null ? Carbon::parse($from)->startOfDay() : null;
        $windowTo = Carbon::parse($to ?? now()->toDateString())->startOfDay();

        $deployments = Deployment::query()
            ->with('asset.category')
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->when($windowFrom !== null, function ($query) use ($windowFrom): void {
                // Deployment must overlap the window: still open, or returned after window start.
                $query->where(function ($where) use ($windowFrom): void {
                    $where->whereNull('returned_at')
                        ->orWhereDate('returned_at', '>=', $windowFrom->toDateString());
                });
            })
            ->whereDate('deployed_from', '<=', $windowTo->toDateString())
            ->orderBy('deployed_from')
            ->get();

        $rows = [];
        $byProject = [];

        foreach ($deployments as $deployment) {
            $start = $deployment->deployed_from->copy()->startOfDay();
            if ($windowFrom !== null && $start->lt($windowFrom)) {
                $start = $windowFrom->copy();
            }

            $end = ($deployment->returned_at ?? $windowTo)->copy()->startOfDay();
            if ($end->gt($windowTo)) {
                $end = $windowTo->copy();
            }

            if ($end->lt($start)) {
                continue; // no overlap with the requested window
            }

            // Equipment is charged per calendar day started, mobilization day included.
            $days = (int) $start->diffInDays($end) + 1;
            $rate = $deployment->daily_rate_internal !== null ? (float) $deployment->daily_rate_internal : null;
            $charge = $rate !== null ? round($days * $rate, 2) : 0.0;

            $rows[] = [
                'deployment_id' => $deployment->id,
                'deployment_code' => $deployment->code,
                'asset_id' => $deployment->asset_id,
                'asset_code' => $deployment->asset?->code,
                'asset_name' => $deployment->asset?->name,
                'category' => $deployment->asset?->category?->name,
                'project_id' => $deployment->project_id,
                'deployed_from' => $deployment->deployed_from->toDateString(),
                'returned_at' => $deployment->returned_at?->toDateString(),
                'status' => $deployment->status->value,
                'days_deployed' => $days,
                'daily_rate_internal' => $rate,
                'internal_charge_suggestion' => $charge,
            ];

            $key = $deployment->project_id;
            $byProject[$key] ??= ['project_id' => $key, 'total_days' => 0, 'total_internal_charge' => 0.0];
            $byProject[$key]['total_days'] += $days;
            $byProject[$key]['total_internal_charge'] = round($byProject[$key]['total_internal_charge'] + $charge, 2);
        }

        return [
            'period' => [
                'from' => $windowFrom?->toDateString(),
                'to' => $windowTo->toDateString(),
            ],
            'rows' => $rows,
            'summary_by_project' => array_values($byProject),
            'totals' => [
                'total_days' => array_sum(array_column($rows, 'days_deployed')),
                'total_internal_charge' => round(array_sum(array_column($rows, 'internal_charge_suggestion')), 2),
            ],
        ];
    }
}
