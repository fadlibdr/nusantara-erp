<?php

namespace Tests\Feature\HrPayroll;

use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\EmploymentType;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Enums\PtkpStatus;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Models\Payslip;
use Modules\HrPayroll\Services\PayrollService;

/**
 * Hand-built payroll fixtures. Deliberately not the module seeder: every number a
 * payroll assertion depends on is written out in the test that asserts it.
 */
trait PayrollFixtures
{
    private int $employeeSequence = 0;

    private int $runSequence = 0;

    protected function payrollService(): PayrollService
    {
        return app(PayrollService::class);
    }

    protected function makeEmployee(array $attributes = []): Employee
    {
        $n = ++$this->employeeSequence;

        /** @var Employee $employee */
        $employee = Employee::query()->create(array_merge([
            'code' => 'EMP-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Karyawan '.$n,
            // NIK doubles as the tax id (PMK 112/2022), so a filled NIK means no surcharge.
            'nik_ktp' => str_pad((string) $n, 16, '3', STR_PAD_LEFT),
            'npwp' => null,
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => PtkpStatus::TK0,
            'join_date' => '2020-01-01',
            'employment_type' => EmploymentType::Tetap,
            'position' => 'Pelaksana',
            'department' => 'proyek',
            'base_salary' => 0,
            'fixed_allowances' => null,
            'status' => 'active',
        ], $attributes));

        return $employee;
    }

    protected function makeRun(array $attributes = []): PayrollRun
    {
        $n = ++$this->runSequence;

        /** @var PayrollRun $run */
        $run = PayrollRun::query()->create(array_merge([
            'code' => 'PYR/TEST/'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'period_year' => 2026,
            'period_month' => 6,
            'run_type' => PayrollRunType::Regular,
            'payment_date' => '2026-06-25',
            'status' => DocumentStatus::Draft,
        ], $attributes));

        return $run;
    }

    protected function makeRecap(Employee $employee, PayrollRun $run, float $overtimeHours): AttendanceRecap
    {
        /** @var AttendanceRecap $recap */
        $recap = AttendanceRecap::query()->create([
            'employee_id' => $employee->id,
            'period_year' => $run->period_year,
            'period_month' => $run->period_month,
            'work_days' => 22,
            'present_days' => 22,
            'overtime_hours' => $overtimeHours,
        ]);

        return $recap;
    }

    protected function payslipFor(PayrollRun $run, Employee $employee): Payslip
    {
        /** @var Payslip $payslip */
        $payslip = $run->payslips()->where('employee_id', $employee->id)->firstOrFail();

        return $payslip;
    }

    /**
     * decimal:2 casts hand back strings; compare on the rupiah value.
     */
    protected function assertMoney(float $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame($expected, round((float) $actual, 2), $message);
    }
}
