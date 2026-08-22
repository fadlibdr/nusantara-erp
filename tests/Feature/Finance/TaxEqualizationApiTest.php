<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * GET finance/tax-equalization — the endpoint the ekualisasi screen reads.
 *
 * fin.view like every other Finance report: printing and reading a working
 * paper writes nothing. The payload contract pinned here is what the SPA
 * builds against — four worksheets, in this order, each carrying rows,
 * residual and warnings even when the year is empty.
 */
class TaxEqualizationApiTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->seedLedger(2026);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna Uji',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_the_endpoint_is_refused_without_fin_view_and_answers_with_it(): void
    {
        $this->actingAs($this->userWith([]), 'sanctum')
            ->getJson('/api/finance/tax-equalization?year=2026')
            ->assertForbidden();

        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/tax-equalization?year=2026')
            ->assertOk();

        $this->assertSame(2026, $response->json('data.year'));

        $worksheets = $response->json('data.worksheets');
        $this->assertSame(
            ['ppn_keluaran', 'ppn_masukan', 'pph21', 'pph_dipotong'],
            array_column($worksheets, 'key'),
        );

        foreach ($worksheets as $worksheet) {
            $this->assertArrayHasKey('title', $worksheet);
            $this->assertArrayHasKey('rows', $worksheet);
            $this->assertArrayHasKey('residual', $worksheet);
            $this->assertArrayHasKey('warnings', $worksheet);
        }
    }

    /**
     * An empty year is four honest "tidak ada data" sheets — never a table of
     * fake zeros a pemeriksa could read as "nothing to reconcile".
     */
    public function test_an_empty_year_says_so_on_every_worksheet(): void
    {
        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/tax-equalization?year=2030')
            ->assertOk();

        foreach ($response->json('data.worksheets') as $worksheet) {
            $this->assertSame([], $worksheet['rows'], "Worksheet {$worksheet['key']} should carry no rows.");
            $this->assertNull($worksheet['residual'], "Worksheet {$worksheet['key']} must not fake a residual.");
            $this->assertNotEmpty($worksheet['warnings'], "Worksheet {$worksheet['key']} must say the year is empty.");
        }
    }

    public function test_the_year_defaults_to_the_month_just_closed_and_rejects_garbage(): void
    {
        // 2026-08-20 -> bulan lalu Juli 2026 -> tahun 2026.
        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/tax-equalization')
            ->assertOk();
        $this->assertSame(2026, $response->json('data.year'));

        $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/tax-equalization?year=99999')
            ->assertStatus(422);
    }
}
