<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\DocumentImportService;
use Modules\Inventory\Enums\AdjustmentReason;
use Modules\Inventory\Models\StockAdjustment;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * P8 kriteria #10 / D12 — kartu stok warisan masuk sebagai stock opname (ADJ)
 * DRAFT: baris mutasi kartu lama tidak pernah diputar ulang (forward-only),
 * hanya saldo penutupnya yang mendarat sebagai qty hitung, dan TIDAK ada
 * jurnal, mutasi ledger, atau perubahan saldo apa pun sampai manusia
 * memutuskan approve-and-post lewat layarnya sendiri.
 *
 * Fixture: tests/fixtures/import-warisan/kartu-stok.xlsx — pemetaan kolom di
 * docs/IMPOR-WARISAN.md §2.
 */
class StockCardImportTest extends ErpTestCase
{
    use InventoryFixtures;

    private function imports(): DocumentImportService
    {
        return app(DocumentImportService::class);
    }

    private function fixture(): string
    {
        return base64_encode((string) file_get_contents(
            base_path('tests/fixtures/import-warisan/kartu-stok.xlsx'),
        ));
    }

    public function test_the_legacy_stock_card_lands_as_a_draft_opname_and_moves_nothing(): void
    {
        $this->makeWarehouse('WH-01');
        $this->makeItem('Semen 40kg', ['code' => 'ITM-01']);
        $this->makeItem('Pasir beton', ['code' => 'ITM-02', 'unit' => 'm3']);

        $journals = DB::table('fin_journals')->count();
        $ledger = DB::table('inv_stock_ledger')->count();
        $balances = DB::table('inv_stock_balances')->count();

        $result = $this->imports()->commit('stock-cards', 'kartu-stok.xlsx', $this->fixture());

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['created']);

        $adjustment = StockAdjustment::query()->with('items.item')->sole();
        $this->assertSame(DocumentStatus::Draft, $adjustment->status);
        $this->assertSame(AdjustmentReason::Opname, $adjustment->reason);
        $this->assertSame('2026-06-30', $adjustment->adjustment_date->toDateString());
        $this->assertSame('kartu-stok.xlsx', $adjustment->import_source);

        $this->assertSame(['ITM-01', 'ITM-02'], $adjustment->items->pluck('item.code')->all());
        $this->assertSame(150.0, (float) $adjustment->items[0]->counted_qty);
        // 80,5 dengan koma desimal Indonesia terbaca 80,5 — bukan 805.
        $this->assertSame(80.5, (float) $adjustment->items[1]->counted_qty);

        // FORWARD-ONLY: draft tidak menyentuh buku mana pun.
        $this->assertSame($journals, DB::table('fin_journals')->count());
        $this->assertSame($ledger, DB::table('inv_stock_ledger')->count());
        $this->assertSame($balances, DB::table('inv_stock_balances')->count());
    }
}
