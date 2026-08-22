<?php

namespace Tests\Feature\Inventory;

use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Stock opname differences bypass the GR/IR chain and land straight in
 * 6-4400 Selisih Persediaan, valued at the warehouse moving average.
 */
class StockAdjustmentPostingTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    private Warehouse $pusat;

    private Item $semen;

    private Item $besi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->besi = $this->makeItem('Besi Beton D13', ['unit' => 'btg']);

        $this->stock()->postReceipt($this->makeGrn($this->pusat, [
            [$this->semen, 100, 15000],
            [$this->besi, 50, 25000],
        ], '2026-03-01'));
    }

    public function test_a_shortage_debits_the_stock_variance_account(): void
    {
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 90]], '2026-03-25')
        );

        // (90 - 100) * 15.000 = -150.000 : inventory shrinks, expense grows.
        $journal = $this->singleJournalFor('stock_adjustment', (int) $adjustment->id);
        $this->assertPostedAndBalanced($journal, '2026-03-25');

        $lines = $this->linesByAccount($journal);

        $this->assertSame(['6-4400', '1-1400'], array_keys($lines));
        $this->assertSame(150000.0, $lines['6-4400']['debit']);
        $this->assertSame(150000.0, $lines['1-1400']['credit']);
    }

    public function test_a_surplus_credits_the_stock_variance_account(): void
    {
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 110]], '2026-03-25')
        );

        // (110 - 100) * 15.000 = +150.000 : the mirror image of the shortage.
        $lines = $this->linesByAccount($this->singleJournalFor('stock_adjustment', (int) $adjustment->id));

        $this->assertSame(['1-1400', '6-4400'], array_keys($lines));
        $this->assertSame(150000.0, $lines['1-1400']['debit']);
        $this->assertSame(150000.0, $lines['6-4400']['credit']);
    }

    public function test_the_variance_journal_carries_no_project(): void
    {
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 90]], '2026-03-25')
        );

        $lines = $this->linesByAccount($this->singleJournalFor('stock_adjustment', (int) $adjustment->id));

        $this->assertNull($lines['6-4400']['project_id']);
        $this->assertNull($lines['1-1400']['project_id']);
        // Opname losses are operating expense, never project realisasi.
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_count_that_matches_the_system_creates_no_journal(): void
    {
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 100]], '2026-03-25')
        );

        $this->assertNoJournalFor('stock_adjustment', (int) $adjustment->id);
        $this->assertNotNull($adjustment->fresh()->posted_at);
    }

    public function test_a_multi_line_difference_is_booked_net(): void
    {
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 130], [$this->besi, 44]], '2026-03-25')
        );

        // semen +30 * 15.000 = +450.000 ; besi -6 * 25.000 = -150.000
        // net = +300.000 => surplus direction.
        $lines = $this->linesByAccount($this->singleJournalFor('stock_adjustment', (int) $adjustment->id));

        $this->assertSame(300000.0, $lines['1-1400']['debit']);
        $this->assertSame(300000.0, $lines['6-4400']['credit']);
    }

    public function test_a_difference_that_nets_to_zero_creates_no_journal_although_stock_moves(): void
    {
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 110], [$this->besi, 44]], '2026-03-25')
        );

        // semen +10 * 15.000 = +150.000 ; besi -6 * 25.000 = -150.000 => net 0.
        // Total inventory value is unchanged, so there is nothing to book, but
        // the quantities did move.
        $this->assertNoJournalFor('stock_adjustment', (int) $adjustment->id);
        $this->assertSame(110.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(44.0, $this->balanceQty($this->pusat, $this->besi));
        // 110 * 15.000 + 44 * 25.000 = 1.650.000 + 1.100.000 = 2.750.000,
        // exactly the 100 * 15.000 + 50 * 25.000 = 2.750.000 received.
        $this->assertSame(
            2750000.0,
            $this->balanceValue($this->pusat, $this->semen) + $this->balanceValue($this->pusat, $this->besi)
        );
    }

    public function test_a_shortage_of_a_zero_valued_item_creates_no_journal(): void
    {
        $pasir = $this->makeItem('Pasir Urug', ['unit' => 'm3']);
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$pasir, 10, 0]], '2026-03-02'));

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$pasir, 6]], '2026-03-25')
        );

        // -4 * 0 = 0 : quantity corrected, no value to write off.
        $this->assertSame(6.0, $this->balanceQty($this->pusat, $pasir));
        $this->assertNoJournalFor('stock_adjustment', (int) $adjustment->id);
    }
}
