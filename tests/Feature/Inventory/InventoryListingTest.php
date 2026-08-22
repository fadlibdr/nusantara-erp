<?php

namespace Tests\Feature\Inventory;

use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;

/**
 * The listing() adoption seen from one adopting module: Inventory.
 *
 * The mechanism itself is proven in tests/Feature/Core/ListingConcernTest;
 * this file pins the per-controller wiring — that the whitelist is the
 * controller's declared one (not "any real column"), that the GRN date window
 * lands on receipt_date, and that one request can carry filters + dates + sort
 * together, because that combined request IS the CSV export walk the SPA
 * performs page by page.
 */
class InventoryListingTest extends ErpTestCase
{
    private function warehouse(): Warehouse
    {
        return Warehouse::query()->create([
            'code' => 'WH-PUSAT', 'name' => 'Gudang Pusat', 'is_active' => true,
        ]);
    }

    private function receipt(Warehouse $warehouse, string $code, string $date, string $status): GoodsReceipt
    {
        return GoodsReceipt::query()->create([
            'code' => $code,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => $date,
            'status' => $status,
        ]);
    }

    public function test_items_refuse_a_sort_on_a_real_column_the_controller_did_not_whitelist(): void
    {
        // barcode exists on inv_items; the refusal proves the declared
        // whitelist decides, not the table schema.
        $this->actingAs($this->adminUser())
            ->getJson('/api/inventory/items?sort=barcode')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_items_sort_by_a_whitelisted_column(): void
    {
        $this->warehouse(); // unrelated row, proves nothing leaks between lists
        $category = ItemCategory::query()->create(['code' => 'SIPIL', 'name' => 'Sipil']);
        foreach ([['ITM-1', 'Semen'], ['ITM-2', 'Besi'], ['ITM-3', 'Pasir']] as [$code, $name]) {
            Item::query()->create([
                'code' => $code, 'name' => $name, 'unit' => 'zak',
                'category_id' => $category->id, 'item_type' => 'material', 'is_active' => true,
            ]);
        }

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/inventory/items?sort=name&dir=asc')
            ->assertOk();

        $this->assertSame(['Besi', 'Pasir', 'Semen'], array_column($response->json('data'), 'name'));
    }

    public function test_goods_receipts_filter_receipt_date_inclusively(): void
    {
        $warehouse = $this->warehouse();
        $this->receipt($warehouse, 'GRN-001', '2026-11-01', StockDocumentStatus::Draft->value);
        $this->receipt($warehouse, 'GRN-002', '2026-11-30', StockDocumentStatus::Draft->value);
        $this->receipt($warehouse, 'GRN-003', '2026-12-01', StockDocumentStatus::Draft->value);

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/inventory/goods-receipts?date_from=2026-11-01&date_to=2026-11-30')
            ->assertOk();

        $codes = array_column($response->json('data'), 'code');
        sort($codes);
        $this->assertSame(['GRN-001', 'GRN-002'], $codes);
    }

    /**
     * The CSV export is a client-side walk of THIS endpoint carrying the
     * visible q/filters/dates/sort at per_page=200 until meta.last_page — so
     * one combined request answering correctly is what makes the exported
     * file honestly "the list, all pages".
     */
    public function test_one_request_carries_status_filter_date_window_and_sort_together_like_the_export_walk(): void
    {
        $warehouse = $this->warehouse();
        $this->receipt($warehouse, 'GRN-001', '2026-11-05', StockDocumentStatus::Draft->value);
        $this->receipt($warehouse, 'GRN-002', '2026-11-20', StockDocumentStatus::Draft->value);
        $this->receipt($warehouse, 'GRN-003', '2026-11-10', StockDocumentStatus::Posted->value); // filtered out by status
        $this->receipt($warehouse, 'GRN-004', '2026-12-02', StockDocumentStatus::Draft->value);  // outside the window

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/inventory/goods-receipts?status=draft&date_from=2026-11-01&date_to=2026-11-30&sort=receipt_date&dir=desc&per_page=200')
            ->assertOk();

        $this->assertSame(['GRN-002', 'GRN-001'], array_column($response->json('data'), 'code'));
        $this->assertSame(1, $response->json('meta.last_page'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_goods_receipts_meta_carries_what_list_js_reads(): void
    {
        $warehouse = $this->warehouse();
        $this->receipt($warehouse, 'GRN-001', '2026-11-05', StockDocumentStatus::Draft->value);

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/inventory/goods-receipts')
            ->assertOk();

        $this->assertSame(['code', 'receipt_date', 'delivery_note_no', 'status'], $response->json('meta.sortable'));
        $this->assertSame('receipt_date', $response->json('meta.date_column'));
    }
}
