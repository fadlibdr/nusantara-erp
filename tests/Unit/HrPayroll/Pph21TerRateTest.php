<?php

namespace Tests\Unit\HrPayroll;

use InvalidArgumentException;
use Modules\HrPayroll\Services\Pph21TerService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * Pph21TerService::rateFor() — the TER A/B/C bracket tables of PMK 168/2023.
 *
 * Boundary semantics under test: every bracket is (min, max], i.e. the minimum is
 * EXCLUSIVE and the maximum is INCLUSIVE. A gross exactly equal to a bracket's max
 * must take that bracket's rate; one rupiah more must take the next bracket's rate.
 */
class Pph21TerRateTest extends ErpTestCase
{
    private Pph21TerService $ter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ter = new Pph21TerService;
    }

    /**
     * Boundary pairs transcribed from the PMK 168/2023 attachment, category A
     * (PTKP TK/0, TK/1, K/0).
     *
     * @return array<string, array{float, float}>
     */
    public static function categoryABoundaryProvider(): array
    {
        return [
            // 0% floor of category A ends at 5.400.000 inclusive.
            'floor max 5.400.000 is still 0%' => [5_400_000.0, 0.0],
            'floor max + 1 rupiah enters 0,25%' => [5_400_001.0, 0.25],
            'second bracket max 5.650.000 stays 0,25%' => [5_650_000.0, 0.25],
            'second bracket max + 1 rupiah enters 0,5%' => [5_650_001.0, 0.5],
            // Mid-table boundary: 11.600.000 < x <= 12.500.000 is 4%.
            'mid bracket max 12.500.000 stays 4%' => [12_500_000.0, 4.0],
            'mid bracket max + 1 rupiah enters 5%' => [12_500_001.0, 5.0],
            // Last closed bracket 910.000.000 < x <= 1.400.000.000 is 33%.
            'last closed bracket max 1.400.000.000 stays 33%' => [1_400_000_000.0, 33.0],
            'above 1.400.000.000 takes the open-ended 34%' => [1_400_000_001.0, 34.0],
            'far above the table still takes 34%' => [50_000_000_000.0, 34.0],
        ];
    }

    /**
     * Category B (PTKP TK/2, TK/3, K/1, K/2).
     *
     * @return array<string, array{float, float}>
     */
    public static function categoryBBoundaryProvider(): array
    {
        return [
            'floor max 6.200.000 is still 0%' => [6_200_000.0, 0.0],
            'floor max + 1 rupiah enters 0,25%' => [6_200_001.0, 0.25],
            // 7.300.000 < x <= 9.200.000 is 1%.
            'mid bracket max 9.200.000 stays 1%' => [9_200_000.0, 1.0],
            'mid bracket max + 1 rupiah enters 1,5%' => [9_200_001.0, 1.5],
            // 18.450.000 < x <= 21.850.000 is 8%.
            'mid bracket max 21.850.000 stays 8%' => [21_850_000.0, 8.0],
            'mid bracket max + 1 rupiah enters 9%' => [21_850_001.0, 9.0],
            'last closed bracket max 1.405.000.000 stays 33%' => [1_405_000_000.0, 33.0],
            'above 1.405.000.000 takes the open-ended 34%' => [1_405_000_001.0, 34.0],
        ];
    }

    /**
     * Category C (PTKP K/3).
     *
     * @return array<string, array{float, float}>
     */
    public static function categoryCBoundaryProvider(): array
    {
        return [
            'floor max 6.600.000 is still 0%' => [6_600_000.0, 0.0],
            'floor max + 1 rupiah enters 0,25%' => [6_600_001.0, 0.25],
            // 10.950.000 < x <= 11.200.000 is 1,75%.
            'mid bracket max 11.200.000 stays 1,75%' => [11_200_000.0, 1.75],
            'mid bracket max + 1 rupiah enters 2%' => [11_200_001.0, 2.0],
            // 22.700.000 < x <= 26.600.000 is 9%.
            'mid bracket max 26.600.000 stays 9%' => [26_600_000.0, 9.0],
            'mid bracket max + 1 rupiah enters 10%' => [26_600_001.0, 10.0],
            'last closed bracket max 1.419.000.000 stays 33%' => [1_419_000_000.0, 33.0],
            'above 1.419.000.000 takes the open-ended 34%' => [1_419_000_001.0, 34.0],
        ];
    }

    #[DataProvider('categoryABoundaryProvider')]
    public function test_category_a_brackets_are_min_exclusive_and_max_inclusive(float $gross, float $expected): void
    {
        $this->assertSame($expected, $this->ter->rateFor('A', $gross));
    }

    #[DataProvider('categoryBBoundaryProvider')]
    public function test_category_b_brackets_are_min_exclusive_and_max_inclusive(float $gross, float $expected): void
    {
        $this->assertSame($expected, $this->ter->rateFor('B', $gross));
    }

    #[DataProvider('categoryCBoundaryProvider')]
    public function test_category_c_brackets_are_min_exclusive_and_max_inclusive(float $gross, float $expected): void
    {
        $this->assertSame($expected, $this->ter->rateFor('C', $gross));
    }

    public function test_each_category_has_its_own_zero_percent_floor(): void
    {
        // The floors differ per category: A 5,4jt, B 6,2jt, C 6,6jt.
        $this->assertSame(0.0, $this->ter->rateFor('A', 5_400_000.0));
        $this->assertSame(0.0, $this->ter->rateFor('B', 6_200_000.0));
        $this->assertSame(0.0, $this->ter->rateFor('C', 6_600_000.0));

        // A gross of 6.200.000 is already taxable in A but still free in B and C.
        $this->assertSame(0.75, $this->ter->rateFor('A', 6_200_000.0)); // 5,95jt < x <= 6,3jt
        $this->assertSame(0.0, $this->ter->rateFor('B', 6_200_000.0));
        $this->assertSame(0.0, $this->ter->rateFor('C', 6_200_000.0));
    }

    public function test_gross_of_exactly_zero_is_untaxed(): void
    {
        // The first bracket's min (0) is exclusive, so 0 matches no bracket at all.
        $this->assertSame(0.0, $this->ter->rateFor('A', 0.0));
        $this->assertSame(0.0, $this->ter->rateFor('B', 0.0));
        $this->assertSame(0.0, $this->ter->rateFor('C', 0.0));
    }

    public function test_negative_gross_is_untaxed(): void
    {
        $this->assertSame(0.0, $this->ter->rateFor('A', -1.0));
        $this->assertSame(0.0, $this->ter->rateFor('B', -12_000_000.0));
        $this->assertSame(0.0, $this->ter->rateFor('C', -0.01));
    }

    public function test_one_rupiah_of_income_already_falls_in_the_zero_percent_bracket(): void
    {
        // Not the same thing as "no bracket": 1 rupiah is inside 0 < x <= 5.400.000.
        $this->assertSame(0.0, $this->ter->rateFor('A', 1.0));
    }

    public function test_category_lookup_is_case_insensitive(): void
    {
        $this->assertSame(4.0, $this->ter->rateFor('a', 12_500_000.0));
        $this->assertSame(8.0, $this->ter->rateFor('b', 21_850_000.0));
        $this->assertSame(9.0, $this->ter->rateFor('c', 26_600_000.0));
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown TER category [D].');

        $this->ter->rateFor('D', 10_000_000.0);
    }

    public function test_an_empty_category_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->ter->rateFor('', 10_000_000.0);
    }
}
