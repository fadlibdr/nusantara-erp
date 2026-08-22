<?php

namespace Tests\Feature\HrPayroll;

use Tests\ErpTestCase;

/**
 * Overtime (Kepmenaker 102/2004 as implemented here):
 *
 *   overtime pay = hours x ((gaji pokok + tunjangan tetap) / divisor) x 1,5
 *
 * The divisor is the statutory 173 by default and comes from the settings layer
 * (payroll.overtime.divisor), so an installation can change it without a deploy.
 */
class PayrollOvertimeTest extends ErpTestCase
{
    use PayrollFixtures;

    public function test_overtime_pay_uses_the_statutory_divisor_of_173(): void
    {
        // Monthly wage = 10.000.000 + 1.000.000 = 11.000.000
        // Hourly       = 11.000.000 / 173 = 63.583,815028...
        // 10 hours     = 10 x 63.583,815028... x 1,5 = 953.757,2254... -> 953.757,23
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 10);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(10.0, $slip->overtime_hours);
        $this->assertMoney(953_757.23, $slip->overtime_pay);
        // Gross = 11.000.000 + 953.757,23
        $this->assertMoney(11_953_757.23, $slip->gross_income);
    }

    public function test_the_overtime_divisor_setting_changes_the_hourly_rate(): void
    {
        $this->setSetting('payroll.overtime.divisor', 200);

        // Hourly = 11.000.000 / 200 = 55.000
        // 10 hours x 55.000 x 1,5 = 825.000
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 10);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(825_000.0, $slip->overtime_pay);
        $this->assertMoney(11_825_000.0, $slip->gross_income);
    }

    public function test_fixed_allowances_are_part_of_the_overtime_hourly_base(): void
    {
        // Same 11.000.000 monthly wage reached two ways => same overtime pay.
        $allBasic = $this->makeEmployee(['base_salary' => 11_000_000]);
        $split = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 700_000, 'makan' => 300_000],
        ]);
        $run = $this->makeRun();
        $this->makeRecap($allBasic, $run, 10);
        $this->makeRecap($split, $run, 10);

        $this->payrollService()->calculate($run);

        $this->assertMoney(953_757.23, $this->payslipFor($run, $allBasic)->overtime_pay);
        $this->assertMoney(953_757.23, $this->payslipFor($run, $split)->overtime_pay);
    }

    public function test_fractional_overtime_hours_are_paid_and_rounded_to_two_decimals(): void
    {
        // 7,5 x (11.000.000 / 173) x 1,5 = 715.317,9190... -> 715.317,92
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 7.5);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(7.5, $slip->overtime_hours);
        $this->assertMoney(715_317.92, $slip->overtime_pay);
    }

    public function test_an_employee_without_an_attendance_recap_gets_no_overtime(): void
    {
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(0.0, $slip->overtime_hours);
        $this->assertMoney(0.0, $slip->overtime_pay);
        $this->assertMoney(11_000_000.0, $slip->gross_income);
    }

    public function test_a_recap_of_zero_hours_pays_no_overtime(): void
    {
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 0);

        $this->payrollService()->calculate($run);

        $this->assertMoney(0.0, $this->payslipFor($run, $employee)->overtime_pay);
    }

    public function test_a_recap_of_another_period_is_not_paid_in_this_run(): void
    {
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $may = $this->makeRun(['period_month' => 5]);
        $june = $this->makeRun(['period_month' => 6]);
        $this->makeRecap($employee, $may, 10);

        $this->payrollService()->calculate($june);

        $this->assertMoney(0.0, $this->payslipFor($june, $employee)->overtime_pay);
    }

    public function test_overtime_is_taxed_but_is_not_part_of_the_bpjs_wage_base(): void
    {
        // BPJS runs on gaji pokok + tunjangan tetap = 11.000.000 (overtime excluded):
        //   kes  min(11.000.000, 12.000.000) x 1% = 110.000
        //   jht  11.000.000 x 2%                  = 220.000
        //   jp   min(11.000.000, 10.547.400) x 1% = 105.474
        //   employee total                        = 435.474
        // PPh 21 runs on the cash gross including overtime:
        //   11.953.757,23 sits in 11.600.000 < x <= 12.500.000 => 4%
        //   11.953.757,23 x 4% = 478.150,2892 -> 478.150,29
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 10);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(110_000.0, $slip->bpjs['kes_employee']);
        $this->assertMoney(220_000.0, $slip->bpjs['jht_employee']);
        $this->assertMoney(105_474.0, $slip->bpjs['jp_employee']);
        $this->assertMoney(435_474.0, $slip->bpjs_employee_total);
        // 440.000 + 407.000 + 210.948 + 97.900 + 33.000 = 1.188.848
        $this->assertMoney(1_188_848.0, $slip->bpjs_company_total);

        $this->assertMoney(4.0, $slip->ter_rate);
        $this->assertMoney(478_150.29, $slip->pph21_amount);
        // 435.474 + 478.150,29 = 913.624,29 ; 11.953.757,23 - 913.624,29 = 11.040.132,94
        $this->assertMoney(913_624.29, $slip->total_deductions);
        $this->assertMoney(11_040_132.94, $slip->net_pay);
    }

    public function test_overtime_can_push_the_employee_into_a_higher_ter_bracket(): void
    {
        // Without overtime 11.000.000 sits in 10.700.000 < x <= 11.050.000 => 3%.
        // One hour of overtime = 95.375,72 lifts the gross to 11.095.375,72, which is
        // in 11.050.000 < x <= 11.600.000 => 3,5%.
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $withoutOt = $this->makeRun(['period_month' => 5]);
        $withOt = $this->makeRun(['period_month' => 6]);
        $this->makeRecap($employee, $withOt, 1);

        $this->payrollService()->calculate($withoutOt);
        $this->payrollService()->calculate($withOt);

        $this->assertMoney(3.0, $this->payslipFor($withoutOt, $employee)->ter_rate);
        $this->assertMoney(3.5, $this->payslipFor($withOt, $employee)->ter_rate);
        $this->assertMoney(95_375.72, $this->payslipFor($withOt, $employee)->overtime_pay);
    }
}
