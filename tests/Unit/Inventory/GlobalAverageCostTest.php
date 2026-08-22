<?php

namespace Tests\Unit\Inventory;

use Modules\Inventory\Models\StockBalance;
use Tests\ErpTestCase;

/**
 * StockService::refreshGlobalAvgCost() — the item-level (all warehouses)
 * weighted average that valuation reports and the adjustment fallback use.
 */
class GlobalAverageCostTest extends ErpTestCase
{
    use InventoryFixtures;

    public function test_the_global_average_is_weighted_across_warehouses(): void
    {
        $pusat = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $semen = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($pusat, $semen, 100, 15000);
        $this->receiveStock($site, $semen, 20, 21000);

        // (100 * 15.000 + 20 * 21.000) / (100 + 20)
        //   = (1.500.000 + 420.000) / 120 = 1.920.000 / 120 = 16.000
        $this->assertSame(16000.0, (float) $semen->fresh()->avg_cost);

        // Each warehouse keeps its own average; the global one is derived.
        $this->assertSame(15000.0, $this->balanceAvg($pusat, $semen));
        $this->assertSame(21000.0, $this->balanceAvg($site, $semen));
    }

    public function test_the_global_average_is_rounded_to_two_decimals(): void
    {
        $pusat = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $semen = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($pusat, $semen, 3, 10000);
        $this->receiveStock($site, $semen, 4, 12500);

        // (3 * 10.000 + 4 * 12.500) / 7 = 80.000 / 7 = 11.428,571428...
        // rounded to 2 dp = 11.428,57
        $this->assertSame(11428.57, (float) $semen->fresh()->avg_cost);
    }

    public function test_warehouses_without_a_positive_balance_are_excluded(): void
    {
        $pusat = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $rusak = $this->makeWarehouse('WH-RUSAK');
        $kosong = $this->makeWarehouse('WH-KOSONG');
        $semen = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($pusat, $semen, 100, 15000);
        $this->receiveStock($site, $semen, 20, 21000);

        // Two balances that must not enter the weighting: a zero row (average
        // retained from an emptied warehouse) and a corrupt negative row.
        StockBalance::create([
            'warehouse_id' => $kosong->id,
            'item_id' => $semen->id,
            'qty' => 0,
            'avg_cost' => 99000,
        ]);
        StockBalance::create([
            'warehouse_id' => $rusak->id,
            'item_id' => $semen->id,
            'qty' => -5,
            'avg_cost' => 50000,
        ]);

        $this->stock()->refreshGlobalAvgCost($semen);

        // Still (1.500.000 + 420.000) / 120 = 16.000 — the 99.000 and 50.000
        // rows carry no positive quantity and are ignored.
        $this->assertSame(16000.0, (float) $semen->fresh()->avg_cost);
    }

    public function test_the_last_known_average_is_retained_when_all_stock_is_consumed(): void
    {
        $pusat = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $semen = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($pusat, $semen, 100, 15000);
        $this->receiveStock($site, $semen, 20, 21000);

        $this->assertSame(16000.0, (float) $semen->fresh()->avg_cost);

        // Emptying WH-PUSAT first leaves only the 20 @ 21.000 in WH-SITE, so the
        // average correctly moves to 21.000 before the second issue empties that
        // warehouse too.
        $this->stock()->postIssue($this->makeIssue($pusat, [[$semen, 100]]));
        $this->assertSame(21000.0, (float) $semen->fresh()->avg_cost);

        $this->stock()->postIssue($this->makeIssue($site, [[$semen, 20]]));

        $this->stock()->refreshGlobalAvgCost($semen);

        // Total qty is now 0 — dividing would be undefined, so valuation
        // continuity wins and the LAST known average (21.000, the cost of the
        // stock that was still on hand) is kept rather than reset to 0.
        $this->assertSame(0.0, $this->balanceQty($pusat, $semen));
        $this->assertSame(0.0, $this->balanceQty($site, $semen));
        $this->assertSame(21000.0, (float) $semen->fresh()->avg_cost);
    }

    public function test_the_global_average_follows_the_stock_that_is_left_after_an_issue(): void
    {
        $murah = $this->makeWarehouse('WH-MURAH');
        $mahal = $this->makeWarehouse('WH-MAHAL');
        $semen = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($murah, $semen, 100, 10000);
        $this->receiveStock($mahal, $semen, 100, 20000);

        // (100 * 10.000 + 100 * 20.000) / 200 = 3.000.000 / 200 = 15.000
        $this->assertSame(15000.0, (float) $semen->fresh()->avg_cost);

        $this->stock()->postIssue($this->makeIssue($murah, [[$semen, 100]]));

        // Only the expensive warehouse still holds stock: 100 * 20.000 / 100 = 20.000.
        // Keeping 15.000 would understate the remaining stock by 25% and would
        // misvalue an opname surplus booked against it.
        $this->assertSame(20000.0, (float) $semen->fresh()->avg_cost);
    }

    public function test_the_global_average_of_an_item_that_was_never_received_stays_zero(): void
    {
        $semen = $this->makeItem('Semen Gresik 40kg');

        $this->stock()->refreshGlobalAvgCost($semen);

        $this->assertSame(0.0, (float) $semen->fresh()->avg_cost);
    }

    public function test_a_receipt_in_one_warehouse_does_not_disturb_another_warehouse_average(): void
    {
        $pusat = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $semen = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($pusat, $semen, 100, 15000);
        $this->receiveStock($site, $semen, 20, 21000);
        $this->receiveStock($site, $semen, 20, 25000);

        // WH-SITE: (20 * 21.000 + 20 * 25.000) / 40 = 920.000 / 40 = 23.000
        $this->assertSame(23000.0, $this->balanceAvg($site, $semen));
        // WH-PUSAT untouched.
        $this->assertSame(15000.0, $this->balanceAvg($pusat, $semen));
        // Global: (1.500.000 + 920.000) / 140 = 2.420.000 / 140 = 17.285,714...
        $this->assertSame(17285.71, (float) $semen->fresh()->avg_cost);
    }
}
