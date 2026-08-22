<?php

namespace Tests\Feature\Estimation;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Services\AhspService;
use Modules\Estimation\Services\BoqService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Riwayat harga satuan — the trend behind one cached number.
 *
 * inv_items carries one avg_cost and one last_price, and est_ahsp one
 * overwritten unit_price; the actual price of every purchase, meanwhile, has
 * been sitting in prc_purchase_order_items and the GRN valuations all along,
 * read by nobody. An estimator pricing besi beton for the next bid could not
 * see that the last three POs paid 12.500 → 13.100 → 13.750 per kg — margin
 * mis-costed in a fluctuating market, exactly the audit's Temuan 17.
 *
 * The endpoint reads Procurement's and Inventory's TABLES from an Estimation
 * service — the module seam the resep itself prescribes — and invents no new
 * table: the history was always there.
 *
 * The second half of the finding, the AHSP snapshot at BOQ approval, turned
 * out to be ALREADY BUILT: est_boq_items copies unit_price at the moment the
 * line is added and nothing re-reads it (AhspService::inUseWarnings documents
 * this as deliberate). The last test pins that behaviour so a refactor cannot
 * quietly reintroduce the overwrite the audit feared.
 */
class PriceHistoryTest extends ErpTestCase
{
    private ?User $admin = null;

    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->itemId = $this->makeItem();
    }

    // -------------------------------------------------------------- fixtures

    private function admin(): User
    {
        return $this->admin ??= $this->adminUser();
    }

    /** ITM-0002 as the demo knows it: besi beton, avg 12.400, last 13.750. */
    private function makeItem(): int
    {
        $categoryId = DB::table('inv_item_categories')->insertGetId([
            'code' => 'CAT-01',
            'name' => 'Material Struktur',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('inv_items')->insertGetId([
            'code' => 'ITM-0002',
            'name' => 'Besi beton ulir D16',
            'category_id' => $categoryId,
            'unit' => 'kg',
            'avg_cost' => 12_400,
            'last_price' => 13_750,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function vendor(string $code = 'VND-0001', string $name = 'PT Baja Prima'): int
    {
        return (int) DB::table('prc_vendors')->insertGetId([
            'code' => $code,
            'name' => $name,
            'classification' => 'material',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function purchaseOrder(int $vendorId, string $code, string $date, string $status, float $price, float $qty = 1000): int
    {
        $poId = (int) DB::table('prc_purchase_orders')->insertGetId([
            'code' => $code,
            'vendor_id' => $vendorId,
            'order_date' => $date,
            'subtotal' => $price * $qty,
            'dpp' => $price * $qty,
            'total' => $price * $qty,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prc_purchase_order_items')->insert([
            'purchase_order_id' => $poId,
            'line_no' => 1,
            'item_id' => $this->itemId,
            'description' => 'Besi beton ulir D16',
            'qty' => $qty,
            'unit' => 'kg',
            'unit_price' => $price,
            'amount' => $price * $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $poId;
    }

    private function goodsReceipt(string $code, string $date, string $status, float $unitCost, float $qty = 1000, ?int $vendorId = null): void
    {
        $warehouseId = DB::table('inv_warehouses')->where('code', 'WH-PUSAT')->value('id')
            ?? DB::table('inv_warehouses')->insertGetId([
                'code' => 'WH-PUSAT',
                'name' => 'Gudang Pusat',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $grnId = DB::table('inv_goods_receipts')->insertGetId([
            'code' => $code,
            'warehouse_id' => $warehouseId,
            'vendor_id' => $vendorId,
            'receipt_date' => $date,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inv_goods_receipt_items')->insert([
            'goods_receipt_id' => $grnId,
            'item_id' => $this->itemId,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => $unitCost * $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function history(array $params = []): array
    {
        return $this->actingAs($this->admin())
            ->getJson('/api/estimation/price-history?'.http_build_query(array_merge(['item_id' => $this->itemId], $params)))
            ->assertOk()
            ->json('data');
    }

    // ------------------------------------------------------------- the trend

    public function test_the_trend_merges_po_prices_and_grn_valuations_in_date_order(): void
    {
        $vendor = $this->vendor();
        $this->purchaseOrder($vendor, 'PO/2026/III/0011', '2026-03-10', 'approved', 12_500);
        $this->purchaseOrder($vendor, 'PO/2026/VI/0027', '2026-06-18', 'closed', 13_750);
        $this->goodsReceipt('GRN/2026/III/0008', '2026-03-17', 'posted', 12_650, 1000, $vendor);

        $data = $this->history();

        $this->assertSame(
            [
                ['2026-03-10', 'po', 'PO/2026/III/0011', 12_500.0],
                ['2026-03-17', 'grn', 'GRN/2026/III/0008', 12_650.0],
                ['2026-06-18', 'po', 'PO/2026/VI/0027', 13_750.0],
            ],
            array_map(
                fn (array $row): array => [$row['date'], $row['source'], $row['code'], (float) $row['unit_price']],
                $data['series'],
            ),
        );

        $this->assertSame('PT Baja Prima', $data['series'][0]['vendor_name']);
        $this->assertSame('ITM-0002', $data['item']['code']);
    }

    /**
     * A draft PO is a price somebody TYPED, not a price anybody agreed to pay —
     * and a rejected one is a price somebody refused. Only approved (and later
     * closed) orders are purchase history.
     */
    public function test_unapproved_po_prices_never_enter_the_history(): void
    {
        $vendor = $this->vendor();
        $this->purchaseOrder($vendor, 'PO/2026/VII/0031', '2026-07-01', 'draft', 20_000);
        $this->purchaseOrder($vendor, 'PO/2026/VII/0032', '2026-07-02', 'submitted', 21_000);
        $this->purchaseOrder($vendor, 'PO/2026/VII/0033', '2026-07-03', 'rejected', 22_000);
        $this->purchaseOrder($vendor, 'PO/2026/VII/0034', '2026-07-04', 'approved', 13_100);

        $series = $this->history()['series'];

        $this->assertCount(1, $series);
        $this->assertSame('PO/2026/VII/0034', $series[0]['code']);
    }

    public function test_a_draft_grn_is_not_a_valuation_yet(): void
    {
        $this->goodsReceipt('GRN/2026/VII/0009', '2026-07-10', 'draft', 13_000);
        $this->goodsReceipt('GRN/2026/VII/0010', '2026-07-11', 'posted', 13_200);

        $series = $this->history()['series'];

        $this->assertCount(1, $series);
        $this->assertSame('GRN/2026/VII/0010', $series[0]['code']);
        // A GRN without a vendor names nobody rather than crashing the join.
        $this->assertNull($series[0]['vendor_name']);
    }

    public function test_the_summary_quotes_the_range_and_the_item_masters_cached_numbers(): void
    {
        $vendor = $this->vendor();
        $this->purchaseOrder($vendor, 'PO/2026/III/0011', '2026-03-10', 'approved', 12_500, 2000);
        $this->purchaseOrder($vendor, 'PO/2026/VI/0027', '2026-06-18', 'approved', 13_750, 1000);

        $data = $this->history();

        $this->assertSame(2, $data['summary']['count']);
        $this->assertEquals(12_500, $data['summary']['min_price']);
        $this->assertEquals(13_750, $data['summary']['max_price']);
        $this->assertEquals(13_750, $data['summary']['latest_price']);
        $this->assertSame('2026-06-18', $data['summary']['latest_date']);
        // Weighted by quantity: (12.500×2000 + 13.750×1000) / 3000 — NOT the
        // midpoint 13.125, which a small trial order would drag as far as a
        // 2.000-ton one.
        $this->assertEquals(12_916.67, $data['summary']['weighted_avg_price']);
        $this->assertEquals(12_400, $data['item']['avg_cost']);
        $this->assertEquals(13_750, $data['item']['last_price']);
    }

    public function test_the_date_range_bounds_the_series(): void
    {
        $vendor = $this->vendor();
        $this->purchaseOrder($vendor, 'PO/2025/XI/0090', '2025-11-05', 'approved', 11_900);
        $this->purchaseOrder($vendor, 'PO/2026/VI/0027', '2026-06-18', 'approved', 13_750);

        $series = $this->history(['date_from' => '2026-01-01', 'date_to' => '2026-12-31'])['series'];

        $this->assertCount(1, $series);
        $this->assertSame('PO/2026/VI/0027', $series[0]['code']);
    }

    public function test_an_item_never_bought_answers_an_empty_series_not_an_error(): void
    {
        $data = $this->history();

        $this->assertSame([], $data['series']);
        $this->assertSame(0, $data['summary']['count']);
        $this->assertNull($data['summary']['latest_price']);
    }

    /**
     * est.view, the same gate as every other Estimation read: the payload is
     * the company's purchase prices per vendor, which is negotiation material.
     */
    public function test_the_endpoint_is_refused_without_the_estimation_view_permission(): void
    {
        $role = Role::findOrCreate('teknisi', 'web');
        $role->syncPermissions(['svc.view', 'inv.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $teknisi */
        $teknisi = User::query()->create([
            'name' => 'Teknisi Lapangan',
            'email' => 'teknisi@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $teknisi->assignRole($role);

        $this->actingAs($teknisi)
            ->getJson('/api/estimation/price-history?item_id='.$this->itemId)
            ->assertForbidden();
    }

    // ------------------------------------- the snapshot half, verified honest

    /**
     * Temuan 17's other half asked for "snapshot harga AHSP saat BOQ disetujui"
     * — and it exists already: est_boq_items copies unit_price when the line is
     * added, update() refuses a non-editable BOQ, and recalcUnitPrice touches
     * only est_ahsp. This test PINS that, because the audit's fear is precisely
     * a future "improvement" that re-reads the analysis into approved lines.
     */
    public function test_repricing_an_ahsp_does_not_move_the_price_frozen_in_an_approved_boq(): void
    {
        $ahspService = app(AhspService::class);
        $boqService = app(BoqService::class);

        $ahsp = $ahspService->create([
            'code' => 'A.4.3.1.10',
            'name' => 'Pembesian 10 kg besi ulir',
            'unit' => 'kg',
            'category' => 'sipil',
            'overhead_pct' => 10,
            'components' => [
                ['component_type' => 'material', 'name' => 'Besi beton ulir D16', 'item_id' => $this->itemId, 'unit' => 'kg', 'coefficient' => 1.05, 'unit_price' => 12_500],
            ],
        ]);
        $frozen = (float) $ahsp->unit_price; // 1,05 × 12.500 × 1,1 = 14.437,50

        $boq = $boqService->create([
            'title' => 'RAB Struktur — Graha Sentosa',
            'sections' => [[
                'section_no' => 'B',
                'name' => 'Pekerjaan Struktur',
                'items' => [
                    ['wbs_code' => 'B.3', 'description' => 'Pembesian besi beton ulir', 'ahsp_id' => $ahsp->id, 'qty' => 948_000],
                ],
            ]],
        ]);
        $boq->submit();
        $boq->approve($this->admin());

        // Steel moves: the analysis is re-priced for the NEXT bid.
        $ahspService->update($ahsp, [
            'components' => [
                ['component_type' => 'material', 'name' => 'Besi beton ulir D16', 'item_id' => $this->itemId, 'unit' => 'kg', 'coefficient' => 1.05, 'unit_price' => 13_750],
            ],
        ]);

        $this->assertEquals(15_881.25, (float) $ahsp->refresh()->unit_price, 'the analysis itself moves');
        $this->assertEquals(
            $frozen,
            (float) BoqItem::query()->where('boq_id', $boq->id)->sole()->unit_price,
            'the approved BOQ line must keep the price it was signed at',
        );
    }
}
