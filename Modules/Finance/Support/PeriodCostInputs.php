<?php

namespace Modules\Finance\Support;

use Illuminate\Support\Facades\DB;
use Modules\Assets\Enums\DepreciationRunStatus;
use Modules\Core\Enums\DocumentStatus;

/**
 * The two cost runs a month's percentage of completion depends on, and whether
 * they have actually landed in the ledger yet.
 *
 * Cost-to-cost progress divides cumulative cost by EAC, so a POC run posted
 * before June's payroll (Rp 196.270.346 of wages on the demo dataset) and June's
 * depreciation (Rp 25.125.000) computes its percentage on a cost base that is
 * short by both — and the revenue it recognises is understated by the same
 * proportion. The run cannot be recalculated afterwards, so the understatement
 * is permanent in that month and lands as a catch-up in the next one.
 *
 * A STATELESS STATIC ON PURPOSE. RevenueRecognitionService::post() consults this
 * on every posting; PeriodCloseService also does, and it pulls in three report
 * services. Injecting the close service into the POC engine would construct all
 * three on every post for two count queries, and would create a service cycle
 * the moment the close needed anything back.
 *
 * "Posted" differs per module and is read as each module actually defines it:
 * a payroll run is posted at APPROVAL (PayrollRunController::approve books the
 * journal in the same transaction as the status change), a depreciation run at
 * DepreciationRunStatus::Posted.
 */
class PeriodCostInputs
{
    /**
     * Cost runs that exist for the period but have not reached the ledger.
     *
     * Only runs that EXIST block. A month with no payroll run at all does not:
     * a company whose HR module is not live yet, or one whose assets are all
     * fully depreciated, would otherwise be wedged out of ever recognising
     * revenue — and a control the business cannot satisfy is as bad as none.
     * The absence is reported by the close checklist as a warning instead.
     *
     * @return array<int, array{source: string, label: string, code: string, status: string}>
     */
    public static function pending(int $year, int $month): array
    {
        $pending = [];

        $payrolls = DB::table('hr_payroll_runs')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNull('deleted_at')
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Submitted->value])
            ->orderBy('code')
            ->get(['code', 'status']);

        foreach ($payrolls as $run) {
            $pending[] = [
                'source' => 'payroll',
                'label' => 'Payroll',
                'code' => (string) $run->code,
                'status' => (string) $run->status,
            ];
        }

        $depreciations = DB::table('ast_depreciation_runs')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', DepreciationRunStatus::Draft->value)
            ->orderBy('code')
            ->get(['code', 'status']);

        foreach ($depreciations as $run) {
            $pending[] = [
                'source' => 'depreciation',
                'label' => 'Penyusutan',
                'code' => (string) $run->code,
                'status' => (string) $run->status,
            ];
        }

        return $pending;
    }

    /** Is there a payroll run of any status for this period? */
    public static function hasPayrollRun(int $year, int $month): bool
    {
        return DB::table('hr_payroll_runs')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNull('deleted_at')
            ->whereNot('status', DocumentStatus::Rejected->value)
            ->exists();
    }

    /** Is there a depreciation run of any status for this period? */
    public static function hasDepreciationRun(int $year, int $month): bool
    {
        return DB::table('ast_depreciation_runs')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->exists();
    }
}
