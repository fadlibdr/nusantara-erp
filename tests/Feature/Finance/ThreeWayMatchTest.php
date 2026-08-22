<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Vendor invoice against a goods purchase order: the third leg of the
 * three-way match (order — receipt — invoice).
 *
 * The audit (M4) showed the match was not a match at all. The bill copied the
 * FULL PO value whatever had actually arrived, the GR/IR decision was a boolean
 * ("has any GRN been posted?") rather than an amount, and the receipt price was
 * free-form user input never compared with the PO. Two reproductions:
 *
 *   partial   PO 100 @ 62.000, received 40 -> GR/IR credited 2.480.000, the
 *             bill debited 6.200.000, leaving 3.720.000 as a DEBIT balance in
 *             a liability account;
 *   price     PO @ 62.000, received @ 65.000 -> GR/IR kept a 300.000 credit
 *             for ever and stock was carried above what was owed.
 *
 * Every case below therefore asserts the same invariant with hand-computed
 * figures: after the invoice, 2-1150 is EXACTLY zero and the difference sits in
 * 6-4500 with the right sign.
 */
class ThreeWayMatchTest extends ErpTestCase
{
    use FinanceFixtures;

    /**
     * The audit's own numbers.
     *
     *   100 zak * 62.000    = 6.200.000  dpp
     *   6.200.000 * 11%     =   682.000  ppn
     *   6.200.000 + 682.000 = 6.882.000  total payable
     */
    private const PO_QTY = 100.0;

    private const PO_UNIT_PRICE = 62000.0;

    private const PO_DPP = 6200000.0;

    private const PO_PPN = 682000.0;

    private const PO_TOTAL = 6882000.0;

    private Vendor $vendor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->vendor = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ---------------------------------------------------------------- exact match

