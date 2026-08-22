<?php

namespace Tests\Unit\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * AP bill arithmetic, independent of the ledger:
 *
 *   total_payable = dpp + ppn_amount - pph_withheld
 *
 * PPh is withheld by us from the vendor, so it reduces what we transfer but
 * never the cost we recognise.
 */
class ApBillMathTest extends ErpTestCase
{
    use FinanceFixtures;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->vendor = $this->makeVendor();
    }

    private function manualBill(array $data = []): ApBill
    {
        return $this->apBills()->create(array_merge([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan jasa pemasangan panel',
            'dpp' => 100000000,
        ], $data));
    }

    public function test_total_payable_is_dpp_plus_ppn_minus_pph(): void
    {
        $bill = $this->manualBill([
            'dpp' => 100000000,
            'ppn_amount' => 11000000,   // PPN 11%
            // A withheld amount has to name its article: the liability account
            // is read off the tax row, and guessing it put PPh final 4(2) in
            // Hutang PPh 23. See BillTaxFieldsTest.
            'pph_tax_id' => $this->makePphFinalTax()->id,
            'pph_amount' => 2650000,    // PPh final konstruksi 2,65%
        ]);

        // 100.000.000 + 11.000.000 - 2.650.000 = 108.350.000
        $this->assertSame(108350000.0, (float) $bill->total_payable);
    }

    public function test_a_bill_without_taxes_is_payable_at_its_dpp(): void
    {
        $bill = $this->manualBill(['dpp' => 42500000]);

        $this->assertSame(0.0, (float) $bill->ppn_amount);
        $this->assertSame(0.0, (float) $bill->pph_amount);
        $this->assertSame(42500000.0, (float) $bill->total_payable);
    }

    public function test_pph_greater_than_the_dpp_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('PPh withheld cannot exceed the bill DPP.');

        try {
            $this->manualBill([
                'dpp' => 100000000,
                'pph_amount' => 100000000.01,
            ]);
        } finally {
            $this->assertDatabaseCount('fin_ap_bills', 0);
        }
    }

    public function test_pph_exactly_equal_to_the_dpp_is_allowed(): void
    {
        $bill = $this->manualBill([
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
            'pph_tax_id' => $this->makePphFinalTax()->id,
            'pph_amount' => 100000000,
        ]);

        // 100.000.000 + 11.000.000 - 100.000.000 = 11.000.000
        $this->assertSame(11000000.0, (float) $bill->total_payable);
    }

    public function test_the_withheld_amount_is_derived_from_the_named_tax_when_omitted(): void
    {
        $pph23 = $this->makePph23Tax(2.0);

        $bill = $this->manualBill([
            'dpp' => 100000000,
            'pph_tax_id' => $pph23->id,
        ]);

        // 100.000.000 * 2 / 100 = 2.000.000 => 100.000.000 - 2.000.000 = 98.000.000
        $this->assertSame(2000000.0, (float) $bill->pph_amount);
        $this->assertSame(98000000.0, (float) $bill->total_payable);
    }

    public function test_the_derived_withholding_is_rounded_to_two_decimals(): void
    {
        $pph23 = $this->makePph23Tax(2.0);

        $bill = $this->manualBill([
            'dpp' => 1234567.89,
            'pph_tax_id' => $pph23->id,
        ]);

        // 1.234.567,89 * 2 / 100 = 24.691,3578 -> dibulatkan 24.691,36
        $this->assertSame(24691.36, (float) $bill->pph_amount);
        // 1.234.567,89 - 24.691,36 = 1.209.876,53
        $this->assertSame(1209876.53, (float) $bill->total_payable);
    }

    public function test_an_explicit_withheld_amount_wins_over_the_tax_rate(): void
    {
        $pph23 = $this->makePph23Tax(2.0);

        $bill = $this->manualBill([
            'dpp' => 100000000,
            'pph_tax_id' => $pph23->id,
            'pph_amount' => 1500000, // hasil rekonsiliasi dengan bukti potong vendor
        ]);

        $this->assertSame(1500000.0, (float) $bill->pph_amount);
        $this->assertSame(98500000.0, (float) $bill->total_payable);
    }

    public function test_a_new_bill_starts_as_an_unpaid_draft_due_in_thirty_days(): void
    {
        $bill = $this->manualBill(['dpp' => 100000000, 'ppn_amount' => 11000000]);

        $this->assertSame(DocumentStatus::Draft, $bill->status);
        $this->assertSame(0.0, (float) $bill->amount_paid);
        $this->assertNull($bill->paid_at);
        // 2026-03-10 + 30 hari = 2026-04-09
        $this->assertSame('2026-04-09', $bill->due_date->toDateString());
        // outstanding = 111.000.000 - 0
        $this->assertSame(111000000.0, $bill->outstanding());
        $this->assertFalse($bill->isFullyPaid());
    }

    public function test_updating_a_draft_bill_recalculates_the_payable(): void
    {
        $bill = $this->manualBill(['dpp' => 100000000, 'ppn_amount' => 11000000]);

        $updated = $this->apBills()->update($bill, [
            'dpp' => 80000000,
            'ppn_amount' => 8800000,
            'pph_tax_id' => $this->makePph23Tax()->id,
            'pph_amount' => 1600000,
        ]);

        // 80.000.000 + 8.800.000 - 1.600.000 = 87.200.000
        $this->assertSame(87200000.0, (float) $updated->total_payable);
    }

    public function test_a_submitted_bill_can_no_longer_be_edited_or_deleted(): void
    {
        $bill = $this->manualBill(['dpp' => 100000000]);
        $bill->submit($this->financeUser());

        try {
            $this->apBills()->update($bill, ['dpp' => 1]);
            $this->fail('Editing a submitted bill should be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        try {
            $this->apBills()->delete($bill);
            $this->fail('Deleting a submitted bill should be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('can no longer be edited', $e->getMessage());
        }

        $fresh = $bill->fresh();
        $this->assertSame(100000000.0, (float) $fresh->dpp);
        $this->assertNull($fresh->deleted_at);
    }
}
