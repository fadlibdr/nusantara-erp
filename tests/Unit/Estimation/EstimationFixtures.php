<?php

namespace Tests\Unit\Estimation;

use App\Models\User;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Services\AhspService;
use Modules\Estimation\Services\BoqService;
use Modules\Estimation\Services\RapService;

/**
 * Hand-built Estimation fixtures shared by the AHSP / BOQ / RAP unit tests.
 *
 * Deliberately dumb: it only assembles rows. Every expected number, with its
 * arithmetic, lives in the test that asserts it.
 */
trait EstimationFixtures
{
    protected function ahsps(): AhspService
    {
        return app(AhspService::class);
    }

    protected function boqs(): BoqService
    {
        return app(BoqService::class);
    }

    protected function raps(): RapService
    {
        return app(RapService::class);
    }

    protected function makeUser(string $email = 'estimator@test.local'): User
    {
        return User::query()->create([
            'name' => 'Made Wirawan',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    /**
     * SNI-style analysis for 1 m3 beton K-300, overhead 10%.
     *
     * Upah   : 0,275 x 150.000 =  41.250
     *          1,650 x 120.000 = 198.000
     * Bahan  : 7,400 x  65.000 = 481.000
     *          0,520 x 350.000 = 182.000
     * Alat   : 0,100 x 250.000 =  25.000
     *                    dasar = 927.250  -> x 1,10 = 1.019.975
     */
    protected function makeConcreteAhsp(array $overrides = []): Ahsp
    {
        return $this->ahsps()->create(array_merge([
            'code' => 'AHSP-BET-K300',
            'name' => 'Beton K-300 (site mix)',
            'unit' => 'm3',
            'category' => 'sipil',
            'overhead_pct' => 10,
            'components' => [
                ['component_type' => 'labor', 'name' => 'Tukang batu', 'unit' => 'OH', 'coefficient' => 0.275, 'unit_price' => 150000],
                ['component_type' => 'labor', 'name' => 'Pekerja', 'unit' => 'OH', 'coefficient' => 1.65, 'unit_price' => 120000],
                ['component_type' => 'material', 'name' => 'Semen Portland 50kg', 'unit' => 'zak', 'coefficient' => 7.4, 'unit_price' => 65000],
                ['component_type' => 'material', 'name' => 'Pasir beton', 'unit' => 'm3', 'coefficient' => 0.52, 'unit_price' => 350000],
                ['component_type' => 'equipment', 'name' => 'Concrete mixer', 'unit' => 'hari', 'coefficient' => 0.1, 'unit_price' => 250000],
            ],
        ], $overrides));
    }
}
