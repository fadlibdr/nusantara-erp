<?php

namespace Tests\Feature\Finance;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ApBillGoodsReceipt;
use Modules\Finance\Models\JournalLine;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * TAGIHAN PARSIAL (#40) — one bill per (PO, set of chosen GRNs).
 *
 * Before this, finalBillExists() allowed exactly ONE non-advance bill per
 * purchase order, so a PO delivered in three shipments across three months
 * could only be invoiced after the last truck — while the vendor invoices
 * every surat jalan as it happens. The resep: the request names specific
 * posted GRNs of the PO; the bill prices ONLY those receipts' lines (received
 * qty x PO unit price), clears ONLY their gl_clearing slices, and marks them
 * billed so the same delivery can never be invoiced twice — by carelessness or
 * by race.
 *
 * The house numbers, sliced three ways:
 *
 *   PO   100 zak * 62.000 = 6.200.000 dpp, PPN 682.000, total 6.882.000
 *   GRN1  50 zak          = 3.100.000
 *   GRN2  30 zak          = 1.860.000
 *   GRN3  20 zak          = 1.240.000
 */
class PartialBillingTest extends ErpTestCase
{
    use FinanceFixtures;

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

        $this->pusat = Warehouse::create(['code' => 'WH-PUSAT', 'name' => 'Gudang Pusat', 'is_active' => true]);
        $this->semen = $this->makeSemen();
        $this->supplier = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ------------------------------------------------- pricing and clearing

