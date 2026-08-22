<?php

namespace Tests\Feature\HrPayroll;

use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\PayrollRun;
use Tests\ErpTestCase;

/**
 * December payroll: PMK 168/2023 Pasal 15 replaces the TER rate with the Pasal 17
 * annual recalculation, minus everything already withheld in Jan-Nov.
 *
 * The prior months are read from this year's APPROVED or CLOSED runs only.
 */
class PayrollDecemberTrueUpTest extends ErpTestCase
{
    use PayrollFixtures;

    /**
     * A finished month: an approved run holding one hand-written payslip.
     */
    private function priorMonth(
        Employee $employee,
        int $month,
        float $gross,
        float $pph21,
        float $jhtEmployee,
        float $jpEmployee,
        PayrollRunType $type = PayrollRunType::Regular,
        DocumentStatus $status = DocumentStatus::Approved,
    ): PayrollRun {
        $run = $this->makeRun([
            'period_month' => $month,
            'run_type' => $type,
            'status' => $status,
        ]);

        $run->payslips()->create([
            'employee_id' => $employee->id,
            'gross_income' => $gross,
            'pph21_amount' => $pph21,
            'bpjs' => [
                'kes_company' => 0, 'kes_employee' => 0,
                'jht_company' => 0, 'jht_employee' => $jhtEmployee,
                'jp_company' => 0, 'jp_employee' => $jpEmployee,
                'jkk_company' => 0, 'jkm_company' => 0,
            ],
            'total_deductions' => $pph21,
            'net_pay' => $gross - $pph21,
        ]);

        return $run;
    }

    public function test_december_settles_the_year_against_the_pasal_17_schedule(): void
    {
        // 12 months x 20.000.000 gross, TK/0, tax id present.
        $employee = $this->makeEmployee(['base_salary' => 20_000_000]);

        // Jan-Nov withheld at TER category A: 19.750.000 < 20.000.000 <= 24.150.000
        // => 9% => 1.800.000/month, 11 months = 19.800.000 already withheld.
        for ($month = 1; $month <= 11; $month++) {
            $this->priorMonth($employee, $month, 20_000_000.0, 1_800_000.0, 400_000.0, 105_474.0);
        }

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);
        $slip = $this->payslipFor($december, $employee);

        // December BPJS employee side on a 20.000.000 wage base:
        // kes min(20jt, 12jt) x 1% = 120.000 ; jht 20jt x 2% = 400.000 ;
        // jp min(20jt, 10.547.400) x 1% = 105.474 => 625.474
        $this->assertMoney(625_474.0, $slip->bpjs_employee_total);

        // Annual gross          = 11 x 20.000.000 + 20.000.000 = 240.000.000
        // Biaya jabatan         = min(5% x 240jt, 6jt)         =   6.000.000
        // Employee JHT + JP     = 12 x (400.000 + 105.474)     =   6.065.688
        // PTKP TK/0                                            =  54.000.000
        // PKP raw = 240.000.000 - 6.000.000 - 6.065.688 - 54.000.000 = 173.934.312
        //        -> rounded down to thousands                        = 173.934.000
        // Pasal 17 = 3.000.000 + (173.934.000 - 60.000.000) x 15%
        //          = 3.000.000 + 17.090.100 = 20.090.100
        // December = 20.090.100 - 19.800.000 = 290.100
        $this->assertMoney(290_100.0, $slip->pph21_amount);

        // A December slip is not a TER month: no category, no rate.
        $this->assertNull($slip->ter_category);
        $this->assertNull($slip->ter_rate);

