<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;
use Tests\ErpTestCase;

/**
 * Temuan #34 tahap 1 — tautan anggaran (boq_item_id) tidak boleh mati di
 * perbatasan PR → PO.
 *
 * Baris PR sudah lama membawa boq_item_id, tetapi baris PO tidak punya
 * kolomnya: begitu PR menjadi PO, harga yang dinegosiasikan kehilangan alamat
 * anggarannya, dan tidak ada mesin mana pun yang bisa menjawab "baris PO ini
 * membeli baris BOQ yang mana?". Kendali harga (tahap 2) dan gate anggaran
 * (#33) menumpang pada tautan ini — kalau tautan putus di sini, keduanya buta.
 */
class PoBoqLinkTest extends ErpTestCase
{
    private function vendor(): Vendor
    {
        return Vendor::query()->create([
            'code' => 'VND-0001',
            'name' => 'PT Semen Distribusi Utama',
            'classification' => 'material',
            'is_pkp' => false,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    private function approvedPrWithBoqLine(?int $boqItemId): PurchaseRequisition
    {
        /** @var PurchaseRequisition $pr */
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-08-20',
            'status' => DocumentStatus::Approved,
        ]);

        $pr->items()->create([
            'line_no' => 1,
            'description' => 'Semen PCC 50 kg',
            'qty' => 100,
            'unit' => 'zak',
            'estimated_price' => 75000,
            'boq_item_id' => $boqItemId,
        ]);

        return $pr;
    }

    public function test_create_from_pr_carries_the_boq_link_onto_the_po_line(): void
    {
        $pr = $this->approvedPrWithBoqLine(4321);

        $po = app(PoService::class)->createFromPr($pr, ['vendor_id' => $this->vendor()->id]);

        $this->assertSame(4321, (int) $po->items->first()->boq_item_id);
    }

    public function test_a_pr_line_without_a_boq_link_stays_unlinked_on_the_po(): void
    {
        $pr = $this->approvedPrWithBoqLine(null);

        $po = app(PoService::class)->createFromPr($pr, ['vendor_id' => $this->vendor()->id]);

        $this->assertNull($po->items->first()->boq_item_id);
    }

    /**
     * Ubah biasa MELUCUTI gerbang harga: form generik tidak membawa
     * boq_item_id, sync hapus-buat-ulang menulis baris tanpa tautan, dan
     * peringatan simpangan harga tidak pernah menyala lagi di PO yang justru
     * sedang diedit harganya.
     */
    public function test_an_ubah_without_the_key_keeps_the_stored_boq_link(): void
    {
        Sanctum::actingAs($this->adminUser());

        $pr = $this->approvedPrWithBoqLine(4321);
        $po = app(PoService::class)->createFromPr($pr, ['vendor_id' => $this->vendor()->id]);
        $line = $po->items->first();

        $this->putJson("/api/procurement/purchase-orders/{$po->id}", [
            'items' => [[
                'id' => $line->id,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'unit' => $line->unit,
                'unit_price' => 99999,
            ]],
        ])->assertOk();

        $this->assertSame(4321, (int) $po->refresh()->items->first()->boq_item_id,
            'Tautan BOQ tersimpan tidak boleh hilang hanya karena payload tidak membawanya.');
    }

    public function test_a_manual_po_line_accepts_and_returns_its_boq_link(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/procurement/purchase-orders', [
            'vendor_id' => $this->vendor()->id,
            'order_date' => '2026-08-08',
            'items' => [[
                'description' => 'Kabel NYY 4x10',
                'qty' => 50,
                'unit' => 'm',
                'unit_price' => 125000,
                'boq_item_id' => 777,
            ]],
        ])->assertStatus(201);

        $this->assertSame(777, (int) $response->json('data.items.0.boq_item_id'));

        $this->assertDatabaseHas('prc_purchase_order_items', [
            'description' => 'Kabel NYY 4x10',
            'boq_item_id' => 777,
        ]);
    }
}
