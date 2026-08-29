<?php

namespace Tests\Feature\Assets;

use Laravel\Sanctum\Sanctum;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Services\DeploymentService;
use Modules\Assets\Services\DepreciationService;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * P5 — ast_assets.ownership owned|rented (deviasi 3.6 "milik sendiri saja").
 *
 * Dua kejujuran yang dipin di sini: alat sewa TIDAK PERNAH disusutkan
 * sekalipun kolom penyusutannya terisi, dan harga perolehan NULL tidak pernah
 * diam-diam menjadi Rp 0 — nilai buku alat sewa adalah NULL (bergaris), bukan
 * nol.
 */
class AssetOwnershipTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function category(): AssetCategory
    {
        return AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 60],
        );
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

    private function ownedPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Excavator PC200 milik sendiri',
            'category_id' => $this->category()->id,
            'acquisition_date' => '2026-01-10',
            'acquisition_cost' => 900_000_000,
            'useful_life_months' => 60,
        ], $overrides);
    }

    private function rentedPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Excavator PC200 sewa',
            'category_id' => $this->category()->id,
            'ownership' => 'rented',
            'vendor_id' => $this->rentalVendor()->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'rental_start' => '2026-06-01',
        ], $overrides);
    }

    public function test_aset_tanpa_ownership_tetap_owned_dan_perilaku_lama_utuh(): void
    {
        // Payload pra-P5 (tanpa field ownership) harus tetap diterima persis
        // seperti dulu — kompatibilitas mundur dari backfill default 'owned'.
        $response = $this->postJson('/api/assets/assets', $this->ownedPayload())->assertCreated();

        $this->assertSame('owned', $response->json('data.ownership'));
        $this->assertSame('900000000.00', $response->json('data.book_value'));
        $this->assertSame('900000000.00', $response->json('data.acquisition_cost'));
    }

    public function test_aset_sewa_dibuat_tanpa_harga_perolehan_dan_nilai_buku_bergaris(): void
    {
        $response = $this->postJson('/api/assets/assets', $this->rentedPayload())->assertCreated();

        $this->assertSame('rented', $response->json('data.ownership'));
        $this->assertNull($response->json('data.acquisition_cost'));
        // NULL, bukan "0.00": alat ini tidak pernah ada di neraca kita.
        $this->assertNull($response->json('data.book_value'));
        $this->assertNull($response->json('data.monthly_depreciation'));
        $this->assertSame('350000.00', $response->json('data.rental_rate'));
        $this->assertSame('per_jam', $response->json('data.rate_basis'));

        $asset = Asset::query()->findOrFail($response->json('data.id'));
        $this->assertNull($asset->acquisition_cost);
        $this->assertNull($asset->book_value);
        $this->assertSame(0.0, $asset->depreciableBase());
    }

    public function test_aset_sewa_menolak_kolom_beli_dan_mewajibkan_kolom_sewa(): void
    {
        // Harga perolehan pada alat sewa adalah angka karangan — ditolak.
        $this->postJson('/api/assets/assets', $this->rentedPayload([
            'acquisition_cost' => 900_000_000,
        ]))->assertUnprocessable()->assertJsonValidationErrors('acquisition_cost');

        // Kolom sewa wajib untuk rented.
        $this->postJson('/api/assets/assets', array_diff_key(
            $this->rentedPayload(),
            ['vendor_id' => true, 'rental_rate' => true, 'rate_basis' => true],
        ))->assertUnprocessable()->assertJsonValidationErrors(['vendor_id', 'rental_rate', 'rate_basis']);

        // Dan tarif sewa pada aset beli sama karangannya.
        $this->postJson('/api/assets/assets', $this->ownedPayload([
            'rental_rate' => 350_000,
        ]))->assertUnprocessable()->assertJsonValidationErrors('rental_rate');
    }

    public function test_aset_sewa_tidak_pernah_disusutkan_meski_kolom_penyusutan_terisi(): void
    {
        $owned = Asset::create([
            'name' => 'Genset 100 kVA',
            'category_id' => $this->category()->id,
            'acquisition_date' => '2026-01-10',
            'acquisition_cost' => 120_000_000,
            'salvage_value' => 0,
            'useful_life_months' => 60,
            'depreciation_start_date' => '2026-01-10',
            'book_value' => 120_000_000,
            'status' => 'available',
        ]);

        // Seseorang mengisi kolom penyusutan pada alat sewa — gate ownership
        // tetap menolak menyusutkannya, karena tidak ada biaya perolehan yang
        // pernah dikapitalisasi untuk disusutkan.
        $rented = Asset::create([
            'name' => 'Excavator sewa',
            'category_id' => $this->category()->id,
            'ownership' => 'rented',
            'vendor_id' => $this->rentalVendor()->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'acquisition_cost' => 900_000_000, // kolom longgar di skema; gate tidak boleh bergantung padanya
            'useful_life_months' => 60,
            'depreciation_start_date' => '2026-01-10',
            'status' => 'available',
        ]);

        $run = app(DepreciationService::class)->runForPeriod(2026, 2);

        $this->assertSame([$owned->id], $run->entries->pluck('asset_id')->all());
        $this->assertSame(0.0, (float) $rented->fresh()->accumulated_depreciation);
    }

    public function test_update_register_tidak_mengubah_nilai_buku_null_jadi_nol(): void
    {
        $id = $this->postJson('/api/assets/assets', $this->rentedPayload())
            ->assertCreated()->json('data.id');

        $this->putJson("/api/assets/assets/{$id}", ['name' => 'Excavator PC200 sewa (revisi)'])
            ->assertOk();

        $asset = Asset::query()->findOrFail($id);
        $this->assertSame('Excavator PC200 sewa (revisi)', $asset->name);
        // Register update menghitung ulang book_value dari komponen biayanya;
        // NULL - 0 bukan Rp 0, dan tidak boleh menjadi Rp 0.
        $this->assertNull($asset->book_value);
    }

    public function test_tarif_internal_pada_mobilisasi_alat_sewa_ditolak(): void
    {
        $projectId = Project::create([
            'name' => 'Proyek Gedung Dua', 'type' => 'construction',
        ])->id;

        $rented = Asset::create([
            'name' => 'Excavator sewa',
            'category_id' => $this->category()->id,
            'ownership' => 'rented',
            'vendor_id' => $this->rentalVendor()->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'useful_life_months' => 0,
            'status' => 'available',
        ]);

        // Biaya alat sewa masuk lewat tagihan AP vendornya (PPK); akrual
        // internal di atasnya membebankan alat yang sama dua kali.
        $response = $this->postJson("/api/assets/assets/{$rented->id}/deploy", [
            'project_id' => $projectId,
            'deployed_from' => '2026-07-01',
            'daily_rate_internal' => 2_500_000,
        ])->assertUnprocessable();

        $this->assertStringContainsString('dua kali', (string) $response->json('message'));

        // Tanpa tarif internal, mobilisasi alat sewa sah.
        $this->postJson("/api/assets/assets/{$rented->id}/deploy", [
            'project_id' => $projectId,
            'deployed_from' => '2026-07-01',
        ])->assertCreated();

        // Dan pintu belakangnya (update mobilisasi) tertutup juga.
        $deploymentId = $rented->fresh()->activeDeployment()->first()->id;
        $update = $this->putJson("/api/assets/deployments/{$deploymentId}", [
            'daily_rate_internal' => 2_500_000,
        ])->assertUnprocessable();

        $this->assertStringContainsString('dua kali', (string) $update->json('message'));
    }

    public function test_utilisasi_memuat_aset_sewa(): void
    {
        $projectId = Project::create([
            'name' => 'Proyek Gedung', 'type' => 'construction',
        ])->id;

        $rented = Asset::create([
            'name' => 'Excavator sewa',
            'category_id' => $this->category()->id,
            'ownership' => 'rented',
            'vendor_id' => $this->rentalVendor()->id,
            'rental_rate' => 350_000,
            'rate_basis' => 'per_jam',
            'useful_life_months' => 0,
            'status' => 'available',
        ]);

        app(DeploymentService::class)->deploy($rented, [
            'project_id' => $projectId,
            'deployed_from' => '2026-07-01',
        ]);

        $report = app(DeploymentService::class)->utilization(null, '2026-07-01', '2026-07-31');

        $row = collect($report['rows'])->firstWhere('asset_id', $rented->id);
        $this->assertNotNull($row, 'Aset sewa harus muncul di laporan utilisasi.');
        $this->assertSame('rented', $row['ownership']);
        $this->assertSame(31, $row['days_deployed']);
    }
}
