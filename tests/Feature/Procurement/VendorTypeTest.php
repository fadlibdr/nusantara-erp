<?php

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Enums\VendorDocumentType;
use Modules\Procurement\Enums\VendorType;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\VendorQualificationService;
use Tests\ErpTestCase;

/**
 * P4 — prc_vendors.vendor_type (supplier|subcontractor|mandor|rental).
 *
 * The boolean is_subcontractor can only say two of the four things a vendor
 * can be. The migration fills vendor_type FROM it (true => subcontractor,
 * false => supplier), keeps the old column working for its 18 existing
 * readers, and the Vendor model keeps the two in step from then on so neither
 * the SPA (which still sends is_subcontractor) nor new code (which reads
 * vendor_type) can watch the other drift.
 */
class VendorTypeTest extends ErpTestCase
{
    // ------------------------------------------------------------ the backfill

    public function test_backfill_mengisi_vendor_type_dari_is_subcontractor_dua_arah(): void
    {
        // Raw rows, bypassing the model hook — the state an upgraded
        // installation is in the moment the column lands: every row still at
        // the shipped default 'supplier', whatever the boolean says.
        $subkonId = DB::table('prc_vendors')->insertGetId([
            'code' => 'VND-9001', 'name' => 'CV Subkon Lama', 'is_subcontractor' => true, 'classification' => 'sipil',
            'vendor_type' => 'supplier', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierId = DB::table('prc_vendors')->insertGetId([
            'code' => 'VND-9002', 'name' => 'PT Material Lama', 'is_subcontractor' => false, 'classification' => 'material',
            'vendor_type' => 'supplier', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A mandor typed AFTER the column exists: re-running the backfill (a
        // re-deploy, a half-applied migrate) must never clobber a type the
        // boolean cannot express back to supplier.
        $mandorId = DB::table('prc_vendors')->insertGetId([
            'code' => 'VND-9003', 'name' => 'Mandor Harjo', 'is_subcontractor' => false, 'classification' => 'jasa',
            'vendor_type' => 'mandor', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up(); // idempotent: column exists, only the backfill runs

        $this->assertSame('subcontractor', DB::table('prc_vendors')->where('id', $subkonId)->value('vendor_type'));
        $this->assertSame('supplier', DB::table('prc_vendors')->where('id', $supplierId)->value('vendor_type'));
        $this->assertSame('mandor', DB::table('prc_vendors')->where('id', $mandorId)->value('vendor_type'));

        // The old column is KEPT, untouched, exactly as it was.
        $this->assertSame(1, (int) DB::table('prc_vendors')->where('id', $subkonId)->value('is_subcontractor'));
        $this->assertSame(0, (int) DB::table('prc_vendors')->where('id', $mandorId)->value('is_subcontractor'));
    }

    // ------------------------------------------------------------ the model sync

    public function test_vendor_baru_menurunkan_vendor_type_dari_is_subcontractor(): void
    {
        $subkon = Vendor::create(['name' => 'CV Subkon Baru', 'classification' => 'jasa', 'is_subcontractor' => true, 'status' => 'active']);
        $supplier = Vendor::create(['name' => 'PT Material Baru', 'classification' => 'jasa', 'is_subcontractor' => false, 'status' => 'active']);

        $this->assertSame(VendorType::Subcontractor, $subkon->vendor_type);
        $this->assertSame(VendorType::Supplier, $supplier->vendor_type);
    }

    public function test_vendor_type_menang_dan_menyinkronkan_is_subcontractor(): void
    {
        $mandor = Vendor::create(['name' => 'Mandor Pak Harjo', 'classification' => 'jasa', 'vendor_type' => 'mandor', 'status' => 'active']);
        $this->assertSame(VendorType::Mandor, $mandor->vendor_type);
        $this->assertFalse($mandor->is_subcontractor);

        $subkon = Vendor::create(['name' => 'CV Tipe Dulu', 'classification' => 'jasa', 'vendor_type' => 'subcontractor', 'status' => 'active']);
        $this->assertTrue($subkon->is_subcontractor);

        // Legacy write on a typed vendor: flipping the boolean TRUE is a claim
        // the type must honour...
        $mandor->update(['is_subcontractor' => true]);
        $this->assertSame(VendorType::Subcontractor, $mandor->fresh()->vendor_type);

        // ...while re-saving a mandor without touching either field moves nothing.
        $rental = Vendor::create(['name' => 'PT Sewa Alat', 'classification' => 'jasa', 'vendor_type' => 'rental', 'status' => 'active']);
        $rental->update(['name' => 'PT Sewa Alat Berat']);
        $this->assertSame(VendorType::Rental, $rental->fresh()->vendor_type);
        $this->assertFalse($rental->fresh()->is_subcontractor);
    }

    // ------------------------------------------------------------ the API

    public function test_api_menerima_vendor_type_dan_memfilternya(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/procurement/vendors', [
            'name' => 'Mandor Pak Harjo',
            'vendor_type' => 'mandor',
            'classification' => 'jasa',
            'status' => 'active',
        ])->assertCreated();

        $this->assertSame('mandor', $response->json('data.vendor_type'));
        $this->assertFalse((bool) $response->json('data.is_subcontractor'));

        Vendor::create(['name' => 'PT Material', 'classification' => 'jasa', 'is_subcontractor' => false, 'status' => 'active']);

        $list = $this->getJson('/api/procurement/vendors?vendor_type=mandor')->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('Mandor Pak Harjo', $list->json('data.0.name'));

        // The legacy filter the SPA still sends keeps answering.
        $legacy = $this->getJson('/api/procurement/vendors?is_subcontractor=0')->assertOk();
        $this->assertCount(2, $legacy->json('data'));
    }

    // ------------------------------------------------------------ qualification narrowing

    public function test_penyempitan_k3l_tetap_menyala_untuk_vendor_type_subcontractor(): void
    {
        $vendor = Vendor::create(['name' => 'CV Subkon Polos', 'classification' => 'jasa', 'vendor_type' => 'subcontractor', 'status' => 'active']);

        $blockers = app(VendorQualificationService::class)->blockers($vendor);

        $this->assertCount(2, $blockers);
        $this->assertStringContainsString('komitmen K3L', $blockers[0]);
        $this->assertStringContainsString('pakta integritas', $blockers[1]);
    }

    public function test_mandor_juga_wajib_k3l_dan_pakta_integritas(): void
    {
        // Keputusan P4 (roadmap diam): the P0-E narrowing exists because of
        // people sent to work on site, and that is a mandor's whole trade —
        // F/CVM adds the CV as his qualification sheet, it does not replace
        // the safety commitment. Documented in VendorQualificationService.
        $mandor = Vendor::create(['name' => 'Mandor Tanpa Dokumen', 'classification' => 'jasa', 'vendor_type' => 'mandor', 'status' => 'active']);

        $service = app(VendorQualificationService::class);

        $blockers = $service->blockers($mandor);
        $this->assertCount(2, $blockers);
        $this->assertStringContainsString('komitmen K3L', $blockers[0]);

        foreach (['k3l_commitment' => 'Komitmen K3L', 'pakta_integritas' => 'Pakta Integritas'] as $type => $name) {
            $mandor->documents()->create(['doc_type' => $type, 'name' => $name, 'is_mandatory' => true, 'valid_until' => null]);
        }

        $this->assertSame([], $service->blockers($mandor->fresh()));
    }

    public function test_vendor_material_murni_tidak_tersentuh_penyempitan(): void
    {
        $supplier = Vendor::create(['name' => 'PT Material Polos', 'classification' => 'jasa', 'vendor_type' => 'supplier', 'status' => 'active']);

        $this->assertSame([], app(VendorQualificationService::class)->blockers($supplier));
    }

    // ------------------------------------------------------------ CV Mandor document type

    public function test_cv_mandor_terdaftar_sebagai_jenis_dokumen_vendor(): void
    {
        $this->assertSame('cv_mandor', VendorDocumentType::CvMandor->value);
        $this->assertSame('CV Mandor', VendorDocumentType::CvMandor->label());

        Sanctum::actingAs($this->adminUser());

        $mandor = Vendor::create(['name' => 'Mandor Pak Harjo', 'classification' => 'jasa', 'vendor_type' => 'mandor', 'status' => 'active']);

        $this->postJson('/api/procurement/vendor-documents', [
            'vendor_id' => $mandor->id,
            'doc_type' => 'cv_mandor',
            'name' => 'CV Mandor Pak Harjo',
        ])->assertCreated();

        $this->assertDatabaseHas('prc_vendor_documents', [
            'vendor_id' => $mandor->id,
            'doc_type' => 'cv_mandor',
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** The migration instance, loaded off disk so its backfill can be re-run. */
    private function migration(): object
    {
        return require base_path(
            'Modules/Procurement/Database/Migrations/2026_08_29_000867_add_vendor_type_to_prc_vendors_table.php'
        );
    }
}
