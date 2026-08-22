<?php

namespace Tests\Feature\Finance;

use Modules\Finance\Database\Seeders\TaxSeeder;
use Modules\Finance\Models\Tax;
use Tests\ErpTestCase;

/**
 * Kode objek pajak is DJP-issued master data, not something the application can
 * derive. Two properties protect it:
 *
 *  - the seeder never invents one, because a guessed code produces a bukti
 *    potong that is filed under the wrong object and looks correct until DJP
 *    disagrees; an empty one is refused by the export and is therefore visible;
 *  - re-seeding never overwrites one, because from the first edit onwards it is
 *    the tax officer's field and a routine deploy must not silently revert it.
 */
class TaxSeederObjectCodeTest extends ErpTestCase
{
    public function test_the_seeder_does_not_invent_an_object_code(): void
    {
        $this->seed(TaxSeeder::class);

        $this->assertGreaterThan(0, Tax::query()->count(), 'the seeder should create tax rows');
        $this->assertSame(
            0,
            Tax::query()->whereNotNull('object_code')->count(),
            'no tax may be seeded with a kode objek pajak',
        );
    }

    public function test_re_seeding_keeps_an_object_code_that_was_filled_in(): void
    {
        $this->seed(TaxSeeder::class);

        $tax = Tax::query()->where('code', Tax::pphFinalCodeForScheme('perancangan_bersertifikat'))->firstOrFail();
        $tax->forceFill(['object_code' => '28-403-09'])->save();

        $this->seed(TaxSeeder::class);

        $this->assertSame('28-403-09', $tax->fresh()->object_code);
    }

    /**
     * Seven schemes with seven different rates are seven different objects. One
     * shared code across all of them is the specific mistake this guards.
     */
    public function test_the_construction_schemes_are_distinct_rows(): void
    {
        $this->seed(TaxSeeder::class);

        $construction = Tax::query()->where('code', 'like', 'PPH4A2-%')->get();

        $this->assertGreaterThanOrEqual(7, $construction->count());
        $this->assertSame(
            $construction->count(),
            $construction->pluck('code')->unique()->count(),
            'each construction scheme needs its own tax row to carry its own object code',
        );
    }
}
