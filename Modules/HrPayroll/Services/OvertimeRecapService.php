<?php

namespace Modules\HrPayroll\Services;

use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\PayrollRun;

/**
 * P0-C: the overtime half of finding #22 — approved ILB hours land on the
 * monthly hr_attendance_recaps.overtime_hours, the number payroll's overtime
 * engine reads and HR used to type by hand.
 *
 * This service is the HR-SIDE DOOR for that write and deliberately knows
 * nothing about Projects: the caller (OvertimePermitService::approve) computes
 * the per-employee totals for one period from every approved permit and hands
 * plain numbers over, so HrPayroll keeps zero imports from Modules\Projects
 * and the dependency stays one-way (Projects → HrPayroll, like Project →
 * Employee).
 *
 * FORWARD-ONLY, copied from LeaveService::syncRecaps: a period whose REGULAR
 * payroll run is already approved or closed is SKIPPED AND REPORTED, never
 * rewritten — the recap is the record of what that posted run was computed
 * from, and editing it afterwards would leave a payroll in the ledger that its
 * own recap contradicts. The permit register keeps the truth either way; only
 * the recap of a posted period stays frozen.
 *
 * The totals arrive RECOMPUTED WHOLESALE per (employee, period), not as
 * increments — an increment goes wrong the first time anything is synced
 * twice or out of order; an absolute total cannot.
 */
class OvertimeRecapService
{
    /**
     * Write one period's overtime totals, or skip the whole period when its
     * payroll is posted.
     *
     * @param  array<int, float>  $hoursByEmployee  employee_id => TOTAL approved
     *                                              overtime hours of that period
     * @return list<string> periods skipped as 'YYYY-MM' — [] or the one period
     */
    public function applyMonthlyOvertime(int $year, int $month, array $hoursByEmployee): array
    {
        if ($hoursByEmployee === []) {
            return [];
        }

        if ($this->periodPayrollPosted($year, $month)) {
            return [sprintf('%04d-%02d', $year, $month)];
        }

        foreach ($hoursByEmployee as $employeeId => $hours) {
            $recap = AttendanceRecap::query()->firstOrNew([
                'employee_id' => $employeeId,
                'period_year' => $year,
                'period_month' => $month,
            ]);

            $recap->overtime_hours = round((float) $hours, 2);
            $recap->save();
        }

        return [];
    }

    /** Same question, same answer as LeaveService::periodPayrollPosted. */
    private function periodPayrollPosted(int $year, int $month): bool
    {
        return PayrollRun::query()
            ->where('run_type', PayrollRunType::Regular->value)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->exists();
    }
}
