<?php

namespace Tests\Feature\Finance;

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
 * Regression suite for the second audit of the GR/IR chain. Its finding was that
 * the predicate was ASYMMETRIC and re-derived live at both ends: the receipt
 * decided which liability to credit from its PO link, while the bill decided
 * whether to clear it from the PO's deliver-to WAREHOUSE and from the CURRENT
 * value of accounting.perpetual_inventory. Five reproductions followed:
 *
 *   A1  a PO with warehouse_id = NULL (delivered straight to site): the GRN
 *       credited 2-1150, the bill took the classic path, and 6.200.000 of
 *       material landed in 5-1100 twice with 2-1150 stranded at -6.200.000;
 *   A2  the perpetual switch toggled between receipt and invoice, stranding a
 *       credit one way and debiting a credit that never existed the other;
 *   A3  advance payments (uang muka) had become impossible — the goods-received
 *       gate refused approval and PaymentService only settles approved bills;
 *   A4  a services PO that carried a warehouse_id could never be billed, because
 *       it demanded a goods receipt that would never exist;
 *   A5  the PO-less receipt accrual (2-1600) had no debit path at all.
 *
 * The repair is a single change of principle: the receipt RECORDS what it
 * credited, and the bill clears exactly that record. Every figure below is
 * hand-computed next to its assertion, on the audit's own numbers — 100 zak
 * semen @ 62.000.
 */
class AdvanceAndReceiptClearingTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    /**
     *   100 zak * 62.000    = 6.200.000  dpp
     *   6.200.000 * 11%     =   682.000  ppn
     *   6.200.000 + 682.000 = 6.882.000  total payable
     */
    private const PO_QTY = 100.0;

    private const PO_UNIT_PRICE = 62000.0;

    private const PO_DPP = 6200000.0;

    private const PO_PPN = 682000.0;

    private const PO_TOTAL = 6882000.0;

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
        $this->supplier = $this->vendor();
        $this->project = $this->makeProject();
    }

    // ------------------------------------------------------------ A1: PO with no warehouse

    public function test_a_purchase_order_delivered_straight_to_site_recognises_the_cost_exactly_once(): void
    {
        // The A1 reproduction: material bought for a site with no warehouse row.
        // The old bill asked "does the PO have a warehouse?", got NULL, and
        // expensed the goods that the GRN had already parked in GR/IR.
        $po = $this->makeGoodsPo(['warehouse_id' => null]);
        $this->assertNull($po->warehouse_id);

        $grn = $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        // The receipt records what it did; that record — not the PO's shape — is
        // what the bill clears.
        $this->assertSame('2-1150', $grn->fresh()->gl_clearing_account);
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Dr 2-1150 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('5-1100', $lines);
        $this->assertArrayNotHasKey('6-4500', $lines);

        $this->issueAll('2026-03-20');

        // 6.200.000 ONCE — the audit measured 12.400.000 here.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        // And no stranded liability: the audit measured -6.200.000.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));

        $cost = ProjectCost::query()->sole();
        // Per-LINE reference since StockService records issue costs per item
        // ('inventory_issue_item', line id) so WBS attribution survives.
        $this->assertSame('inventory_issue_item', $cost->reference_type);
        $this->assertSame(6200000.0, (float) $cost->amount);
    }

    // ------------------------------------------------------------ A2: the switch toggled mid-flow

    public function test_switching_perpetual_off_after_the_receipt_still_clears_what_the_receipt_credited(): void
    {
        // A2(a): GRN posted ON, switch turned OFF, bill approved. The old bill
        // read the switch live, fell back to the classic expense, and left
        // 2-1150 credited with 6.200.000 nobody could ever debit.
        $po = $this->makeGoodsPo();
        $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        $this->assertSame(-6200000.0, $this->accountNet('2-1150'));

        $this->setSetting('accounting.perpetual_inventory', false);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('5-1100', $lines);

        // Nothing stranded, nothing expensed twice.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(6200000.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('5-1100'));
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_switching_perpetual_on_after_the_receipt_never_debits_a_credit_that_was_not_raised(): void
    {
        // A2(b): GRN posted with the switch OFF (no journal at all), switch
        // turned ON, bill approved. The old bill debited 2-1150 6.200.000
        // against a credit that had never existed — a liability account with a
        // DEBIT balance — and 1-1400 stayed at zero.
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPo();
        $grn = $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        $this->assertSame(0, Journal::query()->count());
        $this->assertNull($grn->fresh()->gl_clearing_account);

        $this->setSetting('accounting.perpetual_inventory', true);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Classic treatment, because the receipt recorded nothing to clear.
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);

        // The clearing account is never touched — not credited, not debited.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
    }

    // ------------------------------------------------------------ A3: uang muka

    public function test_an_advance_is_approved_paid_and_netted_off_by_the_final_bill(): void
    {
        $bank = $this->makeBankAccount('1-1210');
        $po = $this->makeGoodsPo();

        // Uang muka 30%: 6.200.000 * 30% = 1.860.000 dpp
        //                1.860.000 * 11% =   204.600 ppn
        //                1.860.000 + 204.600 = 2.064.600 payable
        $advance = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 1860000,
            'bill_date' => '2026-03-02',
        ]));

        $this->assertTrue($advance->isAdvance());
        $this->assertSame(1860000.0, (float) $advance->dpp);
        $this->assertSame(204600.0, (float) $advance->ppn_amount);
        $this->assertSame(2064600.0, (float) $advance->total_payable);

        $advanceLines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $advance->id));

        // Dr 1-1500 1.860.000 + Dr 1-1600 204.600 = Cr 2-1100 2.064.600
        $this->assertSame(1860000.0, $advanceLines['1-1500']['debit']);
        $this->assertSame(204600.0, $advanceLines['1-1600']['debit']);
        $this->assertSame(2064600.0, $advanceLines['2-1100']['credit']);
        $this->assertArrayNotHasKey('5-1100', $advanceLines);
        $this->assertArrayNotHasKey('2-1150', $advanceLines);

        // A prepayment is an asset, never a project cost.
        $this->assertSame(0, ProjectCost::query()->count());

        // A3's headline: the DP can actually be paid. Dr 2-1100 / Cr Bank.
        $payment = $this->approvedOutgoingPayment(
            [
                'payment_date' => '2026-03-03',
                'bank_account_id' => $bank->id,
                'amount' => 2064600,
            ],
            [['payable_type' => 'ap_bill', 'payable_id' => $advance->id, 'amount' => 2064600]],
        );

        $paymentLines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertSame(2064600.0, $paymentLines['2-1100']['debit']);
        $this->assertSame(2064600.0, $paymentLines['1-1210']['credit']);
        $this->assertSame(2064600.0, (float) $advance->fresh()->amount_paid);

        // Goods arrive: Dr 1-1400 6.200.000 / Cr 2-1150 6.200.000
        $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        // Pelunasan: 6.200.000 - 1.860.000 = 4.340.000 dpp
        //            682.000 - 204.600     =   477.400 ppn
        //            4.340.000 + 477.400   = 4.817.400 payable
        $final = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]);

        $this->assertFalse($final->isAdvance());
        $this->assertSame(4340000.0, (float) $final->dpp);
        $this->assertSame(477400.0, (float) $final->ppn_amount);
        $this->assertSame(4817400.0, (float) $final->total_payable);

        $final = $this->approveBill($final);

        $journal = $this->singleJournalFor('ap_bill', (int) $final->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        //   Dr 2-1150 Penerimaan Barang Belum Ditagih   6.200.000
        //   Dr 1-1600 PPN Masukan                         477.400
        //   Cr 1-1500 Uang Muka Proyek                  1.860.000
        //   Cr 2-1100 Hutang Usaha                      4.817.400
        // 6.200.000 + 477.400 = 6.677.400 = 1.860.000 + 4.817.400
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertSame(477400.0, $lines['1-1600']['debit']);
        $this->assertSame(1860000.0, $lines['1-1500']['credit']);
        $this->assertSame(4817400.0, $lines['2-1100']['credit']);
        $this->assertSame(6677400.0, $journal->totalDebit());
        $this->assertSame(6677400.0, $journal->totalCredit());

        // gross dpp = 4.340.000 + 1.860.000 = 6.200.000 = value received, so
        // there is no purchase difference to book.
        $this->assertArrayNotHasKey('6-4500', $lines);
        $this->assertSame(1860000.0, (float) $final->advance_applied_amount);
        $this->assertSame(6200000.0, (float) $final->gl_cleared_amount);

        $this->issueAll('2026-03-20');

        // The prepayment is consumed, not double-counted.
        $this->assertSame(0.0, $this->accountNet('1-1500'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        // PPN masukan totals the PO's PPN exactly once: 204.600 + 477.400
        $this->assertSame(682000.0, $this->accountNet('1-1600'));
        // Cost recognised once, on the issue.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
        // Per-line issue reference — see StockService's per-item cost rows.
        $this->assertSame('inventory_issue_item', ProjectCost::query()->sole()->reference_type);
        // 2.064.600 + 4.817.400 = 6.882.000 credited, 2.064.600 already paid.
        $this->assertSame(-4817400.0, $this->accountNet('2-1100'));
    }

    public function test_an_advance_on_a_services_purchase_order_nets_off_the_classic_expense(): void
    {
        // The same netting has to work where there is no GR/IR at all.
        $po = $this->makeServicesPo();

        $advance = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 1860000,
            'bill_date' => '2026-03-02',
        ]));

        $final = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $final->id));

        //   Dr 5-1100 gross dpp 4.340.000 + 1.860.000 = 6.200.000
        //   Dr 1-1600 477.400
        //   Cr 1-1500 1.860.000
        //   Cr 2-1100 4.817.400
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(477400.0, $lines['1-1600']['debit']);
        $this->assertSame(1860000.0, $lines['1-1500']['credit']);
        $this->assertSame(4817400.0, $lines['2-1100']['credit']);

        $this->assertSame(0.0, $this->accountNet('1-1500'));
        // The full contract value is the project cost, booked once.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $cost = ProjectCost::query()->sole();
        $this->assertSame('ap_bill', $cost->reference_type);
        $this->assertSame((int) $final->id, (int) $cost->reference_id);
        $this->assertSame(6200000.0, (float) $cost->amount);
        $this->assertSame(1860000.0, (float) $advance->fresh()->dpp);
    }

    public function test_the_one_bill_per_po_rule_is_relaxed_exactly_as_far_as_advance_plus_final(): void
    {
        $po = $this->makeGoodsPo();

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 1860000,
            'bill_date' => '2026-03-02',
        ]));

        // A second advance is refused…
        try {
            $this->apBills()->create([
                'purchase_order_id' => $po->id,
                'is_advance' => true,
                'dpp' => 500000,
                'bill_date' => '2026-03-03',
            ]);
            $this->fail('Expected the second advance to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('An advance bill already exists', $e->getMessage());
        }

        $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        // …the final is allowed…
        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        // …and a second final is not.
        try {
            $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-20']);
            $this->fail('Expected the second final bill to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString("A bill already exists for PO {$po->code}.", $e->getMessage());
        }
    }

    public function test_an_advance_cannot_be_raised_once_the_final_bill_exists(): void
    {
        // A late prepayment would debit 1-1500 with nothing left to credit it
        // back: the final bill was already priced net of the advances approved
        // when it was raised.
        $po = $this->makeGoodsPo();
        $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);
        $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $this->expectExceptionMessage('sudah memiliki tagihan final');

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 1860000,
            'bill_date' => '2026-03-11',
        ]);
    }

    public function test_an_advance_without_a_purchase_order_is_refused(): void
    {
        $this->expectExceptionMessage('Uang muka hanya dapat dibuat atas pesanan pembelian (PO).');

        $this->apBills()->create([
            'vendor_id' => $this->supplier->id,
            'is_advance' => true,
            'bill_date' => '2026-03-02',
            'description' => 'Uang muka tanpa PO',
            'dpp' => 1000000,
        ]);
    }

    // ------------------------------------------------------------ A4: services PO with a warehouse

    public function test_a_services_purchase_order_that_names_a_warehouse_bills_with_no_goods_receipt(): void
    {
        // A4: raised from a PR that named a warehouse, so warehouse_id is set,
        // but every line is a service (item_id NULL). The old gate demanded a
        // GRN that would never exist and the PO could never be billed.
        $po = $this->makeServicesPo(['warehouse_id' => $this->pusat->id]);
        $this->assertNotNull($po->warehouse_id);
        $this->assertNull($po->items()->value('item_id'));

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Classic treatment: Dr 5-1100 6.200.000 + Dr 1-1600 682.000
        //                  = Cr 2-1100 6.882.000
        $this->assertSame(6200000.0, $lines['5-1100']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertArrayNotHasKey('6-4500', $lines);

        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
    }

    public function test_a_mixed_purchase_order_only_waits_for_its_stock_lines(): void
    {
        // One stock line (fully received) and one service line that will never
        // be "received": the gate must look at the stock line only.
        $po = $this->makeGoodsPo();
        $po->items()->create([
            'line_no' => 2,
            'description' => 'Ongkos angkut',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 0,
            'amount' => 0,
            'qty_received' => 0,
        ]);

        $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertSame(
            6200000.0,
            $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id))['2-1150']['debit'],
        );
    }

    // ------------------------------------------------------------ A5: the PO-less receipt

    public function test_a_vendor_receipt_without_a_po_is_cleared_by_a_bill_that_references_it(): void
    {
        // A5's debit path. The receipt credits 2-1600; a manual bill against the
        // receipt debits exactly that back out. The old manual bill debited
        // 6-4100 instead and booked the goods a SECOND time, leaving 2-1600
        // credited for ever.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::PO_QTY, self::PO_UNIT_PRICE]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        $this->assertSame('2-1600', $grn->fresh()->gl_clearing_account);
        $this->assertSame(-6200000.0, $this->accountNet('2-1600'));

        $bill = $this->apBills()->create([
            'goods_receipt_id' => $grn->id,
            'ppn_amount' => self::PO_PPN,
            'bill_date' => '2026-03-10',
        ]);

        // The bill defaults to the outstanding accrual, so the two sides cannot
        // drift apart by construction.
        $this->assertSame(6200000.0, (float) $bill->dpp);
        $this->assertSame((int) $this->supplier->id, (int) $bill->vendor_id);

        $bill = $this->approveBill($bill);

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Dr 2-1600 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000
        $this->assertSame(6200000.0, $lines['2-1600']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('6-4100', $lines);
        $this->assertArrayNotHasKey('5-1100', $lines);

        // The accrual is empty and no cost has been recognised yet.
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(6200000.0, $this->accountNet('1-1400'));
        $this->assertSame(0, ProjectCost::query()->count());

        $this->issueAll('2026-03-20');

        // 6.200.000 once, on the issue — not 12.400.000.
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
    }

    public function test_the_same_goods_receipt_cannot_be_billed_twice(): void
    {
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::PO_QTY, self::PO_UNIT_PRICE]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $grn->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->expectExceptionMessage("A bill already exists for GRN {$grn->code}.");

        $this->apBills()->create(['goods_receipt_id' => $grn->id, 'bill_date' => '2026-03-20']);
    }

    public function test_opening_stock_credits_equity_and_cannot_be_billed(): void
    {
        // No PO and no vendor: there is no counterparty, so there is no
        // liability — and no trading event either, so nothing may reach the
        // P&L. The credit lands in equity and is closed there; nothing is left
        // for a bill to clear, and the engine refuses to pretend otherwise.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::PO_QTY, self::PO_UNIT_PRICE]],
            '2026-03-05',
        ));

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        $this->assertSame(6200000.0, $lines['1-1400']['debit']);
        $this->assertSame(6200000.0, $lines['3-3100']['credit']);
        $this->assertSame(0.0, $this->accountNet('6-4400'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertNull($grn->fresh()->gl_clearing_account);

        $this->expectExceptionMessage('tidak memiliki akrual penerimaan yang masih dapat ditagih');

        $this->apBills()->create(['goods_receipt_id' => $grn->id, 'bill_date' => '2026-03-10']);
    }

    public function test_a_receipt_that_belongs_to_a_po_must_be_billed_through_that_po(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->receive($po, self::PO_QTY, self::PO_UNIT_PRICE);

        $this->expectExceptionMessage('tagihkan melalui pesanan pembeliannya');

        $this->apBills()->create(['goods_receipt_id' => $grn->id, 'bill_date' => '2026-03-10']);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * An approved purchase order for 100 zak semen with a real stock line
     * (item_id set — the schema's own definition of a goods line).
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
     * The same commercial terms with a SERVICE line (item_id null). It may or
     * may not name a deliver-to warehouse — that is the whole point of A4.
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
     * Receive against the PO line for real, so Procurement's received quantity
     * moves and the GR/IR credit the bill clears actually exists.
     */
    private function receive(PurchaseOrder $po, float $qty, float $unitCost): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $po->warehouse_id ?? $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => '2026-03-05',
            'received_by' => $this->financeUser()->id,
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
