<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\WorkOrder;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Tests\ErpTestCase;

/**
 * P5 — PPK: perintah kerja alat sewa & jasa berbasis periode (deviasi 3.5).
 */
class WorkOrderTest extends ErpTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminUser();
        Sanctum::actingAs($this->admin);
    }

    private function rentalVendor(array $attributes = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => 'PT Alat Berat Nusantara',
            'classification' => 'jasa',
            'vendor_type' => 'rental',
            'is_pkp' => false,
            'status' => 'active',
        ], $attributes));
    }

    private function project(): Project
    {
        return Project::create(['name' => 'Gedung Kantor Graha', 'type' => 'construction']);
    }

    private function rentedAsset(): Asset
    {
        $category = AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 60],
        );

        return Asset::create([
            'name' => 'Excavator PC200 sewa',
            'category_id' => $category->id,
            'ownership' => 'rented',
            'vendor_id' => $this->rentalVendor(['name' => 'PT Sewa Alat Dua'])->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'useful_life_months' => 0,
            'status' => 'available',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $this->rentalVendor()->id,
            'project_id' => $this->project()->id,
            'title' => 'Sewa alat berat tahap struktur',
            'start_date' => '2026-06-01',
            'end_date' => '2026-12-31',
            'items' => [
                [
                    'description' => 'Sewa excavator PC200 (dengan operator)',
                    'asset_id' => $this->rentedAsset()->id,
                    'rate_basis' => 'per_jam',
                    'rate' => 350_000,
                    'qty_periods' => 100,
                ],
                [
                    'description' => 'Sewa scaffolding lengkap',
                    'rate_basis' => 'per_bulan',
                    'rate' => 15_000_000,
                    'qty_periods' => 6,
                ],
            ],
        ], $overrides);
    }

    public function test_ppk_dibuat_dengan_nilai_dari_baris_dan_kode_ppk(): void
    {
        $response = $this->postJson('/api/procurement/work-orders', $this->validPayload())
            ->assertCreated();

        // 100 x 350.000 + 6 x 15.000.000 = 35.000.000 + 90.000.000
        $this->assertSame('125000000.00', $response->json('data.value'));
        $this->assertStringStartsWith('PPK/', $response->json('data.code'));
        // Vendor non-PKP: tanpa PPN.
        $this->assertSame('0.0000', $response->json('data.ppn_rate'));
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_ppk_untuk_vendor_mandor_atau_subkon_ditolak_422(): void
    {
        $mandor = Vendor::create([
            'name' => 'Mandor Harjo', 'classification' => 'jasa',
            'vendor_type' => 'mandor', 'status' => 'active',
        ]);
        $subkon = Vendor::create([
            'name' => 'CV Karya Sipil', 'classification' => 'sipil',
            'vendor_type' => 'subcontractor', 'status' => 'active',
        ]);

        foreach ([$mandor, $subkon] as $vendor) {
            $response = $this->postJson(
                '/api/procurement/work-orders',
                $this->validPayload(['vendor_id' => $vendor->id]),
            )->assertUnprocessable();

            $this->assertStringContainsString('vendor rental', (string) $response->json('message'));
        }

        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_vendor_supplier_jasa_diterima_untuk_ppk(): void
    {
        // Roadmap menyebut "vendor rental/jasa": pemasok jasa terdaftar
        // sebagai supplier hari ini, jadi supplier diterima; hanya mandor
        // (SP3) dan subkontraktor (SPK) yang punya pintunya sendiri.
        $supplier = Vendor::create([
            'name' => 'PT Elektrindo Supply', 'classification' => 'jasa',
            'vendor_type' => 'supplier', 'status' => 'active',
        ]);

        $this->postJson(
            '/api/procurement/work-orders',
            $this->validPayload(['vendor_id' => $supplier->id]),
        )->assertCreated();
    }

    public function test_baris_per_jam_tanpa_alat_ditolak_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['items'][0]['asset_id']);

        $response = $this->postJson('/api/procurement/work-orders', $payload)
            ->assertUnprocessable();

        $this->assertStringContainsString('per_jam', (string) $response->json('message'));
        $this->assertStringContainsString('alat', (string) $response->json('message'));
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_maker_checker_ppk_pengaju_tidak_boleh_menyetujui(): void
    {
        $id = $this->postJson('/api/procurement/work-orders', $this->validPayload())
            ->assertCreated()->json('data.id');

        $this->postJson("/api/procurement/work-orders/{$id}/submit")->assertOk();

        // Admin mengajukan; admin pula yang mencoba menyetujui — ditolak SoD.
        $this->postJson("/api/procurement/work-orders/{$id}/approve")->assertUnprocessable();
        $this->assertSame(DocumentStatus::Submitted, WorkOrder::query()->findOrFail($id)->status);

        // Orang kedua dengan prc.approve boleh.
        $approver = $this->userWithPermission('prc.approve');
        Sanctum::actingAs($approver);
        $this->postJson("/api/procurement/work-orders/{$id}/approve")->assertOk();
        $this->assertSame(DocumentStatus::Approved, WorkOrder::query()->findOrFail($id)->status);
    }

    private function userWithPermission(string $permission): User
    {
        $role = Role::findOrCreate('role-'.str_replace('.', '-', $permission), 'web');
        $role->givePermissionTo($permission);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Penyetuju PPK',
            'email' => str()->random(10).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
