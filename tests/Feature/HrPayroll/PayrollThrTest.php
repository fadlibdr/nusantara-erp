<?php

namespace Tests\Feature\HrPayroll;

use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\PayrollRun;
use Tests\ErpTestCase;

/**
 * THR keagamaan (Permenaker 6/2016 Pasal 3):
 *   masa kerja >= 12 bulan  => 1 x upah
 *   masa kerja  1-11 bulan  => upah x masa kerja / 12
 *   masa kerja  <  1 bulan  => not entitled
 *
 * Company policy in this implementation uses gaji pokok as the THR base. No BPJS is
 * levied on a THR run; PPh 21 = TER(gaji + THR) - TER(gaji), floored at zero.
 *
 * Every run below is for period 2026-06, so the tenure reference date is 2026-06-30.
 */
class PayrollThrTest extends ErpTestCase
{
    use PayrollFixtures;

    private function thrRun(): PayrollRun
    {
        return $this->makeRun(['run_type' => PayrollRunType::Thr]);
    }

    public function test_twelve_months_of_tenure_earns_one_full_month_of_base_salary(): void
    {
        $employee = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
            'join_date' => '2020-01-01', // far beyond 12 months on 2026-06-30
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // THR = 1 x gaji pokok = 12.000.000 (tunjangan tetap excluded by policy)
        $this->assertMoney(12_000_000.0, $slip->thr_amount);
        $this->assertMoney(12_000_000.0, $slip->gross_income);
        $this->assertMoney(0.0, $slip->basic_salary);
        $this->assertMoney(0.0, $slip->allowances_total);
        $this->assertNull($slip->allowances);
    }

    public function test_tenure_of_exactly_twelve_months_already_earns_the_full_thr(): void
    {
        // Joined 2025-06-30, measured to 2026-06-30 => 12 whole months.
        $employee = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'join_date' => '2025-06-30',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);

