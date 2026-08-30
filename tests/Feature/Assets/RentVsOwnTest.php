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
use Spatie\Permission\Models\Role;
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
        $this->assertNull($row['committed_rental_cost'], 'Tanpa jam terukur, biaya sewa belum bisa dihitung.');
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
        $this->assertSame(4_550_000.0, (float) $row['committed_rental_cost']);
        $this->assertTrue($row['comparable']);
    }

    /**
     * Gelombang perbaikan C1: seperti reports/outstanding milik Procurement,
     * layar ini bukan GET per dokumen — ia mengagregasi tarif sewa, vendor
     * rental, dan harga perolehan SELURUH aset sekaligus, maka dijaga
     * ast.view eksplisit, bukan sekadar auth:sanctum.
     */
    public function test_tanpa_ast_view_laporan_ditolak_403(): void
    {
        $hr = Role::findOrCreate('hr-uji', 'web');
        $hr->syncPermissions(['hr.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Staf HR',
            'email' => 'hr@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($hr);

        Sanctum::actingAs($user);

        $this->getJson('/api/assets/reports/rent-vs-own')->assertForbidden();
    }

    public function test_pemegang_ast_view_boleh_membaca_laporan(): void
    {
        $pengawas = Role::findOrCreate('pengawas-aset-uji', 'web');
        $pengawas->syncPermissions(['ast.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengawas Aset',
            'email' => 'pengawas-aset@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($pengawas);

        Sanctum::actingAs($user);

        $this->getJson('/api/assets/reports/rent-vs-own')->assertOk();
    }

    /**
     * Gelombang perbaikan C2: angka basis kalender dihitung sampai
     * rental_end — KOMITMEN penuh periode sewa, bukan biaya yang sudah
     * berjalan (sewa Jun–Des dibaca 30 Agustus tetap 7 bulan, bukan 3).
     * Kunci service, label layar, dan dokumentasi harus sama-sama berkata
     * "terikat"; label lama "Biaya sewa berjalan" mengklaim biaya berjalan
     * untuk angka yang bukan itu.
     */
    public function test_kunci_service_dan_label_layar_sama_sama_berkata_terikat(): void
    {
        $asset = $this->rentedAsset([
            'rate_basis' => 'per_bulan',
            'rental_rate' => 10_000_000,
            'rental_start' => '2026-06-01',
            'rental_end' => '2026-12-31',
        ]);

        $row = collect($this->getJson('/api/assets/reports/rent-vs-own')->assertOk()->json('data.rows'))
            ->firstWhere('asset_id', $asset->id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('committed_rental_cost', $row);
        $this->assertArrayNotHasKey('rental_cost', $row, 'Kunci lama mengklaim biaya berjalan; angkanya komitmen.');
        // Jun s/d Des = 7 bulan DIMULAI x Rp 10 jt — total periode terikat,
        // berapa pun tanggal layar ini dibuka.
        $this->assertSame(70_000_000.0, (float) $row['committed_rental_cost']);

        $spa = (string) file_get_contents(public_path('app/js/views/sewavsbeli.js'));
        $this->assertStringContainsString("text: 'Biaya sewa terikat'", $spa);
        $this->assertStringContainsString('row.committed_rental_cost', $spa);
        $this->assertStringNotContainsString('Biaya sewa berjalan', $spa,
            'Label lama menjanjikan biaya berjalan untuk angka komitmen s/d akhir sewa.');
        $this->assertStringNotContainsString('row.rental_cost', $spa);

        $panduan = (string) file_get_contents(base_path('docs/PANDUAN-PENGGUNA.md'));
        $this->assertStringNotContainsString('Biaya sewa berjalan', $panduan,
            'Panduan pengguna masih memakai label lama yang mengklaim biaya berjalan.');
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
