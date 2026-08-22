<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\ProjectCost;

/**
 * Single write-path into the project cost ledger. Finance feeds it from AP
 * bill approvals; HrPayroll (labor), Inventory (material issues), kas kecil
 * and Assets (internal plant charge) call record() with their own reference
 * types.
 *
 * A CLOSED MONTH IS CLOSED HERE TOO. Every caller but one posts a journal in
 * the same transaction with the same date, so JournalService::assertPeriodOpen
 * was guarding this ledger by accident — ApBillService (bill_date), StockService
 * (issue_date), PayrollPostingService (postingDate), KasbonService
 * (settlementDate), PettyCashVoucherService (voucher_date). DeploymentService's
 * internal plant charge posts NO journal at all, deliberately, so it inherited
 * no guard: demobilising DEP/2026/III/0001 on 2026-07-08 with returned_at
 * 2026-06-15 wrote Rp 265.000.000 of equipment cost into a June that had been
 * closed and whose PSAK 115 run had already been posted and reported. June's
 * trial balance was untouched, but project profitability for June gained cost
 * the June ledger never carried and the run's cost-to-date stopped
 * reproducing — permanently, because a measured period can never be reopened.
 * The guard belongs here rather than in that one caller so the next
 * journal-less writer inherits it.
 */
class ProjectCostService
{
    /**
     * Idempotent per source document: recording the same reference twice for
     * the same category updates the row instead of duplicating the cost.
     *
     * $wbsTaskId is a TRAILING optional (prj_wbs_tasks.id) so the existing
     * HrPayroll/Inventory/ApBill callers compile and behave unchanged; petty
     * cash passes it so a bon keyed against one WBS task stays readable per
     * task, not only per category. It is payload, not key — the idempotency
     * key stays (reference, category), and callers that need two WBS rows in
     * one category use per-line references (see KasbonService::settle()).
     */
    public function record(
        int $projectId,
        string $costDate,
        CostCategory $category,
        string $referenceType,
        int $referenceId,
        string $description,
        float $amount,
        ?int $wbsTaskId = null,
    ): ProjectCost {
        $this->assertPeriodOpen($costDate);

        return ProjectCost::query()->updateOrCreate(
            [
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'cost_category' => $category->value,
            ],
            [
                'project_id' => $projectId,
                'cost_date' => $costDate,
                'description' => $description,
                'amount' => round($amount, 2),
                'wbs_task_id' => $wbsTaskId,
            ],
        );
    }

    /**
     * Refuse a cost row dated inside a CLOSED fiscal period.
     *
     * A MISSING period is not a refusal, and that asymmetry is deliberate:
     * ProjectCostLedgerTest and the Inventory backfill migration write cost
     * rows on installations that have no fiscal calendar at all, and there is
     * no rule to consult there — the same stance StockService::stockEventDate
     * takes. What is refused is the case the calendar has an opinion about:
     * a month somebody has signed off.
     *
     * The message says biaya proyek, not jurnal, because no journal is
     * involved on the path this exists for.
     */
    private function assertPeriodOpen(string $costDate): void
    {
        if (! Schema::hasTable('fin_fiscal_periods')) {
            return;
        }

        $period = FiscalPeriod::forDate(Carbon::parse($costDate));

        if ($period === null || $period->isOpen()) {
            return;
        }

        throw new LogicException(sprintf(
            'Periode fiskal %04d-%02d sudah ditutup; biaya proyek bertanggal %s tidak dapat dicatat ke dalamnya.',
            $period->year,
            $period->month,
            $costDate,
        ));
    }

    /**
     * Drop everything one source document charged to the project ledger.
     *
     * The counterpart of record(): when the document that caused the cost is
     * cancelled and its journal reversed, a surviving cost row would keep the
     * project P&L above the general ledger by exactly that amount — the very
     * disagreement the ledger-mirroring rule in ApBillService exists to avoid.
     *
     * @return int rows removed
     */
    public function remove(string $referenceType, int $referenceId): int
    {
        return ProjectCost::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->delete();
    }

    /**
     * Realisasi totals per category for a project.
     *
     * @return array<string, float>
     */
    public function totalsByCategory(int $projectId): array
    {
        $totals = ProjectCost::query()
            ->where('project_id', $projectId)
            ->selectRaw('cost_category, SUM(amount) as total')
            ->groupBy('cost_category')
            ->pluck('total', 'cost_category');

        $result = [];

        foreach (CostCategory::cases() as $category) {
            $result[$category->value] = round((float) ($totals[$category->value] ?? 0), 2);
        }

        return $result;
    }
}
