<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * WHAT A GOODS RECEIPT IS ALLOWED TO CREDIT — audits N1, N2 and the runtime
 * half of A5, checked as balance-sheet arithmetic over a whole cycle rather
 * than one journal at a time.
 *
 * The rule the engine now obeys, and every test below is a measurement of it:
 *
 *   A RECEIPT MAY ONLY RAISE A CREDIT SOME DOCUMENT IN THIS PRODUCT CAN DEBIT
 *   BACK OUT, AND WHEN NO SUCH DOCUMENT EXISTS IT MAY NOT RAISE A LIABILITY AT
 *   ALL.
 *
 * What the audits measured before the repair, on one 100 zak @ 62.000 purchase:
 *
 *   N1 over-delivery   the PO was fully received and billed (2-1150 = 0,00),
 *       20 zak more arrived and were booked against that same PO on a line with
 *       po_item_id NULL. StockService credited 2-1150 another 1.240.000 because
 *       purchase_order_id was merely non-null; createFromPo then refused ("A
 *       bill already exists") and createFromGoodsReceipt refused ("GRN terkait
 *       PO"). Stranded: -1.240.000, permanently.
 *   N1 late delivery   a PO closed short and billed classically (Dr 5-1100
 *       6.200.000) still accepted a receipt afterwards, giving 5-1100 =
 *       12.400.000 for a 6.200.000 purchase and 2-1150 stranded at -6.200.000.
 *   N2 phantom ids     purchase_order_id = 999999 passed validation
 *       ('nullable|integer', no Rule::exists) and credited 2-1150 for an order
 *       that does not exist; an invented vendor_id routed the credit to 2-1600
 *       just as unreachably.
 *   A5 runtime         a receipt with neither PO nor vendor — opening stock —
 *       credited 6-4400 Selisih Persediaan, an EXPENSE account. A credit to an
 *       expense is income: the whole of a company's opening inventory was
 *       reported as operating profit in its go-live year.
 *
 * The figures used throughout, once:
 *
 *   ordered   100 zak * 62.000  = 6.200.000  (stock line on the PO)
 *   freight                       500.000    (service line, item_id NULL)
 *   PO DPP    6.200.000 + 500.000 = 6.700.000, PPN 11% = 737.000, total 7.437.000
 *   over-delivery 20 zak * 62.000 = 1.240.000, PPN 11% = 136.400, total 1.376.400
 *   opening stock 500 zak * 66.000 = 33.000.000
 */
class ReceiptClearingGuardsTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const UNIT_COST = 62000.0;

    private const ORDER_QTY = 100.0;

    /** 100 * 62.000 = 6.200.000 */
    private const ORDER_VALUE = 6200000.0;

    private const FREIGHT = 500000.0;

    /** 6.200.000 + 500.000 = 6.700.000 */
    private const PO_DPP = 6700000.0;

    /** 6.700.000 * 11% = 737.000 */
    private const PO_PPN = 737000.0;

    private const EXTRA_QTY = 20.0;

    /** 20 * 62.000 = 1.240.000 */
    private const EXTRA_VALUE = 1240000.0;

    /** 1.240.000 * 11% = 136.400 */
    private const EXTRA_PPN = 136400.0;

    private const OPENING_QTY = 500.0;

    private const OPENING_UNIT_COST = 66000.0;

    /** 500 * 66.000 = 33.000.000 */
    private const OPENING_VALUE = 33000000.0;

    private Warehouse $pusat;

    private Item $semen;

    private Vendor $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Portland 50kg');
        $this->supplier = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ------------------------------------------------------------ N1: the over-delivery

    public function test_an_over_delivery_after_the_po_bill_accrues_and_is_billed_to_zero(): void
    {
        // A mixed order — 100 zak of semen plus 500.000 of freight — so that
        // receiving the goods in full leaves the order APPROVED (the service
        // line is still open) and its single final bill can be approved while
        // it is. That is the state in which the GR/IR route is spent but the
        // order is still alive, and it is where the audit's 1.240.000 was
        // stranded.
        $po = $this->makeMixedPo();

        $this->receiveAgainstPo($po, self::ORDER_QTY, '2026-03-05');

        // 100 * 62.000 = 6.200.000 into persediaan, credited to GR/IR because
        // this order can still be billed.
        $firstReceipt = GoodsReceipt::query()->latest('id')->sole();
        $this->assertSame('2-1150', $firstReceipt->gl_clearing_account);
        $this->assertSame(self::ORDER_VALUE, $firstReceipt->recordedClearingAmount());
        $this->assertSame(DocumentStatus::Approved, $po->fresh()->status);

        // The PO bill: 6.700.000 DPP against 6.200.000 of goods, so 500.000 of
        // freight lands in the purchase price variance.
        $poBill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));

        $this->assertSame(self::PO_DPP, (float) $poBill->dpp);
        $this->assertSame(self::PO_PPN, (float) $poBill->ppn_amount);

        $poBillLines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $poBill->id));

        // Dr 2-1150 6.200.000 + Dr 6-4500 500.000 + Dr 1-1600 737.000
        //   = Cr 2-1100 7.437.000
        $this->assertSame(self::ORDER_VALUE, $poBillLines['2-1150']['debit']);
        $this->assertSame(self::FREIGHT, $poBillLines['6-4500']['debit']);
        $this->assertSame(self::PO_PPN, $poBillLines['1-1600']['debit']);
        $this->assertSame(7437000.0, $poBillLines['2-1100']['credit']);
        $this->assertSame(0.0, $this->accountNet('2-1150')); // 6.200.000 in, 6.200.000 out

        // 20 zak more arrive. The order is still approved, so the receipt is
        // allowed — but its final bill is approved, so GR/IR can no longer be
        // reached and the credit goes to the penerimaan accrual instead.
        $extra = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::EXTRA_QTY, self::UNIT_COST]],
            '2026-03-12',
            ['purchase_order_id' => $po->id, 'vendor_id' => $this->supplier->id],
        ));

        $extraLines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $extra->id));

        // 20 * 62.000 = 1.240.000: Dr 1-1400 / Cr 2-1600, recorded as clearable.
        $this->assertSame(self::EXTRA_VALUE, $extraLines['1-1400']['debit']);
        $this->assertSame(self::EXTRA_VALUE, $extraLines['2-1600']['credit']);
        $this->assertArrayNotHasKey('2-1150', $extraLines);
        $this->assertSame('2-1600', $extra->fresh()->gl_clearing_account);
        $this->assertSame(self::EXTRA_VALUE, $extra->fresh()->recordedClearingAmount());

        // The unlinked line touched no PO quantity: 100 ordered, 100 received.
        $this->assertSame(100.0, (float) $po->items()->whereNotNull('item_id')->value('qty_received'));

        // The debit path exists and is reachable: a bill against the RECEIPT.
        $extraBill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $extra->id,
            'ppn_amount' => self::EXTRA_PPN,
            'bill_date' => '2026-03-15',
        ]));

        // It defaults to exactly the outstanding accrual, so the two ends of the
        // chain cannot drift: 1.240.000 credited, 1.240.000 billed.
        $this->assertSame(self::EXTRA_VALUE, (float) $extraBill->dpp);
        $this->assertSame(1376400.0, (float) $extraBill->total_payable); // 1.240.000 + 136.400
        $this->assertSame(self::EXTRA_VALUE, (float) $extraBill->gl_cleared_amount);

        $extraBillLines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $extraBill->id));

        // Dr 2-1600 1.240.000 + Dr 1-1600 136.400 = Cr 2-1100 1.376.400
        $this->assertSame(self::EXTRA_VALUE, $extraBillLines['2-1600']['debit']);
        $this->assertSame(self::EXTRA_PPN, $extraBillLines['1-1600']['debit']);
        $this->assertSame(1376400.0, $extraBillLines['2-1100']['credit']);

        // THE INVARIANT. Both transit accounts are empty — nothing stranded in
        // either direction — and every rupiah that arrived is on the balance
        // sheet as stock, exactly once:
        //   1-1400  6.200.000 + 1.240.000 = 7.440.000 = 120 zak * 62.000
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(7440000.0, $this->accountNet('1-1400'));
        $this->assertSame(7440000.0, $this->stockSubLedgerValue());

        // Nothing was expensed on the way: material becomes cost when it is
        // issued, and the freight variance is the only P&L line in the cycle.
        $this->assertSame(0, $this->lineCountFor('5-1100'));
        $this->assertSame(0, $this->lineCountFor('6-4100'));
        $this->assertSame(self::FREIGHT, $this->accountNet('6-4500'));

        // Liabilities: 7.437.000 + 1.376.400 = 8.813.400 owed to the vendor,
        // matched by 7.440.000 stock + 873.400 PPN Masukan + 500.000 variance.
        $this->assertSame(-8813400.0, $this->accountNet('2-1100'));
        $this->assertSame(873400.0, $this->accountNet('1-1600')); // 737.000 + 136.400
        $this->assertSame(0.0, round(
            $this->accountNet('1-1400') + $this->accountNet('1-1600')
            + $this->accountNet('6-4500') + $this->accountNet('2-1100'),
            2,
        ));
    }

    public function test_a_receipt_against_a_closed_order_is_refused_and_the_stock_movement_rolls_back(): void
    {
        // The plain over-delivery: everything ordered has arrived, so
        // Procurement closed the order, and it has been invoiced.
        $po = $this->makeStockPo();
        $this->receiveAgainstPo($po, self::ORDER_QTY, '2026-03-05');

        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));

        $this->assertSame(0.0, $this->accountNet('2-1150'));

        $before = [
            'journals' => Journal::query()->count(),
            'ledger' => StockLedgerEntry::query()->count(),
            'qty' => $this->balanceQty($this->pusat, $this->semen),
            'value' => $this->balanceValue($this->pusat, $this->semen),
            'received' => (float) $po->items()->whereNotNull('item_id')->value('qty_received'),
        ];

        // 20 zak arrive anyway, booked against the closed order on a line with
        // po_item_id NULL — the shape the request permits and the shape that
        // used to slip past every check.
        $extra = $this->makeGrn(
            $this->pusat,
            [[$this->semen, self::EXTRA_QTY, self::UNIT_COST]],
            '2026-03-12',
            ['purchase_order_id' => $po->id, 'vendor_id' => $this->supplier->id],
        );

        try {
            $this->stock()->postReceipt($extra);
            $this->fail('Expected a receipt against a closed purchase order to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($po->code, $e->getMessage());
            $this->assertStringContainsString('closed', $e->getMessage());
        }

        // The refusal is a rollback, not a half-posting: no stock moved, no
        // ledger row, no journal, no PO quantity, and the GRN is still a draft
        // that can be corrected.
        $this->assertSame($before['journals'], Journal::query()->count());
        $this->assertSame($before['ledger'], StockLedgerEntry::query()->count());
        $this->assertSame($before['qty'], $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame($before['value'], $this->balanceValue($this->pusat, $this->semen));
        $this->assertSame($before['received'], (float) $po->items()->whereNotNull('item_id')->value('qty_received'));
        $this->assertSame(StockDocumentStatus::Draft, $extra->fresh()->status);
        $this->assertNull($extra->fresh()->gl_clearing_account);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));

        // 6.200.000 of stock, 6.200.000 in the GL, 100 zak on the shelf.
        $this->assertSame(self::ORDER_VALUE, $this->accountNet('1-1400'));
        $this->assertSame(self::ORDER_VALUE, $this->stockSubLedgerValue());

        // The delivery is still recordable, exactly as the refusal message says:
        // drop the purchase order and book it against the vendor.
        $recorded = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::EXTRA_QTY, self::UNIT_COST]],
            '2026-03-12',
            ['vendor_id' => $this->supplier->id],
        ));

        $this->assertSame('2-1600', $recorded->fresh()->gl_clearing_account);
        $this->assertSame(self::EXTRA_VALUE, $recorded->fresh()->recordedClearingAmount());

        $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $recorded->id,
            'bill_date' => '2026-03-15',
        ]));

        // 6.200.000 + 1.240.000 = 7.440.000 = 120 zak * 62.000, and both
        // clearing accounts are empty again.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(7440000.0, $this->accountNet('1-1400'));
        $this->assertSame(7440000.0, $this->stockSubLedgerValue());
    }

    public function test_goods_arriving_after_a_classic_bill_cannot_expense_the_purchase_twice(): void
    {
        // The audit's variant B: a project PO closed short with NOTHING
        // received, invoiced in full. No receipt recorded any clearing, so the
        // bill takes the classic path and expenses the material outright.
        //
        // Shipped straight to site (no deliver-to warehouse), because a
        // warehouse-bound stock order that received nothing can no longer be
        // billed to a project at all — Finance refuses it up front now (T44,
        // ApBillService::assertOrderedStockWasReceived). This is the shape that
        // legitimately reaches the classic path, and it is the one where a late
        // receipt against the same order would charge the purchase twice.
        $po = $this->makeStockPo(['project_id' => $this->project->id, 'warehouse_id' => null]);

        app(PoService::class)->close($po);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));

        $billLines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Dr 5-1100 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000
        // (100 * 62.000 = 6.200.000; 6.200.000 * 11% = 682.000)
        $this->assertSame(self::ORDER_VALUE, $billLines['5-1100']['debit']);
        $this->assertSame(682000.0, $billLines['1-1600']['debit']);
        $this->assertSame(6882000.0, $billLines['2-1100']['credit']);
        $this->assertSame(self::ORDER_VALUE, (float) ProjectCost::query()->sole()->amount);

        // The goods turn up a week later and the clerk books them against that
        // same order on an unlinked line. Posting this used to debit persediaan
        // and credit GR/IR again — 5-1100 = 12.400.000 for a 6.200.000 purchase
        // once the material was issued, and -6.200.000 stranded in 2-1150.
        $late = $this->makeGrn(
            $this->pusat,
            [[$this->semen, self::ORDER_QTY, self::UNIT_COST]],
            '2026-03-15',
            ['purchase_order_id' => $po->id, 'vendor_id' => $this->supplier->id],
        );

        try {
            $this->stock()->postReceipt($late);
            $this->fail('Expected a receipt against an already-billed closed order to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($po->code, $e->getMessage());
        }

        // The purchase is recognised exactly once, and nowhere else:
        //   5-1100  6.200.000   (the bill, classic treatment)
        //   1-1400  0,00        no goods were ever capitalised
        //   2-1150  no line at all — the credit that had no debit path was
        //                        never raised in the first place
        $this->assertSame(self::ORDER_VALUE, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->stockSubLedgerValue());
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));

        // One journal for the bill, none for the refused receipt, and the
        // project carries the cost once.
        $this->assertSame(1, Journal::query()->count());
        $this->assertSame(StockDocumentStatus::Draft, $late->fresh()->status);
        $this->assertSame(self::ORDER_VALUE, round((float) ProjectCost::query()->sum('amount'), 2));
    }

    // ------------------------------------------------------------ N2: ids must resolve

    public function test_the_store_endpoint_rejects_a_purchase_order_and_a_vendor_that_do_not_exist(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        $response = $this->postJson('/api/inventory/goods-receipts', $this->receiptPayload([
            'purchase_order_id' => 999999,
            'vendor_id' => 424242,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['purchase_order_id', 'vendor_id']);
        $this->assertDatabaseCount('inv_goods_receipts', 0);

        // A soft-deleted purchase order resolves to a row, but not to one any
        // bill can name, so it is refused on the same ground.
        $deleted = $this->makeStockPo();
        $deleted->delete();

        $this->postJson('/api/inventory/goods-receipts', $this->receiptPayload([
            'purchase_order_id' => $deleted->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['purchase_order_id']);

        $this->assertDatabaseCount('inv_goods_receipts', 0);

        // And the rule is not a blanket refusal: real ids still pass.
        $live = $this->makeStockPo();

        $this->postJson('/api/inventory/goods-receipts', $this->receiptPayload([
            'purchase_order_id' => $live->id,
            'vendor_id' => $this->supplier->id,
        ]))->assertCreated();

        $this->assertDatabaseCount('inv_goods_receipts', 1);
    }

    public function test_the_update_endpoint_rejects_ids_that_do_not_exist(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        $created = $this->postJson('/api/inventory/goods-receipts', $this->receiptPayload());
        $created->assertCreated();

        $grnId = (int) $created->json('data.id');

        $this->putJson("/api/inventory/goods-receipts/{$grnId}", ['purchase_order_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_order_id']);

        $this->putJson("/api/inventory/goods-receipts/{$grnId}", ['vendor_id' => 424242])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);

        // Neither id reached the draft, so no later posting can read one.
        $grn = GoodsReceipt::query()->findOrFail($grnId);
        $this->assertNull($grn->purchase_order_id);
        $this->assertNull($grn->vendor_id);
    }

    public function test_an_unresolvable_order_id_credits_an_account_a_bill_can_actually_debit(): void
    {
        // The API can no longer produce this row, but an import, a fixture or a
        // console script can, so the engine resolves the id itself instead of
        // trusting it. 999999 resolves to nothing, so it steers nothing: the
        // vendor — who does resolve — decides the credit.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::ORDER_QTY, self::UNIT_COST]],
            '2026-03-05',
            ['purchase_order_id' => 999999, 'vendor_id' => $this->supplier->id],
        ));

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        // 100 * 62.000 = 6.200.000: Dr 1-1400 / Cr 2-1600, NOT 2-1150 — no bill
        // for purchase order 999999 will ever be raised.
        $this->assertSame(['1-1400', '2-1600'], array_keys($lines));
        $this->assertSame(self::ORDER_VALUE, $lines['1-1400']['debit']);
        $this->assertSame(self::ORDER_VALUE, $lines['2-1600']['credit']);
        $this->assertSame('2-1600', $grn->fresh()->gl_clearing_account);

        // …and that account has a document: a bill against the receipt clears
        // it to zero, which is what "a real debit path" means.
        $bill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $grn->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertSame(self::ORDER_VALUE, (float) $bill->gl_cleared_amount);
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(self::ORDER_VALUE, $this->accountNet('1-1400'));
        $this->assertSame(self::ORDER_VALUE, $this->stockSubLedgerValue());
    }

    public function test_an_unresolvable_vendor_id_invents_no_liability(): void
    {
        // Neither id resolves, so there is no counterparty at all. Booking an
        // accrual here would create a balance no document could remove.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::ORDER_QTY, self::UNIT_COST]],
            '2026-03-05',
            ['vendor_id' => 424242],
        ));

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        // 6.200.000 against equity, and nothing recorded for a bill to chase.
        $this->assertSame(['1-1400', '3-3100'], array_keys($lines));
        $this->assertSame(self::ORDER_VALUE, $lines['3-3100']['credit']);
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertFalse($grn->fresh()->hasRecordedClearing());
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));

        $this->expectExceptionMessage('tidak memiliki akrual penerimaan');

        $this->apBills()->create(['goods_receipt_id' => $grn->id, 'bill_date' => '2026-03-10']);
    }

    // ------------------------------------------------------------ A5 runtime: opening stock

    public function test_opening_stock_is_credited_to_equity_and_never_reported_as_income(): void
    {
        // Go-live: 500 zak already on the shelf. No purchase order, no vendor,
        // no transaction — the counter-entry of an asset that simply exists is
        // equity, not a negative expense.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        $journal = $this->singleJournalFor('goods_receipt', (int) $grn->id);
        $lines = $this->linesByAccount($journal);

        // 500 * 66.000 = 33.000.000: Dr 1-1400 / Cr 3-3100 Saldo Awal.
        $this->assertPostedAndBalanced($journal, '2026-07-01');
        $this->assertSame(self::OPENING_VALUE, $lines['1-1400']['debit']);
        $this->assertSame(self::OPENING_VALUE, $lines['3-3100']['credit']);
        $this->assertArrayNotHasKey('6-4400', $lines);
        $this->assertSame(0, $this->lineCountFor('6-4400'));

        // Nothing is owed and nothing is clearable.
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));

        // The profit and loss is untouched — this is the whole point of the
        // finding. Crediting 6-4400 reported 33.000.000 of operating profit for
        // a company that had not traded at all.
        $profitLoss = $this->reports()->profitLoss('2026-01-01', '2026-12-31');

        $this->assertSame(0.0, $profitLoss['revenue']['total']);
        $this->assertSame(0.0, $profitLoss['cogs']['total']);
        $this->assertSame(0.0, $profitLoss['operating_expenses']['total']);
        $this->assertSame(0.0, $profitLoss['other']['total']);
        $this->assertSame(0.0, $profitLoss['net_profit']);

        // The books balance: 33.000.000 of asset against 33.000.000 of equity.
        $trialBalance = $this->reports()->trialBalance(2026, 7);

        $this->assertTrue($trialBalance['balanced']);
        $this->assertSame(self::OPENING_VALUE, $trialBalance['totals']['closing_debit']);
        $this->assertSame(self::OPENING_VALUE, $trialBalance['totals']['closing_credit']);
        $this->assertSame(self::OPENING_VALUE, $this->accountNet('1-1400'));
        $this->assertSame(self::OPENING_VALUE, $this->stockSubLedgerValue());
    }

    public function test_an_opname_shortage_is_still_an_operating_expense(): void
    {
        // The distinction the repair had to keep: stock that ARRIVES with no
        // counterparty is equity, but stock that goes MISSING really was lost
        // and belongs in the profit and loss.
        $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        // Opname counts 495 of the 500 booked in: 5 zak short.
        $adjustment = $this->stock()->postAdjustment(
            $this->makeAdjustment($this->pusat, [[$this->semen, 495]], '2026-07-31')
        );

        $lines = $this->linesByAccount($this->singleJournalFor('stock_adjustment', (int) $adjustment->id));

        // 5 * 66.000 = 330.000: Dr 6-4400 Selisih Persediaan / Cr 1-1400.
        $this->assertSame(330000.0, $lines['6-4400']['debit']);
        $this->assertSame(330000.0, $lines['1-1400']['credit']);
        $this->assertSame(330000.0, $this->accountNet('6-4400'));

        // 33.000.000 - 330.000 = 32.670.000 = 495 * 66.000, in the ledger and
        // in the sub-ledger alike.
        $this->assertSame(32670000.0, $this->accountNet('1-1400'));
        $this->assertSame(32670000.0, $this->stockSubLedgerValue());

        // The equity credit stays exactly where the receipt put it: an opname
        // loss does not reclassify the opening balance.
        $this->assertSame(-self::OPENING_VALUE, $this->accountNet('3-3100'));

        // And the P&L now carries the loss — 330.000 of operating expense, a
        // NEGATIVE result. The opening balance contributed nothing to it.
        $profitLoss = $this->reports()->profitLoss('2026-01-01', '2026-12-31');

        $this->assertSame(330000.0, $profitLoss['operating_expenses']['total']);
        $this->assertSame(-330000.0, $profitLoss['net_profit']);
        $this->assertSame(0.0, $profitLoss['revenue']['total']);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * An approved purchase order with ONE stock line: 100 zak @ 62.000.
     */
    private function makeStockPo(array $attributes = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'vendor_id' => $this->supplier->id,
            'warehouse_id' => $this->pusat->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::ORDER_VALUE,
            'discount_amount' => 0,
            'dpp' => self::ORDER_VALUE,
            'ppn_rate' => 11.0,
            'ppn_amount' => 682000.0, // 6.200.000 * 11%
            'total' => 6882000.0,
            'status' => DocumentStatus::Approved,
        ], $attributes));

        $this->addStockLine($po);

        return $po->refresh();
    }

    /**
     * The same order plus a 500.000 freight line carrying no inventory item.
     * A service line is never "received", so Procurement leaves the order open
     * while the stock line is complete — and ApBillService's own gate looks at
     * stock lines only, so the final bill can be approved meanwhile.
     */
    private function makeMixedPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->supplier->id,
            'warehouse_id' => $this->pusat->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::PO_DPP,
            'discount_amount' => 0,
            'dpp' => self::PO_DPP,
            'ppn_rate' => 11.0,
            'ppn_amount' => self::PO_PPN,
            'total' => 7437000.0, // 6.700.000 + 737.000
            'status' => DocumentStatus::Approved,
        ]);

        $this->addStockLine($po);

        $po->items()->create([
            'line_no' => 2,
            'description' => 'Ongkos angkut ke gudang',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => self::FREIGHT,
            'amount' => self::FREIGHT,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    private function addStockLine(PurchaseOrder $po): void
    {
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->semen->id,
            'description' => 'Semen Portland 50kg',
            'qty' => self::ORDER_QTY,
            'unit' => 'zak',
            'unit_price' => self::UNIT_COST,
            'amount' => self::ORDER_VALUE,
            'qty_received' => 0,
        ]);
    }

    /**
     * A receipt properly linked to the PO's stock line, so Procurement's
     * received quantity moves and the order closes when it is complete.
     */
    private function receiveAgainstPo(PurchaseOrder $po, float $qty, string $date): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
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
            'unit_cost' => self::UNIT_COST,
            'amount' => round($qty * self::UNIT_COST, 2),
        ]);

        return $this->stock()->postReceipt($grn->refresh());
    }

    /**
     * A valid POST body for the goods receipt endpoint, with the two
     * cross-module ids left out unless the caller supplies them.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function receiptPayload(array $attributes = []): array
    {
        return array_merge([
            'warehouse_id' => $this->pusat->id,
            'receipt_date' => '2026-03-05',
            'items' => [[
                'item_id' => $this->semen->id,
                'qty' => self::ORDER_QTY,
                'unit_cost' => self::UNIT_COST,
            ]],
        ], $attributes);
    }

    // ------------------------------------------------------------ ledger helpers

    /**
     * Signed movement of one COA account: debit - credit. Zero means the
     * account has been fully cleared.
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

    /**
     * sum(qty * avg_cost) over the whole stock sub-ledger — what the warehouses
     * say they are holding, against which GL 1-1400 has to reconcile.
     */
    private function stockSubLedgerValue(): float
    {
        return round((float) DB::table('inv_stock_balances')->sum(DB::raw('qty * avg_cost')), 2);
    }
}