    public function test_a_bill_at_the_po_price_clears_the_clearing_account_to_exactly_zero(): void
    {
        $po = $this->makeGoodsPo();
        $this->receiveGoods($po, self::PO_QTY, self::PO_UNIT_PRICE);

        // 100 * 62.000 = 6.200.000 credited to GR/IR by the receipt.
        $this->assertSame(-6200000.0, $this->accountNet('2-1150'));

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
        $this->assertArrayNotHasKey('6-4500', $lines); // same price: no difference
        $this->assertArrayNotHasKey('5-1100', $lines); // cost waits for the issue
        $this->assertArrayNotHasKey('1-1400', $lines); // the GRN already booked the asset

        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_the_gr_ir_debit_matches_the_receipt_credit_on_a_price_that_rounds(): void
    {
        // Guards the "rupiah for rupiah" claim: both sides round the line amount
        // to the cent before summing, so a fractional quantity leaves no residue.
        //   7,777 m3 * 999,99 = 7.776,92223 -> 7.776,92 dpp
        //   7.776,92 * 11%    =   855,4612  ->   855,46 ppn
        //   7.776,92 + 855,46 = 8.632,38 total payable
        $pasir = $this->makeItemNamed('Pasir Beton', 'm3');
        $po = $this->makeGoodsPo([
            'subtotal' => 7776.92,
            'dpp' => 7776.92,
            'ppn_amount' => 855.46,
            'total' => 8632.38,
        ], qty: 7.777, unitPrice: 999.99, item: $pasir);

        $this->receiveGoods($po, 7.777, 999.99, $pasir);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(7776.92, $lines['2-1150']['debit']);
        $this->assertSame(855.46, $lines['1-1600']['debit']);
        $this->assertSame(8632.38, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('6-4500', $lines);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
    }

    // ---------------------------------------------------------------- price differences

    public function test_a_receipt_above_the_po_price_credits_the_purchase_variance(): void
    {
        $po = $this->makeGoodsPo();
        $this->receiveGoods($po, self::PO_QTY, 65000); // 100 * 65.000 = 6.500.000

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Invoice 6.200.000 - goods in 6.500.000 = -300.000 => CREDIT variance:
        // the company was billed less than the goods were taken in at.
        $this->assertSame(6500000.0, $lines['2-1150']['debit']);
        $this->assertSame(300000.0, $lines['6-4500']['credit']);
        $this->assertSame(0.0, $lines['6-4500']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);

        // 6.500.000 + 682.000 = 7.182.000 = 300.000 + 6.882.000
        $this->assertSame(7182000.0, $journal->totalDebit());
        $this->assertSame(7182000.0, $journal->totalCredit());

        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(-300000.0, $this->accountNet('6-4500'));
    }

    public function test_a_receipt_below_the_po_price_debits_the_purchase_variance(): void
    {
        $po = $this->makeGoodsPo();
        $this->receiveGoods($po, self::PO_QTY, 60000); // 100 * 60.000 = 6.000.000

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Invoice 6.200.000 - goods in 6.000.000 = +200.000 => DEBIT variance:
        // the vendor bills more than arrived, an extra cost.
        $this->assertSame(6000000.0, $lines['2-1150']['debit']);
        $this->assertSame(200000.0, $lines['6-4500']['debit']);
        $this->assertSame(0.0, $lines['6-4500']['credit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);

        // 6.000.000 + 200.000 + 682.000 = 6.882.000
        $this->assertSame(6882000.0, $journal->totalDebit());

        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(200000.0, $this->accountNet('6-4500'));
    }

    public function test_a_partial_delivery_accepted_by_closing_the_po_still_clears_the_clearing_account(): void
    {
        // The M4 partial reproduction, start to finish.
        $po = $this->makeGoodsPo();
        $this->receiveGoods($po, 40, self::PO_UNIT_PRICE); // 40 * 62.000 = 2.480.000

        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $bill->submit($this->financeUser());

        // While the PO is still open the short delivery blocks the invoice.
        try {
            $this->apBills()->approve($bill, $this->financeApprover());
            $this->fail('Expected the partial delivery to block the approval.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('seluruhnya', $e->getMessage());
        }

        // The buyer accepts the short shipment by closing the PO.
        app(PoService::class)->close($po->fresh());

        $this->apBills()->approve($bill->fresh(), $this->financeApprover());

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // GR/IR is cleared for what ARRIVED (2.480.000), never for what was
        // ordered; the rest of the invoice, 6.200.000 - 2.480.000 = 3.720.000,
        // is a purchase difference and not a liability that never existed.
        $this->assertSame(2480000.0, $lines['2-1150']['debit']);
        $this->assertSame(3720000.0, $lines['6-4500']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);

        // Credited 2.480.000, debited 2.480.000 => zero, NOT a 3.720.000 debit
        // balance sitting in a liability account.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(3720000.0, $this->accountNet('6-4500'));

        // Stock still carries only the 40 zak that arrived.
        $this->assertSame(2480000.0, $this->accountNet('1-1400'));
    }

    // ---------------------------------------------------------------- the gate

    public function test_approving_a_goods_po_bill_with_no_posted_receipt_writes_nothing_at_all(): void
    {
        $po = $this->makeGoodsPo();
        $this->makeGoodsReceipt($po, self::PO_QTY, self::PO_UNIT_PRICE); // left in draft

        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $bill->submit($this->financeUser());

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
            $this->fail('Expected a LogicException: the goods have not been received.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($po->code, $e->getMessage());
            $this->assertStringContainsString('setelah barang diterima', $e->getMessage());
        }

        // The whole approval rolled back: status, journal and cost ledger.
        $this->assertSame(DocumentStatus::Submitted, $bill->fresh()->status);
        $this->assertNull($bill->fresh()->approved_at);
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
        $this->assertSame(0, ProjectCost::query()->count());

        // And no stock moved either — the GRN is still a draft.
        $this->assertSame(0, StockBalance::query()->count());
    }

    // ---------------------------------------------------------------- untouched paths

    public function test_a_services_purchase_order_bill_approves_without_any_receipt(): void
    {
        // No deliver-to warehouse => jasa/sewa: nothing enters stock, no GRN
        // will ever exist, and no GR/IR balance was ever raised. The classic
        // expense treatment must still apply, gate and all.
        $po = $this->makeServicesPo();

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Dr 5-1100 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertArrayNotHasKey('6-4500', $lines);

        // The cost belongs to the project the moment the vendor bills it.
        $cost = ProjectCost::query()->sole();
        $this->assertSame('ap_bill', $cost->reference_type);
        $this->assertSame('material', $cost->cost_category->value);
        $this->assertSame(6200000.0, (float) $cost->amount);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
    }

    public function test_periodic_inventory_restores_the_classic_expense_at_billing_treatment(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPo();
        $this->receiveGoods($po, self::PO_QTY, self::PO_UNIT_PRICE);

        // The receipt moved quantity only: no GRN journal, so no GR/IR at all.
        $this->assertSame(0, Journal::query()->count());

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Dr 5-1100 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertArrayNotHasKey('6-4500', $lines);

        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
    }

    // ---------------------------------------------------------------- fixtures

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->firstOrCreate(
            ['code' => 'WH-PUSAT'],
            ['name' => 'Gudang Pusat', 'is_active' => true],
        );
    }

    private function makeItemNamed(string $name, string $unit = 'zak'): Item
    {
        $category = ItemCategory::query()->firstOrCreate(
            ['code' => 'CAT-UMUM'],
            ['name' => 'Material Umum'],
        );

        return Item::query()->firstOrCreate(
            ['name' => $name],
            [
                'category_id' => $category->id,
                'unit' => $unit,
                'item_type' => ItemType::Material,
                'min_stock' => 0,
                'avg_cost' => 0,
                'last_price' => 0,
                'is_active' => true,
            ],
        );
    }

    private function semen(): Item
    {
        return $this->makeItemNamed('Semen Gresik 40kg');
    }

    /**
     * An approved GOODS purchase order: 100 zak @ 62.000 = 6.200.000 dpp,
     * PPN 11% = 682.000, total 6.882.000, delivered into a warehouse.
     */
    private function makeGoodsPo(
        array $attributes = [],
        float $qty = self::PO_QTY,
        float $unitPrice = self::PO_UNIT_PRICE,
        ?Item $item = null,
    ): PurchaseOrder {
        $item ??= $this->semen();

        $po = PurchaseOrder::create(array_merge([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'warehouse_id' => $this->warehouse()->id,
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
            'item_id' => $item->id,
            'description' => $item->name,
            'qty' => $qty,
            'unit' => $item->unit,
            'unit_price' => $unitPrice,
            'amount' => round($qty * $unitPrice, 2),
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    /**
     * The same commercial terms with NO deliver-to warehouse: a services PO.
     */
    private function makeServicesPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::PO_DPP,
            'discount_amount' => 0,
            'dpp' => self::PO_DPP,
            'ppn_rate' => 11.0,
            'ppn_amount' => self::PO_PPN,
            'total' => self::PO_TOTAL,
            'status' => DocumentStatus::Approved,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'description' => 'Sewa alat berat Maret 2026',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => self::PO_DPP,
            'amount' => self::PO_DPP,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    /**
     * A DRAFT goods receipt against the PO line.
     */
    private function makeGoodsReceipt(PurchaseOrder $po, float $qty, float $unitCost, ?Item $item = null): GoodsReceipt
    {
        $item ??= $this->semen();

        $grn = GoodsReceipt::create([
            'warehouse_id' => $po->warehouse_id ?? $this->warehouse()->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => '2026-03-05',
            'received_by' => $this->financeUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => $item->id,
            'po_item_id' => $po->items()->value('id'),
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => round($qty * $unitCost, 2),
        ]);

        return $grn->refresh();
    }

    /**
     * Receive for real through StockService, so the GR/IR credit the bill has
     * to clear actually exists in the ledger.
     */
    private function receiveGoods(PurchaseOrder $po, float $qty, float $unitCost, ?Item $item = null): GoodsReceipt
    {
        return app(StockService::class)->postReceipt($this->makeGoodsReceipt($po, $qty, $unitCost, $item));
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
}
