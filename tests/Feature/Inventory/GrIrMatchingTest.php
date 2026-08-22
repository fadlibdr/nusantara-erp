<?php

namespace Tests\Feature\Inventory;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Regression suite for the GR/IR chain, end to end: receipt -> vendor bill ->
 * material issue. It exists because an audit found three ways for value to be
 * counted twice, or never at all:
 *
 *   H1  the bill was approved BEFORE the goods arrived, so the cost was booked
 *       once on the bill (Dr 5-1100) and again on the issue (Dr 5-1100), and
 *       the GR/IR credit raised by the later receipt was never debited back;
 *   H2  a receipt WITHOUT a purchase order credited 2-1150 all the same, and
 *       nothing in the engine can ever debit 2-1150 without a PO — a phantom
 *       liability for the life of the installation;
 *   M4  a partial delivery or a price difference left 2-1150 with a residue,
 *       in a partial delivery even a DEBIT balance in a liability account.
 *
 * Every expected figure below is hand-computed in a comment next to it, using
 * the audit's own reproduction: 100 zak semen @ 62.000.
 */
class GrIrMatchingTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    /**
     * The purchase order the audit reproduced H1 with.
     *
     *   qty * unit price = 100 zak * 62.000 = 6.200.000  (DPP)
     *   PPN 11%          = 6.200.000 * 0,11 =   682.000
     *   total            = 6.200.000 + 682.000 = 6.882.000
     */
    private const PO_QTY = 100.0;

    private const PO_UNIT_PRICE = 62000.0;

    private const PO_DPP = 6200000.0;

    private const PO_PPN = 682000.0;

    private const PO_TOTAL = 6882000.0;

    private Warehouse $pusat;

    private Item $semen;

    private Vendor $vendor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->vendor = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ---------------------------------------------------------------- H2: no PO, no GR/IR

    public function test_a_receipt_without_a_purchase_order_leaves_the_clearing_account_at_exactly_zero(): void
    {
        // The shipped demo's only goods receipt is exactly this shape: opening
        // stock, purchase_order_id NULL. No bill will ever carry that PO, so a
        // credit to 2-1150 could never be debited back out again (H2).
        $grn = $this->stock()->postReceipt(
            $this->makeGrnFor(null, self::PO_QTY, self::PO_UNIT_PRICE)
        );

        $this->assertNull($grn->purchase_order_id);

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        // 100 zak * 62.000 = 6.200.000, accrued in 2-1600 instead of GR/IR.
        $this->assertSame(6200000.0, $lines['1-1400']['debit']);
        $this->assertSame(6200000.0, $lines['2-1600']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);

        // The whole point of H2: the clearing account is untouched, so it does
        // not carry a liability no document can ever settle.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1150'));
    }

    // ---------------------------------------------------------------- with a PO: GR/IR

    public function test_a_receipt_against_a_purchase_order_credits_the_clearing_account(): void
    {
        $po = $this->makeGoodsPo();

        $grn = $this->stock()->postReceipt(
            $this->makeGrnFor($po, self::PO_QTY, self::PO_UNIT_PRICE)
        );

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        // 100 zak * 62.000 = 6.200.000 parked in GR/IR until the invoice lands.
        $this->assertSame(6200000.0, $lines['1-1400']['debit']);
        $this->assertSame(6200000.0, $lines['2-1150']['credit']);
        $this->assertArrayNotHasKey('2-1600', $lines);

        // Signed: a credit balance of 6.200.000 in a liability account.
        $this->assertSame(-6200000.0, $this->accountNet('2-1150'));

        // Nothing has reached the P&L: the cost waits for the material issue.
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    // ---------------------------------------------------------------- full cycle at PO price

    public function test_the_full_cycle_at_the_po_price_nets_the_clearing_account_to_exactly_zero(): void
    {
        $po = $this->makeGoodsPo();
        $this->stock()->postReceipt($this->makeGrnFor($po, self::PO_QTY, self::PO_UNIT_PRICE));

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Dr 2-1150 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertSame(6882000.0, $journal->totalDebit());
        $this->assertSame(6882000.0, $journal->totalCredit());

        // Received at the PO price, so there is no price difference to book.
        $this->assertArrayNotHasKey('6-4500', $lines);

        // GRN credited 6.200.000, the bill debited 6.200.000 => 0.
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // No cost anywhere yet: the goods are still an asset in the warehouse.
        $this->assertSame(0, ProjectCost::query()->count());
        $this->assertSame(0, ProjectCost::query()->where('reference_type', 'ap_bill')->count());
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(6200000.0, $this->accountNet('1-1400'));
    }

    /**
     * THE double-count regression test (H1). After the whole chain the material
     * cost must appear ONCE — the audit reproduced 12.400.000 for a 6.200.000
     * purchase because the bill and the issue each booked it.
     */
    public function test_issuing_the_material_books_the_project_cost_and_5_1100_exactly_once(): void
    {
        $po = $this->makeGoodsPo();
        $this->stock()->postReceipt($this->makeGrnFor($po, self::PO_QTY, self::PO_UNIT_PRICE));

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        // The warehouse now holds 100 zak at 62.000; issue every one of them.
        $issue = $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, self::PO_QTY]],
            (int) $this->project->id,
            '2026-03-20',
        ));

        $lines = $this->linesByAccount($this->singleJournalFor('inventory_issue', (int) $issue->id));

        // 100 zak * 62.000 average = 6.200.000 out of stock into project cost.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(6200000.0, $lines['1-1400']['credit']);
        $this->assertSame((int) $this->project->id, $lines['5-1100']['project_id']);

        // ONCE, not twice: 6.200.000, never 12.400.000.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));

        // In 6.200.000 (GRN), out 6.200.000 (issue) => stock account empty.
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // And exactly one project cost row, raised by the ISSUE's line, not
        // the bill.
        $costs = ProjectCost::query()->get();
        $this->assertCount(1, $costs);
        $this->assertSame('inventory_issue_item', $costs->first()->reference_type);
        $this->assertSame((int) $issue->items()->sole()->id, (int) $costs->first()->reference_id);
        $this->assertSame('material', $costs->first()->cost_category->value);
        $this->assertSame(6200000.0, (float) $costs->first()->amount);
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sum('amount'));

        // Three documents, three journals: receipt, bill, issue.
        $this->assertSame(3, Journal::query()->count());
    }

    /**
     * H1 as the audit staged it: invoice booked while the goods are still in
     * transit. The engine now refuses that approval outright, which is what
     * makes the double count impossible instead of merely compensated for.
     */
    public function test_the_invoice_cannot_be_booked_before_the_goods_arrive(): void
    {
        $po = $this->makeGoodsPo();
        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $bill->submit($this->financeUser());

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
            $this->fail('Expected the goods-receipt gate to refuse the approval.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($po->code, $e->getMessage());
            $this->assertStringContainsString('setelah barang diterima', $e->getMessage());
        }

        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0, ProjectCost::query()->count());
        $this->assertSame(0.0, $this->accountNet('5-1100'));

        // Receive, then bill, then issue: the value still lands exactly once.
        $this->stock()->postReceipt($this->makeGrnFor($po, self::PO_QTY, self::PO_UNIT_PRICE));
        $this->apBills()->approve($bill->fresh(), $this->financeApprover());

        $issue = $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, self::PO_QTY]],
            (int) $this->project->id,
            '2026-03-20',
        ));

        // 100 * 62.000 = 6.200.000 once, in 5-1100 and in the cost ledger.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sum('amount'));
        $this->assertSame(1, ProjectCost::query()->count());
        $this->assertSame('inventory_issue_item', ProjectCost::query()->sole()->reference_type);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame((int) $issue->items()->sole()->id, (int) ProjectCost::query()->sole()->reference_id);
    }

    // ---------------------------------------------------------------- price differences (M4)

    public function test_receiving_above_the_po_price_still_clears_gr_ir_and_credits_the_variance(): void
    {
        // PO at 62.000, delivery note priced at 65.000.
        $po = $this->makeGoodsPo();
        $this->stock()->postReceipt($this->makeGrnFor($po, self::PO_QTY, 65000));

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Received 100 * 65.000 = 6.500.000; billed 6.200.000.
        // 6.200.000 - 6.500.000 = -300.000 => the variance is a CREDIT (gain).
        $this->assertSame(6500000.0, $lines['2-1150']['debit']);
        $this->assertSame(300000.0, $lines['6-4500']['credit']);
        $this->assertSame(0.0, $lines['6-4500']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);

        // 6.500.000 + 682.000 = 300.000 + 6.882.000 = 7.182.000.
        $this->assertSame(7182000.0, $this->singleJournalFor('ap_bill', (int) $bill->id)->totalDebit());

        // Credited 6.500.000 by the GRN, debited 6.500.000 by the bill.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(-300000.0, $this->accountNet('6-4500'));
    }

    public function test_receiving_below_the_po_price_still_clears_gr_ir_and_debits_the_variance(): void
    {
        // PO at 62.000, delivery note priced at 60.000.
        $po = $this->makeGoodsPo();
        $this->stock()->postReceipt($this->makeGrnFor($po, self::PO_QTY, 60000));

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Received 100 * 60.000 = 6.000.000; billed 6.200.000.
        // 6.200.000 - 6.000.000 = +200.000 => the variance is a DEBIT (cost).
        $this->assertSame(6000000.0, $lines['2-1150']['debit']);
        $this->assertSame(200000.0, $lines['6-4500']['debit']);
        $this->assertSame(0.0, $lines['6-4500']['credit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);

        // 6.000.000 + 200.000 + 682.000 = 6.882.000.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(200000.0, $this->accountNet('6-4500'));

        // Stock is carried at what actually arrived, 6.000.000, not at the
        // invoice; the 200.000 is a P&L difference, not an asset.
        $this->assertSame(6000000.0, $this->accountNet('1-1400'));
        $this->assertSame(6000000.0, $this->balanceValue($this->pusat, $this->semen));
    }

    // ---------------------------------------------------------------- perpetual switched off

    public function test_switching_perpetual_inventory_off_keeps_stock_out_of_the_ledger_entirely(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPo();
        $grn = $this->stock()->postReceipt($this->makeGrnFor($po, self::PO_QTY, self::PO_UNIT_PRICE));

        // Quantities move, the ledger does not.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertNoJournalFor('goods_receipt', (int) $grn->id);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Classic treatment: the cost is recognised when the vendor bills.
        // Dr 5-1100 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertArrayNotHasKey('6-4500', $lines);

        $issue = $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, self::PO_QTY]],
            (int) $this->project->id,
            '2026-03-20',
        ));

        // The issue moves quantity only — booking it again would double-count
        // the cost the bill already recognised.
        $this->assertNoJournalFor('inventory_issue', (int) $issue->id);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));

        // Still exactly one recognition of 6.200.000, this time at billing.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(1, Journal::query()->count());

        $costs = ProjectCost::query()->get();
        $this->assertCount(1, $costs);
        $this->assertSame('ap_bill', $costs->first()->reference_type);
        $this->assertSame(6200000.0, (float) $costs->first()->amount);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * An approved GOODS purchase order — it names a deliver-to warehouse, which
     * is what subjects its bill to the three-way match.
     *
     *   100 zak * 62.000       = 6.200.000  dpp
     *   6.200.000 * 11%        =   682.000  ppn
     *   6.200.000 + 682.000    = 6.882.000  total
     */
    private function makeGoodsPo(array $attributes = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'warehouse_id' => $this->pusat->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::PO_DPP,
            'discount_amount' => 0,
            'dpp' => self::PO_DPP,
            'ppn_rate' => 11.0,
            'ppn_amount' => self::PO_PPN,
            'total' => self::PO_TOTAL,
            'status' => DocumentStatus::Approved,
        ], $attributes));

        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->semen->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => self::PO_QTY,
            'unit' => 'zak',
            'unit_price' => self::PO_UNIT_PRICE,
            'amount' => self::PO_DPP,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    /**
     * A draft goods receipt, optionally against a PO line so Procurement can
     * track the received quantity and close the order.
     */
    private function makeGrnFor(?PurchaseOrder $po, float $qty, float $unitCost, string $date = '2026-03-05'): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $po?->id,
            'vendor_id' => $this->vendor->id,
            'receipt_date' => $date,
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => $this->semen->id,
            'po_item_id' => $po?->items()->value('id'),
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => round($qty * $unitCost, 2),
        ]);

        return $grn->refresh();
    }

    /**
     * Signed movement of a COA account across every posted journal line:
     * debit - credit. Zero means the account has been fully cleared.
     */
    private function accountNet(string $code): float
    {
        $sums = JournalLine::query()
            ->where('account_id', $this->accountId($code))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return round((float) $sums->debit - (float) $sums->credit, 2);
    }

    private function lineCountFor(string $code): int
    {
        return JournalLine::query()->where('account_id', $this->accountId($code))->count();
    }

    private function assertNoJournalFor(string $referenceType, int $referenceId): void
    {
        $this->assertDatabaseMissing('fin_journals', [
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}
