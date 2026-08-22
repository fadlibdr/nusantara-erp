<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Setting;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Services\JournalService;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * erp:inventory-method-check — the guard on audit A2.
 *
 * accounting.perpetual_inventory is no longer a checkbox; it is an install-time
 * election in config/erp.php, changed only by a deploy. A deploy is a deliberate
 * act, but it must not silently corrupt the ledger, so this command answers one
 * question with numbers and an exit code: would changing the method right now
 * strand anything?
 *
 * It answers "yes" while any of three things is true — a goods receipt whose
 * recorded clearing no bill has settled, a stock movement already posted inside
 * an open fiscal period, or stock on hand still carrying value — and never
 * migrates anything, because moving between the methods needs a revaluation
 * journal an accountant writes.
 */
class InventoryMethodCheckTest extends ErpTestCase
{
    use InventoryFixtures;

    private const COMMAND = 'erp:inventory-method-check';

    public function test_a_fresh_installation_may_still_choose_its_method(): void
    {
        // Nothing posted, nothing on hand, no open period holding a movement:
        // exactly when the election is legitimately made.
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('SAFE')
            ->assertExitCode(0);
    }

    public function test_stock_on_hand_and_a_movement_in_an_open_period_both_block_a_change(): void
    {
        [$warehouse, $item] = $this->stocked();

        $this->receiveStock($warehouse, $item, 100, 15000, '2026-03-10');

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('UNSAFE')
            // 100 * 15.000 = 1.500.000 on hand, and the receipt itself sits in an
            // open period.
            ->expectsOutputToContain('Rp 1.500.000,00')
            ->expectsOutputToContain('Stock movements are already posted inside an open fiscal period')
            ->expectsOutputToContain('Stock on hand still carries value')
            ->assertExitCode(1);
    }

