<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
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
 * WHAT A VENDOR BILL MAY CAPITALISE, AND WHAT IT OWES THE PROJECT LEDGER —
 * audits N3 and N4.
 *
 * N3. ApBillService::debitAccountCode used to debit 1-1400 Persediaan Material
 * for ANY non-project PO bill that recorded no clearing. "The bill names a
 * purchase order" is not evidence that the company is holding something.
 * Measured before the repair: a rental PO, "Sewa genset kantor" DPP 5.000.000,
 * put 5.000.000 into 1-1400 against a stock sub-ledger of 0,00 — permanently,
 * because no goods issue can ever relieve an asset no warehouse holds, and the
 * rental was therefore never expensed at all.
 *
 * Capitalising asserts two things at once, and the repair checks both:
 *
 *   the order is genuinely stock — at least one line naming an inventory item
 *       (prc_purchase_order_items.item_id), the same definition the delivery
 *       gate uses. A rental or a service has no such line;
 *   perpetual inventory is off — under periodic the bill is the only document
 *       that can put goods on the balance sheet. Under PERPETUAL a stock PO
 *       reaching the classic path recorded no clearing, which means no receipt
 *       ever debited persediaan: the goods did not arrive, so there is no asset
 *       to add to. That is a cost.
 *
 * The invariant every test here re-checks: GL 1-1400 equals
 * sum(qty * avg_cost) over inv_stock_balances afterwards. An asset balance that
 * no warehouse can account for is exactly the defect.
 *
 * N4. The purchase price variance of a matched bill is posted to the GL
 * CARRYING THE BILL'S PROJECT, but approve() only wrote fin_project_costs on
 * the classic path, so project realisasi disagreed with the ledger by precisely
 * that variance. It is now recorded in the same cost bucket as the material it
 * belongs to.
 *
 * Figures, once:
 *   opening stock   100 zak * 62.000 = 6.200.000 (no PO, no vendor)
 *   rental PO       5.000.000 DPP, PPN 11% = 550.000, total 5.550.000
 *   goods PO        100 zak * 62.000 = 6.200.000 DPP, PPN 682.000, total 6.882.000
 *   delivered at    100 zak * 60.000 = 6.000.000  => variance +200.000
 *   or delivered at 100 zak * 64.000 = 6.400.000  => variance -200.000
 */
class NonStockBillAccountingTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const OPENING_QTY = 100.0;

    private const OPENING_UNIT_COST = 62000.0;

    /** 100 * 62.000 = 6.200.000 */
    private const OPENING_VALUE = 6200000.0;

    private const RENTAL_DPP = 5000000.0;

    /** 5.000.000 * 11% = 550.000 */
    private const RENTAL_PPN = 550000.0;

    private const ORDER_QTY = 100.0;

    private const ORDER_UNIT_PRICE = 62000.0;

    /** 100 * 62.000 = 6.200.000 */
    private const ORDER_DPP = 6200000.0;

    /** 6.200.000 * 11% = 682.000 */
    private const ORDER_PPN = 682000.0;

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

    // ------------------------------------------------------------ N3: only stock is capitalised

    public function test_a_non_project_services_bill_is_expensed_and_never_touches_persediaan(): void
    {
        // Real stock on hand first, so "1-1400 equals the sub-ledger" is a
        // statement about a live balance and not about two zeroes.
        $this->receiveOpeningStock();

        $this->assertSame(self::OPENING_VALUE, $this->accountNet('1-1400'));
        $this->assertSame(self::OPENING_VALUE, $this->stockSubLedgerValue());

        // A rental: one line, no inventory item, no project, no warehouse
        // anywhere in the chain and no goods receipt possible.
        $rental = $this->makeServicesPo();

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $rental->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Dr 6-4100 5.000.000 + Dr 1-1600 550.000 = Cr 2-1100 5.550.000
        $this->assertSame(self::RENTAL_DPP, $lines['6-4100']['debit']);
        $this->assertSame(self::RENTAL_PPN, $lines['1-1600']['debit']);
        $this->assertSame(5550000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('1-1400', $lines);
        $this->assertSame(0.0, (float) $bill->gl_cleared_amount);

        // THE INVARIANT: the genset never entered persediaan, so the general
        // ledger still says exactly what the warehouses say.
        $this->assertSame(self::OPENING_VALUE, $this->accountNet('1-1400'));
        $this->assertSame(self::OPENING_VALUE, $this->stockSubLedgerValue());
        $this->assertSame(
            $this->stockSubLedgerValue(),
            $this->accountNet('1-1400'),
            'GL 1-1400 must equal sum(qty * avg_cost) over inv_stock_balances.',
        );

        // And the rental IS expensed — the defect was not only a misplaced
        // asset, it was a cost that never reached the profit and loss.
        $profitLoss = $this->reports()->profitLoss('2026-01-01', '2026-12-31');

        $this->assertSame(self::RENTAL_DPP, $profitLoss['operating_expenses']['total']);
        $this->assertSame(-self::RENTAL_DPP, $profitLoss['net_profit']);

        // No project on the bill, so nothing reaches the project ledger.
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_goods_order_closed_short_with_no_receipts_is_expensed_not_capitalised(): void
    {
        $this->receiveOpeningStock();

        // A materials PO the vendor never delivered against, closed by the
        // buyer and invoiced anyway. It IS a stock order — it names an
        // inventory item — but under perpetual inventory nothing was received,
        // so no receipt debited persediaan and there is no asset to add to.
        $po = $this->makeGoodsPo();

        app(PoService::class)->close($po);

        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
        $this->assertSame(0, GoodsReceipt::query()->where('purchase_order_id', $po->id)->count());

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Dr 6-4100 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000
        $this->assertSame(self::ORDER_DPP, $lines['6-4100']['debit']);
        $this->assertSame(self::ORDER_PPN, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('1-1400', $lines);

        // Persediaan still holds only the 6.200.000 that a warehouse actually
        // received; the sub-ledger agrees to the rupiah.
        $this->assertSame(self::OPENING_VALUE, $this->accountNet('1-1400'));
        $this->assertSame(self::OPENING_VALUE, $this->stockSubLedgerValue());
        $this->assertSame(self::OPENING_QTY, $this->balanceQty($this->pusat, $this->semen));

        // Nothing was cleared, because nothing was ever credited.
        $this->assertSame(0.0, (float) $bill->gl_cleared_amount);
        $this->assertSame(0, $this->lineCountFor('2-1150'));
    }

    // ------------------------------------------------------------ N4: the variance is a project cost

    public function test_a_purchase_price_variance_on_a_project_reaches_the_project_ledger(): void
    {
        // Ordered at 62.000, delivered at 60.000 (the delivery note price is
        // what the warehouse books), invoiced at the PO price. The 200.000
        // difference is a real cost of this project's material.
        $po = $this->makeGoodsPo(['project_id' => $this->project->id]);

        $this->receiveAgainstPo($po, self::ORDER_QTY, 60000.0, '2026-03-05');

        // 100 * 60.000 = 6.000.000 credited to GR/IR, and the order is complete.
        $this->assertSame(-6000000.0, $this->accountNet('2-1150'));
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $lines = $this->linesByAccount($journal);

        // Dr 2-1150 6.000.000 + Dr 6-4500 200.000 + Dr 1-1600 682.000
        //   = Cr 2-1100 6.882.000     (6.200.000 - 6.000.000 = 200.000)
        $this->assertPostedAndBalanced($journal, '2026-03-10');
        $this->assertSame(6000000.0, $lines['2-1150']['debit']);
        $this->assertSame(200000.0, $lines['6-4500']['debit']);
        $this->assertSame(self::ORDER_PPN, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // The variance line carries the project in the GL…
        $this->assertSame((int) $this->project->id, $lines['6-4500']['project_id']);

        // …so it must carry the project in the cost ledger too. This row is
        // what the audit found missing.
        $varianceCost = ProjectCost::query()->where('reference_type', 'ap_bill')->sole();

        $this->assertSame((int) $this->project->id, (int) $varianceCost->project_id);
        $this->assertSame('material', $varianceCost->cost_category->value);
        $this->assertSame(200000.0, (float) $varianceCost->amount);
        $this->assertSame(200000.0, round((float) ProjectCost::query()->sum('amount'), 2));

        // Consume the material, which is what turns the 6.000.000 asset into
        // cost: 100 zak at the 60.000 moving average.
        $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, self::ORDER_QTY]],
            (int) $this->project->id,
            '2026-03-20',
        ));

        // GL and project ledger, to the rupiah, on the same purchase:
        //   5-1100  6.000.000  material consumed
        //   6-4500    200.000  the vendor billed above the delivery note
        //             6.200.000 = the bill's gross DPP
        $projectPl = $this->reports()->profitLoss('2026-01-01', '2026-12-31', (int) $this->project->id);

        $this->assertSame(6000000.0, $projectPl['cogs']['total']);
        $this->assertSame(200000.0, $projectPl['operating_expenses']['total']);

        $ledgerCost = round($projectPl['cogs']['total'] + $projectPl['operating_expenses']['total'], 2);
        $realisasi = round((float) ProjectCost::query()->where('project_id', $this->project->id)->sum('amount'), 2);

        $this->assertSame(6200000.0, $ledgerCost);
        $this->assertSame(6200000.0, $realisasi);
        $this->assertSame($ledgerCost, $realisasi);
        $this->assertSame(self::ORDER_DPP, $realisasi); // what the vendor billed
        $this->assertSame(6200000.0, $this->reports()->projectProfitability((int) $this->project->id)['total_cost']);

        // Two rows, one per source document, both in the material bucket.
        $this->assertSame(2, ProjectCost::query()->count());
        $this->assertSame(
            6200000.0,
            round((float) ProjectCost::query()->where('cost_category', 'material')->sum('amount'), 2),
        );
    }

    public function test_a_vendor_billing_below_the_delivery_note_records_a_negative_project_cost(): void
    {
        // The mirror image: delivered at 64.000, invoiced at 62.000. The GL
        // CREDITS 6-4500, so the project ledger has to carry a negative cost or
        // the two disagree by 400.000 across the pair of cases.
        $po = $this->makeGoodsPo(['project_id' => $this->project->id]);

        $this->receiveAgainstPo($po, self::ORDER_QTY, 64000.0, '2026-03-05');

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $lines = $this->linesByAccount($journal);

        // Dr 2-1150 6.400.000 + Dr 1-1600 682.000
        //   = Cr 6-4500 200.000 + Cr 2-1100 6.882.000
        //   (6.200.000 - 6.400.000 = -200.000)
        $this->assertPostedAndBalanced($journal, '2026-03-10');
        $this->assertSame(6400000.0, $lines['2-1150']['debit']);
        $this->assertSame(200000.0, $lines['6-4500']['credit']);
        $this->assertSame(0.0, $lines['6-4500']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(-200000.0, $this->accountNet('6-4500'));

        // The cost ledger mirrors the sign, not just the amount.
        $this->assertSame(-200000.0, (float) ProjectCost::query()->sole()->amount);

        $projectPl = $this->reports()->profitLoss('2026-01-01', '2026-12-31', (int) $this->project->id);

        $this->assertSame(-200000.0, $projectPl['operating_expenses']['total']);
        $this->assertSame(
            round((float) ProjectCost::query()->where('project_id', $this->project->id)->sum('amount'), 2),
            $projectPl['operating_expenses']['total'],
        );
    }

    // ------------------------------------------------------------ fixtures

    /**
     * 100 zak already on the shelf at go-live: no PO, no vendor, so the credit
     * goes to equity and no clearing is recorded. It exists here only to give
     * 1-1400 and the stock sub-ledger a non-zero balance to agree on.
     */
    private function receiveOpeningStock(): GoodsReceipt
    {
        return $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-03-01',
        ));
    }

    /**
     * "Sewa genset kantor": an approved PO whose only line names no inventory
     * item. Nothing about it can ever appear in a warehouse.
     */
    private function makeServicesPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->supplier->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::RENTAL_DPP,
            'discount_amount' => 0,
            'dpp' => self::RENTAL_DPP,
            'ppn_rate' => 11.0,
            'ppn_amount' => self::RENTAL_PPN,
            'total' => 5550000.0,
            'status' => DocumentStatus::Approved,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'item_id' => null, // "null for non-stock/service lines" — the schema's own rule
            'description' => 'Sewa genset kantor 1 bulan',
            'qty' => 1,
            'unit' => 'bulan',
            'unit_price' => self::RENTAL_DPP,
            'amount' => self::RENTAL_DPP,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    /**
     * An approved materials PO: 100 zak @ 62.000 on a real stock line.
     */
    private function makeGoodsPo(array $attributes = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'vendor_id' => $this->supplier->id,
            'warehouse_id' => $this->pusat->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::ORDER_DPP,
            'discount_amount' => 0,
            'dpp' => self::ORDER_DPP,
            'ppn_rate' => 11.0,
            'ppn_amount' => self::ORDER_PPN,
            'total' => 6882000.0,
            'status' => DocumentStatus::Approved,
        ], $attributes));

        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->semen->id,
            'description' => 'Semen Portland 50kg',
            'qty' => self::ORDER_QTY,
            'unit' => 'zak',
            'unit_price' => self::ORDER_UNIT_PRICE,
            'amount' => self::ORDER_DPP,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    /**
     * Receive against the PO's stock line at the DELIVERY NOTE price, which is
     * what creates the difference the bill has to account for.
     */
    private function receiveAgainstPo(PurchaseOrder $po, float $qty, float $unitCost, string $date): GoodsReceipt
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
            'unit_cost' => $unitCost,
            'amount' => round($qty * $unitCost, 2),
        ]);

        return $this->stock()->postReceipt($grn->refresh());
    }

    // ------------------------------------------------------------ ledger helpers

    /**
     * Signed movement of one COA account: debit - credit.
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
     * sum(qty * avg_cost) over inv_stock_balances — the stock sub-ledger GL
     * 1-1400 has to reconcile to.
     */
    private function stockSubLedgerValue(): float
    {
        return round((float) DB::table('inv_stock_balances')->sum(DB::raw('qty * avg_cost')), 2);
    }
}
