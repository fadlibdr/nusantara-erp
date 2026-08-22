<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * Temuan #34 tahap 2 — peringatan penyimpangan harga saat PO diajukan.
 *
 * Harga BOQ yang dibekukan saat kontrak dimenangkan adalah janji marjin
 * proyek; harga PO adalah kenyataannya. Selisih di atas ambang
 * (erp.procurement.price_warning_pct, default 10%) diajukan diam-diam sudah
 * memakan marjin tanpa satu pun mata melihatnya. PERINGATAN, bukan blokir:
 * eskalasi harga pasar itu nyata — pengaju harus mengakuinya secara eksplisit
 * (pola confirm-resubmit temuan #72), bukan dilarang membeli.
 */
class PoPriceDeviationWarnTest extends ErpTestCase
{
    private function boqItemPriced(float $unitPrice): BoqItem
    {
        $boq = Boq::query()->create([
            'title' => 'RAB Gedung Uji Harga',
            'status' => DocumentStatus::Approved,
        ]);

        $section = $boq->sections()->create([
            'section_no' => 'A',
            'name' => 'Pekerjaan persiapan',
        ]);

        return BoqItem::query()->create([
            'boq_id' => $boq->id,
            'section_id' => $section->id,
            'wbs_code' => 'A.1',
            'description' => 'Semen PCC 50 kg',
            'qty' => 100,
            'unit' => 'zak',
            'unit_price' => $unitPrice,
            'amount' => 100 * $unitPrice,
        ]);
    }

    /** Draft PO with a single line, optionally pinned to a BOQ line. */
    private function draftPo(float $unitPrice, ?int $boqItemId): PurchaseOrder
    {
        $vendor = Vendor::query()->create([
            'code' => 'VND-0001',
            'name' => 'PT Semen Distribusi Utama',
            'classification' => 'material',
            'is_pkp' => false,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);

        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-08',
            'payment_term_days' => 30,
            'subtotal' => 100 * $unitPrice,
            'discount_amount' => 0,
            'dpp' => 100 * $unitPrice,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'total' => 100 * $unitPrice,
            'status' => DocumentStatus::Draft,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'boq_item_id' => $boqItemId,
            'description' => 'Semen PCC 50 kg',
            'qty' => 100,
            'unit' => 'zak',
            'unit_price' => $unitPrice,
            'amount' => 100 * $unitPrice,
        ]);

        return $po;
    }

    private function submit(PurchaseOrder $po, array $payload = [])
    {
        return $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit", $payload);
    }

    public function test_a_price_above_the_threshold_is_refused_until_confirmed_and_names_the_numbers(): void
    {
        Sanctum::actingAs($this->adminUser());
        $boqLine = $this->boqItemPriced(100_000);
        $po = $this->draftPo(115_000, $boqLine->id); // +15% > ambang 10%

        $response = $this->submit($po)->assertStatus(422);

        // Kunci galat mengandung titik, jadi dibaca langsung dari array —
        // notasi titik json() akan menafsirkannya sebagai jalur bersarang.
        $message = (string) ($response->json('errors')['items.1.unit_price'][0] ?? '');
        $this->assertStringContainsString('Rp 115.000', $message);   // harga PO
        $this->assertStringContainsString('Rp 100.000', $message);   // harga BOQ beku
        $this->assertStringContainsString('15', $message);           // penyimpangan
        $this->assertStringContainsString('10', $message);           // ambang

        // Ditolak berarti tidak tersentuh: masih draf, tanpa jejak pengajuan.
        $po->refresh();
        $this->assertSame(DocumentStatus::Draft, $po->status);
        $this->assertSame(0, $po->approvals()->count());
    }

    public function test_the_confirm_flag_lets_the_acknowledged_deviation_through(): void
    {
        Sanctum::actingAs($this->adminUser());
        $boqLine = $this->boqItemPriced(100_000);
        $po = $this->draftPo(115_000, $boqLine->id);

        $this->submit($po, ['confirm_price_deviation' => true])->assertOk();

        $this->assertSame(DocumentStatus::Submitted, $po->fresh()->status);
    }

    public function test_a_price_within_the_threshold_submits_without_ceremony(): void
    {
        Sanctum::actingAs($this->adminUser());
        $boqLine = $this->boqItemPriced(100_000);
        $po = $this->draftPo(105_000, $boqLine->id); // +5%

        $this->submit($po)->assertOk();
        $this->assertSame(DocumentStatus::Submitted, $po->fresh()->status);
    }

    public function test_buying_below_the_boq_price_is_not_a_deviation(): void
    {
        Sanctum::actingAs($this->adminUser());
        $boqLine = $this->boqItemPriced(100_000);
        $po = $this->draftPo(60_000, $boqLine->id); // -40%: lebih murah, bukan bahaya marjin

        $this->submit($po)->assertOk();
    }

    public function test_a_line_without_a_boq_link_has_no_price_to_deviate_from(): void
    {
        Sanctum::actingAs($this->adminUser());
        $po = $this->draftPo(999_000, null);

        $this->submit($po)->assertOk();
    }

    public function test_the_threshold_is_policy_a_wider_one_lets_the_same_price_pass(): void
    {
        config()->set('erp.procurement.price_warning_pct', 20);

        Sanctum::actingAs($this->adminUser());
        $boqLine = $this->boqItemPriced(100_000);
        $po = $this->draftPo(115_000, $boqLine->id); // +15% < ambang 20%

        $this->submit($po)->assertOk();
    }
}
