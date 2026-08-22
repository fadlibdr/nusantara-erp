<?php

namespace Tests\Feature\Inventory;

use LogicException;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * The whole inventory -> GL bridge is driven by the accounting.* parameters:
 * a switch that turns perpetual posting off entirely, plus the three COA codes
 * the engine posts to.
 */
class InventoryLedgerSettingsTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    private const PROJECT_ID = 77;

    private Warehouse $pusat;

    private Item $semen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
    }

    public function test_perpetual_inventory_off_moves_stock_without_any_journal(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );

        // Quantities and valuation still work — only the GL is bypassed.
        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(15000.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertSame(1, StockLedgerEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_perpetual_inventory_off_stops_the_issue_journal_and_the_project_cost(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );

        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15')
        );

        // The line is still valued at the moving average (40 * 15.000 = 600.000)
        // because valuation is independent of the posting switch.
        $line = $issue->items()->first();
        $this->assertSame(15000.0, (float) $line->unit_cost);
        $this->assertSame(600000.0, (float) $line->amount);

        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_perpetual_inventory_off_stops_the_adjustment_journal(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );

        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 90]], '2026-03-25')
        );

        $this->assertNotNull($adjustment->fresh()->posted_at);
        $this->assertSame(90.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_the_inventory_account_setting_is_honoured_on_both_legs(): void
    {
        $this->makePostableAccount('1-1450', 'Persediaan Barang Proyek', 'asset', 'debit');
        $this->setSetting('accounting.inventory_account', '1-1450');

        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->pusat, [[$this->semen, 40]], self::PROJECT_ID, '2026-03-15')
        );

        // 100 * 15.000 = 1.500.000 in, 40 * 15.000 = 600.000 out — both against
        // the overridden account, never the shipped 1-1400.
        $receipt = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));
        $consumption = $this->linesByAccount($this->singleJournalFor('inventory_issue', (int) $issue->id));

        $this->assertSame(1500000.0, $receipt['1-1450']['debit']);
        $this->assertArrayNotHasKey('1-1400', $receipt);

        $this->assertSame(600000.0, $consumption['1-1450']['credit']);
        $this->assertArrayNotHasKey('1-1400', $consumption);
        $this->assertSame(600000.0, $consumption['5-1100']['debit']);
    }

    public function test_the_grn_clearing_account_setting_is_honoured(): void
    {
        $this->makePostableAccount('2-1160', 'GR/IR Alternatif', 'liability', 'credit');
        $this->setSetting('accounting.grn_clearing_account', '2-1160');

        // GR/IR only applies to a receipt against a PO — that is the receipt a
        // vendor bill will come along and clear.
        $po = $this->makeGoodsPurchaseOrder($this->pusat);

        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10', ['purchase_order_id' => $po->id])
        );

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        $this->assertSame(['1-1400', '2-1160'], array_keys($lines));
        $this->assertSame(1500000.0, $lines['2-1160']['credit']);
    }

    public function test_the_receipt_accrual_account_setting_is_honoured(): void
    {
        $this->makePostableAccount('2-1650', 'Akrual Penerimaan Barang', 'liability', 'credit');
        $this->setSetting('accounting.receipt_accrual_account', '2-1650');

        // A vendor delivery with no PO: the credit follows the accrual setting,
        // never GR/IR, and the receipt records the overridden account so the
        // bill clears that one rather than the shipped default.
        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10', [
                'vendor_id' => $this->vendor()->id,
            ])
        );

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        $this->assertSame(['1-1400', '2-1650'], array_keys($lines));
        $this->assertSame(1500000.0, $lines['2-1650']['credit']);
        $this->assertArrayNotHasKey('2-1600', $lines);
        $this->assertArrayNotHasKey('2-1150', $lines);

        $this->assertSame('2-1650', $grn->fresh()->gl_clearing_account);
        $this->assertSame(1500000.0, $grn->fresh()->recordedClearingAmount());
    }

    public function test_a_receipt_with_no_po_and_no_vendor_follows_the_opening_balance_setting(): void
    {
        $this->makePostableAccount('3-3150', 'Saldo Awal Persediaan', 'equity', 'credit');
        $this->setSetting('accounting.opening_balance_account', '3-3150');

        // Opening stock: no counterparty, so no liability is booked at all and
        // there is nothing for a bill to clear. The counter-entry follows the
        // opening balance setting — NOT the stock variance setting, which is
        // for opname differences and would put the value in the P&L.
        $this->makePostableAccount('6-4450', 'Selisih Opname Gudang', 'expense', 'debit');
        $this->setSetting('accounting.stock_variance_account', '6-4450');

        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        $this->assertSame(['1-1400', '3-3150'], array_keys($lines));
        $this->assertSame(1500000.0, $lines['3-3150']['credit']);
        $this->assertArrayNotHasKey('6-4450', $lines);
        $this->assertArrayNotHasKey('6-4400', $lines);
        $this->assertArrayNotHasKey('2-1600', $lines);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertNull($grn->fresh()->gl_clearing_account);
    }

    public function test_periodic_inventory_records_no_clearing_on_the_receipt(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPurchaseOrder($this->pusat);

        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10', ['purchase_order_id' => $po->id])
        );

        // No journal, therefore no credit, therefore nothing recorded — the
        // vendor bill must fall back to the classic expense treatment even if
        // the switch is turned back on before the invoice arrives.
        $this->assertSame(0, Journal::query()->count());
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertNull($grn->fresh()->gl_clearing_amount);
    }

    public function test_the_stock_variance_account_setting_is_honoured(): void
    {
        $this->makePostableAccount('6-4450', 'Selisih Opname Gudang', 'expense', 'debit');
        $this->setSetting('accounting.stock_variance_account', '6-4450');

        $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 90]], '2026-03-25')
        );

        // -10 * 15.000 = 150.000
        $lines = $this->linesByAccount($this->singleJournalFor('stock_adjustment', (int) $adjustment->id));

        $this->assertSame(['6-4450', '1-1400'], array_keys($lines));
        $this->assertSame(150000.0, $lines['6-4450']['debit']);
        $this->assertArrayNotHasKey('6-4400', $lines);
    }

    public function test_pointing_the_inventory_account_at_a_group_account_fails_and_rolls_back_the_stock(): void
    {
        // 1-1000 "Aset Lancar" is a COA group: is_postable = false.
        $this->setSetting('accounting.inventory_account', '1-1000');

        $grn = $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10');

        try {
            $this->stock()->postReceipt($grn);
            $this->fail('Expected a LogicException for a non-postable account.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot be posted to', $e->getMessage());
        }

        $this->assertNull($this->balanceOf($this->pusat, $this->semen));
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
    }

    public function test_pointing_the_inventory_account_at_an_unknown_code_fails_and_rolls_back_the_stock(): void
    {
        $this->setSetting('accounting.inventory_account', '9-9999');

        $grn = $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10');

        try {
            $this->stock()->postReceipt($grn);
            $this->fail('Expected a LogicException for a missing account.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->assertNull($this->balanceOf($this->pusat, $this->semen));
        $this->assertSame(0.0, (float) $this->semen->fresh()->last_price);
        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
    }
}
