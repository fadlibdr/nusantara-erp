<?php

namespace Tests\Unit\HrPayroll;

use Modules\HrPayroll\Enums\PtkpStatus;
use Modules\HrPayroll\Services\Pph21TerService;
use Tests\ErpTestCase;

/**
 * Pph21TerService::annualTrueUp() — the December recalculation of PMK 168/2023.
 *
 *   biaya jabatan = min(5% x annual gross, 6.000.000)
 *   PKP           = max(0, annual gross - biaya jabatan - employee JHT/JP - PTKP)
 *                   rounded DOWN to full thousands
 *   annual tax    = Pasal 17 (x 1,2 without a tax id)
 *   december tax  = annual tax - TER already withheld Jan-Nov (may be negative)
 */
class Pph21AnnualTrueUpTest extends ErpTestCase
{
    private Pph21TerService $ter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ter = new Pph21TerService;
    }

    public function test_true_up_with_the_biaya_jabatan_cap_binding(): void
    {
        // gross 180.000.000; 5% = 9.000.000 > cap => biaya jabatan 6.000.000
        // PKP = 180.000.000 - 6.000.000 - 5.000.000 - 54.000.000 (TK/0) = 115.000.000
        // Pasal 17 = 3.000.000 + (115.000.000 - 60.000.000) x 15% = 3.000.000 + 8.250.000
        //          = 11.250.000
        // December = 11.250.000 - 10.000.000 = 1.250.000
        $result = $this->ter->annualTrueUp(
            PtkpStatus::TK0,
            annualGross: 180_000_000.0,
            annualEmployeePensionContributions: 5_000_000.0,
            withheldJanToNov: 10_000_000.0,
        );

        $this->assertSame(115_000_000.0, $result['taxable']);
        $this->assertSame(11_250_000.0, $result['annual_tax']);
        $this->assertSame(1_250_000.0, $result['december_tax']);
    }

    public function test_true_up_with_the_biaya_jabatan_below_the_cap(): void
    {
        // gross 100.000.000; 5% = 5.000.000 < 6.000.000 cap => biaya jabatan 5.000.000
        // PKP = 100.000.000 - 5.000.000 - 2.000.000 - 58.500.000 (K/0) = 34.500.000
        // Pasal 17 = 34.500.000 x 5% = 1.725.000
        // December = 1.725.000 - 1.000.000 = 725.000
        $result = $this->ter->annualTrueUp(
            PtkpStatus::K0,
            annualGross: 100_000_000.0,
            annualEmployeePensionContributions: 2_000_000.0,
            withheldJanToNov: 1_000_000.0,
        );

        $this->assertSame(34_500_000.0, $result['taxable']);
        $this->assertSame(1_725_000.0, $result['annual_tax']);
        $this->assertSame(725_000.0, $result['december_tax']);
    }

    public function test_the_biaya_jabatan_cap_bites_at_exactly_120_million_of_gross(): void
    {
        // 5% of 120.000.000 is exactly 6.000.000 — the cap and the percentage agree.
        // PKP = 120.000.000 - 6.000.000 - 0 - 54.000.000 = 60.000.000
        // Pasal 17 = 60.000.000 x 5% = 3.000.000
        $atCap = $this->ter->annualTrueUp(PtkpStatus::TK0, 120_000_000.0, 0.0, 0.0);

        $this->assertSame(60_000_000.0, $atCap['taxable']);
        $this->assertSame(3_000_000.0, $atCap['annual_tax']);

        // One rupiah under: 5% = 5.999.999,95, so the percentage still applies.
        // PKP raw = 119.999.999 - 5.999.999,95 - 54.000.000 = 59.999.999,05
        //         -> floored to thousands = 59.999.000
        // Pasal 17 = 59.999.000 x 5% = 2.999.950
        $justUnderCap = $this->ter->annualTrueUp(PtkpStatus::TK0, 119_999_999.0, 0.0, 0.0);

        $this->assertSame(59_999_000.0, $justUnderCap['taxable']);
        $this->assertSame(2_999_950.0, $justUnderCap['annual_tax']);
    }

    public function test_taxable_income_is_rounded_down_to_full_thousands(): void
    {
        // gross 200.000.777; biaya jabatan capped at 6.000.000
        // PKP raw = 200.000.777 - 6.000.000 - 0 - 54.000.000 = 140.000.777
        //         -> 140.000.000 (dibulatkan ribuan penuh ke bawah)
        // Pasal 17 = 3.000.000 + (140.000.000 - 60.000.000) x 15% = 15.000.000
        // Without the rounding the tax would have been 15.000.116,55.
        $result = $this->ter->annualTrueUp(PtkpStatus::TK0, 200_000_777.0, 0.0, 0.0);

        $this->assertSame(140_000_000.0, $result['taxable']);
        $this->assertSame(15_000_000.0, $result['annual_tax']);
    }

    public function test_the_thousands_rounding_never_rounds_up(): void
    {
        // PKP raw = 100_000_999 - 5_000_049,95 - 0 - 54.000.000 = 41.000.949,05 -> 41.000.000
        $result = $this->ter->annualTrueUp(PtkpStatus::TK0, 100_000_999.0, 0.0, 0.0);

        $this->assertSame(41_000_000.0, $result['taxable']);
        // 41.000.000 x 5% = 2.050.000
        $this->assertSame(2_050_000.0, $result['annual_tax']);
    }

    public function test_december_tax_is_negative_when_ter_over_withheld(): void
    {
        // PKP = 120.000.000 - 6.000.000 - 0 - 54.000.000 = 60.000.000 => Pasal 17 3.000.000
        // TER already withheld 5.000.000 => December = 3.000.000 - 5.000.000 = -2.000.000
        // A negative December amount is a refund paid back through the payroll.
        $result = $this->ter->annualTrueUp(
            PtkpStatus::TK0,
            annualGross: 120_000_000.0,
            annualEmployeePensionContributions: 0.0,
            withheldJanToNov: 5_000_000.0,
        );

        $this->assertSame(3_000_000.0, $result['annual_tax']);
        $this->assertSame(-2_000_000.0, $result['december_tax']);
    }

    public function test_taxable_income_is_floored_at_zero_when_ptkp_exceeds_gross(): void
    {
        // gross 50.000.000; biaya jabatan 2.500.000
        // PKP raw = 50.000.000 - 2.500.000 - 0 - 54.000.000 = -6.500.000 => 0
        // Annual tax 0; anything withheld earlier is refunded in full.
        $result = $this->ter->annualTrueUp(PtkpStatus::TK0, 50_000_000.0, 0.0, 100_000.0);

        $this->assertSame(0.0, $result['taxable']);
        $this->assertSame(0.0, $result['annual_tax']);
        $this->assertSame(-100_000.0, $result['december_tax']);
    }

    public function test_pension_contributions_reduce_the_taxable_base(): void
    {
        // Same gross, different employee JHT+JP: the difference in PKP must be exactly
        // the difference in contributions (6.000.000), i.e. 6.000.000 x 15% = 900.000 tax.
        $without = $this->ter->annualTrueUp(PtkpStatus::TK0, 180_000_000.0, 0.0, 0.0);
        $with = $this->ter->annualTrueUp(PtkpStatus::TK0, 180_000_000.0, 6_000_000.0, 0.0);

        // 180.000.000 - 6.000.000 - 0 - 54.000.000 = 120.000.000
        $this->assertSame(120_000_000.0, $without['taxable']);
        // 180.000.000 - 6.000.000 - 6.000.000 - 54.000.000 = 114.000.000
        $this->assertSame(114_000_000.0, $with['taxable']);

        // 3.000.000 + 60.000.000 x 15% = 12.000.000  vs  3.000.000 + 54.000.000 x 15% = 11.100.000
        $this->assertSame(12_000_000.0, $without['annual_tax']);
        $this->assertSame(11_100_000.0, $with['annual_tax']);
        $this->assertSame(900_000.0, $without['annual_tax'] - $with['annual_tax']);
    }

    public function test_the_ptkp_of_the_status_is_the_one_deducted(): void
    {
        // K/3 PTKP is 72.000.000 against TK/0's 54.000.000: 18.000.000 more relief.
        $tk0 = $this->ter->annualTrueUp(PtkpStatus::TK0, 180_000_000.0, 0.0, 0.0);
        $k3 = $this->ter->annualTrueUp(PtkpStatus::K3, 180_000_000.0, 0.0, 0.0);

        $this->assertSame(120_000_000.0, $tk0['taxable']);
        $this->assertSame(102_000_000.0, $k3['taxable']);
        $this->assertSame(18_000_000.0, $tk0['taxable'] - $k3['taxable']);
    }

    public function test_an_employee_without_a_tax_id_owes_120_percent_of_the_annual_tax(): void
    {
        // K/3: PKP = 300.000.000 - 6.000.000 (cap) - 6.000.000 - 72.000.000 = 216.000.000
        // Pasal 17 = 3.000.000 + (216.000.000 - 60.000.000) x 15% = 3.000.000 + 23.400.000
        //          = 26.400.000
        // x 1,2 = 31.680.000; December = 31.680.000 - 20.000.000 = 11.680.000
        $result = $this->ter->annualTrueUp(
            PtkpStatus::K3,
            annualGross: 300_000_000.0,
            annualEmployeePensionContributions: 6_000_000.0,
            withheldJanToNov: 20_000_000.0,
            hasTaxId: false,
        );

        $this->assertSame(216_000_000.0, $result['taxable']);
        $this->assertSame(31_680_000.0, $result['annual_tax']);
        $this->assertSame(11_680_000.0, $result['december_tax']);
    }

    public function test_the_surcharge_leaves_the_taxable_base_untouched(): void
    {
        $with = $this->ter->annualTrueUp(PtkpStatus::K3, 300_000_000.0, 6_000_000.0, 0.0, hasTaxId: true);
        $without = $this->ter->annualTrueUp(PtkpStatus::K3, 300_000_000.0, 6_000_000.0, 0.0, hasTaxId: false);

        $this->assertSame($with['taxable'], $without['taxable']);
        // 26.400.000 x 1,2 = 31.680.000
        $this->assertSame(26_400_000.0, $with['annual_tax']);
        $this->assertSame(31_680_000.0, $without['annual_tax']);
    }

    public function test_a_zero_gross_year_produces_no_tax_and_no_refund(): void
    {
        $result = $this->ter->annualTrueUp(PtkpStatus::TK0, 0.0, 0.0, 0.0);

        $this->assertSame(0.0, $result['taxable']);
        $this->assertSame(0.0, $result['annual_tax']);
        $this->assertSame(0.0, $result['december_tax']);
    }
}
