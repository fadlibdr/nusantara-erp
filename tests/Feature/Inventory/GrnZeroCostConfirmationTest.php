<?php

namespace Tests\Feature\Inventory;

use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderItem;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Harga satuan 0 pada baris GRN tertaut PO butuh konfirmasi eksplisit.
 *
 * Sebelumnya harga 0 diterima diam-diam: StockService memposting nilai berapa
 * pun termasuk 0 (diperlakukan sebagai free-issue tanpa jurnal), sehingga satu
 * salah ketik membuat stok bernilai nol, HPP rata-rata gudang turun permanen,
 * dan issue berikutnya membebani proyek Rp 0. Free-issue sungguhan tetap sah —
 * asal dikonfirmasi lewat confirm_zero_cost, yang dikirim SPA setelah
 * confirmDialog menyebut barang-barangnya.
 */
class GrnZeroCostConfirmationTest extends ErpTestCase
{
    use InventoryFixtures;

    private Warehouse $pusat;

    private Item $semen;

    private Item $besi;

    private PurchaseOrder $po;

    private PurchaseOrderItem $poSemen;

    private PurchaseOrderItem $poBesi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->besi = $this->makeItem('Besi Beton D13', ['unit' => 'btg']);

        $this->po = $this->makeGoodsPurchaseOrder($this->pusat);
        $this->poSemen = $this->po->items()->create([
            'line_no' => 1,
            'item_id' => $this->semen->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 100,
            'unit' => 'zak',
            'unit_price' => 15_000,
            'amount' => 1_500_000,
        ]);
        $this->poBesi = $this->po->items()->create([
            'line_no' => 2,
            'item_id' => $this->besi->id,
            'description' => 'Besi Beton D13',
            'qty' => 5,
            'unit' => 'btg',
            'unit_price' => 200_000,
            'amount' => 1_000_000,
        ]);

        Sanctum::actingAs($this->adminUser());
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function payload(array $items, array $extra = []): array
    {
        return array_merge([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $this->po->id,
            'receipt_date' => '2026-03-10',
            'items' => $items,
        ], $extra);
    }

    public function test_baris_tertaut_po_berharga_0_ditolak_tanpa_konfirmasi(): void
    {
        $response = $this->postJson('/api/inventory/goods-receipts', $this->payload([
            ['item_id' => $this->semen->id, 'po_item_id' => $this->poSemen->id, 'qty' => 100, 'unit_cost' => 0],
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_cost']);
    }

    public function test_pesan_penolakan_menyebut_nama_barangnya(): void
    {
        // Pesan inilah yang dirangkai SPA menjadi confirmDialog — tanpa nama
        // barang, klerk mengonfirmasi "sesuatu" tanpa tahu baris yang mana.
        $errors = $this->postJson('/api/inventory/goods-receipts', $this->payload([
            ['item_id' => $this->semen->id, 'po_item_id' => $this->poSemen->id, 'qty' => 100, 'unit_cost' => 0],
        ]))->json('errors');

        $this->assertStringContainsString('Semen Gresik 40kg', (string) ($errors['items.0.unit_cost'][0] ?? ''));
    }

    public function test_dengan_confirm_zero_cost_baris_free_issue_diterima(): void
    {
        $response = $this->postJson('/api/inventory/goods-receipts', $this->payload(
            [['item_id' => $this->semen->id, 'po_item_id' => $this->poSemen->id, 'qty' => 100, 'unit_cost' => 0]],
            ['confirm_zero_cost' => true],
        ));

        $response->assertCreated();

        // Flag konfirmasi bukan kolom — tidak boleh ikut mengalir ke model.
        $this->assertSame(0.0, (float) $response->json('data.items.0.unit_cost'));
    }

    public function test_hanya_baris_nol_yang_ditandai_bukan_baris_sehat(): void
    {
        $response = $this->postJson('/api/inventory/goods-receipts', $this->payload([
            ['item_id' => $this->semen->id, 'po_item_id' => $this->poSemen->id, 'qty' => 100, 'unit_cost' => 15_000],
            ['item_id' => $this->besi->id, 'po_item_id' => $this->poBesi->id, 'qty' => 5, 'unit_cost' => 0],
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.unit_cost'])
            ->assertJsonMissingValidationErrors(['items.0.unit_cost']);
    }

    public function test_baris_lepas_tanpa_po_tetap_boleh_berharga_0(): void
    {
        // Penerimaan tanpa PO (stok awal, retur dari site) memang boleh 0 —
        // jalur free-receipt lama tidak berubah; yang dipagari hanya baris
        // yang mengaku memenuhi baris PO berharga.
        $this->postJson('/api/inventory/goods-receipts', [
            'warehouse_id' => $this->pusat->id,
            'receipt_date' => '2026-03-10',
            'items' => [
                ['item_id' => $this->semen->id, 'qty' => 100, 'unit_cost' => 0],
            ],
        ])->assertCreated();
    }

    public function test_jalur_update_dipagari_sama(): void
    {
        $grn = $this->makeGrn($this->pusat, [[$this->semen, 100, 15_000]], '2026-03-10', [
            'purchase_order_id' => $this->po->id,
        ]);

        $this->putJson("/api/inventory/goods-receipts/{$grn->id}", [
            'items' => [
                ['item_id' => $this->semen->id, 'po_item_id' => $this->poSemen->id, 'qty' => 100, 'unit_cost' => 0],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_cost']);

        $this->putJson("/api/inventory/goods-receipts/{$grn->id}", [
            'confirm_zero_cost' => true,
            'items' => [
                ['item_id' => $this->semen->id, 'po_item_id' => $this->poSemen->id, 'qty' => 100, 'unit_cost' => 0],
            ],
        ])->assertOk();
    }
}
