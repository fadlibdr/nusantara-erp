<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\JournalLine;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Procurement\Models\PurchaseOrder;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * ONE CREDIT, ONE CLEARING — whichever route settles it.
 *
 * A goods receipt's GR/IR credit can be swept by either of two documents: a
 * bill keyed on the purchase order, or a bill keyed on the receipt itself. The
 * clearing arithmetic originally weighed only the key the bill in hand used, so
 * the two routes were invisible to one another: a receipt whose credit its PO
 * bill had already debited could be billed a SECOND time through
 * createFromGoodsReceipt(), because no bill carried that goods_receipt_id.
 *
 * Measured before the fix, on 100 zak @ 62.000 = 6.200.000 + PPN 682.000:
 *   after the PO bill      2-1150 = 0,00        (correct)
 *   after re-billing       2-1150 = +6.200.000  (a liability with a DEBIT balance)
 *                          2-1100 = -13.082.000 for one 6.882.000 delivery
 * and it scaled with the number of receipts on the order. Unrecoverable through
 * the product: an approved AP bill has no cancel endpoint.
 *
 * clearedAgainstReceipts() now weighs BOTH keys, which is what the standing
 * safety command InventoryMethodCheck::clearedAgainst() already did.
 */
class DoubleClearingTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const QTY = 100.0;

    private const UNIT_COST = 62000.0;

    private const DPP = 6200000.0;   // 100 x 62.000

    private const PPN = 682000.0;    // 11% of 6.200.000

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
    }

    /**
     * The scenario the audit reproduced: bill the PO, then bill the receipt.
     */
    public function test_a_receipt_already_cleared_by_its_po_bill_cannot_be_billed_again(): void
    {
        [$receipt, $purchaseOrder] = $this->receiveAgainstPurchaseOrder();

        // The PO bill sweeps the whole GR/IR credit.
        $poBill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_invoice_no' => 'INV-PO-1',
        ]));

        $this->assertSame(self::DPP, round((float) $poBill->gl_cleared_amount, 2));
        $this->assertSame(0.0, $this->balanceOfAccount('2-1150'), 'the PO bill must clear GR/IR to exactly zero');

        // Billing the same receipt again must be refused, not silently doubled.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak memiliki akrual penerimaan yang masih dapat ditagih');

        $this->apBills()->create([
            'goods_receipt_id' => $receipt->id,
            'vendor_invoice_no' => 'INV-GRN-DUPLICATE',
        ]);
    }

    /**
     * And the ledger is untouched by the attempt — no half-written bill, no
     * movement on the clearing account or the payable.
     */
    public function test_the_refused_second_bill_leaves_the_ledger_intact(): void
    {
        [$receipt, $purchaseOrder] = $this->receiveAgainstPurchaseOrder();

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_invoice_no' => 'INV-PO-1',
        ]));

        $payableBefore = $this->balanceOfAccount('2-1100');
        $billsBefore = ApBill::query()->count();

        try {
            $this->apBills()->create([
                'goods_receipt_id' => $receipt->id,
                'vendor_invoice_no' => 'INV-GRN-DUPLICATE',
            ]);
            $this->fail('A second clearing of the same receipt was accepted.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame($billsBefore, ApBill::query()->count(), 'no bill row may survive the refusal');
        $this->assertSame(0.0, $this->balanceOfAccount('2-1150'), 'GR/IR must stay at zero, not go into debit');
        $this->assertSame($payableBefore, $this->balanceOfAccount('2-1100'), 'no second payable for one delivery');
    }

    /**
     * Two receipts on one order: the single PO bill clears both, so neither can
     * then be billed on its own. This is the variant that scaled the defect.
     */
    public function test_neither_of_two_receipts_can_be_rebilled_after_one_po_bill(): void
    {
        $warehouse = $this->makeWarehouse('WH-DC');
        $item = $this->makeItem('Semen Portland 50kg');
        $vendor = $this->vendor();

        $purchaseOrder = $this->makeGoodsPurchaseOrder($warehouse, [
            'vendor_id' => $vendor->id,
            'subtotal' => self::DPP, 'dpp' => self::DPP,
            'ppn_amount' => self::PPN, 'total' => self::DPP + self::PPN,
        ]);

        // 60 + 40 zak, 3.720.000 + 2.480.000 = the same 6.200.000.
        foreach ([60.0, 40.0] as $qty) {
            $this->stock()->postReceipt($this->makeGrn(
                $warehouse,
                [[$item, $qty, self::UNIT_COST]],
                '2026-03-10',
                ['vendor_id' => $vendor->id, 'purchase_order_id' => $purchaseOrder->id],
            ));
        }

        $this->assertSame(-self::DPP, $this->balanceOfAccount('2-1150'), 'both receipts credit GR/IR');

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_invoice_no' => 'INV-PO-1',
        ]));

        $this->assertSame(0.0, $this->balanceOfAccount('2-1150'));

        foreach (GoodsReceipt::query()->pluck('id') as $receiptId) {
            try {
                $this->apBills()->create([
                    'goods_receipt_id' => $receiptId,
                    'vendor_invoice_no' => 'INV-DUP-'.$receiptId,
                ]);
                $this->fail("Receipt #{$receiptId} was billed a second time.");
            } catch (LogicException) {
                // expected
            }
        }

        $this->assertSame(0.0, $this->balanceOfAccount('2-1150'), 'GR/IR still exactly zero');
    }

    /**
     * The guard must not over-reach: a receipt raised against a vendor with NO
     * purchase order still has its own billing route, and it must still work.
     */
    public function test_a_vendor_receipt_without_a_purchase_order_is_still_billable(): void
    {
        $warehouse = $this->makeWarehouse('WH-DC');
        $item = $this->makeItem('Semen Portland 50kg');

        $receipt = $this->stock()->postReceipt($this->makeGrn(
            $warehouse,
            [[$item, self::QTY, self::UNIT_COST]],
            '2026-03-10',
            ['vendor_id' => $this->vendor()->id],
        ));

        // No PO, so the credit is the penerimaan accrual, not GR/IR.
        $this->assertSame(-self::DPP, $this->balanceOfAccount('2-1600'));

        $bill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $receipt->id,
            'vendor_invoice_no' => 'INV-GRN-1',
        ]));

        $this->assertSame(self::DPP, round((float) $bill->gl_cleared_amount, 2));
        $this->assertSame(0.0, $this->balanceOfAccount('2-1600'), 'the receipt bill clears the accrual to zero');
    }

    /**
     * @return array{0: GoodsReceipt, 1: PurchaseOrder}
     */
    private function receiveAgainstPurchaseOrder(): array
    {
        $warehouse = $this->makeWarehouse('WH-DC');
        $item = $this->makeItem('Semen Portland 50kg');
        $vendor = $this->vendor();

        $purchaseOrder = $this->makeGoodsPurchaseOrder($warehouse, [
            'vendor_id' => $vendor->id,
            'subtotal' => self::DPP, 'dpp' => self::DPP,
            'ppn_amount' => self::PPN, 'total' => self::DPP + self::PPN,
        ]);

        $receipt = $this->stock()->postReceipt($this->makeGrn(
            $warehouse,
            [[$item, self::QTY, self::UNIT_COST]],
            '2026-03-10',
            ['vendor_id' => $vendor->id, 'purchase_order_id' => $purchaseOrder->id],
        ));

        $this->assertSame(-self::DPP, $this->balanceOfAccount('2-1150'), 'the receipt credits GR/IR');

        return [$receipt, $purchaseOrder->refresh()];
    }

    /**
     * Signed GL balance (debit positive) for one account code.
     */
    private function balanceOfAccount(string $code): float
    {
        $accountId = $this->accountId($code);

        $debit = (float) JournalLine::query()->where('account_id', $accountId)->sum('debit');
        $credit = (float) JournalLine::query()->where('account_id', $accountId)->sum('credit');

        return round($debit - $credit, 2);
    }
}
