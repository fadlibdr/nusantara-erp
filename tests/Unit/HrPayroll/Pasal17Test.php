<?php

namespace Tests\Unit\HrPayroll;

use Modules\HrPayroll\Services\Pph21TerService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * Pph21TerService::pasal17() — the annual progressive schedule of
 * Pasal 17 ayat (1) huruf a UU PPh as amended by UU 7/2021 (UU HPP):
 *
 *   5%  on the first        60.000.000
 *   15% on    60.000.000 -> 250.000.000
 *   25% on   250.000.000 -> 500.000.000
 *   30% on   500.000.000 -> 5.000.000.000
 *   35% above              5.000.000.000
 *
 * Cumulative tax at each layer ceiling (used by the expectations below):
 *   60jt  ->     3.000.000
 *   250jt ->    31.500.000  (3.000.000 + 190.000.000 x 15%)
 *   500jt ->    94.000.000  (31.500.000 + 250.000.000 x 25%)
 *   5M    -> 1.444.000.000  (94.000.000 + 4.500.000.000 x 30%)
 */
class Pasal17Test extends ErpTestCase
{
    private Pph21TerService $ter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ter = new Pph21TerService;
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function layerBoundaryProvider(): array
    {
        return [
            // Exactly on each UU HPP boundary.
            '60jt is fully taxed at 5%' => [60_000_000.0, 3_000_000.0],
            '250jt tops up the 15% layer' => [250_000_000.0, 31_500_000.0],
            '500jt tops up the 25% layer' => [500_000_000.0, 94_000_000.0],
            '5M tops up the 30% layer' => [5_000_000_000.0, 1_444_000_000.0],

            // One rupiah past each boundary takes the next layer's rate on that rupiah.
            '60jt + 1 adds 15% of one rupiah' => [60_000_001.0, 3_000_000.15],
            '250jt + 1 adds 25% of one rupiah' => [250_000_001.0, 31_500_000.25],
            '500jt + 1 adds 30% of one rupiah' => [500_000_001.0, 94_000_000.30],
            '5M + 1 adds 35% of one rupiah' => [5_000_000_001.0, 1_444_000_000.35],
        ];
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function insideLayerProvider(): array
    {
        return [
            // 50.000.000 x 5% = 2.500.000
            'inside the 5% layer' => [50_000_000.0, 2_500_000.0],
            // 3.000.000 + 40.000.000 x 15% = 3.000.000 + 6.000.000 = 9.000.000
            'inside the 15% layer' => [100_000_000.0, 9_000_000.0],
            // 31.500.000 + 50.000.000 x 25% = 31.500.000 + 12.500.000 = 44.000.000
            'inside the 25% layer' => [300_000_000.0, 44_000_000.0],
            // 94.000.000 + 500.000.000 x 30% = 94.000.000 + 150.000.000 = 244.000.000
            'inside the 30% layer' => [1_000_000_000.0, 244_000_000.0],
            // 1.444.000.000 + 1.000.000.000 x 35% = 1.444.000.000 + 350.000.000 = 1.794.000.000
            'inside the 35% layer' => [6_000_000_000.0, 1_794_000_000.0],
        ];
    }

    #[DataProvider('layerBoundaryProvider')]
    public function test_pasal17_layer_boundaries(float $taxable, float $expected): void
    {
        $this->assertSame($expected, $this->ter->pasal17($taxable));
    }

    #[DataProvider('insideLayerProvider')]
    public function test_pasal17_inside_each_layer(float $taxable, float $expected): void
    {
        $this->assertSame($expected, $this->ter->pasal17($taxable));
    }

    public function test_zero_taxable_income_pays_nothing(): void
    {
        $this->assertSame(0.0, $this->ter->pasal17(0.0));
    }

    public function test_negative_taxable_income_pays_nothing(): void
    {
        // Can happen when PTKP exceeds gross; the schedule must not go negative.
        $this->assertSame(0.0, $this->ter->pasal17(-25_000_000.0));
    }

    public function test_the_very_first_rupiah_is_taxed_at_five_percent(): void
    {
        // 1 x 5% = 0,05
        $this->assertSame(0.05, $this->ter->pasal17(1.0));
    }

    public function test_the_schedule_is_continuous_across_the_60jt_boundary(): void
    {
        // Marginal step across the boundary must be one rupiah at 15%, not a cliff.
        $this->assertSame(
            0.15,
            round($this->ter->pasal17(60_000_001.0) - $this->ter->pasal17(60_000_000.0), 2),
        );
    }

    public function test_the_schedule_is_monotonically_increasing(): void
    {
        $previous = -1.0;

        foreach ([0, 1_000_000, 60_000_000, 60_000_001, 250_000_000, 250_000_001,
            500_000_000, 500_000_001, 5_000_000_000, 5_000_000_001, 9_000_000_000] as $taxable) {
            $tax = $this->ter->pasal17((float) $taxable);
            $this->assertGreaterThan($previous, $tax, "Tax fell going up to {$taxable}.");
            $previous = $tax;
        }
    }
}
