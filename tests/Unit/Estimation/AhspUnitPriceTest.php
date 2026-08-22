<?php

namespace Tests\Unit\Estimation;

use Modules\Estimation\Enums\AhspCategory;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\AhspComponent;
use Tests\ErpTestCase;

/**
 * AHSP — Analisa Harga Satuan Pekerjaan.
 *
 *   unit_price = sum(coefficient * unit_price per component) * (1 + overhead_pct / 100)
 *
 * Each component subtotal is rounded to the rupiah cent BEFORE it is summed.
 */
class AhspUnitPriceTest extends ErpTestCase
{
    use EstimationFixtures;

    public function test_unit_price_is_the_component_sum_grossed_up_by_overhead(): void
    {
        $ahsp = $this->makeConcreteAhsp();

        // 0,275 x 150.000 =  41.250
        // 1,650 x 120.000 = 198.000
        // 7,400 x  65.000 = 481.000
        // 0,520 x 350.000 = 182.000
        // 0,100 x 250.000 =  25.000
        //                   -------
        //             dasar 927.250 ; overhead 10% -> 927.250 * 1,10 = 1.019.975
        $this->assertSame(1019975.0, (float) $ahsp->unit_price);
        $this->assertSame(5, $ahsp->components()->count());
        $this->assertSame(AhspCategory::Sipil, $ahsp->category);
    }

    public function test_each_component_subtotal_is_coefficient_times_unit_price(): void
    {
        $ahsp = $this->makeConcreteAhsp();

        $subtotals = $ahsp->components()->orderBy('id')->get()
            ->map(fn (AhspComponent $component): float => $component->subtotal())->all();

        $this->assertSame([41250.0, 198000.0, 481000.0, 182000.0, 25000.0], $subtotals);
    }

    public function test_zero_overhead_leaves_the_base_cost_untouched(): void
    {
        $ahsp = $this->makeConcreteAhsp(['overhead_pct' => 0]);

        // 927.250 * (1 + 0/100) = 927.250
        $this->assertSame(927250.0, (float) $ahsp->unit_price);
    }

    public function test_overhead_defaults_to_ten_percent_when_omitted(): void
    {
        $ahsp = $this->ahsps()->create([
            'code' => 'AHSP-PAS-BATA',
            'name' => 'Pasangan bata merah 1:4',
            'unit' => 'm2',
            'category' => 'arsitektur',
            'components' => [
                ['component_type' => 'labor', 'name' => 'Tukang batu', 'unit' => 'OH', 'coefficient' => 0.1, 'unit_price' => 150000],
                ['component_type' => 'material', 'name' => 'Bata merah', 'unit' => 'bh', 'coefficient' => 70, 'unit_price' => 1000],
            ],
        ]);

        // dasar = 15.000 + 70.000 = 85.000 ; overhead bawaan 10% -> 93.500
        $this->assertSame(10.0, (float) $ahsp->overhead_pct);
        $this->assertSame(93500.0, (float) $ahsp->unit_price);
    }

    public function test_component_subtotals_are_rounded_before_they_are_summed(): void
    {
        $ahsp = $this->ahsps()->create([
            'code' => 'AHSP-ROUND',
            'name' => 'Uji pembulatan komponen',
            'unit' => 'ls',
            'category' => 'ict',
            'overhead_pct' => 0,
            'components' => [
                ['component_type' => 'material', 'name' => 'Komponen A', 'unit' => 'bh', 'coefficient' => 0.125, 'unit_price' => 100.05],
                ['component_type' => 'material', 'name' => 'Komponen B', 'unit' => 'bh', 'coefficient' => 0.125, 'unit_price' => 100.05],
                ['component_type' => 'material', 'name' => 'Komponen C', 'unit' => 'bh', 'coefficient' => 0.125, 'unit_price' => 100.05],
            ],
        ]);

        // 0,125 * 100,05 = 12,50625 -> dibulatkan per komponen menjadi 12,51
        // 3 x 12,51 = 37,53   (bukan round(3 x 12,50625) = 37,52)
        $this->assertSame(37.53, (float) $ahsp->unit_price);
    }

    public function test_an_analysis_without_components_prices_at_zero(): void
    {
        $ahsp = $this->ahsps()->create([
            'code' => 'AHSP-EMPTY',
            'name' => 'Analisa kosong',
            'unit' => 'ls',
            'category' => 'mep',
            'overhead_pct' => 15,
            'components' => [],
        ]);

        $this->assertSame(0.0, (float) $ahsp->unit_price);
    }

    public function test_components_are_replaced_wholesale_on_update(): void
    {
        $ahsp = $this->makeConcreteAhsp();

        $this->ahsps()->update($ahsp, [
            'components' => [
                ['component_type' => 'labor', 'name' => 'Pekerja', 'unit' => 'OH', 'coefficient' => 2, 'unit_price' => 120000],
            ],
        ]);

        $ahsp->refresh();

        // Lima komponen lama hilang seluruhnya: 2 x 120.000 = 240.000 ; x 1,10 = 264.000
        $this->assertSame(1, $ahsp->components()->count());
        $this->assertSame(264000.0, (float) $ahsp->unit_price);
        $this->assertSame(1, AhspComponent::query()->count());
    }

    public function test_updating_without_the_components_key_keeps_them_and_reprices(): void
    {
        $ahsp = $this->makeConcreteAhsp();

        $this->ahsps()->update($ahsp, ['overhead_pct' => 20]);

        $ahsp->refresh();

        $this->assertSame(5, $ahsp->components()->count());
        // dasar 927.250 * 1,20 = 1.112.700
        $this->assertSame(1112700.0, (float) $ahsp->unit_price);
    }

    public function test_clearing_the_components_on_update_drops_the_price_to_zero(): void
    {
        $ahsp = $this->makeConcreteAhsp();

        $this->ahsps()->update($ahsp, ['components' => []]);

        $this->assertSame(0, $ahsp->refresh()->components()->count());
        $this->assertSame(0.0, (float) $ahsp->unit_price);
    }

    public function test_recalc_repairs_a_cached_price_that_drifted(): void
    {
        $ahsp = $this->makeConcreteAhsp();

        // Someone poked the cached column directly.
        Ahsp::query()->whereKey($ahsp->id)->update(['unit_price' => 1]);

        $this->ahsps()->recalcUnitPrice($ahsp->refresh());

        $this->assertSame(1019975.0, (float) $ahsp->refresh()->unit_price);
    }
}
