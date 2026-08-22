<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Services\DepreciationService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Iam\Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Penyusutan milik finance, bukan milik admin.
 *
 * Posting run penyusutan butuh ast.post dan tidak satu pun role bawaan selain
 * admin memegangnya — role finance bahkan tanpa ast.view, sehingga menu Aset
 * tidak tampak bagi login yang bertanggung jawab atas tutup buku bulanan.
 * Dalam praktik langkah penyusutan pada close akan terlewat, atau dikerjakan
 * akun admin/IT — orang yang salah secara kontrol.
 */
class FinanceDepreciationPermissionTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function role(string $name): Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->firstOrFail();
    }

    private function financeUser(): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Finance',
            'email' => 'dewi@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole('finance');

        return $user;
    }

    public function test_role_finance_memegang_ast_view_dan_ast_post(): void
    {
        $finance = $this->role('finance');

        $this->assertTrue(
            $finance->hasPermissionTo('ast.view'),
            'Tanpa ast.view menu Aset tidak tampak bagi finance dan run penyusutan tidak bisa ditinjau.',
        );
        $this->assertTrue(
            $finance->hasPermissionTo('ast.post'),
            'Posting penyusutan adalah langkah tutup buku milik finance, bukan milik admin/IT.',
        );
    }

    public function test_register_aset_tetap_di_luar_jangkauan_finance(): void
    {
        $finance = $this->role('finance');

        // Pencatat aset dan pemosting penyusutannya harus tetap dua orang
        // berbeda — pemisahan yang sama seperti maker-checker keuangan.
        foreach (['ast.create', 'ast.update', 'ast.delete', 'ast.approve'] as $permission) {
            $this->assertFalse(
                $finance->hasPermissionTo($permission),
                "finance tidak boleh memegang {$permission}: register aset milik sisi proyek.",
            );
        }
    }

    /**
     * RoleSeeder hanya membentuk instalasi baru; role erp1 di-seed sebelum
     * paket ini, jadi migrasinya yang harus melakukan operasi yang sama di
     * sana. Ini menciptakan ulang keadaan pra-migrasi lalu menjalankannya.
     */
    public function test_migrasi_memberi_grant_pada_role_finance_yang_sudah_ada(): void
    {
        $finance = $this->role('finance');
        $finance->revokePermissionTo(['ast.view', 'ast.post']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->role('finance')->hasPermissionTo('ast.post'));

        $migration = require base_path(
            'Modules/Iam/Database/Migrations/2026_08_06_000241_give_the_finance_role_ast_view_and_post.php',
        );
        $migration->up();

        $this->assertTrue($this->role('finance')->hasPermissionTo('ast.view'));
        $this->assertTrue($this->role('finance')->hasPermissionTo('ast.post'));
    }

    public function test_user_finance_bisa_memposting_run_penyusutan_lewat_endpoint(): void
    {
        $category = AssetCategory::query()->create([
            'code' => 'KENDARAAN-T',
            'name' => 'Kendaraan',
            'useful_life_months_default' => 60,
            'depreciation_account_hint' => '6-3100',
            'accum_account_hint' => '1-2310',
        ]);

        Asset::query()->create([
            'code' => 'AST-T0001',
            'name' => 'Dump Truck Uji',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'depreciation_start_date' => '2025-01-01',
            'acquisition_cost' => 96_000_000,
            'useful_life_months' => 96,
            'salvage_value' => 0,
            'accumulated_depreciation' => 0,
            'book_value' => 96_000_000,
            'status' => 'available',
        ]);

        $run = app(DepreciationService::class)->runForPeriod(2026, 3);

        Sanctum::actingAs($this->financeUser());

        $this->postJson("/api/assets/depreciation-runs/{$run->id}/post")->assertOk();

        $this->assertSame('posted', $run->fresh()->status->value);
    }
}
