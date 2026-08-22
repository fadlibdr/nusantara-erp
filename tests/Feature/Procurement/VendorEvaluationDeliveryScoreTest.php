<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Notification;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorDocument;
use Modules\Procurement\Models\VendorEvaluation;
use Modules\Procurement\Services\VendorEvaluationService;
use Tests\ErpTestCase;

/**
 * Menutup loop evaluasi vendor — temuan #68.
 *
 * Sebelum ini skor ketepatan kirim diisi manual padahal buktinya sudah
 * tercatat (GRN vs expected_date PO), rating tidak tampil saat memilih
 * vendor, dan tidak ada apa pun yang meminta evaluasi saat PO bernilai besar
 * ditutup — demo: 1 evaluasi untuk 5 vendor, dan vendor yang sering telat
 * tetap terpilih tanpa hambatan.
 */
class VendorEvaluationDeliveryScoreTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function vendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'name' => 'PT Baja Nusantara',
            'classification' => 'material',
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    private function po(Vendor $vendor, ?string $expectedDate, float $total = 15_000_000, string $status = 'approved'): PurchaseOrder
    {
        return PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-03-01',
            'expected_date' => $expectedDate,
            'payment_term_days' => 30,
            'subtotal' => $total,
            'discount_amount' => 0,
            'dpp' => $total,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'total' => $total,
            'status' => DocumentStatus::from($status),
        ]);
    }

    private function grn(PurchaseOrder $po, string $receiptDate, string $status = 'posted'): GoodsReceipt
    {
        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'WH-UJI'],
            ['name' => 'Gudang Uji', 'is_active' => true],
        );

        return GoodsReceipt::query()->create([
            'warehouse_id' => $warehouse->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => $receiptDate,
            'status' => $status,
        ]);
    }

    public function test_skor_kirim_diturunkan_dari_grn_vs_tanggal_janji(): void
    {
        $vendor = $this->vendor();
        $po = $this->po($vendor, '2026-03-10');

        $this->grn($po, '2026-03-08');            // tepat waktu
        $this->grn($po, '2026-03-10');            // hari-H masih tepat waktu
        $this->grn($po, '2026-03-25');            // telat
        $this->grn($po, '2026-03-05', 'draft');   // draf belum jadi bukti kiriman
        $this->grn($this->po($vendor, null), '2026-04-01'); // PO tanpa janji tak bisa dinilai

        $snapshot = app(VendorEvaluationService::class)->deliverySnapshot($vendor->id);

        $this->assertSame(3, $snapshot['considered']);
        $this->assertSame(2, $snapshot['on_time']);
        $this->assertSame(1, $snapshot['late']);
        $this->assertEqualsWithDelta(66.7, $snapshot['on_time_pct'], 0.1);
        $this->assertSame(2, $snapshot['suggested_score']);
    }

    public function test_evaluasi_tanpa_skor_kirim_terisi_otomatis_dengan_jejak(): void
    {
        $vendor = $this->vendor();
        $po = $this->po($vendor, '2026-03-10');
        $this->grn($po, '2026-03-08');

        $created = $this->postJson('/api/procurement/vendor-evaluations', [
            'vendor_id' => $vendor->id,
            'period' => '2026-S1',
            'quality_score' => 4,
            'price_score' => 3,
            'service_score' => 4,
        ])->assertCreated()->json('data');

        $this->assertSame(5, $created['delivery_score']); // 1/1 tepat waktu
        $this->assertStringContainsString('otomatis', (string) $created['notes']);
        $this->assertEqualsWithDelta(4.0, (float) $created['total_score'], 0.01);
    }

    public function test_tanpa_riwayat_grn_skor_kirim_tetap_wajib_manual(): void
    {
        $response = $this->postJson('/api/procurement/vendor-evaluations', [
            'vendor_id' => $this->vendor()->id,
            'period' => '2026-S1',
            'quality_score' => 4,
            'price_score' => 3,
            'service_score' => 4,
        ])->assertUnprocessable();

        $this->assertArrayHasKey('delivery_score', $response->json('errors'));
    }

    public function test_endpoint_saran_skor_kirim(): void
    {
        $vendor = $this->vendor();
        $po = $this->po($vendor, '2026-03-10');
        $this->grn($po, '2026-03-20');

        $data = $this->getJson("/api/procurement/vendor-evaluations/delivery-suggestion?vendor_id={$vendor->id}")
            ->assertOk()->json('data');

        $this->assertSame(1, $data['considered']);
        $this->assertSame(1, $data['suggested_score']);

        // Vendor tanpa riwayat: jawaban jujur "tidak ada dasar", bukan angka karangan.
        $kosong = $this->getJson('/api/procurement/vendor-evaluations/delivery-suggestion?vendor_id='.$this->vendor(['name' => 'PT Baru'])->id)
            ->assertOk()->json('data');
        $this->assertNull($kosong);
    }

    public function test_rating_dan_dokumen_kedaluwarsa_tampil_di_label_picker(): void
    {
        $this->travelTo('2026-08-08');

        $dinilai = $this->vendor(['name' => 'PT Dinilai']);
        VendorEvaluation::query()->create([
            'vendor_id' => $dinilai->id,
            'period' => '2026-S1',
            'quality_score' => 4, 'delivery_score' => 4, 'price_score' => 4, 'service_score' => 4,
            'total_score' => 4.0,
        ]);
        $dinilai->forceFill(['rating' => 4.0])->save();

        $bermasalah = $this->vendor(['name' => 'PT Kedaluwarsa']);
        VendorDocument::query()->create([
            'vendor_id' => $bermasalah->id,
            'doc_type' => 'sbu_konstruksi',
            'name' => 'SBU Konstruksi',
            'valid_until' => '2026-01-01',
            'is_mandatory' => true,
        ]);

        $rows = collect($this->getJson('/api/procurement/vendors')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertStringContainsString('★ 4,0', $rows[$dinilai->id]['picker_label']);
        $this->assertStringContainsString('dok. wajib kedaluwarsa', $rows[$bermasalah->id]['picker_label']);
    }

    public function test_tutup_po_besar_meminta_evaluasi_lewat_pesan_dan_notifikasi(): void
    {
        config(['erp.procurement.evaluation_threshold' => 10_000_000]);

        $vendor = $this->vendor();
        $po = $this->po($vendor, null, 15_000_000);

        $message = (string) $this->postJson("/api/procurement/purchase-orders/{$po->id}/close")
            ->assertOk()->json('message');

        $this->assertStringContainsString('valuasi', $message); // Evaluasi/evaluasi
        $this->assertTrue(
            Notification::query()->where('title', 'Evaluasi vendor diperlukan')->exists(),
            'Penutupan PO besar harus meninggalkan notifikasi bagi pemegang prc.create.',
        );
    }

    public function test_tutup_po_kecil_atau_vendor_baru_dievaluasi_tidak_diganggu(): void
    {
        config(['erp.procurement.evaluation_threshold' => 100_000_000]);

        $vendor = $this->vendor();

        // Di bawah ambang: tidak ada prompt.
        $kecil = $this->po($vendor, null, 5_000_000);
        $this->postJson("/api/procurement/purchase-orders/{$kecil->id}/close")->assertOk();
        $this->assertFalse(Notification::query()->where('title', 'Evaluasi vendor diperlukan')->exists());

        // Di atas ambang tetapi baru saja dievaluasi: prompt hanya mengulang
        // pekerjaan yang sudah dilakukan.
        config(['erp.procurement.evaluation_threshold' => 10_000_000]);
        VendorEvaluation::query()->create([
            'vendor_id' => $vendor->id,
            'period' => '2026-S1',
            'quality_score' => 4, 'delivery_score' => 4, 'price_score' => 4, 'service_score' => 4,
            'total_score' => 4.0,
        ]);

        $besar = $this->po($vendor, null, 15_000_000);
        $this->postJson("/api/procurement/purchase-orders/{$besar->id}/close")->assertOk();
        $this->assertFalse(Notification::query()->where('title', 'Evaluasi vendor diperlukan')->exists());
    }
}
