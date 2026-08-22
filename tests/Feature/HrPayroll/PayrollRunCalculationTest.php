<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Models\Payslip;
use Tests\ErpTestCase;

/**
 * PayrollService::calculate() as a whole: which employees it covers, that it is
 * idempotent, that the run totals reconcile with the payslips, and that a run which
 * is no longer editable refuses to change.
 */
class PayrollRunCalculationTest extends ErpTestCase
{
    use PayrollFixtures;

    /**
     * Three employees whose payslips are hand-computed in the tests below:
     *
     *   E1 8.000.000 + 1.000.000 allowances = 9.000.000 gross
     *      BPJS employee 360.000 ; TER A 1,75% = 157.500 ; net 8.482.500
     *   E2 20.000.000 + 5.000.000 allowances = 25.000.000 gross
     *      BPJS employee 725.474 ; TER A 10% = 2.500.000 ; net 21.774.526
     *   E3 5.000.000 gross (under the TER floor)
     *      BPJS employee 200.000 ; TER A 0% = 0 ; net 4.800.000
     */
    private function seedThreeEmployees(): void
    {
        $this->makeEmployee([
            'base_salary' => 8_000_000,
            'fixed_allowances' => ['transport' => 500_000, 'makan' => 500_000],
        ]);
        $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
        ]);
        $this->makeEmployee(['base_salary' => 5_000_000]);
    }

    public function test_calculating_twice_does_not_duplicate_payslips(): void
    {
        $this->seedThreeEmployees();
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $firstPass = $run->payslips()->get()->map->only(['employee_id', 'gross_income', 'net_pay'])->all();

        $this->payrollService()->calculate($run);
        $secondPass = $run->payslips()->get()->map->only(['employee_id', 'gross_income', 'net_pay'])->all();

        $this->assertSame(3, $run->payslips()->count());
        $this->assertSame(3, Payslip::query()->count(), 'Stale payslips must be removed, not orphaned.');
        $this->assertEquals($firstPass, $secondPass);
    }

    public function test_run_totals_equal_the_sum_of_the_payslips(): void
    {
        $this->seedThreeEmployees();
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $run->refresh();

        // 9.000.000 + 25.000.000 + 5.000.000 = 39.000.000
        $this->assertMoney(39_000_000.0, $run->total_gross);
        // 517.500 + 3.225.474 + 200.000 = 3.942.974
        $this->assertMoney(3_942_974.0, $run->total_deductions);
        // 8.482.500 + 21.774.526 + 4.800.000 = 35.057.026
        $this->assertMoney(35_057_026.0, $run->total_net);

        // ... and the totals stay internally consistent.
        $this->assertMoney(
            round((float) $run->total_gross - (float) $run->total_deductions, 2),
            $run->total_net,
        );
        $this->assertMoney(round((float) $run->payslips()->sum('gross_income'), 2), $run->total_gross);
        $this->assertMoney(round((float) $run->payslips()->sum('total_deductions'), 2), $run->total_deductions);
        $this->assertMoney(round((float) $run->payslips()->sum('net_pay'), 2), $run->total_net);
    }

    public function test_the_totals_are_unchanged_by_a_second_calculation(): void
    {
        $this->seedThreeEmployees();
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $run->refresh();
        $first = [$run->total_gross, $run->total_deductions, $run->total_net];

        $this->payrollService()->calculate($run);
        $run->refresh();

        $this->assertSame($first, [$run->total_gross, $run->total_deductions, $run->total_net]);
    }

    public function test_recalculating_picks_up_changed_master_data(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 8_000_000, 'fixed_allowances' => ['transport' => 1_000_000]]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $this->assertMoney(9_000_000.0, $this->payslipFor($run, $employee)->gross_income);

        $employee->update(['base_salary' => 10_000_000]);
        $this->payrollService()->calculate($run);
        $run->refresh();

        // 10.000.000 + 1.000.000 = 11.000.000, still exactly one payslip.
        $this->assertSame(1, $run->payslips()->count());
        $this->assertMoney(11_000_000.0, $this->payslipFor($run, $employee)->gross_income);
        $this->assertMoney(11_000_000.0, $run->total_gross);
    }

    public function test_resigned_employees_are_not_paid(): void
    {
        $active = $this->makeEmployee(['base_salary' => 9_000_000]);
        $resigned = $this->makeEmployee([
            'base_salary' => 9_000_000,
            'status' => 'resigned',
            'resign_date' => '2026-05-31',
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);

        $this->assertSame(1, $run->payslips()->count());
        $this->assertSame(1, $run->payslips()->where('employee_id', $active->id)->count());
        $this->assertSame(0, $run->payslips()->where('employee_id', $resigned->id)->count());
    }

    public function test_an_employee_who_joins_after_the_period_ends_is_not_paid(): void
    {
        // Period is June 2026, so the cut-off is 2026-06-30.
        $onTheLastDay = $this->makeEmployee(['base_salary' => 9_000_000, 'join_date' => '2026-06-30']);
        $nextMonth = $this->makeEmployee(['base_salary' => 9_000_000, 'join_date' => '2026-07-01']);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);

        $this->assertSame(1, $run->payslips()->count());
        $this->assertSame(1, $run->payslips()->where('employee_id', $onTheLastDay->id)->count());
        $this->assertSame(0, $run->payslips()->where('employee_id', $nextMonth->id)->count());
    }

    public function test_an_approved_run_refuses_to_be_recalculated(): void
    {
        $this->seedThreeEmployees();
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);
        $run->refresh();

        $before = [
            'count' => $run->payslips()->count(),
            'gross' => $run->total_gross,
            'net' => $run->total_net,
        ];

        // Two people: hrManager() mints a fresh user each call, and payroll —
        // the largest single disbursement a contractor makes each month — is
        // exactly the document maker-checker refuses to let one person wave
        // through.
        $preparer = $this->hrManager();
        $approver = $this->hrManager();
        $run->submit($preparer);
        $run->approve($approver);

        try {
            $this->payrollService()->calculate($run);
            $this->fail('calculate() must refuse a run that is no longer editable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot be modified while status is approved', $e->getMessage());
        }

        $run->refresh();
        $this->assertSame(DocumentStatus::Approved, $run->status);
        $this->assertSame($before['count'], $run->payslips()->count());
        $this->assertSame($before['gross'], $run->total_gross);
        $this->assertSame($before['net'], $run->total_net);
    }

    public function test_an_approved_run_refuses_to_be_updated(): void
    {
        $run = $this->makeRun(['notes' => 'Gaji Juni 2026']);
        // Two people: hrManager() mints a fresh user each call, and payroll —
        // the largest single disbursement a contractor makes each month — is
        // exactly the document maker-checker refuses to let one person wave
        // through.
        $preparer = $this->hrManager();
        $approver = $this->hrManager();
        $run->submit($preparer);
        $run->approve($approver);

        $this->expectException(LogicException::class);

        try {
            $this->payrollService()->update($run, ['notes' => 'diubah', 'period_month' => 5]);
        } finally {
            $run->refresh();
            $this->assertSame('Gaji Juni 2026', $run->notes);
            $this->assertSame(6, $run->period_month);
        }
    }

    public function test_an_approved_run_refuses_to_be_deleted(): void
    {
        $run = $this->makeRun();
        // Two people: hrManager() mints a fresh user each call, and payroll —
        // the largest single disbursement a contractor makes each month — is
        // exactly the document maker-checker refuses to let one person wave
        // through.
        $preparer = $this->hrManager();
        $approver = $this->hrManager();
        $run->submit($preparer);
        $run->approve($approver);

        try {
            $this->payrollService()->delete($run);
            $this->fail('delete() must refuse a run that is no longer editable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot be modified', $e->getMessage());
        }

        $this->assertDatabaseHas('hr_payroll_runs', ['id' => $run->id, 'deleted_at' => null]);
    }

    public function test_a_submitted_run_refuses_to_be_recalculated(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000]);
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);
        $run->submit($this->hrManager());

        $this->expectException(LogicException::class);

        $this->payrollService()->calculate($run);
    }

    public function test_a_rejected_run_can_be_recalculated_again(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9_000_000]);
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);

        $approver = $this->hrManager();
        $run->submit($approver);
        $run->reject($approver, 'Rekap lembur belum lengkap.');

        $employee->update(['base_salary' => 10_000_000]);
        $this->payrollService()->calculate($run);
        $run->refresh();

        // Rejected is an editable state again (DocumentStatus::isEditable()).
        $this->assertSame(DocumentStatus::Rejected, $run->status);
        $this->assertSame(1, $run->payslips()->count());
        $this->assertMoney(10_000_000.0, $run->total_gross);
    }

    public function test_changing_the_period_drops_the_stale_payslips_and_zeroes_the_totals(): void
    {
        $this->seedThreeEmployees();
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);

        $this->payrollService()->update($run, ['period_month' => 5]);
        $run->refresh();

        $this->assertSame(0, $run->payslips()->count());
        $this->assertMoney(0.0, $run->total_gross);
        $this->assertMoney(0.0, $run->total_deductions);
        $this->assertMoney(0.0, $run->total_net);
    }

    public function test_editing_only_the_notes_keeps_the_calculated_payslips(): void
    {
        $this->seedThreeEmployees();
        $run = $this->makeRun();
        $this->payrollService()->calculate($run);

        $this->payrollService()->update($run, ['notes' => 'Pembayaran 25 Juni.']);
        $run->refresh();

        $this->assertSame(3, $run->payslips()->count());
        $this->assertMoney(39_000_000.0, $run->total_gross);
    }

    public function test_a_run_without_eligible_employees_produces_zero_totals(): void
    {
        $this->makeEmployee(['base_salary' => 9_000_000, 'join_date' => '2026-07-01']);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $run->refresh();

        $this->assertSame(0, $run->payslips()->count());
        $this->assertMoney(0.0, $run->total_gross);
        $this->assertMoney(0.0, $run->total_deductions);
        $this->assertMoney(0.0, $run->total_net);
    }

    private function hrManager(): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Manajer HR',
            'email' => 'manajer.hr'.uniqid().'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);

        return $user;
    }
}
