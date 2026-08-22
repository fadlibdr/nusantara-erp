<?php

namespace Tests\Unit\HrPayroll;

use Modules\HrPayroll\Enums\PtkpStatus;
use Modules\HrPayroll\Services\Pph21TerService;
use Tests\ErpTestCase;

/**
 * Pph21TerService::monthlyTax() — Jan-Nov withholding:
 * amount = monthly gross x TER rate / 100, x 1,2 when the employee has no tax id
 * (Pasal 21 ayat 5a UU PPh).
 */
class Pph21MonthlyTaxTest extends ErpTestCase
{
    private Pph21TerService $ter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ter = new Pph21TerService;
    }

    public function test_monthly_tax_is_gross_times_the_ter_rate_of_category_a(): void
    {
        // TK/0 => category A; 9.650.000 < 10.000.000 <= 10.050.000 => 2%.
        // 10.000.000 x 2 / 100 = 200.000
        $result = $this->ter->monthlyTax(PtkpStatus::TK0, 10_000_000.0);

        $this->assertSame('A', $result['category']);
        $this->assertSame(2.0, $result['rate']);
        $this->assertSame(200_000.0, $result['amount']);
    }

    public function test_monthly_tax_uses_category_b_for_a_k1_employee(): void
    {
        // K/1 => category B; 11.600.000 < 12.000.000 <= 12.600.000 => 3%.
        // 12.000.000 x 3 / 100 = 360.000
        $result = $this->ter->monthlyTax(PtkpStatus::K1, 12_000_000.0);

        $this->assertSame('B', $result['category']);
        $this->assertSame(3.0, $result['rate']);
        $this->assertSame(360_000.0, $result['amount']);
    }

    public function test_monthly_tax_uses_category_c_for_a_k3_employee(): void
    {
        // K/3 => category C; 14.150.000 < 15.000.000 <= 15.550.000 => 5%.
        // 15.000.000 x 5 / 100 = 750.000
        $result = $this->ter->monthlyTax(PtkpStatus::K3, 15_000_000.0);

        $this->assertSame('C', $result['category']);
        $this->assertSame(5.0, $result['rate']);
        $this->assertSame(750_000.0, $result['amount']);
    }

    public function test_income_inside_the_zero_percent_floor_is_not_withheld(): void
    {
        // 5.000.000 <= 5.400.000 => 0% for category A.
        $result = $this->ter->monthlyTax(PtkpStatus::TK0, 5_000_000.0);

        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(0.0, $result['amount']);
    }

    public function test_zero_gross_produces_zero_tax(): void
    {
        $result = $this->ter->monthlyTax(PtkpStatus::TK0, 0.0);

        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(0.0, $result['amount']);
    }

    public function test_the_amount_is_rounded_to_two_decimals(): void
    {
        // 5.400.000 < 5.400.123 <= 5.650.000 => 0,25%.
        // 5.400.123 x 0,25 / 100 = 13.500,3075 -> 13.500,31
        $result = $this->ter->monthlyTax(PtkpStatus::TK0, 5_400_123.0);

        $this->assertSame(0.25, $result['rate']);
        $this->assertSame(13_500.31, $result['amount']);
    }

    public function test_an_employee_without_a_tax_id_is_withheld_at_120_percent(): void
    {
        // 10.000.000 x 2% = 200.000; 200.000 x 1,2 = 240.000
        $result = $this->ter->monthlyTax(PtkpStatus::TK0, 10_000_000.0, hasTaxId: false);

        $this->assertSame(2.0, $result['rate'], 'The surcharge must not change the bracket rate.');
        $this->assertSame(240_000.0, $result['amount']);
    }

    public function test_the_surcharge_is_applied_after_the_base_amount_is_rounded(): void
    {
        // round(5.400.123 x 0,25%) = 13.500,31; round(13.500,31 x 1,2) = 16.200,37
        // (rounding only at the end would give 16.200,369 -> 16.200,37 as well, but
        // the intermediate rounding is the documented behaviour).
        $result = $this->ter->monthlyTax(PtkpStatus::TK0, 5_400_123.0, hasTaxId: false);

        $this->assertSame(16_200.37, $result['amount']);
    }

    public function test_the_surcharge_cannot_create_tax_out_of_a_zero_percent_bracket(): void
    {
        // 0 x 1,2 = 0 — a low earner without a tax id still owes nothing.
        $result = $this->ter->monthlyTax(PtkpStatus::TK0, 5_400_000.0, hasTaxId: false);

        $this->assertSame(0.0, $result['amount']);
    }

    public function test_the_statutory_surcharge_factor_is_120_percent(): void
    {
        $this->assertSame(1.2, Pph21TerService::NON_TAX_ID_SURCHARGE);
    }

    public function test_a_tax_id_is_assumed_when_the_flag_is_omitted(): void
    {
        $withDefault = $this->ter->monthlyTax(PtkpStatus::TK0, 10_000_000.0);
        $withExplicitTaxId = $this->ter->monthlyTax(PtkpStatus::TK0, 10_000_000.0, hasTaxId: true);

        $this->assertSame($withExplicitTaxId['amount'], $withDefault['amount']);
        $this->assertSame(200_000.0, $withDefault['amount']);
    }

    public function test_the_ter_boundary_carries_through_to_the_withheld_amount(): void
    {
        // 12.500.000 is the max of the 4% bracket: 12.500.000 x 4% = 500.000
        $atBoundary = $this->ter->monthlyTax(PtkpStatus::TK0, 12_500_000.0);
        // One rupiah more jumps to 5%: 12.500.001 x 5% = 625.000,05
        $aboveBoundary = $this->ter->monthlyTax(PtkpStatus::TK0, 12_500_001.0);

        $this->assertSame(500_000.0, $atBoundary['amount']);
        $this->assertSame(625_000.05, $aboveBoundary['amount']);
    }
}