        // 625.474 + 290.100 = 915.574 ; 20.000.000 - 915.574 = 19.084.426
        $this->assertMoney(915_574.0, $slip->total_deductions);
        $this->assertMoney(19_084_426.0, $slip->net_pay);
    }

    public function test_over_withholding_produces_a_negative_december_tax_that_is_refunded(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 20_000_000]);

        // Same year, but 2.500.000 was withheld each month => 27.500.000 for Jan-Nov.
        for ($month = 1; $month <= 11; $month++) {
            $this->priorMonth($employee, $month, 20_000_000.0, 2_500_000.0, 400_000.0, 105_474.0);
        }

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);
        $slip = $this->payslipFor($december, $employee);

        // Annual tax is unchanged at 20.090.100; December = 20.090.100 - 27.500.000
        //                                                 = -7.409.900 (refund)
        $this->assertMoney(-7_409_900.0, $slip->pph21_amount);
        // Deductions = 625.474 - 7.409.900 = -6.784.426 (a negative deduction)
        $this->assertMoney(-6_784_426.0, $slip->total_deductions);
        // Net = 20.000.000 - (-6.784.426) = 26.784.426
        $this->assertMoney(26_784_426.0, $slip->net_pay);
    }

    public function test_only_approved_or_closed_prior_runs_count_towards_the_year(): void
    {
        // 60.000.000/month employee, TK/0, one prior month approved.
        $employee = $this->makeEmployee(['base_salary' => 60_000_000]);
        $this->priorMonth($employee, 11, 60_000_000.0, 12_000_000.0, 1_200_000.0, 105_474.0);

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);

        // Annual gross      = 2 x 60.000.000                     = 120.000.000
        // Biaya jabatan     = min(6.000.000, 6.000.000)          =   6.000.000
        // Employee JHT + JP = 2 x (1.200.000 + 105.474)          =   2.610.948
        // PKP raw = 120.000.000 - 6.000.000 - 2.610.948 - 54.000.000 = 57.389.052
        //        -> 57.389.000 ; Pasal 17 = 57.389.000 x 5%          =  2.869.450
        // December = 2.869.450 - 12.000.000 = -9.130.550
        $this->assertMoney(-9_130_550.0, $this->payslipFor($december, $employee)->pph21_amount);
        // 1.425.474 (Dec BPJS employee) - 9.130.550 = -7.705.076
        $this->assertMoney(-7_705_076.0, $this->payslipFor($december, $employee)->total_deductions);
        $this->assertMoney(67_705_076.0, $this->payslipFor($december, $employee)->net_pay);
    }

    public function test_a_draft_prior_run_is_ignored_by_the_true_up(): void
    {
        // Identical to the approved case above, except November is still a draft.
        $employee = $this->makeEmployee(['base_salary' => 60_000_000]);
        $this->priorMonth(
            $employee, 11, 60_000_000.0, 12_000_000.0, 1_200_000.0, 105_474.0,
            status: DocumentStatus::Draft,
        );

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);
        $slip = $this->payslipFor($december, $employee);

        // Annual gross      = 60.000.000 (December only)
        // Biaya jabatan     = min(3.000.000, 6.000.000)  = 3.000.000
        // Employee JHT + JP = 1.200.000 + 105.474        = 1.305.474
        // PKP raw = 60.000.000 - 3.000.000 - 1.305.474 - 54.000.000 = 1.694.526
        //        -> 1.694.000 ; Pasal 17 = 1.694.000 x 5% = 84.700
        // Nothing withheld => December = 84.700
        $this->assertMoney(84_700.0, $slip->pph21_amount);
        // 1.425.474 + 84.700 = 1.510.174 ; 60.000.000 - 1.510.174 = 58.489.826
        $this->assertMoney(1_510_174.0, $slip->total_deductions);
        $this->assertMoney(58_489_826.0, $slip->net_pay);
    }

    public function test_a_closed_prior_run_counts_exactly_like_an_approved_one(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 60_000_000]);
        $this->priorMonth(
            $employee, 11, 60_000_000.0, 12_000_000.0, 1_200_000.0, 105_474.0,
            status: DocumentStatus::Closed,
        );

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);

        $this->assertMoney(-9_130_550.0, $this->payslipFor($december, $employee)->pph21_amount);
    }

    public function test_an_approved_thr_run_is_part_of_the_annual_gross(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 20_000_000]);

        // November salary: gross 20.000.000, TER withheld 1.800.000.
        $this->priorMonth($employee, 11, 20_000_000.0, 1_800_000.0, 400_000.0, 105_474.0);
        // June THR: gross 20.000.000, withheld 1.000.000, no BPJS on a THR run.
        $this->priorMonth(
            $employee, 6, 20_000_000.0, 1_000_000.0, 0.0, 0.0,
            type: PayrollRunType::Thr,
        );

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);

        // Annual gross      = 20.000.000 x 3                      = 60.000.000
        // Biaya jabatan     = min(3.000.000, 6.000.000)           =  3.000.000
        // Employee JHT + JP = 505.474 (Nov) + 0 (THR) + 505.474 (Dec) = 1.010.948
        // PKP raw = 60.000.000 - 3.000.000 - 1.010.948 - 54.000.000 = 1.989.052
        //        -> 1.989.000 ; Pasal 17 = 1.989.000 x 5% = 99.450
        // Withheld = 1.800.000 + 1.000.000 = 2.800.000
        // December = 99.450 - 2.800.000 = -2.700.550
        $this->assertMoney(-2_700_550.0, $this->payslipFor($december, $employee)->pph21_amount);
    }

    public function test_a_prior_run_of_another_year_does_not_leak_into_the_true_up(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 60_000_000]);
        // Same month number, previous fiscal year — must be invisible to 2026's true-up.
        $lastYear = $this->makeRun(['period_year' => 2025, 'period_month' => 11, 'status' => DocumentStatus::Approved]);
        $lastYear->payslips()->create([
            'employee_id' => $employee->id,
            'gross_income' => 60_000_000,
            'pph21_amount' => 12_000_000,
            'bpjs' => ['jht_employee' => 1_200_000, 'jp_employee' => 105_474],
        ]);

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);

        // Identical to the "December only" arithmetic: 84.700.
        $this->assertMoney(84_700.0, $this->payslipFor($december, $employee)->pph21_amount);
    }

    public function test_the_december_surcharge_applies_to_the_annual_tax_of_an_employee_without_a_tax_id(): void
    {
        // hasTaxId() is false only when both NPWP and NIK are blank.
        $employee = $this->makeEmployee([
            'base_salary' => 20_000_000,
            'nik_ktp' => '',
            'npwp' => null,
        ]);

        // TER with surcharge Jan-Nov: 1.800.000 x 1,2 = 2.160.000/month => 23.760.000.
        for ($month = 1; $month <= 11; $month++) {
            $this->priorMonth($employee, $month, 20_000_000.0, 2_160_000.0, 400_000.0, 105_474.0);
        }

        $december = $this->makeRun(['period_month' => 12]);
        $this->payrollService()->calculate($december);
        $slip = $this->payslipFor($december, $employee);

        // Pasal 17 on PKP 173.934.000 = 20.090.100 ; x 1,2 = 24.108.120
        // December = 24.108.120 - 23.760.000 = 348.120
        $this->assertMoney(348_120.0, $slip->pph21_amount);
        // 625.474 + 348.120 = 973.594 ; 20.000.000 - 973.594 = 19.026.406
        $this->assertMoney(973_594.0, $slip->total_deductions);
        $this->assertMoney(19_026_406.0, $slip->net_pay);
    }

    public function test_a_november_run_still_uses_the_ter_rate(): void
    {
        // Guard against the true-up leaking into the wrong month.
        $employee = $this->makeEmployee(['base_salary' => 20_000_000]);
        $november = $this->makeRun(['period_month' => 11]);

        $this->payrollService()->calculate($november);
        $slip = $this->payslipFor($november, $employee);

        // 19.750.000 < 20.000.000 <= 24.150.000 => 9% => 1.800.000
        $this->assertSame('A', $slip->ter_category);
        $this->assertMoney(9.0, $slip->ter_rate);
        $this->assertMoney(1_800_000.0, $slip->pph21_amount);
    }
}
