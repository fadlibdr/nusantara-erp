<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * Jejak audit override prakualifikasi harus jujur — tiga cara ia bisa bohong.
 *
 * Kolom qualification_override_reason adalah SATU-SATUNYA jejak "PO ini
 * terbit ke vendor bermasalah karena X" yang dibaca auditor. Jejak itu bohong
 * dalam tiga arah:
 *
 * 1. submit() yang DITOLAK karena status (dokumen sudah submitted/approved)
 *    tetap mencap alasan override — PO yang tidak pernah lolos gate tercatat
 *    seolah-olah lolos lewat override.
 * 2. "Buat PO" dari PR menjatuhkan alasannya diam-diam — override yang
 *    benar-benar dipakai justru TIDAK meninggalkan jejak.
 * 3. create() menyimpan alasan yang diketik untuk vendor SEHAT — override
 *    yang tidak meng-override apa pun bukan override, dan mencatatnya
 *    menuduh vendor sehat bermasalah.
 */
class PoQualificationOverrideAuditTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function vendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'name' => 'PT Pemasok Baja Utama',
            'classification' => 'material',
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    private function po(Vendor $vendor, DocumentStatus $status): PurchaseOrder
    {
        return PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-01',
            'payment_term_days' => 30,
            'subtotal' => 1_000_000,
            'discount_amount' => 0,
            'dpp' => 1_000_000,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'total' => 1_000_000,
            'status' => $status,
        ]);
    }

    private function approvedPr(): PurchaseRequisition
    {
        $pr = PurchaseRequisition::query()->create([
            'purpose' => 'Besi beton lantai 3',
            'needed_date' => '2026-08-20',
            'status' => DocumentStatus::Approved,
        ]);

        $pr->items()->create([
            'line_no' => 1,
            'description' => 'Besi beton D16',
            'qty' => 100,
            'unit' => 'btg',
            'estimated_price' => 150_000,
        ]);

        return $pr;
    }

    /**
     * Lubang 1: submit yang ditolak karena STATUS tidak boleh mencap alasan.
     * PO ini sudah submitted; pengajuan ulang ditolak Approvable::submit —
     * tapi alasannya dulunya sudah telanjur tersimpan sebelum penolakan itu.
     */
    public function test_submit_yang_ditolak_karena_status_tidak_mencap_alasan_override(): void
    {
        $po = $this->po($this->vendor(['status' => 'inactive']), DocumentStatus::Submitted);

        $response = $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit", [
            'qualification_override_reason' => 'Vendor tunggal pemegang lisensi',
        ])->assertUnprocessable();

        $this->assertStringContainsString('Cannot submit', (string) $response->json('message'));

        $fresh = $po->fresh();
        $this->assertSame(DocumentStatus::Submitted, $fresh->status);
        $this->assertNull(
            $fresh->qualification_override_reason,
            'Pengajuan yang DITOLAK tidak boleh meninggalkan jejak override.',
        );
    }

    /**
     * Lubang 3: alasan yang diketik untuk vendor sehat bukan jejak override.
     */
    public function test_create_po_vendor_sehat_mengabaikan_alasan_yang_terlanjur_diketik(): void
    {
        $vendor = $this->vendor();

        $response = $this->postJson('/api/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-08',
            'qualification_override_reason' => 'Salah paham formulir — vendor ini sehat',
            'items' => [
                ['description' => 'Semen 50kg', 'qty' => 10, 'unit' => 'sak', 'unit_price' => 75_000],
            ],
        ])->assertCreated();

        $po = PurchaseOrder::query()->findOrFail($response->json('data.id'));
        $this->assertNull(
            $po->qualification_override_reason,
            'Override yang tidak meng-override apa pun harus tetap NULL.',
        );
    }

    public function test_create_po_vendor_terblokir_dengan_override_menyimpan_alasannya(): void
    {
        $vendor = $this->vendor(['status' => 'inactive']);

        $response = $this->postJson('/api/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-08',
            'qualification_override_reason' => 'Pembelian darurat — vendor tunggal pemegang lisensi',
            'items' => [
                ['description' => 'Semen 50kg', 'qty' => 10, 'unit' => 'sak', 'unit_price' => 75_000],
            ],
        ])->assertCreated();

        $po = PurchaseOrder::query()->findOrFail($response->json('data.id'));
        $this->assertSame(
            'Pembelian darurat — vendor tunggal pemegang lisensi',
            $po->qualification_override_reason,
        );
    }

    /**
     * Lubang 2: "Buat PO" dari PR memakai daftar atribut eksplisit yang
     * menjatuhkan alasannya — override yang dipakai justru tanpa jejak.
     */
    public function test_buat_po_dari_pr_dengan_override_menyimpan_alasannya(): void
    {
        $vendor = $this->vendor(['status' => 'inactive']);
        $pr = $this->approvedPr();

        $response = $this->postJson("/api/procurement/purchase-requisitions/{$pr->id}/create-po", [
            'vendor_id' => $vendor->id,
            'qualification_override_reason' => 'Stok kritis — hanya vendor ini yang sanggup kirim minggu ini',
        ])->assertCreated();

        $po = PurchaseOrder::query()->findOrFail($response->json('data.id'));
        $this->assertSame(
            'Stok kritis — hanya vendor ini yang sanggup kirim minggu ini',
            $po->qualification_override_reason,
        );
    }

    public function test_buat_po_dari_pr_vendor_sehat_mengabaikan_alasan_yang_terlanjur_diketik(): void
    {
        $vendor = $this->vendor();
        $pr = $this->approvedPr();

        $response = $this->postJson("/api/procurement/purchase-requisitions/{$pr->id}/create-po", [
            'vendor_id' => $vendor->id,
            'qualification_override_reason' => 'Salah paham formulir — vendor ini sehat',
        ])->assertCreated();

        $po = PurchaseOrder::query()->findOrFail($response->json('data.id'));
        $this->assertNull($po->qualification_override_reason);
    }
}
