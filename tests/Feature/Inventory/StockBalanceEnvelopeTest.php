<?php

namespace Tests\Feature\Inventory;

use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * The Saldo Stok envelope — audit T28/T29.
 *
 * GET inventory/stock/balances used to answer a bare paginated collection, so
 * the screen derived "Nilai persediaan" by reducing the ONE page it loaded —
 * silently short the moment the balance list outgrew per_page — and the value
 * of goods on the road, which sendTransfer() removes from the source balance
 * and receiveTransfer() has not yet handed to the destination, appeared on no
 * screen at all; only erp:inventory-method-check printed it. The endpoint now
 * carries meta.totals computed in SQL over the WHOLE filtered set, and its
 * in-transit figure is StockService::inTransitValue() — the same method the
 * CLI check reads — so the screen and the check cannot drift apart.
 */
class StockBalanceEnvelopeTest extends ErpTestCase
{
    use InventoryFixtures;

    private const ENDPOINT = '/api/inventory/stock/balances';

    public function test_the_on_hand_total_covers_every_row_not_only_the_loaded_page(): void
    {
        $this->seedLedger(2026);
        $warehouse = $this->makeWarehouse('WH-PUSAT');

        // Three balance rows; the request below loads a page of two.
        foreach ([
            ['Semen Gresik 40kg', 100, 15000],   // 1.500.000
            ['Besi Beton 10mm', 50, 20000],      // 1.000.000
            ['Pasir Cor', 10, 62000],            //   620.000
        ] as [$name, $qty, $unitCost]) {
            $this->receiveStock($warehouse, $this->makeItem($name), $qty, $unitCost, '2026-03-10');
        }

        $response = $this->actingAs($this->adminUser())
            ->getJson(self::ENDPOINT.'?per_page=2')
            ->assertOk();

        // The page holds two rows; the totals still count all three. A reduce
        // over data would answer 2.500.000 here — the exact undercount the
        // Saldo Stok tile used to print past 200 rows.
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(3120000.0, (float) $response->json('meta.totals.on_hand_value'));
        $this->assertSame(0.0, (float) $response->json('meta.totals.in_transit_value'));
        $this->assertSame(0, $response->json('meta.totals.in_transit_transfers'));
        $this->assertSame(3120000.0, (float) $response->json('meta.totals.owned_value'));
    }

    public function test_the_in_transit_figure_rides_with_the_truck_and_returns_to_zero_on_receipt(): void
    {
        $this->seedLedger(2026);
        $warehouse = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $item = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($warehouse, $item, 200, 62000, '2026-03-10');

        $transfer = $this->makeTransfer($warehouse, $site, [[$item, 200]], '2026-03-20');
        $this->stock()->sendTransfer($transfer);

        $admin = $this->adminUser();

        // The lot has left WH-PUSAT and not reached WH-SITE: on hand zero,
        // 12.400.000 on the road, and the total owned unchanged — the transit
        // window moves value between columns, never creates or destroys it.
        $totals = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json('meta.totals');
        $this->assertSame(0.0, (float) $totals['on_hand_value']);
        $this->assertSame(12400000.0, (float) $totals['in_transit_value']);
        $this->assertSame(1, $totals['in_transit_transfers']);
        $this->assertSame(12400000.0, (float) $totals['owned_value']);

        $this->stock()->receiveTransfer($transfer);

        $totals = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json('meta.totals');
        $this->assertSame(12400000.0, (float) $totals['on_hand_value']);
        $this->assertSame(0.0, (float) $totals['in_transit_value']);
        $this->assertSame(0, $totals['in_transit_transfers']);
        $this->assertSame(12400000.0, (float) $totals['owned_value']);
    }

    public function test_a_warehouse_filter_scopes_on_hand_while_the_road_belongs_to_no_warehouse(): void
    {
        $this->seedLedger(2026);
        $warehouse = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $item = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($warehouse, $item, 100, 15000, '2026-03-10');

        // 40 zak have arrived at the site; another 20 are still on the truck.
        $arrived = $this->makeTransfer($warehouse, $site, [[$item, 40]], '2026-03-12');
        $this->stock()->sendTransfer($arrived);
        $this->stock()->receiveTransfer($arrived);
        $this->stock()->sendTransfer($this->makeTransfer($warehouse, $site, [[$item, 20]], '2026-03-15'));

        $totals = $this->actingAs($this->adminUser())
            ->getJson(self::ENDPOINT.'?warehouse_id='.$site->id)
            ->assertOk()
            ->json('meta.totals');

        // On hand follows the filter (site only: 40 * 15.000); the in-transit
        // figure deliberately does not — goods on the road sit in NEITHER
        // warehouse balance, so no warehouse filter can claim them.
        $this->assertSame(600000.0, (float) $totals['on_hand_value']);
        $this->assertSame(300000.0, (float) $totals['in_transit_value']);
        $this->assertSame(1, $totals['in_transit_transfers']);
    }

    /**
     * The point of moving inTransitValue() into StockService: one query, two
     * mouths. The CLI names the reconciling figure and the screen serves the
     * same rupiah, because they call the same method — a second copy would
     * drift on its first edit and this test would catch the drift.
     */
    public function test_the_cli_check_and_the_screen_quote_the_same_in_transit_figure(): void
    {
        $this->seedLedger(2026);
        $warehouse = $this->makeWarehouse('WH-PUSAT');
        $site = $this->makeWarehouse('WH-SITE');
        $item = $this->makeItem('Semen Gresik 40kg');

        $this->receiveStock($warehouse, $item, 200, 62000, '2026-03-10');
        $this->stock()->sendTransfer(
            $this->makeTransfer($warehouse, $site, [[$item, 200]], '2026-03-20')
        );

        $this->artisan('erp:inventory-method-check')
            ->expectsOutputToContain('Rp 12.400.000,00 in transit')
            ->doesntExpectOutputToContain('disagree by')
            ->assertExitCode(1);

        $this->assertSame(
            12400000.0,
            (float) $this->actingAs($this->adminUser())
                ->getJson(self::ENDPOINT)
                ->assertOk()
                ->json('meta.totals.in_transit_value'),
        );
    }
}