    /**
     * A receipt-to-invoice chain that is still open. The credit is read from what
     * the receipt RECORDED it credited, less what non-cancelled bills recorded
     * they cleared — the same two facts ApBillService reads, never a re-derivation
     * from the PO.
     */
    public function test_a_receipt_whose_clearing_no_bill_has_settled_blocks_a_change(): void
    {
        [$warehouse, $item] = $this->stocked();

        $receipt = $this->receiveStock($warehouse, $item, 100, 62000, '2026-03-10');

        // The state an installation is in between the delivery and the invoice:
        // 6.200.000 credited to GR/IR and recorded on the receipt.
        DB::table('inv_goods_receipts')->where('id', $receipt->id)->update([
            'purchase_order_id' => 4242,
            'gl_clearing_account' => '2-1150',
            'gl_clearing_amount' => 6200000,
        ]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('UNSAFE')
            ->expectsOutputToContain('of clearing no bill has settled')
            ->expectsOutputToContain('PO #4242')
            ->expectsOutputToContain('Rp 6.200.000,00')
            ->assertExitCode(1);

        // The vendor bill lands and debits exactly that back out; the chain is
        // closed and the blocker is gone. (Everything else still blocks — this
        // asserts only that the clearing probe is settled, by its absence.)
        $this->billClearing((int) $receipt->id, 4242, 6200000);

        $this->artisan(self::COMMAND)
            ->doesntExpectOutputToContain('of clearing no bill has settled')
            ->assertExitCode(1);
    }

    /**
     * An override stored while the key was still editable outranks config/erp.php
     * for ever, so editing the file would change nothing at all. Reported, not
     * ignored — and read from the table rather than through the resolver, because
     * the resolver's map is cached and a health check must see what is stored.
     */
    public function test_an_override_left_over_from_the_checkbox_blocks_a_change(): void
    {
        Setting::query()->create([
            'key' => 'accounting.perpetual_inventory',
            'value' => false,
            'group' => 'accounting',
        ]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Method in force : PERIODIC')
            ->expectsOutputToContain('A stored override for accounting.perpetual_inventory is still in force')
            ->assertExitCode(1);

        Setting::query()->where('key', 'accounting.perpetual_inventory')->delete();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Method in force : PERPETUAL')
            ->expectsOutputToContain('SAFE')
            ->assertExitCode(0);
    }

    // ------------------------------------------- the check that never compared

    /**
     * The command printed the stock sub-ledger and GL 1-1400 side by side and
     * never tested one against the other, so a break produced the same verdict,
     * the same blocker list and the same exit code as a clean install — the
     * difference was on the operator's screen, unlabelled. This is the only
     * inventory-to-GL tie-out point in the product (PeriodCloseService::itemSubledgerTied
     * covers 1-1300 and 2-1100 only), so what it misses is missed everywhere.
     */
    public function test_a_sub_ledger_that_disagrees_with_the_inventory_account_is_a_blocker_of_its_own(): void
    {
        [$warehouse, $item] = $this->stocked();

        $this->receiveStock($warehouse, $item, 100, 15000, '2026-03-10');

        // Clean: 100 * 15.000 on both sides, so only the ordinary "stock still
        // carries value" blocker fires and nothing claims a break.
        $this->artisan(self::COMMAND)
            ->doesntExpectOutputToContain('disagree by')
            ->assertExitCode(1);

        // A journal on 1-1400 with no stock movement behind it — the shape a
        // hand-keyed JV or a half-migrated install leaves.
        app(JournalService::class)->autoPost(
            'manual_probe',
            1,
            [
                ['account_code' => '1-1400', 'debit' => 600000, 'description' => 'JV manual tanpa mutasi stok'],
                ['account_code' => '3-3100', 'credit' => 600000, 'description' => 'JV manual tanpa mutasi stok'],
            ],
            '2026-03-20',
            'Probe: nilai persediaan tanpa dokumen',
        );

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('UNSAFE')
            ->expectsOutputToContain('The stock sub-ledger and GL 1-1400 disagree by Rp 600.000,00')
            ->expectsOutputToContain('GL higher — value with no stock behind it')
            ->assertExitCode(1);
    }

    /**
     * Goods on the road are the ONE difference that is by design: sendTransfer()
     * takes them out of the source balance and receiveTransfer() puts them into
     * the destination's, with no journal between, so for the whole transit window
     * the sub-ledger is below the general ledger by exactly the value in transit.
     * The command now names that figure instead of leaving an accountant to
     * discover it, and does not cry break over it.
     */
    public function test_goods_in_transit_are_named_as_the_reconciling_figure_and_not_reported_as_a_break(): void
    {
        [$warehouse, $item] = $this->stocked();
        $site = $this->makeWarehouse('WH-SITE');

        $this->receiveStock($warehouse, $item, 200, 62000, '2026-03-10');
        $this->stock()->sendTransfer(
            $this->makeTransfer($warehouse, $site, [[$item, 200]], '2026-03-20')
        );

        // Sub-ledger 0, GL 12.400.000, and the two tie once the road is counted.
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Rp 12.400.000,00 in transit')
            ->doesntExpectOutputToContain('disagree by')
            ->assertExitCode(1);
    }

    /**
     * Under periodic inventory StockService::ledgerPostingEnabled() suppresses
     * every inventory GL posting, so 1-1400 correctly holds nothing while the
     * sub-ledger legitimately accumulates. A blanket comparison would cry break
     * on every healthy periodic installation, which is why the tie-out is scoped
     * to the perpetual method.
     */
    public function test_a_periodic_installation_is_not_reported_as_a_break_for_holding_uncapitalised_stock(): void
    {
        [$warehouse, $item] = $this->stocked();

        $this->setSetting('accounting.perpetual_inventory', false);

        $this->receiveStock($warehouse, $item, 100, 15000, '2026-03-10');

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Method in force : PERIODIC')
            ->expectsOutputToContain('Rp 1.500.000,00')
            ->doesntExpectOutputToContain('disagree by')
            ->assertExitCode(1);
    }

    /**
     * inv_stock_adjustments is on Core's DocumentStatus lifecycle and records the
     * ledger hit in posted_at; DocumentStatus has no 'posted' case at all. The one
     * shared `where('status', 'posted')` the command applied to all three document
     * tables therefore matched ZERO opnames, always — so a company whose only
     * movement in an open period was a posted 25 March opname (Dr 6-4400 /
     * Cr 1-1400) was told SAFE with exit code 0, in the exact state the check
     * exists to block.
     */
    public function test_a_posted_opname_alone_in_an_open_period_blocks_a_change(): void
    {
        [$warehouse, $item] = $this->stocked();

        // February holds the receipt; March holds the opname that writes the lot
        // off, so stock ends at zero and only the opname is left to speak.
        $this->receiveStock($warehouse, $item, 100, 15000, '2026-02-10');
        $this->stock()->postAdjustment($this->makeAdjustment($warehouse, [[$item, 0]], '2026-03-25'));

        FiscalPeriod::query()->where('year', 2026)->where('month', 2)->update(['status' => 'closed']);

        $adjustment = StockAdjustment::query()->sole();
        // The mismatch itself, stated: the column says 'approved' and can never
        // say 'posted', while posted_at is what isPosted() reads.
        $this->assertSame(DocumentStatus::Approved, $adjustment->status);
        $this->assertNotNull($adjustment->posted_at);
        $this->assertSame(0, DB::table('inv_stock_adjustments')->where('status', 'posted')->count());

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('UNSAFE')
            ->expectsOutputToContain('1 posted stock adjustment(s)')
            ->expectsOutputToContain('Stock movements are already posted inside an open fiscal period')
            ->assertExitCode(1);
    }

    public function test_an_in_transit_transfer_straddling_the_change_over_blocks_it_too(): void
    {
        // Goods that have left one warehouse and not arrived at the other would
        // be accounted under two different methods if the deploy landed now.
        [$warehouse, $item] = $this->stocked();
        $site = $this->makeWarehouse('WH-SITE');

        $this->receiveStock($warehouse, $item, 200, 62000, '2026-02-10');
        $this->stock()->sendTransfer(
            $this->makeTransfer($warehouse, $site, [[$item, 200]], '2026-03-20')
        );

        FiscalPeriod::query()->where('year', 2026)->where('month', 2)->update(['status' => 'closed']);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('1 transfer(s) still in transit')
            ->assertExitCode(1);
    }

    /**
     * A warehouse and an item, with the ledger open for the year the fixtures
     * post into.
     *
     * @return array{0: Warehouse, 1: Item}
     */
    private function stocked(): array
    {
        $this->seedLedger(2026);

        return [$this->makeWarehouse('WH-PUSAT'), $this->makeItem('Semen Gresik 40kg')];
    }

    /**
     * A non-cancelled vendor bill recording what it cleared, written straight to
     * the table: this test is about the command reading fin_ap_bills, not about
     * how ApBillService fills it in.
     */
    private function billClearing(int $receiptId, int $purchaseOrderId, float $amount): void
    {
        DB::table('fin_ap_bills')->insert([
            'code' => 'BIL/2026/III/9001',
            'vendor_id' => 1,
            'purchase_order_id' => $purchaseOrderId,
            'goods_receipt_id' => $receiptId,
            'description' => 'Tagihan material semen',
            'vendor_invoice_no' => 'INV-SEMEN-0001',
            'bill_date' => '2026-03-20',
            'due_date' => '2026-04-19',
            'dpp' => $amount,
            'ppn_amount' => 0,
            'pph_amount' => 0,
            'total_payable' => $amount,
            'amount_paid' => 0,
            'status' => 'approved',
            'gl_cleared_amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
