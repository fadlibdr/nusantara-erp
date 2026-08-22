<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\ApBill;
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
 * UANG MUKA — the workflow the first repair broke (audit A3), plus the services
 * PO it made unbillable (A4).
 *
 * A 20–30 % down payment against a material PO is the ordinary payment term for
 * Indonesian construction purchases. After the H1 fix it could not be recorded
 * at all: assertGoodsReceived refused to approve a goods-PO bill before a GRN
 * was posted, and PaymentService::settleBill only settles APPROVED bills — so
 * the DP could be neither booked nor paid, and the operator was pushed into a
 * manual JV outside the AP sub-ledger.
 *
 * A4 was the same gate seen from the other side: "warehouse_id != null" was
 * taken to mean "goods PO", so a rental or service PO raised from a PR that
 * named a warehouse demanded a goods receipt that would never exist.
 *
 * What the two ends now agree on:
 *
 *   an ADVANCE is a prepaid ASSET (1-1500 Uang Muka Proyek). No goods exist, so
 *   no gate applies, no project cost is booked, and the final bill credits it
 *   back out;
 *   the goods gate looks at the PO's STOCK LINES (prc_purchase_order_items
 *   .item_id), which is what actually determines whether a delivery is owed.
 *
 * Every figure is hand-computed beside its assertion:
 *
 *   PO       100 zak * 62.000        = 6.200.000 dpp
 *            6.200.000 * 11%         =   682.000 ppn      total 6.882.000
 *   uang muka 30%  6.200.000 * 0,30  = 1.860.000 dpp
 *            1.860.000 * 11%         =   204.600 ppn      total 2.064.600
 *   pelunasan 6.200.000 - 1.860.000  = 4.340.000 dpp
 *              682.000 -   204.600   =   477.400 ppn      total 4.817.400
 */
class AdvancePaymentTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const PO_QTY = 100.0;

    private const PO_UNIT_PRICE = 62000.0;

    private const PO_DPP = 6200000.0;

    private const PO_PPN = 682000.0;

    private const PO_TOTAL = 6882000.0;

    private const ADVANCE_DPP = 1860000.0;

    private const ADVANCE_PPN = 204600.0;

    private const ADVANCE_TOTAL = 2064600.0;

    private Warehouse $pusat;

    private Item $semen;

    private Vendor $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->supplier = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ---------------------------------------------------------------- the advance is bookable

    public function test_an_advance_on_a_goods_purchase_order_is_approved_with_no_goods_receipt(): void
    {
        $po = $this->makeGoodsPo();

        // Nothing has been delivered — this is the whole point of a DP.
        $this->assertSame(0.0, (float) $po->items()->sum('qty_received'));
        $this->assertSame(0, GoodsReceipt::query()->count());

        $advance = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => self::ADVANCE_DPP,
            'bill_date' => '2026-03-02',
        ]));

        $this->assertTrue($advance->isAdvance());
        $this->assertSame(DocumentStatus::Approved, $advance->status);

        // 6.200.000 * 30% = 1.860.000; 1.860.000 * 11% = 204.600;
        // 1.860.000 + 204.600 = 2.064.600 payable.
        $this->assertSame(1860000.0, (float) $advance->dpp);
        $this->assertSame(204600.0, (float) $advance->ppn_amount);
        $this->assertSame(2064600.0, (float) $advance->total_payable);

        $journal = $this->singleJournalFor('ap_bill', (int) $advance->id);
        $this->assertPostedAndBalanced($journal, '2026-03-02');

        $lines = $this->linesByAccount($journal);

        // Dr 1-1500 Uang Muka Proyek 1.860.000
        // Dr 1-1600 PPN Masukan        204.600
        // Cr 2-1100 Hutang Usaha     2.064.600
        $this->assertSame(1860000.0, $lines['1-1500']['debit']);
        $this->assertSame(204600.0, $lines['1-1600']['debit']);
        $this->assertSame(2064600.0, $lines['2-1100']['credit']);
        $this->assertSame(2064600.0, $journal->totalDebit());
        $this->assertSame(2064600.0, $journal->totalCredit());

        // A prepayment is an asset: no expense, no GR/IR, no realisasi proyek.
        $this->assertArrayNotHasKey('5-1100', $lines);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertSame(1860000.0, $this->accountNet('1-1500'));
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, ProjectCost::query()->count());

        // It cleared nothing and consumed nothing — those are the final bill's job.
        $this->assertSame(0.0, (float) $advance->gl_cleared_amount);
        $this->assertSame(0.0, (float) $advance->advance_applied_amount);
    }

    // ---------------------------------------------------------------- the advance is payable

    public function test_the_advance_can_be_paid_out_through_the_payment_service(): void
    {
        $bank = $this->makeBankAccount('1-1210');
        $po = $this->makeGoodsPo();

        $advance = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => self::ADVANCE_DPP,
            'bill_date' => '2026-03-02',
        ]));

        // draft -> submitted (by the clerk) -> approved (by a second person)
        // -> posted: a disbursement no longer goes straight to the ledger.
        $payment = $this->approvedOutgoingPayment(
            [
                'payment_date' => '2026-03-03',
                'bank_account_id' => $bank->id,
                'amount' => self::ADVANCE_TOTAL,
            ],
            [[
                'payable_type' => 'ap_bill',
                'payable_id' => $advance->id,
                'amount' => self::ADVANCE_TOTAL,
            ]],
            (int) $this->financeUser()->id,
        );

        // A real disbursement document exists — the thing the previous round
        // made impossible, because only an APPROVED bill can be settled.
        $this->assertStringStartsWith('PAY/', $payment->code);
        $this->assertSame(PaymentStatus::Posted, $payment->status);
        $this->assertCount(1, $payment->allocations);

        $journal = $this->singleJournalFor('payment', (int) $payment->id);
        $this->assertPostedAndBalanced($journal, '2026-03-03');

        $lines = $this->linesByAccount($journal);

        // Dr 2-1100 Hutang Usaha 2.064.600 / Cr 1-1210 Bank 2.064.600
        $this->assertSame(2064600.0, $lines['2-1100']['debit']);
        $this->assertSame(2064600.0, $lines['1-1210']['credit']);

        $advance = $advance->fresh();
        $this->assertSame(2064600.0, (float) $advance->amount_paid);
        $this->assertSame(0.0, $advance->outstanding()); // 2.064.600 - 2.064.600
        $this->assertTrue($advance->isFullyPaid());
        $this->assertNotNull($advance->paid_at);

        // Billed 2.064.600, paid 2.064.600 => the payable is settled, and the
        // 1.860.000 prepayment sits in the asset account waiting for the goods.
        $this->assertSame(0.0, $this->accountNet('2-1100'));
        $this->assertSame(1860000.0, $this->accountNet('1-1500'));
        $this->assertSame(-2064600.0, $this->accountNet('1-1210'));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    // ---------------------------------------------------------------- the final bill nets it off

    public function test_the_final_bill_nets_the_advance_back_to_exactly_zero(): void
    {
        $bank = $this->makeBankAccount('1-1210');
        $po = $this->makeGoodsPo();

        $advance = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => self::ADVANCE_DPP,
            'bill_date' => '2026-03-02',
        ]));

        $this->approvedOutgoingPayment(
            [
                'payment_date' => '2026-03-03',
                'bank_account_id' => $bank->id,
                'amount' => self::ADVANCE_TOTAL,
            ],
            [['payable_type' => 'ap_bill', 'payable_id' => $advance->id, 'amount' => self::ADVANCE_TOTAL]],
        );

        // The goods finally arrive: Dr 1-1400 6.200.000 / Cr 2-1150 6.200.000.
        $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        $final = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]);

        // Pelunasan is priced net of the DP:
        //   6.200.000 - 1.860.000 = 4.340.000 dpp
        //     682.000 -   204.600 =   477.400 ppn
        //   4.340.000 +   477.400 = 4.817.400 payable
        $this->assertFalse($final->isAdvance());
        $this->assertSame(4340000.0, (float) $final->dpp);
        $this->assertSame(477400.0, (float) $final->ppn_amount);
        $this->assertSame(4817400.0, (float) $final->total_payable);

        $final = $this->approveBill($final);

        $journal = $this->singleJournalFor('ap_bill', (int) $final->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        //   Dr 2-1150 Penerimaan Barang Belum Ditagih  6.200.000
        //   Dr 1-1600 PPN Masukan                        477.400
        //   Cr 1-1500 Uang Muka Proyek                 1.860.000
        //   Cr 2-1100 Hutang Usaha                     4.817.400
        // 6.200.000 + 477.400 = 6.677.400 = 1.860.000 + 4.817.400.
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertSame(477400.0, $lines['1-1600']['debit']);
        $this->assertSame(1860000.0, $lines['1-1500']['credit']);
        $this->assertSame(4817400.0, $lines['2-1100']['credit']);
        $this->assertSame(6677400.0, $journal->totalDebit());
        $this->assertSame(6677400.0, $journal->totalCredit());

        // gross dpp = 4.340.000 + 1.860.000 = 6.200.000 = the value received,
        // so there is no purchase price difference to book.
        $this->assertArrayNotHasKey('6-4500', $lines);
        $this->assertSame(1860000.0, (float) $final->advance_applied_amount);
        $this->assertSame(6200000.0, (float) $final->gl_cleared_amount);

        // THE assertion: the prepayment is consumed, not double-counted.
        // 1.860.000 debited by the advance - 1.860.000 credited here = 0.
        $this->assertSame(0.0, $this->accountNet('1-1500'));

        // Hutang Usaha carries the remainder and nothing else:
        // -2.064.600 (advance) + 2.064.600 (paid) - 4.817.400 (pelunasan).
        $this->assertSame(-4817400.0, $this->accountNet('2-1100'));
        $this->assertSame(4817400.0, $final->fresh()->outstanding());

        // PPN Masukan totals the PO's own PPN exactly once:
        // 204.600 + 477.400 = 682.000.
        $this->assertSame(682000.0, $this->accountNet('1-1600'));
        // And the vendor was billed the PO total once: 2.064.600 + 4.817.400.
        $this->assertSame(
            6882000.0,
            round((float) ApBill::query()->where('purchase_order_id', $po->id)->sum('total_payable'), 2),
        );

        // Consumption is still the only step that creates cost.
        $this->issueAll('2026-03-20');

        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
        // Per-line issue reference — see StockService's per-item cost rows.
        $this->assertSame('inventory_issue_item', ProjectCost::query()->sole()->reference_type);
    }

    // ---------------------------------------------------------------- the gate is still a gate

    public function test_a_non_advance_bill_on_a_goods_purchase_order_with_no_receipt_is_still_refused(): void
    {
        // Relaxing the gate for advances must not relax it for the invoice
        // itself: those goods will arrive later, debit persediaan and then debit
        // project cost when issued, so expensing them now books them twice (H1).
        $po = $this->makeGoodsPo();

        $bill = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]);
        $bill->submit($this->financeUser());

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
            $this->fail('Expected the goods-receipt gate to refuse the approval.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($po->code, $e->getMessage());
            $this->assertStringContainsString('setelah barang diterima', $e->getMessage());
        }

        // The whole approval rolled back: no journal, no cost, still submitted.
        $this->assertSame(DocumentStatus::Submitted, ApBill::query()->find($bill->id)->status);
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0, ProjectCost::query()->count());
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0, $this->lineCountFor('2-1150'));

        // …and an unapproved bill cannot be paid either, which is exactly why
        // the advance shape had to exist rather than the gate be loosened.
        $bank = $this->makeBankAccount('1-1210');
        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-03-11',
            'bank_account_id' => $bank->id,
            'amount' => self::PO_TOTAL,
        ]);

        try {
            // Refused at submit now: a disbursement's allocations are validated
            // when they are typed, before an approver is asked to agree to them.
            $this->payments()->submit($payment, [[
                'payable_type' => 'ap_bill',
                'payable_id' => $bill->id,
                'amount' => self::PO_TOTAL,
            ]], $this->financeUser());
            $this->fail('Expected an unapproved bill to be unpayable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('is not approved', $e->getMessage());
        }

        $this->assertSame(0, Journal::query()->count());
    }

    // ---------------------------------------------------------------- A4: services PO with a warehouse

    public function test_a_services_purchase_order_that_carries_a_warehouse_bills_with_no_receipt(): void
    {
        // Raised from a PR that named a gudang, so warehouse_id is set — but
        // every line is a service (item_id NULL), so nothing will ever be
        // received into it. Under the old predicate this PO could never be
        // billed, and closing it did not help because the gate ran regardless.
        $po = $this->makeServicesPo(['warehouse_id' => $this->pusat->id]);

        $this->assertNotNull($po->warehouse_id);
        $this->assertNull($po->items()->value('item_id'));
        $this->assertSame(DocumentStatus::Approved, $po->status);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Classic expense treatment, because no receipt recorded any clearing:
        // Dr 5-1100 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertSame(6882000.0, $journal->totalDebit());
        $this->assertSame((int) $this->project->id, $lines['5-1100']['project_id']);

        // Nothing pretends a delivery happened.
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertArrayNotHasKey('1-1400', $lines);
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0.0, (float) $bill->gl_cleared_amount);
        $this->assertSame(0, GoodsReceipt::query()->count());

        // The service is the cost, booked once, on the bill.
        $cost = ProjectCost::query()->sole();
        $this->assertSame('ap_bill', $cost->reference_type);
        $this->assertSame((int) $bill->id, (int) $cost->reference_id);
        $this->assertSame(6200000.0, (float) $cost->amount);
    }

    public function test_a_services_purchase_order_with_a_warehouse_also_supports_the_advance_and_its_payment(): void
    {
        // The A3 and A4 repairs have to compose: a rental PO with a warehouse,
        // paid 30 % up front and settled on completion.
        $bank = $this->makeBankAccount('1-1210');
        $po = $this->makeServicesPo(['warehouse_id' => $this->pusat->id]);

        $advance = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => self::ADVANCE_DPP,
            'bill_date' => '2026-03-02',
        ]));

        $payment = $this->approvedOutgoingPayment(
            [
                'payment_date' => '2026-03-03',
                'bank_account_id' => $bank->id,
                'amount' => self::ADVANCE_TOTAL,
            ],
            [['payable_type' => 'ap_bill', 'payable_id' => $advance->id, 'amount' => self::ADVANCE_TOTAL]],
        );

        $this->assertSame(PaymentStatus::Posted, $payment->status);

        $final = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $final->id));

        //   Dr 5-1100 gross dpp 4.340.000 + 1.860.000 = 6.200.000
        //   Dr 1-1600                                     477.400
        //   Cr 1-1500                                   1.860.000
        //   Cr 2-1100                                   4.817.400
        // 6.200.000 + 477.400 = 6.677.400 = 1.860.000 + 4.817.400.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(477400.0, $lines['1-1600']['debit']);
        $this->assertSame(1860000.0, $lines['1-1500']['credit']);
        $this->assertSame(4817400.0, $lines['2-1100']['credit']);

        // The prepayment is back to zero and the service cost is booked once.
        $this->assertSame(0.0, $this->accountNet('1-1500'));
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
        $this->assertSame(1860000.0, (float) $final->advance_applied_amount);
        $this->assertSame(0.0, (float) $final->gl_cleared_amount);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * An approved GOODS purchase order: 100 zak semen on a real stock line
     * (item_id set), delivered into WH-PUSAT.
     */
    private function makeGoodsPo(array $attributes = []): PurchaseOrder
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
     * The same commercial terms on a SERVICE line (item_id null): equipment
     * rental for the month. The caller decides whether it names a warehouse.
     */
    private function makeServicesPo(array $attributes = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'vendor_id' => $this->supplier->id,
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
        ], $attributes));

        $po->items()->create([
            'line_no' => 1,
            'description' => 'Sewa excavator Maret 2026',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => self::PO_DPP,
            'amount' => self::PO_DPP,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    private function receive(PurchaseOrder $po, float $qty, float $unitCost): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $po->warehouse_id ?? $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => '2026-03-05',
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

    private function issueAll(string $date): void
    {
        $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, self::PO_QTY]],
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
