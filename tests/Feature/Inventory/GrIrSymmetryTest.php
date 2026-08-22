<?php

namespace Tests\Feature\Inventory;

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
use Modules\Procurement\Services\PoService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * SYMMETRY of the GR/IR predicate (audit A1 + A2).
 *
 * The defect was never one wrong branch; it was that the two ends of the chain
 * answered the same question from DIFFERENT inputs, live, at different times:
 *
 *   receipt end  StockService::postReceiptJournal chose the liability to credit
 *                from the GRN's purchase_order_id;
 *   invoice end  ApBillService decided whether to clear it from the PO's
 *                warehouse_id AND from the CURRENT value of
 *                accounting.perpetual_inventory.
 *
 * Those inputs can disagree, and every reproduction below is one way they did:
 *
 *   A1  PO delivered straight to site (warehouse_id NULL): the GRN credited
 *       2-1150 because it had a PO, the bill expensed the goods because the PO
 *       had no warehouse. Measured: 5-1100 = 12.400.000 for a 6.200.000
 *       purchase, with 2-1150 stranded at -6.200.000.
 *   A2a perpetual ON at receipt, OFF at invoice: the credit was stranded.
 *   A2b perpetual OFF at receipt (no journal at all), ON at invoice: the bill
 *       debited 2-1150 against a credit that never existed — a liability
 *       account with a DEBIT balance — while 1-1400 stayed at 0.
 *
 * The invariant these tests pin is the repair itself: THE RECEIPT RECORDS WHAT
 * IT CREDITED (gl_clearing_account / gl_clearing_amount) AND THE BILL CLEARS
 * EXACTLY THAT RECORD. Nothing is re-derived, so the two ends cannot disagree.
 *
 * Every figure is hand-computed in a comment beside its assertion, on the
 * audit's own reproduction: 100 zak semen @ 62.000.
 */
class GrIrSymmetryTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    /**
     *   100 zak * 62.000    = 6.200.000  dpp
     *   6.200.000 * 11%     =   682.000  ppn
     *   6.200.000 + 682.000 = 6.882.000  total
     */
    private const PO_QTY = 100.0;

    private const PO_UNIT_PRICE = 62000.0;

    private const PO_DPP = 6200000.0;

    private const PO_PPN = 682000.0;

    private const PO_TOTAL = 6882000.0;

    private Warehouse $pusat;

    private Warehouse $site;

    private Item $semen;

    private Vendor $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->site = $this->makeWarehouse('WH-SITE');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->supplier = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ---------------------------------------------------------------- A1: PO with no warehouse

    public function test_a_purchase_order_with_no_warehouse_recognises_the_material_cost_exactly_once(): void
    {
        // Material bought for a site that has no gudang row of its own: the
        // buyer leaves the PO's deliver-to warehouse empty and the driver
        // unloads at the project. The goods still land in a stock location, so
        // the GRN names one; the PO does not.
        $po = $this->makePo(['warehouse_id' => null]);
        $this->assertNull($po->warehouse_id);

        $grn = $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE, $this->site);

        // 100 * 62.000 = 6.200.000 — Dr 1-1400 / Cr 2-1150, and the receipt
        // WRITES DOWN that it credited 2-1150 with 6.200.000.
        $receiptLines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));
        $this->assertSame(6200000.0, $receiptLines['1-1400']['debit']);
        $this->assertSame(6200000.0, $receiptLines['2-1150']['credit']);
        $this->assertSame('2-1150', $grn->fresh()->gl_clearing_account);
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Dr 2-1150 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        // The PO's (missing) warehouse is not consulted anywhere.
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertSame(6882000.0, $journal->totalDebit());
        $this->assertSame(6882000.0, $journal->totalCredit());
        $this->assertArrayNotHasKey('5-1100', $lines); // the audit's double count
        $this->assertArrayNotHasKey('6-4500', $lines); // received at the PO price
        $this->assertSame(6200000.0, (float) $bill->gl_cleared_amount);

        // 100 zak out at the 62.000 moving average = 6.200.000.
        $this->issueAll($this->site, '2026-03-20');

        // ONCE: 6.200.000, never the 12.400.000 the audit measured.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        // 6.200.000 credited by the GRN, 6.200.000 debited by the bill => 0.
        // The audit measured -6.200.000 stranded here for ever.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        // In 6.200.000 (receipt), out 6.200.000 (issue) => the asset is empty.
        $this->assertSame(0.0, $this->accountNet('1-1400'));

        $cost = ProjectCost::query()->sole();
        $this->assertSame('inventory_issue_item', $cost->reference_type);
        $this->assertSame('material', $cost->cost_category->value);
        $this->assertSame(6200000.0, (float) $cost->amount);
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sum('amount'));
    }

    // ---------------------------------------------------------------- A2a: switched OFF after the receipt

    public function test_perpetual_switched_off_after_the_receipt_still_clears_what_the_receipt_credited(): void
    {
        $po = $this->makePo();
        $grn = $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE, $this->pusat);

        // 100 * 62.000 = 6.200.000 sitting as a credit in GR/IR.
        $this->assertSame(-6200000.0, $this->accountNet('2-1150'));
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());

        // The operator flips the accounting method between delivery and invoice.
        $this->setSetting('accounting.perpetual_inventory', false);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // The bill clears the RECORD, not today's parameter:
        // Dr 2-1150 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('5-1100', $lines);

        // Nothing stranded and nothing expensed twice: 6.200.000 - 6.200.000 = 0.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0, ProjectCost::query()->count());
        // The goods are still an asset — 6.200.000 of persediaan on hand.
        $this->assertSame(6200000.0, $this->accountNet('1-1400'));

        // Put the installation back on perpetual before consuming the stock,
        // which is what an operator who flipped the switch by accident does.
        $this->setSetting('accounting.perpetual_inventory', true);
        $this->issueAll($this->pusat, '2026-03-20');

        // Dr 5-1100 6.200.000 / Cr 1-1400 6.200.000 closes the cycle: the cost
        // is recognised exactly once and every clearing account is empty.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
        $this->assertSame(3, Journal::query()->count()); // receipt, bill, issue
    }

    // ---------------------------------------------------------------- A2b: switched ON after the receipt

    public function test_perpetual_switched_on_after_the_receipt_never_debits_a_credit_that_was_never_raised(): void
    {
        // Periodic inventory at delivery time: quantities move, the ledger does
        // not, and the receipt therefore records NO clearing.
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makePo();
        $grn = $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE, $this->pusat);

        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, Journal::query()->count());
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertFalse($grn->fresh()->hasRecordedClearing());

        // The switch goes on before the invoice is booked.
        $this->setSetting('accounting.perpetual_inventory', true);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Classic treatment, because the receipt recorded nothing to clear:
        // Dr 5-1100 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);

        // THE assertion of A2b: the clearing account is never touched at all —
        // not credited, and above all never DEBITED into the impossible state
        // the audit measured (a liability account with a debit balance).
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, (float) $bill->gl_cleared_amount);

        // Cost recognised once, at billing, as periodic inventory requires.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
        $this->assertSame('ap_bill', ProjectCost::query()->sole()->reference_type);

        // The material is deliberately NOT issued here. Consuming it with the
        // switch now ON would relieve a persediaan balance the receipt never
        // raised — the cost of changing inventory method mid-flow, which is an
        // accounting-policy decision (and a stock-side opening entry), not
        // something the GR/IR predicate can or should paper over. What this
        // test pins is that the predicate itself invents nothing.
    }

    // ---------------------------------------------------------------- partial deliveries

    public function test_two_receipts_against_one_purchase_order_are_cleared_for_the_sum_they_recorded(): void
    {
        $po = $this->makePo();

        // Two truckloads at two different prices, so the sum the bill has to
        // clear cannot be re-derived from the PO — only the receipts know it.
        //   60 zak * 62.000 = 3.720.000
        //   40 zak * 61.500 = 2.460.000
        //   3.720.000 + 2.460.000 = 6.180.000 recorded in GR/IR
        $first = $this->receive($po, 60, 62000, $this->pusat, '2026-03-05');
        $second = $this->receive($po, 40, 61500, $this->pusat, '2026-03-06');

        $this->assertSame(3720000.0, $first->fresh()->recordedClearingAmount());
        $this->assertSame(2460000.0, $second->fresh()->recordedClearingAmount());
        $this->assertSame(-6180000.0, $this->accountNet('2-1150'));

        // 60 + 40 = 100 = the ordered quantity, so Procurement closed the PO.
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        //   Dr 2-1150 3.720.000 + 2.460.000        = 6.180.000
        //   Dr 6-4500 6.200.000 - 6.180.000        =    20.000  (billed above what arrived)
        //   Dr 1-1600                              =   682.000
        //   Cr 2-1100                              = 6.882.000
        // 6.180.000 + 20.000 + 682.000 = 6.882.000.
        $this->assertSame(6180000.0, $lines['2-1150']['debit']);
        $this->assertSame(20000.0, $lines['6-4500']['debit']);
        $this->assertSame(0.0, $lines['6-4500']['credit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertSame(6882000.0, $journal->totalDebit());
        $this->assertSame(6882000.0, $journal->totalCredit());
        $this->assertSame(6180000.0, (float) $bill->gl_cleared_amount);

        // Both credits cleared by the one bill: 6.180.000 - 6.180.000 = 0.
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // Stock carries what arrived: (3.720.000 + 2.460.000) / 100 = 61.800.
        $this->assertSame(61800.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->issueAll($this->pusat, '2026-03-20');

        // 100 * 61.800 = 6.180.000 into project cost, 1-1400 back to zero.
        $this->assertSame(6180000.0, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(
            6180000.0,
            (float) ProjectCost::query()->where('reference_type', 'inventory_issue_item')->sole()->amount
        );

        // And the vendor's DPP is recognised exactly once across the P&L:
        // 6.180.000 material + 20.000 purchase difference = 6.200.000.
        $this->assertSame(6200000.0, round($this->accountNet('5-1100') + $this->accountNet('6-4500'), 2));

        // The project's own books say the same, to the rupiah: the issue
        // carries the material, the bill carries the price difference, and the
        // two together are the 6.200.000 the vendor invoiced. The variance line
        // is charged to the project in the GL, so leaving it out of
        // fin_project_costs would make realisasi disagree with the ledger.
        $this->assertSame(20000.0, (float) ProjectCost::query()
            ->where('reference_type', 'ap_bill')
            ->where('reference_id', $bill->id)
            ->sole()
            ->amount);
        $this->assertSame(6200000.0, round((float) ProjectCost::query()->sum('amount'), 2));
    }

    public function test_a_short_shipment_on_a_closed_purchase_order_leaves_no_residue_in_the_clearing_account(): void
    {
        // Only 60 of the 100 zak ever arrive; the buyer closes the PO to say
        // nothing more is coming, and the vendor still invoices the full order.
        $po = $this->makePo();
        $this->receive($po, 60, self::PO_UNIT_PRICE, $this->pusat);

        app(PoService::class)->close($po->fresh());

        // 60 * 62.000 = 3.720.000 received and recorded.
        $this->assertSame(-3720000.0, $this->accountNet('2-1150'));

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        //   Dr 2-1150                    3.720.000  (exactly what arrived)
        //   Dr 6-4500 6.200.000 - 3.720.000 = 2.480.000  (billed but not delivered)
        //   Dr 1-1600                      682.000
        //   Cr 2-1100                    6.882.000
        $this->assertSame(3720000.0, $lines['2-1150']['debit']);
        $this->assertSame(2480000.0, $lines['6-4500']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);

        // The M4 hazard, restated: the clearing account nets to exactly zero
        // and never carries a debit balance of its own.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(2480000.0, $this->accountNet('6-4500'));
        // Persediaan carries the goods that really arrived, 3.720.000.
        $this->assertSame(3720000.0, $this->accountNet('1-1400'));
        $this->assertSame(3720000.0, $this->balanceValue($this->pusat, $this->semen));
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * An approved purchase order for 100 zak semen with a real STOCK line
     * (item_id set — the schema's own definition of a goods line).
     */
    private function makePo(array $attributes = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'vendor_id' => $this->supplier->id,
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
     * Receive against the PO's stock line for real, so Procurement's received
     * quantity moves and the credit the bill has to clear actually exists.
     */
    private function receive(
        PurchaseOrder $po,
        float $qty,
        float $unitCost,
        Warehouse $into,
        string $date = '2026-03-05',
    ): GoodsReceipt {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $into->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => $date,
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => $this->semen->id,
            'po_item_id' => $po->items()->whereNotNull('item_id')->value('id'),
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => round($qty * $unitCost, 2),
        ]);

        return $this->stock()->postReceipt($grn->refresh());
    }

    private function issueAll(Warehouse $from, string $date): void
    {
        $this->stock()->postIssue($this->makeIssue(
            $from,
            [[$this->semen, $this->balanceQty($from, $this->semen)]],
            (int) $this->project->id,
            $date,
        ));
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
}
