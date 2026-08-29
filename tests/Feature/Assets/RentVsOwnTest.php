<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Services\DeploymentService;
use Modules\Assets\Services\EquipmentLogService;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P5 — layar Evaluasi Sewa vs Beli, BACA SAJA (deviasi 3.5 "sewa-vs-beli ⬜").
 *
 * Kejujuran yang dipin: alat sewa tanpa jam tercatat menampilkan bergaris
 * (null), bukan nol; aset beli tanpa harga perolehan berkata "tidak dapat
 * dibandingkan", bukan membandingkan dengan Rp 0.
 */
class RentVsOwnTest extends ErpTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminUser();
        Sanctum::actingAs($this->admin);
    }

    private function category(): AssetCategory
    {
        return AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 60],
        );
    }

    private function rentedAsset(array $attributes = []): Asset
    {
        $vendor = Vendor::create([
            'name' => 'PT Alat Berat Nusantara '.str()->random(4),
            'classification' => 'jasa',
            'vendor_type' => 'rental',
            'status' => 'active',
        ]);

        return Asset::create(array_merge([
            'name' => 'Excavator sewa',
            'category_id' => $this->category()->id,
            'ownership' => 'rented',
            'vendor_id' => $vendor->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'useful_life_months' => 0,
            'status' => 'available',
        ], $attributes));
    }

    public function test_alat_sewa_tanpa_jam_tercatat_bergaris_bukan_nol(): void
    {
        $asset = $this->rentedAsset();

        $row = collect($this->getJson('/api/assets/reports/rent-vs-own')->assertOk()->json('data.rows'))
            ->firstWhere('asset_id', $asset->id);

        $this->assertNotNull($row);
        $this->assertNull($row['hours_logged'], 'Tanpa log, jam harus null (bergaris), bukan 0.');
        $this->assertNull($row['rental_cost'], 'Tanpa jam terukur, biaya sewa belum bisa dihitung.');
        $this->assertFalse($row['comparable']);
        $this->assertStringContainsString('jam', (string) $row['note']);
    }

    public function test_alat_sewa_per_jam_dihitung_dari_delta_register(): void
    {
        $asset = $this->rentedAsset();
        $project = Project::create(['name' => 'Proyek', 'type' => 'construction']);

        $deployment = app(DeploymentService::class)->deploy($asset, [
            'project_id' => $project->id,
            'deployed_from' => '2026-07-01',
        ]);

        foreach ([['2026-07-02', 1200.0], ['2026-07-31', 1213.0]] as [$date, $meter]) {
            app(EquipmentLogService::class)->record($deployment, [
                'log_date' => $date,
                'hour_meter' => $meter,
            ], $this->admin);
        }

        $row = collect($this->getJson('/api/assets/reports/rent-vs-own')->assertOk()->json('data.rows'))
            ->firstWhere('asset_id', $asset->id);

        $this->assertSame(13.0, (float) $row['hours_logged']);
        // 13 jam x Rp 350.000.
        $this->assertSame(4_550_000.0, (float) $row['rental_cost']);
        $this->assertTrue($row['comparable']);
    }

    public function test_aset_beli_tanpa_harga_perolehan_tidak_dapat_dibandingkan(): void
    {
        // Baris warisan/impor: skema kini mengizinkan NULL; layar harus jujur.
        $asset = Asset::create([
            'name' => 'Genset warisan tanpa harga',
            'category_id' => $this->category()->id,
            'acquisition_cost' => null,
            'useful_life_months' => 60,
            'status' => 'available',
        ]);

        $row = collect($this->getJson('/api/assets/reports/rent-vs-own')->assertOk()->json('data.rows'))
            ->firstWhere('asset_id', $asset->id);

        $this->assertFalse($row['comparable']);
        $this->assertStringContainsString('Tidak dapat dibandingkan', (string) $row['note']);
        $this->assertNull($row['acquisition_cost']);
    }

    public function test_aset_beli_dengan_harga_menampilkan_sisi_beli(): void
    {
        $asset = Asset::create([
            'name' => 'Excavator milik sendiri',
            'category_id' => $this->category()->id,
            'acquisition_date' => '2026-01-10',
            'acquisition_cost' => 900_000_000,
            'salvage_value' => 0,
            'useful_life_months' => 60,
            'accumulated_depreciation' => 90_000_000,
            'book_value' => 810_000_000,
            'status' => 'available',
        ]);

        $row = collect($this->getJson('/api/assets/reports/rent-vs-own')->assertOk()->json('data.rows'))
            ->firstWhere('asset_id', $asset->id);

        $this->assertTrue($row['comparable']);
        $this->assertSame(900_000_000.0, (float) $row['acquisition_cost']);
        // 900 jt / 60 bulan.
        $this->assertSame(15_000_000.0, (float) $row['monthly_depreciation']);
    }
}