    public function test_a_bill_for_two_of_three_receipts_prices_and_clears_only_those_receipts(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $grn2 = $this->receive($po, 30, self::PO_UNIT_PRICE, '2026-03-12');
        $grn3 = $this->receive($po, 20, self::PO_UNIT_PRICE, '2026-03-19');

        // All three receipts credited GR/IR: 3.100.000 + 1.860.000 + 1.240.000.
        $this->assertSame(-6200000.0, $this->accountNet('2-1150'));

        $bill = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id, $grn2->id],
            'bill_date' => '2026-03-15',
            'vendor_invoice_no' => 'INV-PART-1',
        ]);

        // 50 zak + 30 zak at the PO price, NOT the whole order:
        //   (50 + 30) * 62.000 = 4.960.000 dpp
        //   4.960.000 * 11%    =   545.600 ppn
        //   4.960.000+545.600  = 5.505.600 payable
        $this->assertSame(4960000.0, (float) $bill->dpp);
        $this->assertSame(545600.0, (float) $bill->ppn_amount);
        $this->assertSame(5505600.0, (float) $bill->total_payable);

        // The claim on the two receipts is written down at create time.
        $slices = ApBillGoodsReceipt::query()->where('ap_bill_id', $bill->id)
            ->orderBy('goods_receipt_id')->get();
        $this->assertCount(2, $slices);
        $this->assertSame(3100000.0, (float) $slices[0]->dpp_amount);
        $this->assertSame(1860000.0, (float) $slices[1]->dpp_amount);

        $bill = $this->approveBill($bill);

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-15');

        $lines = $this->linesByAccount($journal);

        // Dr 2-1150 4.960.000 + Dr 1-1600 545.600 = Cr 2-1100 5.505.600;
        // received at the PO price, so no purchase variance.
        $this->assertSame(4960000.0, $lines['2-1150']['debit']);
        $this->assertSame(545600.0, $lines['1-1600']['debit']);
        $this->assertSame(5505600.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('6-4500', $lines);

        // GRN3's slice is the ONLY credit left: -6.200.000 + 4.960.000.
        $this->assertSame(-1240000.0, $this->accountNet('2-1150'));
        $this->assertSame(4960000.0, round((float) $bill->gl_cleared_amount, 2));

        // Per-receipt clearing recorded on the slices, never pooled.
        $slices = $slices->fresh();
        $this->assertSame(3100000.0, (float) $slices[0]->cleared_amount);
        $this->assertSame(1860000.0, (float) $slices[1]->cleared_amount);

        // The undelivered/unbilled remainder is untouched.
        $this->assertNull(ApBillGoodsReceipt::query()->where('goods_receipt_id', $grn3->id)->first());
    }

    public function test_the_remaining_receipt_bills_on_its_own_and_gr_ir_reaches_zero(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $grn2 = $this->receive($po, 30, self::PO_UNIT_PRICE, '2026-03-12');
        $grn3 = $this->receive($po, 20, self::PO_UNIT_PRICE, '2026-03-19');

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id, $grn2->id],
            'bill_date' => '2026-03-15',
            'vendor_invoice_no' => 'INV-PART-1',
        ]));

        $rest = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn3->id],
            'bill_date' => '2026-03-20',
            'vendor_invoice_no' => 'INV-PART-2',
        ]));

        // 20 * 62.000 = 1.240.000; PPN 11% = 136.400.
        $this->assertSame(1240000.0, (float) $rest->dpp);
        $this->assertSame(136400.0, (float) $rest->ppn_amount);

        // Both bills together sweep GR/IR to exactly zero, and the vendor was
        // billed the PO total exactly once: 5.505.600 + 1.376.400 = 6.882.000.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(
            self::PO_TOTAL,
            round((float) ApBill::query()->where('purchase_order_id', $po->id)->sum('total_payable'), 2),
        );
    }

    public function test_a_receipt_received_above_the_po_price_books_the_difference_as_variance(): void
    {
        $po = $this->makeGoodsPo();
        // 50 zak taken in at 65.000: persediaan/GR-IR carry 3.250.000, but the
        // vendor may only invoice the PO price: 50 * 62.000 = 3.100.000.
        $grn = $this->receive($po, 50, 65000.0, '2026-03-05');

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn->id],
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-PART-VAR',
        ]));

        $this->assertSame(3100000.0, (float) $bill->dpp);

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Dr 2-1150 3.250.000 (what THIS receipt recorded), Cr 6-4500 150.000
        // (billed under the goods value), Dr 1-1600 341.000, Cr 2-1100 3.441.000.
        $this->assertSame(3250000.0, $lines['2-1150']['debit']);
        $this->assertSame(150000.0, $lines['6-4500']['credit']);
        $this->assertSame(341000.0, $lines['1-1600']['debit']);
        $this->assertSame(3441000.0, $lines['2-1100']['credit']);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
    }

    // ------------------------------------------------- double-billing locks

    public function test_the_same_receipt_cannot_be_billed_twice(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-12');

        $first = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'vendor_invoice_no' => 'INV-PART-1',
        ]);

        try {
            $this->apBills()->create([
                'purchase_order_id' => $po->id,
                'goods_receipt_ids' => [$grn1->id],
                'vendor_invoice_no' => 'INV-PART-DUP',
            ]);
            $this->fail('The same receipt was billed twice.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($first->code, $e->getMessage());
        }

        // And the refusal left no half-written bill behind.
        $this->assertSame(1, ApBill::query()->where('purchase_order_id', $po->id)->count());
    }

    public function test_the_unique_index_backstops_the_race_two_clerks_lose_politely(): void
    {
        // Two concurrent creates both pass the polite existence check before
        // either inserts; the second INSERT must die on the unique index, not
        // silently double the claim. The polite check cannot see an uncommitted
        // sibling, so the index is the lock — simulated here by inserting the
        // winning claim behind the check's back.
        $po = $this->makeGoodsPo();
        $grn = $this->receive($po, 100, self::PO_UNIT_PRICE, '2026-03-05');

        $bill = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn->id],
            'vendor_invoice_no' => 'INV-RACE-1',
        ]);

        $this->expectException(QueryException::class);

        DB::table('fin_ap_bill_goods_receipts')->insert([
            'ap_bill_id' => $bill->id,
            'goods_receipt_id' => $grn->id,
            'dpp_amount' => 1,
            'cleared_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_partial_billed_receipt_cannot_be_billed_through_the_receipt_route(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-12');

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'vendor_invoice_no' => 'INV-PART-1',
        ]));

        // Either receipt: the billed one is spoken for, the unbilled one still
        // belongs to the PO route — both are refused off the accrual route.
        foreach (GoodsReceipt::query()->pluck('id') as $receiptId) {
            try {
                $this->apBills()->create([
                    'goods_receipt_id' => $receiptId,
                    'vendor_invoice_no' => 'INV-GRN-'.$receiptId,
                ]);
                $this->fail("Receipt #{$receiptId} slipped through the accrual route.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('tagihkan melalui pesanan pembeliannya', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------- mode exclusivity

    public function test_a_whole_po_bill_is_refused_once_partial_bills_exist(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-12');

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'vendor_invoice_no' => 'INV-PART-1',
        ]);

        // A whole-PO bill now would price the full 6.200.000 and sweep BOTH
        // receipts — including the one the partial bill above already claims.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sudah ditagih parsial');

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'vendor_invoice_no' => 'INV-WHOLE',
        ]);
    }

    public function test_a_partial_bill_is_refused_once_a_whole_po_bill_exists(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->receive($po, 100, self::PO_UNIT_PRICE, '2026-03-05');

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'vendor_invoice_no' => 'INV-WHOLE',
        ]);

        $this->expectException(LogicException::class);

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn->id],
            'vendor_invoice_no' => 'INV-PART-LATE',
        ]);
    }

    public function test_a_receipt_of_another_po_is_refused_by_name(): void
    {
        $po = $this->makeGoodsPo();
        $other = $this->makeGoodsPo();
        $foreign = $this->receive($other, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($foreign->code);

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$foreign->id],
            'vendor_invoice_no' => 'INV-CROSS',
        ]);
    }

    public function test_an_advance_cannot_name_receipts(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');

        $this->expectException(LogicException::class);

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 1000000,
            'goods_receipt_ids' => [$grn->id],
            'vendor_invoice_no' => 'INV-ADV-GRN',
        ]);
    }

    // ------------------------------------------------- the T44 protections survive

    public function test_partial_bills_pass_while_the_undelivered_remainder_still_cannot_be_billed(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $grn2 = $this->receive($po, 30, self::PO_UNIT_PRICE, '2026-03-12');
        // 20 zak still on the vendor's truck: the PO is NOT fully received.

        // Billing what arrived is exactly what partial billing is for — the
        // whole-PO completeness gate must not refuse it.
        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id, $grn2->id],
            'bill_date' => '2026-03-15',
            'vendor_invoice_no' => 'INV-PART-1',
        ]));

        $this->assertSame(DocumentStatus::Approved, $bill->status);
        $this->assertSame(4960000.0, (float) $bill->dpp);

        // The undelivered 20 zak have no GRN, so no partial bill can name them
        // — and the whole-PO bill that WOULD price them is refused outright.
        try {
            $this->apBills()->create([
                'purchase_order_id' => $po->id,
                'vendor_invoice_no' => 'INV-REST',
            ]);
            $this->fail('The undelivered remainder was billable.');
        } catch (LogicException) {
            // expected — partial bills exist, the whole-PO route is closed
        }

        // Project cost carries NOTHING from the matched partial bill: the goods
        // become cost when they are issued, not when they are invoiced (T44's
        // double-charge is what this pins).
        $this->assertSame(0.0, $this->accountNet('5-1100'));
    }

    public function test_a_partial_bill_may_not_name_an_unposted_receipt(): void
    {
        $po = $this->makeGoodsPo();
        $draft = $this->makeReceipt($po, 50, self::PO_UNIT_PRICE, '2026-03-05'); // never posted

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('diposting');

        $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$draft->id],
            'vendor_invoice_no' => 'INV-DRAFT-GRN',
        ]);
    }

    // ------------------------------------------------- uang muka, proportional

    public function test_the_advance_is_recovered_proportionally_per_partial_bill(): void
    {
        $po = $this->makeGoodsPo();

        // 30% DP: 1.860.000 dpp + 204.600 ppn = 2.064.600.
        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 1860000.0,
            'bill_date' => '2026-03-02',
            'vendor_invoice_no' => 'INV-DP',
        ]));

        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $grn2 = $this->receive($po, 30, self::PO_UNIT_PRICE, '2026-03-12');
        $grn3 = $this->receive($po, 20, self::PO_UNIT_PRICE, '2026-03-19');

        // GRN1: gross 3.100.000 = 50% of the PO -> recovers 50% of the DP.
        //   recovery 930.000; dpp 3.100.000 - 930.000 = 2.170.000;
        //   ppn 11% = 238.700; payable 2.408.700.
        $first = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'bill_date' => '2026-03-06',
            'vendor_invoice_no' => 'INV-PART-1',
        ]));

        $this->assertSame(2170000.0, (float) $first->dpp);
        $this->assertSame(238700.0, (float) $first->ppn_amount);
        $this->assertSame(2408700.0, (float) $first->total_payable);
        $this->assertSame(930000.0, (float) $first->advance_applied_amount);

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $first->id));

        //   Dr 2-1150 3.100.000  + Dr 1-1600 238.700
        //   Cr 1-1500   930.000  + Cr 2-1100 2.408.700
        $this->assertSame(3100000.0, $lines['2-1150']['debit']);
        $this->assertSame(238700.0, $lines['1-1600']['debit']);
        $this->assertSame(930000.0, $lines['1-1500']['credit']);
        $this->assertSame(2408700.0, $lines['2-1100']['credit']);

        // GRN2: cumulative 80% -> entitled 1.488.000, taken 930.000 -> 558.000.
        $second = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn2->id],
            'bill_date' => '2026-03-13',
            'vendor_invoice_no' => 'INV-PART-2',
        ]));

        $this->assertSame(1302000.0, (float) $second->dpp); // 1.860.000 - 558.000
        $this->assertSame(558000.0, (float) $second->advance_applied_amount);

        // GRN3: cumulative 100% -> the LAST rupiah of the DP comes back.
        $third = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn3->id],
            'bill_date' => '2026-03-20',
            'vendor_invoice_no' => 'INV-PART-3',
        ]));

        $this->assertSame(868000.0, (float) $third->dpp); // 1.240.000 - 372.000
        $this->assertSame(372000.0, (float) $third->advance_applied_amount);

        // THE assertion: 930.000 + 558.000 + 372.000 = 1.860.000, so the
        // prepayment account is at exactly zero — consumed once, to the rupiah.
        $this->assertSame(0.0, $this->accountNet('1-1500'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // And the vendor is owed the pelunasan total, once:
        // 2.408.700 + 1.445.220 + 963.480 + 2.064.600 (DP) = 6.882.000.
        $this->assertSame(
            self::PO_TOTAL,
            round((float) ApBill::query()->where('purchase_order_id', $po->id)->sum('total_payable'), 2),
        );
    }

    public function test_proportional_recovery_is_exact_to_the_rupiah_when_thirds_do_not_divide(): void
    {
        // 3.000.000 PO in three equal 1.000.000 deliveries against a 1.000.000
        // DP: each slice is entitled to 333.333,33... — progressive rounding
        // must hand out 333.333,33 / 333.333,34 / 333.333,33 so the LAST bill
        // leaves 1-1500 at exactly zero, not at ±0,01.
        $po = $this->makeGoodsPo([
            'subtotal' => 3000000.0, 'dpp' => 3000000.0,
            'ppn_amount' => 330000.0, 'total' => 3330000.0,
        ], qty: 300.0, unitPrice: 10000.0);

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'is_advance' => true,
            'dpp' => 1000000.0,
            'bill_date' => '2026-03-02',
            'vendor_invoice_no' => 'INV-DP',
        ]));

        $expected = [333333.33, 333333.34, 333333.33];

        foreach ([1, 2, 3] as $i) {
            $grn = $this->receive($po, 100, 10000.0, '2026-03-0'.($i + 2));
            $bill = $this->approveBill($this->apBills()->create([
                'purchase_order_id' => $po->id,
                'goods_receipt_ids' => [$grn->id],
                'bill_date' => '2026-03-0'.($i + 2),
                'vendor_invoice_no' => 'INV-PART-'.$i,
            ]));

            $this->assertSame($expected[$i - 1], (float) $bill->advance_applied_amount, "bill {$i}");
        }

        $this->assertSame(0.0, $this->accountNet('1-1500'));
    }

    // ------------------------------------------------- header discount

    public function test_a_po_header_discount_is_shared_proportionally_across_partial_bills(): void
    {
        // Subtotal 6.200.000 with 200.000 discount: dpp 6.000.000, PPN 660.000.
        $po = $this->makeGoodsPo([
            'discount_amount' => 200000.0,
            'dpp' => 6000000.0, 'ppn_amount' => 660000.0, 'total' => 6660000.0,
        ]);

        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $grn2 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-12');

        // GRN1 is half the order: priced 3.100.000 less half the discount
        // (100.000) = 3.000.000 dpp; PPN 330.000.
        $first = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'bill_date' => '2026-03-06',
            'vendor_invoice_no' => 'INV-DISC-1',
        ]));

        $this->assertSame(3000000.0, (float) $first->dpp);
        $this->assertSame(330000.0, (float) $first->ppn_amount);

        // The receipt went in at 3.100.000; billing 3.000.000 leaves the
        // 100.000 discount as a credit variance — the same shape a discounted
        // whole-PO bill produces.
        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $first->id));
        $this->assertSame(3100000.0, $lines['2-1150']['debit']);
        $this->assertSame(100000.0, $lines['6-4500']['credit']);

        $second = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn2->id],
            'bill_date' => '2026-03-13',
            'vendor_invoice_no' => 'INV-DISC-2',
        ]));

        $this->assertSame(3000000.0, (float) $second->dpp);

        // Both bills together: dpp 6.000.000 = the PO's own dpp, GR/IR zero.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(
            6660000.0,
            round((float) ApBill::query()->where('purchase_order_id', $po->id)->sum('total_payable'), 2),
        );
    }

    // ------------------------------------------------- cancellation releases

    public function test_cancelling_a_partial_bill_releases_its_receipts_for_rebilling(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-12');

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'bill_date' => '2026-03-06',
            'vendor_invoice_no' => 'INV-WRONG',
        ]));

        $this->assertSame(-3100000.0, $this->accountNet('2-1150')); // GRN2's slice

        $this->apBills()->cancel($bill->fresh(), $this->financeApprover(), 'Salah harga satuan');

        // The reversal gave the clearing back and the claim rows are gone.
        $this->assertSame(-6200000.0, $this->accountNet('2-1150'));
        $this->assertSame(0, ApBillGoodsReceipt::query()->where('ap_bill_id', $bill->id)->count());
        $this->assertSame(0.0, (float) $bill->fresh()->gl_cleared_amount);

        // The same receipt bills again, cleanly.
        $again = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'bill_date' => '2026-03-07',
            'vendor_invoice_no' => 'INV-RIGHT',
        ]));

        $this->assertSame(3100000.0, (float) $again->dpp);
        $this->assertSame(-3100000.0, $this->accountNet('2-1150'));
    }

    public function test_deleting_a_draft_partial_bill_releases_its_receipts(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->receive($po, 100, self::PO_UNIT_PRICE, '2026-03-05');

        $draft = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn->id],
            'vendor_invoice_no' => 'INV-DRAFT',
        ]);

        $this->apBills()->delete($draft);

        $this->assertSame(0, ApBillGoodsReceipt::query()->count());

        // Free again: a fresh bill claims the receipt without complaint.
        $bill = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn->id],
            'vendor_invoice_no' => 'INV-FRESH',
        ]);

        $this->assertSame(self::PO_DPP, (float) $bill->dpp);
    }

    public function test_the_dpp_of_a_partial_bill_cannot_be_edited(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');

        $draft = $this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn->id],
            'vendor_invoice_no' => 'INV-EDIT',
        ]);

        $this->expectException(LogicException::class);

        $this->apBills()->update($draft, ['dpp' => 999999.0]);
    }

    // ------------------------------------------------- the wire format

    public function test_the_store_endpoint_accepts_goods_receipt_ids_and_reports_the_slices(): void
    {
        $po = $this->makeGoodsPo();
        $grn1 = $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-05');
        $this->receive($po, 50, self::PO_UNIT_PRICE, '2026-03-12');

        $admin = $this->adminUser(); // create once — the helper inserts a fresh row per call

        $response = $this->actingAs($admin)->postJson('api/finance/ap-bills', [
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grn1->id],
            'vendor_invoice_no' => 'INV-HTTP-1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.dpp', '3100000.00')
            ->assertJsonPath('data.billed_receipts.0.goods_receipt_id', $grn1->id)
            ->assertJsonPath('data.billed_receipts.0.goods_receipt_code', $grn1->code);

        // Naming receipts without a PO is a field error, not a 500.
        $this->actingAs($admin)->postJson('api/finance/ap-bills', [
            'goods_receipt_ids' => [$grn1->id],
            'vendor_id' => $this->supplier->id,
            'description' => 'Tanpa PO',
            'dpp' => 1000,
            'vendor_invoice_no' => 'INV-HTTP-2',
        ])->assertUnprocessable()->assertJsonValidationErrors(['goods_receipt_ids']);
    }

    // ------------------------------------------------- fixtures

    private function makeSemen(): Item
    {
        $category = ItemCategory::query()->firstOrCreate(
            ['code' => 'CAT-UMUM'],
            ['name' => 'Material Umum'],
        );

        return Item::query()->create([
            'name' => 'Semen Gresik 40kg',
            'category_id' => $category->id,
            'unit' => 'zak',
            'item_type' => ItemType::Material,
            'min_stock' => 0,
            'avg_cost' => 0,
            'last_price' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * An approved GOODS purchase order: 100 zak @ 62.000 on one stock line,
     * delivered into WH-PUSAT, charged to a project.
     */
    private function makeGoodsPo(
        array $attributes = [],
        float $qty = self::PO_QTY,
        float $unitPrice = self::PO_UNIT_PRICE,
    ): PurchaseOrder {
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
            'qty' => $qty,
            'unit' => 'zak',
            'unit_price' => $unitPrice,
            'amount' => round($qty * $unitPrice, 2),
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    /**
     * A DRAFT receipt against the PO's stock line.
     */
    private function makeReceipt(PurchaseOrder $po, float $qty, float $unitCost, string $date): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $po->warehouse_id ?? $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => $date,
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

        return $grn->refresh();
    }

    /**
     * Receive for real through StockService, so the GR/IR credit the partial
     * bill has to clear actually exists in the ledger.
     */
    private function receive(PurchaseOrder $po, float $qty, float $unitCost, string $date): GoodsReceipt
    {
        return app(StockService::class)
            ->postReceipt($this->makeReceipt($po, $qty, $unitCost, $date));
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