        $this->assertMoney(12_000_000.0, $this->payslipFor($run, $employee)->thr_amount);
    }

    public function test_eleven_months_of_tenure_is_paid_pro_rata(): void
    {
        // Joined 2025-07-15 => 11 whole months on 2026-06-30.
        // 12.000.000 x 11 / 12 = 11.000.000
        $employee = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'join_date' => '2025-07-15',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);

        $this->assertMoney(11_000_000.0, $this->payslipFor($run, $employee)->thr_amount);
    }

    public function test_six_months_of_tenure_is_paid_half(): void
    {
        // Joined 2025-12-15 => 6 whole months on 2026-06-30.
        // 12.000.000 x 6 / 12 = 6.000.000
        $employee = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'join_date' => '2025-12-15',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);

        $this->assertMoney(6_000_000.0, $this->payslipFor($run, $employee)->thr_amount);
    }

    public function test_tenure_of_exactly_one_month_earns_one_twelfth(): void
    {
        // Joined 2026-05-30 => 1 whole month on 2026-06-30.
        // 12.000.000 x 1 / 12 = 1.000.000
        $employee = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'join_date' => '2026-05-30',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);

        $this->assertMoney(1_000_000.0, $this->payslipFor($run, $employee)->thr_amount);
    }

    public function test_less_than_one_month_of_tenure_produces_no_payslip_at_all(): void
    {
        // Joined 2026-06-10 => 0 whole months on 2026-06-30: not yet entitled.
        $newcomer = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'join_date' => '2026-06-10',
        ]);
        $veteran = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'join_date' => '2020-01-01',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);

        $this->assertSame(1, $run->payslips()->count());
        $this->assertSame(0, $run->payslips()->where('employee_id', $newcomer->id)->count());
        $this->assertSame(1, $run->payslips()->where('employee_id', $veteran->id)->count());
    }

    public function test_pro_rata_thr_is_rounded_to_two_decimals(): void
    {
        // Joined 2025-11-15 => 7 whole months on 2026-06-30.
        // 10.000.000 x 7 / 12 = 5.833.333,3333... -> 5.833.333,33
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'join_date' => '2025-11-15',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);

        $this->assertMoney(5_833_333.33, $this->payslipFor($run, $employee)->thr_amount);
    }

    public function test_thr_pph21_is_the_ter_difference_between_salary_plus_thr_and_salary(): void
    {
        // Monthly salary gross = 12.000.000 + 1.000.000 = 13.000.000
        // Combined with THR    = 13.000.000 + 12.000.000 = 25.000.000
        //   TER A on 25.000.000 (24.150.000 < x <= 26.450.000) = 10% => 2.500.000
        //   TER A on 13.000.000 (12.500.000 < x <= 13.750.000) =  5% =>   650.000
        // PPh 21 on the THR    = 2.500.000 - 650.000 = 1.850.000
        // Net                  = 12.000.000 - 1.850.000 = 10.150.000
        $employee = $this->makeEmployee([
            'base_salary' => 12_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
            'join_date' => '2020-01-01',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // The stored rate is the TER rate of the combined income.
        $this->assertSame('A', $slip->ter_category);
        $this->assertMoney(10.0, $slip->ter_rate);
        $this->assertMoney(1_850_000.0, $slip->pph21_amount);
        $this->assertMoney(1_850_000.0, $slip->total_deductions);
        $this->assertMoney(10_150_000.0, $slip->net_pay);
    }

    public function test_thr_pph21_on_a_pro_rata_payment(): void
    {
        // Joined 2025-11-15 => 7 months; THR = 10.000.000 x 7 / 12 = 5.833.333,33
        // Monthly salary gross = 10.000.000 (no fixed allowances)
        // Combined = 15.833.333,33 (15.100.000 < x <= 16.950.000) => 7%
        //          = 1.108.333,3331 -> 1.108.333,33
        // Salary only = 10.000.000 (9.650.000 < x <= 10.050.000) => 2% => 200.000
        // PPh 21 = 1.108.333,33 - 200.000 = 908.333,33
        // Net    = 5.833.333,33 - 908.333,33 = 4.925.000,00
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'join_date' => '2025-11-15',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(7.0, $slip->ter_rate);
        $this->assertMoney(908_333.33, $slip->pph21_amount);
        $this->assertMoney(4_925_000.0, $slip->net_pay);
    }

    public function test_thr_pph21_is_zero_when_the_combined_income_stays_under_the_ter_floor(): void
    {
        // Salary 2.000.000 + THR 2.000.000 = 4.000.000 <= 5.400.000 => TER 0%,
        // so the difference is 0 and the floor at zero holds.
        $employee = $this->makeEmployee([
            'base_salary' => 2_000_000,
            'join_date' => '2020-01-01',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(0.0, $slip->ter_rate);
        $this->assertMoney(0.0, $slip->pph21_amount);
        $this->assertMoney(0.0, $slip->total_deductions);
        $this->assertMoney(2_000_000.0, $slip->net_pay);
    }

    public function test_thr_pph21_is_never_negative(): void
    {
        // TER is monotonic, so TER(gaji + THR) >= TER(gaji) for every employee; the
        // guard must hold across the whole salary range.
        $employees = [];
        foreach ([2_000_000, 5_400_000, 9_000_000, 25_000_000, 120_000_000] as $salary) {
            $employees[] = $this->makeEmployee([
                'base_salary' => $salary,
                'join_date' => '2020-01-01',
            ]);
        }
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);

        foreach ($employees as $employee) {
            $slip = $this->payslipFor($run, $employee);
            $this->assertGreaterThanOrEqual(0.0, (float) $slip->pph21_amount);
            $this->assertLessThanOrEqual((float) $slip->thr_amount, (float) $slip->pph21_amount);
        }
    }

    public function test_no_bpjs_is_levied_on_a_thr_run(): void
    {
        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
            'join_date' => '2020-01-01',
        ]);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(0.0, $slip->bpjs_employee_total);
        $this->assertMoney(0.0, $slip->bpjs_company_total);

        foreach ($slip->bpjs as $component => $amount) {
            $this->assertSame(0, (int) $amount, "BPJS component [{$component}] must be zero on a THR run.");
        }

        // Overtime is not part of a THR run either.
        $this->assertMoney(0.0, $slip->overtime_hours);
        $this->assertMoney(0.0, $slip->overtime_pay);
    }

    public function test_the_run_totals_add_up_across_a_mixed_thr_population(): void
    {
        // Full THR: 12.000.000 ; pro rata 6/12: 6.000.000 ; not entitled: no payslip.
        $this->makeEmployee(['base_salary' => 12_000_000, 'join_date' => '2020-01-01']);
        $this->makeEmployee(['base_salary' => 12_000_000, 'join_date' => '2025-12-15']);
        $this->makeEmployee(['base_salary' => 12_000_000, 'join_date' => '2026-06-10']);
        $run = $this->thrRun();

        $this->payrollService()->calculate($run);
        $run->refresh();

        $this->assertSame(2, $run->payslips()->count());
        // 12.000.000 + 6.000.000 = 18.000.000
        $this->assertMoney(18_000_000.0, $run->total_gross);
        $this->assertMoney(
            round((float) $run->payslips()->sum('pph21_amount'), 2),
            $run->total_deductions,
        );
        $this->assertMoney(
            round((float) $run->total_gross - (float) $run->total_deductions, 2),
            $run->total_net,
        );
    }
}
