<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\Journal;
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
 * THE TWO WAYS THE THREE-WAY MATCH WAS STILL BYPASSABLE.
 *
 *   THE UNLINKED LINE. po_item_id is the only thing that reaches
 *   PoService::registerReceipt(), which holds the only over-receipt ceiling in
 *   the system. registerPoReceipt() returned before it whenever the line carried
 *   no reference, so a storeman who typed a row instead of using "Salin baris
 *   dari PO" was uncapped: the audit received 1000 zak against a 100-zak order
 *   with no refusal, qty_received stayed 0.000 so the order never closed,
 *   Rp 15.000.000 went into 1-1400 and 2-1150 for 100 zak that arrived, and the
 *   PO bill then swept the difference into 6-4500 as a Rp 13.500.000 purchase
 *   "gain" plus a MINUS Rp 13.500.000 material row in fin_project_costs.
 *
 *   THE ALREADY-EXPENSED ORDER. ApBillService::approve three-way-matches only
 *   when the receipts recorded a clearing amount to sweep; with nothing received
 *   it takes the classic path and debits 5-1100 gross. Goods arriving afterwards
 *   against that still-APPROVED order were accepted and routed to the 2-1600
 *   accrual — offering Finance a second payable for a delivery already invoiced.
 *   Measured on a copy of the live demo: PO/2026/II/0001 took 5-1100 from
 *   Rp 228.240.000 to Rp 437.740.000 and doubled PRJ-2026-001's material
 *   realisasi against an unchanged RAP.
 *
 * The order used throughout: 100 zak @ Rp 62.000 = Rp 6.200.000, PPN 11% =
 * Rp 682.000, total Rp 6.882.000.
 */
class ThreeWayMatchLinkageTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const UNIT_COST = 62000.0;

    private const ORDER_QTY = 100.0;

    /** 100 * 62.000 */
    private const ORDER_VALUE = 6200000.0;

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

    // ------------------------------------------------------------ the unlinked line

    public function test_an_unlinked_line_that_exceeds_the_ordered_quantity_is_refused(): void
    {
        $po = $this->makeStockPo();

        // The fat finger: 1000 zak on a 100-zak order, keyed by hand so the line
        // carries no po_item_id and never reaches PoService's ceiling.
        $grn = $this->makeUnlinkedGrn($po, 1000);

        try {
            $this->stock()->postReceipt($grn);
            $this->fail('Expected an unlinked over-receipt to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi 100,000 yang dipesan.', $e->getMessage());
            $this->assertStringContainsString($po->code, $e->getMessage());
            $this->assertStringContainsString('Semen Portland 50kg', $e->getMessage());
        }

        // Nothing of the audit's Rp 15.000.000 exists: no stock, no clearing,
        // no order movement, and the GRN is a draft the clerk can repair.
        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0.0, (float) $po->items()->whereNotNull('item_id')->value('qty_received'));
    }

    public function test_the_linked_version_of_the_same_receipt_is_capped_by_the_purchase_order(): void
    {
        $po = $this->makeStockPo();

        // Linked, and now the ceiling that always existed is finally consulted.
        $grn = $this->makeLinkedGrn($po, 1000);

        try {
            $this->stock()->postReceipt($grn);
            $this->fail('Expected the over-receipt ceiling to refuse 1000 against 100.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('exceeds remaining quantity', $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_linked_receipt_within_the_ordered_quantity_posts_and_moves_the_order(): void
    {
        $po = $this->makeStockPo();

        $grn = $this->stock()->postReceipt($this->makeLinkedGrn($po, self::ORDER_QTY));

        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertSame(self::ORDER_QTY, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(self::ORDER_QTY, (float) $po->items()->whereNotNull('item_id')->value('qty_received'));
        // The delivery is complete, so Procurement closes the order.
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
        $this->assertSame('2-1150', $grn->fresh()->gl_clearing_account);
    }

    public function test_an_unlinked_partial_delivery_within_the_ordered_quantity_still_posts(): void
    {
        // The ceiling is a quantity, not a paperwork demand: 40 zak of a 100-zak
        // order arriving on a hand-keyed line is an ordinary partial delivery and
        // has always been allowed.
        $po = $this->makeStockPo();

        $grn = $this->stock()->postReceipt($this->makeUnlinkedGrn($po, 40));

        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertSame(40.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_two_unlinked_deliveries_cannot_walk_past_the_order_between_them(): void
    {
        // The reason the ceiling is cumulative: an unlinked line never moves
        // qty_received, so per-receipt arithmetic would wave through 60 + 60
        // against an order for 100.
        $po = $this->makeStockPo();

        $this->stock()->postReceipt($this->makeUnlinkedGrn($po, 60));

        $second = $this->makeUnlinkedGrn($po, 60, '2026-03-06');

        try {
            $this->stock()->postReceipt($second);
            $this->fail('Expected the cumulative ceiling to refuse the second delivery.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('menjadi 120,000', $e->getMessage());
            $this->assertStringContainsString('melebihi 100,000 yang dipesan.', $e->getMessage());
        }

        $this->assertSame(60.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(StockDocumentStatus::Draft, $second->fresh()->status);
    }

    public function test_an_unlinked_line_for_an_article_the_order_never_mentioned_is_still_allowed(): void
    {
        // A substituted article is exactly what an unlinked line is FOR, and
        // assertPurchaseOrderCanReceive's docblock says so. Narrowing the guard
        // to articles the order is still waiting for is what keeps this open.
        $po = $this->makeStockPo();
        $paku = $this->makeItem('Paku Beton 3 inci', ['unit' => 'kg']);

        $grn = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $this->supplier->id,
            'receipt_date' => '2026-03-05',
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);
        $grn->items()->create([
            'item_id' => $paku->id,
            'qty' => 20,
            'unit_cost' => 25000,
            'amount' => 500000,
        ]);

        $posted = $this->stock()->postReceipt($grn->refresh());

        $this->assertSame(StockDocumentStatus::Posted, $posted->fresh()->status);
        $this->assertSame(20.0, $this->balanceQty($this->pusat, $paku));
    }

    public function test_a_late_delivery_after_the_ordered_quantity_is_complete_is_still_allowed(): void
    {
        // A mixed order: receiving the goods in full leaves it APPROVED (the
        // service line is open) with the stock line fully received, which is the
        // over-delivery shape receiptCreditLeg() routes to the accrual.
        $po = $this->makeStockPo();
        $po->items()->create([
            'line_no' => 2,
            'description' => 'Ongkos angkut ke gudang',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 500000,
            'amount' => 500000,
            'qty_received' => 0,
        ]);

        $this->stock()->postReceipt($this->makeLinkedGrn($po, self::ORDER_QTY));
        $this->assertSame(DocumentStatus::Approved, $po->fresh()->status);

        $extra = $this->stock()->postReceipt($this->makeUnlinkedGrn($po, 20, '2026-03-12'));

        $this->assertSame(StockDocumentStatus::Posted, $extra->fresh()->status);
        $this->assertSame(120.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_line_pointing_at_another_orders_po_line_is_refused_rather_than_skipped(): void
    {
        // registerPoReceipt() dropped a mismatched reference silently, which is
        // the same ceiling bypass wearing a reference.
        $po = $this->makeStockPo();
        $other = $this->makeStockPo();

        $grn = $this->makeUnlinkedGrn($po, 1000);
        $grn->items()->update(['po_item_id' => $other->items()->whereNotNull('item_id')->value('id')]);

        try {
            $this->stock()->postReceipt($grn->fresh());
            $this->fail('Expected a foreign po_item_id to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('bukan milik PO '.$po->code, $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Draft, $grn->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
    }

    // ------------------------------------------------------- the expensed order

    public function test_goods_cannot_be_received_against_an_approved_order_whose_bill_was_expensed(): void
    {
        // A free-text PO — items.*.item_id is nullable, so a materials order can
        // carry no stock line at all — lets ApBillService::assertStockCommitmentSettled
        // through with nothing received. The bill then takes the classic path.
        $po = $this->makeFreeTextPo();

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));

        $billLines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));
        $this->assertSame(self::ORDER_VALUE, $billLines['5-1100']['debit']);
        // Nothing was swept, which is exactly what makes the goods already
        // expensed rather than capitalised.
        $this->assertSame(0.0, round((float) $bill->fresh()->gl_cleared_amount, 2));
        $this->assertSame(DocumentStatus::Approved, $po->fresh()->status);

        // The goods turn up. Posting this used to capitalise them AND raise a
        // second payable in 2-1600 for a delivery already invoiced.
        $late = $this->makeUnlinkedGrn($po, self::ORDER_QTY, '2026-03-15');

        try {
            $this->stock()->postReceipt($late);
            $this->fail('Expected a receipt against an already-expensed order to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($po->code, $e->getMessage());
            $this->assertStringContainsString('terhitung dua kali', $e->getMessage());
        }

        // The purchase is recognised exactly once and nowhere else.
        $this->assertSame(self::ORDER_VALUE, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(StockDocumentStatus::Draft, $late->fresh()->status);
        $this->assertSame(self::ORDER_VALUE, round((float) ProjectCost::query()->sum('amount'), 2));
    }

    public function test_the_same_delivery_is_recordable_against_the_vendor_without_the_order(): void
    {
        // The refusal names the way out, so the way out has to work: drop the PO
        // and the delivery raises the accrual a bill against the receipt clears.
        $po = $this->makeFreeTextPo();
        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));

        $grn = $this->makeUnlinkedGrn($po, self::ORDER_QTY, '2026-03-15');
        $grn->forceFill(['purchase_order_id' => null])->save();

        $posted = $this->stock()->postReceipt($grn->fresh());

        $this->assertSame(StockDocumentStatus::Posted, $posted->fresh()->status);
        $this->assertSame('2-1600', $posted->fresh()->gl_clearing_account);
        $this->assertSame(self::ORDER_VALUE, $posted->fresh()->recordedClearingAmount());
    }

    public function test_an_order_whose_bill_three_way_matched_still_accepts_a_later_delivery(): void
    {
        // gl_cleared_amount > 0 means that bill DID sweep receipts, so goods
        // arriving after it are a genuine over-delivery and keep the accrual
        // route — the guard must not fire here.
        $po = $this->makeStockPo();
        $po->items()->create([
            'line_no' => 2,
            'description' => 'Ongkos angkut ke gudang',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 500000,
            'amount' => 500000,
            'qty_received' => 0,
        ]);

        $this->stock()->postReceipt($this->makeLinkedGrn($po, self::ORDER_QTY));

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));
        $this->assertGreaterThan(0.0, (float) $bill->fresh()->gl_cleared_amount);

        $extra = $this->stock()->postReceipt($this->makeUnlinkedGrn($po, 20, '2026-03-12'));

        $this->assertSame(StockDocumentStatus::Posted, $extra->fresh()->status);
        $this->assertSame('2-1600', $extra->fresh()->gl_clearing_account);
    }

    // ----------------------------------------------------------------- fixtures

    private function makeStockPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->supplier->id,
            'warehouse_id' => $this->pusat->id,
            'project_id' => $this->project->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::ORDER_VALUE,
            'discount_amount' => 0,
            'dpp' => self::ORDER_VALUE,
            'ppn_rate' => 11.0,
            'ppn_amount' => 682000.0,
            'total' => 6882000.0,
            'status' => DocumentStatus::Approved,
        ]);

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

        return $po->refresh();
    }

    /**
     * The same order priced as free text: no line names an inventory item, so
     * ApBillService::assertStockCommitmentSettled has nothing to hold the bill
     * back with. PurchaseOrderStoreRequest permits it (items.*.item_id is
     * nullable), which is what makes the classic-expensing shape reachable.
     */
    private function makeFreeTextPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->supplier->id,
            'warehouse_id' => $this->pusat->id,
            'project_id' => $this->project->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::ORDER_VALUE,
            'discount_amount' => 0,
            'dpp' => self::ORDER_VALUE,
            'ppn_rate' => 11.0,
            'ppn_amount' => 682000.0,
            'total' => 6882000.0,
            'status' => DocumentStatus::Approved,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'description' => 'Semen Portland 50kg (teks bebas, tanpa item master)',
            'qty' => self::ORDER_QTY,
            'unit' => 'zak',
            'unit_price' => self::UNIT_COST,
            'amount' => self::ORDER_VALUE,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    private function makeUnlinkedGrn(PurchaseOrder $po, float $qty, string $date = '2026-03-05'): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $this->supplier->id,
            'receipt_date' => $date,
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => $this->semen->id,
            'qty' => $qty,
            'unit_cost' => self::UNIT_COST,
            'amount' => round($qty * self::UNIT_COST, 2),
        ]);

        return $grn->refresh();
    }

    private function makeLinkedGrn(PurchaseOrder $po, float $qty, string $date = '2026-03-05'): GoodsReceipt
    {
        $grn = $this->makeUnlinkedGrn($po, $qty, $date);

        $grn->items()->update(['po_item_id' => $po->items()->whereNotNull('item_id')->value('id')]);

        return $grn->refresh();
    }

    private function accountNet(string $code): float
    {
        $row = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', $code)
            ->whereNull('fin_journals.deleted_at')
            ->where('fin_journals.status', 'posted')
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) - COALESCE(SUM(fin_journal_lines.credit), 0) AS net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }
}
