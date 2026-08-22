<?php

namespace Tests\Feature\HrPayroll;

use Tests\ErpTestCase;

/**
 * BPJS Kesehatan + Ketenagakerjaan on a regular payroll run.
 *
 * Wage base = gaji pokok + tunjangan tetap. Shipped rates (config/erp.php):
 *   Kesehatan 4% employer / 1% employee, wage capped at 12.000.000
 *   JHT       3,7% employer / 2% employee, NO wage cap
 *   JP        2% employer / 1% employee, wage capped at 10.547.400
 *   JKK       risk class 3 => 0,89% employer only
 *   JKM       0,30% employer only
 */
class PayrollBpjsTest extends ErpTestCase
{
    use PayrollFixtures;

    public function test_an_employee_below_every_wage_cap_pays_the_plain_percentages(): void
    {
        // Wage base = 8.000.000 + 500.000 + 500.000 = 9.000.000, under both caps.
        $employee = $this->makeEmployee([
            'base_salary' => 8_000_000,
            'fixed_allowances' => ['transport' => 500_000, 'makan' => 500_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(9_000_000.0, $slip->allowances_total + $slip->basic_salary);
        $this->assertMoney(9_000_000.0, $slip->gross_income);

        // 9.000.000 x 4% = 360.000 / x 1% = 90.000
        $this->assertMoney(360_000.0, $slip->bpjs['kes_company']);
        $this->assertMoney(90_000.0, $slip->bpjs['kes_employee']);
        // 9.000.000 x 3,7% = 333.000 / x 2% = 180.000
        $this->assertMoney(333_000.0, $slip->bpjs['jht_company']);
        $this->assertMoney(180_000.0, $slip->bpjs['jht_employee']);
        // 9.000.000 x 2% = 180.000 / x 1% = 90.000
        $this->assertMoney(180_000.0, $slip->bpjs['jp_company']);
        $this->assertMoney(90_000.0, $slip->bpjs['jp_employee']);
        // 9.000.000 x 0,89% = 80.100 ; x 0,30% = 27.000
        $this->assertMoney(80_100.0, $slip->bpjs['jkk_company']);
        $this->assertMoney(27_000.0, $slip->bpjs['jkm_company']);

        // Employee side = kes + jht + jp employee shares = 90.000 + 180.000 + 90.000
        $this->assertMoney(360_000.0, $slip->bpjs_employee_total);
        // Company side = 360.000 + 333.000 + 180.000 + 80.100 + 27.000
        $this->assertMoney(980_100.0, $slip->bpjs_company_total);
    }

    public function test_an_employee_above_every_wage_cap_has_kesehatan_and_jp_capped_but_not_jht(): void
    {
        // Wage base = 20.000.000 + 5.000.000 = 25.000.000, above both caps.
        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // Kesehatan is computed on the 12.000.000 cap, not on 25.000.000:
        // 12.000.000 x 4% = 480.000 / x 1% = 120.000
        $this->assertMoney(480_000.0, $slip->bpjs['kes_company']);
        $this->assertMoney(120_000.0, $slip->bpjs['kes_employee']);

        // JP is computed on the 10.547.400 cap:
        // 10.547.400 x 2% = 210.948 / x 1% = 105.474
        $this->assertMoney(210_948.0, $slip->bpjs['jp_company']);
        $this->assertMoney(105_474.0, $slip->bpjs['jp_employee']);

        // JHT has NO cap — it runs on the full 25.000.000:
        // 25.000.000 x 3,7% = 925.000 / x 2% = 500.000
        $this->assertMoney(925_000.0, $slip->bpjs['jht_company']);
        $this->assertMoney(500_000.0, $slip->bpjs['jht_employee']);

        // JKK/JKM are uncapped employer-only levies:
        // 25.000.000 x 0,89% = 222.500 ; x 0,30% = 75.000
        $this->assertMoney(222_500.0, $slip->bpjs['jkk_company']);
        $this->assertMoney(75_000.0, $slip->bpjs['jkm_company']);

        // 120.000 + 500.000 + 105.474 = 725.474
        $this->assertMoney(725_474.0, $slip->bpjs_employee_total);
        // 480.000 + 925.000 + 210.948 + 222.500 + 75.000 = 1.913.448
        $this->assertMoney(1_913_448.0, $slip->bpjs_company_total);
    }

    public function test_the_employee_total_excludes_every_employer_only_component(): void
    {
        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertMoney(
            $slip->bpjs['kes_employee'] + $slip->bpjs['jht_employee'] + $slip->bpjs['jp_employee'],
            $slip->bpjs_employee_total,
            'JKK and JKM are borne by the employer and must never be deducted.',
        );
        $this->assertMoney(
            $slip->bpjs['kes_company'] + $slip->bpjs['jht_company'] + $slip->bpjs['jp_company']
                + $slip->bpjs['jkk_company'] + $slip->bpjs['jkm_company'],
            $slip->bpjs_company_total,
        );
    }

    public function test_the_wage_base_is_basic_plus_fixed_allowances(): void
    {
        // Same 9.000.000 wage base reached two different ways must produce the same BPJS.
        $allBasic = $this->makeEmployee(['base_salary' => 9_000_000]);
        $split = $this->makeEmployee([
            'base_salary' => 6_500_000,
            'fixed_allowances' => ['transport' => 1_500_000, 'makan' => 1_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);

        $this->assertMoney(360_000.0, $this->payslipFor($run, $allBasic)->bpjs_employee_total);
        $this->assertMoney(360_000.0, $this->payslipFor($run, $split)->bpjs_employee_total);
        $this->assertMoney(980_100.0, $this->payslipFor($run, $allBasic)->bpjs_company_total);
        $this->assertMoney(980_100.0, $this->payslipFor($run, $split)->bpjs_company_total);
    }

    public function test_a_jp_salary_cap_override_changes_the_pension_contribution(): void
    {
        // Raise the JP cap from 10.547.400 to 20.000.000 on a 25.000.000 wage base.
        $this->setSetting('payroll.bpjs.jp.salary_cap', 20_000_000);

        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // 20.000.000 x 2% = 400.000 / x 1% = 200.000 (was 210.948 / 105.474)
        $this->assertMoney(400_000.0, $slip->bpjs['jp_company']);
        $this->assertMoney(200_000.0, $slip->bpjs['jp_employee']);
        // 120.000 + 500.000 + 200.000 = 820.000
        $this->assertMoney(820_000.0, $slip->bpjs_employee_total);
        // 480.000 + 925.000 + 400.000 + 222.500 + 75.000 = 2.102.500
        $this->assertMoney(2_102_500.0, $slip->bpjs_company_total);
    }

    public function test_a_jkk_risk_class_override_changes_the_accident_premium(): void
    {
        // Risk class 5 = 1,74% instead of class 3's 0,89%.
        $this->setSetting('payroll.bpjs.jkk.default_risk_class', 5);

        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // 25.000.000 x 1,74% = 435.000
        $this->assertMoney(435_000.0, $slip->bpjs['jkk_company']);
        // 480.000 + 925.000 + 210.948 + 435.000 + 75.000 = 2.125.948
        $this->assertMoney(2_125_948.0, $slip->bpjs_company_total);
        // JKK is employer-only: the employee side is untouched.
        $this->assertMoney(725_474.0, $slip->bpjs_employee_total);
    }

    public function test_a_kesehatan_salary_cap_override_changes_the_health_contribution(): void
    {
        // Lift the Kesehatan cap above the wage so the full 25.000.000 is used.
        $this->setSetting('payroll.bpjs.kesehatan.salary_cap', 30_000_000);

        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // 25.000.000 x 4% = 1.000.000 / x 1% = 250.000
        $this->assertMoney(1_000_000.0, $slip->bpjs['kes_company']);
        $this->assertMoney(250_000.0, $slip->bpjs['kes_employee']);
        // 250.000 + 500.000 + 105.474 = 855.474
        $this->assertMoney(855_474.0, $slip->bpjs_employee_total);
    }

    public function test_a_jht_rate_override_changes_the_uncapped_contribution(): void
    {
        $this->setSetting('payroll.bpjs.jht.employee', 3.0);

        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'fixed_allowances' => ['tunjangan_jabatan' => 5_000_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // 25.000.000 x 3% = 750.000 (default 2% would be 500.000)
        $this->assertMoney(750_000.0, $slip->bpjs['jht_employee']);
        // 120.000 + 750.000 + 105.474 = 975.474
        $this->assertMoney(975_474.0, $slip->bpjs_employee_total);
    }

    public function test_deductions_and_net_pay_combine_bpjs_with_pph21(): void
    {
        // Wage base 9.000.000, TK/0 => TER category A, 8.550.000 < 9.000.000 <= 9.650.000
        // => 1,75% => PPh 21 = 9.000.000 x 1,75% = 157.500
        // Deductions = 360.000 (BPJS employee) + 157.500 = 517.500
        // Net        = 9.000.000 - 517.500 = 8.482.500
        $employee = $this->makeEmployee([
            'base_salary' => 8_000_000,
            'fixed_allowances' => ['transport' => 500_000, 'makan' => 500_000],
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertSame('A', $slip->ter_category);
        $this->assertMoney(1.75, $slip->ter_rate);
        $this->assertMoney(157_500.0, $slip->pph21_amount);
        $this->assertMoney(517_500.0, $slip->total_deductions);
        $this->assertMoney(8_482_500.0, $slip->net_pay);
    }

    public function test_an_employee_without_a_tax_id_is_deducted_the_120_percent_surcharge(): void
    {
        // hasTaxId() is false only when both NPWP and NIK are blank.
        $employee = $this->makeEmployee([
            'base_salary' => 8_000_000,
            'fixed_allowances' => ['transport' => 500_000, 'makan' => 500_000],
            'nik_ktp' => '',
            'npwp' => null,
        ]);
        $run = $this->makeRun();

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // 9.000.000 x 1,75% = 157.500 ; 157.500 x 1,2 = 189.000
        $this->assertMoney(1.75, $slip->ter_rate, 'The surcharge must not move the bracket.');
        $this->assertMoney(189_000.0, $slip->pph21_amount);
        // 360.000 + 189.000 = 549.000 ; 9.000.000 - 549.000 = 8.451.000
        $this->assertMoney(549_000.0, $slip->total_deductions);
        $this->assertMoney(8_451_000.0, $slip->net_pay);
    }
}
