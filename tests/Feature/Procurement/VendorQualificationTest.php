<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Exceptions\VendorNotQualifiedException;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorDocument;
use Modules\Procurement\Services\VendorQualificationService;
use Tests\ErpTestCase;

/**
 * Gate prakualifikasi vendor — temuan #35.
 *
 * Sebelum gate ini status vendor tidak pernah diperiksa di mana pun:
 * PoService::create/createFromPr dan SubcontractService hanya findOrFail,
 * jadi PO/SPK bisa terbit ke vendor nonaktif atau subkon yang SBU-nya
 * kedaluwarsa. Untuk subkon ini beririsan pajak: tarif PPh final PP 9/2022
 * (2,65% vs 4%) dipilih manual tanpa bukti sertifikat yang masih berlaku.
 *
 * Jalan daruratnya override BERALASAN, bukan pintu belakang tanpa jejak:
 * alasannya tersimpan di kolom qualification_override_reason PO.
 */
class VendorQualificationTest extends ErpTestCase
{
    private VendorQualificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(VendorQualificationService::class);
    }

    private function vendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'name' => 'PT Subkon Struktur Utama',
            'classification' => 'sipil',
            'is_subcontractor' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    private function document(Vendor $vendor, array $attributes = []): VendorDocument
    {
        return VendorDocument::query()->create(array_merge([
            'vendor_id' => $vendor->id,
            'doc_type' => 'sbu_konstruksi',
            'name' => 'SBU Konstruksi BG007',
            'is_mandatory' => true,
        ], $attributes));
    }

    private function draftPo(Vendor $vendor, float $total = 5_000_000): PurchaseOrder
    {
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-01',
            'payment_term_days' => 30,
            'subtotal' => $total,
            'discount_amount' => 0,
            'dpp' => $total,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'total' => $total,
            'status' => DocumentStatus::Draft,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'description' => 'Pekerjaan bekisting lantai 2',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $total,
            'amount' => $total,
        ]);

        return $po;
    }

    public function test_vendor_nonaktif_diblokir_dan_penyebabnya_disebut(): void
    {
        $vendor = $this->vendor(['status' => 'inactive']);

        $blockers = $this->service->blockers($vendor);
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('nonaktif', $blockers[0]);

        $this->expectException(VendorNotQualifiedException::class);
        $this->expectExceptionMessage('nonaktif');
        $this->service->assertQualified($vendor);
    }

    public function test_dokumen_wajib_kedaluwarsa_diblokir_yang_lain_tidak(): void
    {
        $this->travelTo('2026-08-08');

        $vendor = $this->vendor();

        // Kedaluwarsa kemarin DAN wajib: memblokir.
        $this->document($vendor, ['name' => 'SBU Konstruksi BG007', 'valid_until' => '2026-08-07']);
        // Masih sah PADA hari terakhirnya ("berlaku s/d"): tidak memblokir.
        $this->document($vendor, ['doc_type' => 'nib', 'name' => 'NIB', 'valid_until' => '2026-08-08']);
        // Kedaluwarsa tapi TIDAK wajib: catatan, bukan blokir.
        $this->document($vendor, ['doc_type' => 'siup', 'name' => 'SIUP lama', 'valid_until' => '2020-01-01', 'is_mandatory' => false]);
        // Wajib tanpa tanggal = tidak kedaluwarsa (pola hr_certificates).
        $this->document($vendor, ['doc_type' => 'npwp', 'name' => 'NPWP', 'valid_until' => null]);

        $blockers = $this->service->blockers($vendor);

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('SBU Konstruksi BG007', $blockers[0]);
        $this->assertStringContainsString('kedaluwarsa', $blockers[0]);
    }

    public function test_vendor_tanpa_register_tidak_diblokir(): void
    {
        // Register yang kosong adalah data yang belum diisi, bukan pelanggaran:
        // memblokir seluruh vendor pada hari pertama fitur ini dipasang berarti
        // setiap PO butuh override — gate yang langsung dimatikan orang.
        $this->assertSame([], $this->service->blockers($this->vendor()));
    }

    public function test_override_beralasan_meloloskan_dan_mengembalikan_daftar_blokir(): void
    {
        $vendor = $this->vendor(['status' => 'inactive']);

        $overridden = $this->service->assertQualified($vendor, 'Pembelian darurat — vendor tunggal pemegang lisensi');
        $this->assertCount(1, $overridden);

        // Alasan kosong / spasi bukan alasan.
        $this->expectException(VendorNotQualifiedException::class);
        $this->service->assertQualified($vendor, '   ');
    }

    public function test_submit_po_vendor_bermasalah_ditolak_422(): void
    {
        Sanctum::actingAs($this->adminUser());

        $vendor = $this->vendor(['status' => 'inactive']);
        $po = $this->draftPo($vendor);

        $response = $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit")
            ->assertUnprocessable();
        $this->assertStringContainsString('nonaktif', (string) $response->json('message'));

        $this->assertSame(DocumentStatus::Draft, $po->fresh()->status);
    }

    public function test_submit_po_dengan_override_lolos_dan_alasannya_tercatat(): void
    {
        Sanctum::actingAs($this->adminUser());

        $vendor = $this->vendor(['status' => 'inactive']);
        $po = $this->draftPo($vendor);

        $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit", [
            'qualification_override_reason' => 'Vendor tunggal pemegang lisensi principal',
        ])->assertOk();

        $fresh = $po->fresh();
        $this->assertSame(DocumentStatus::Submitted, $fresh->status);
        $this->assertSame('Vendor tunggal pemegang lisensi principal', $fresh->qualification_override_reason);
    }

    public function test_submit_po_vendor_sehat_tidak_terganggu(): void
    {
        Sanctum::actingAs($this->adminUser());

        $vendor = $this->vendor();
        $this->document($vendor, ['valid_until' => now()->addYear()->toDateString()]);
        $po = $this->draftPo($vendor);

        $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit")->assertOk();

        $fresh = $po->fresh();
        $this->assertSame(DocumentStatus::Submitted, $fresh->status);
        $this->assertNull($fresh->qualification_override_reason);
    }
}
