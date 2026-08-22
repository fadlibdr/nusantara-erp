<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\Tax;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Bank receipts and disbursements. Posting must settle exactly what the
 * allocations say — no more, no less — and book
 *
 *   in  => Dr Bank / Cr 1-1300 Piutang Usaha
 *   out => Dr 2-1100 Hutang Usaha / Cr Bank
 *
 * Every rejected posting has to leave the payment draft AND the settled
 * document untouched.
 */
class PaymentPostingTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    private Vendor $vendor;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer);
        $this->vendor = $this->makeVendor();
        $this->bank = $this->makeBankAccount('1-1210');
    }

    /**
     * An approved AR invoice with total = dpp + dpp * rate / 100.
     */
    private function approvedInvoice(float $dpp, float $ppnRate = 11.0): ArInvoice
    {
        return $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Penagihan progres pekerjaan',
            'dpp' => $dpp,
            'ppn_rate' => $ppnRate,
        ]));
    }

    private function approvedBill(float $dpp, float $ppn = 0.0, float $pph = 0.0): ApBill
    {
        return $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan vendor',
            'dpp' => $dpp,
            'ppn_amount' => $ppn,
            // A withheld amount has to name its article — the liability account
            // is read off the tax row rather than guessed. See BillTaxFieldsTest.
            'pph_tax_id' => $pph > 0
                ? (int) (Tax::query()
                    ->where('code', Tax::pphFinalCodeForScheme('pelaksanaan_bersertifikat'))
                    ->value('id') ?? $this->makePphFinalTax()->id)
                : null,
            'pph_amount' => $pph,
        ]));
    }

    private function draftPayment(string $direction, float $amount, string $date = '2026-04-05'): Payment
    {
        return $this->payments()->create([
            'direction' => $direction,
            'payment_date' => $date,
            'bank_account_id' => $this->bank->id,
            'amount' => $amount,
        ]);
    }

    // ------------------------------------------------------------ happy paths

    public function test_a_full_receipt_settles_the_invoice_and_books_the_bank_journal(): void
    {
        // DPP 1.000.000.000 + PPN 110.000.000 = total 1.110.000.000
        $invoice = $this->approvedInvoice(1000000000);
        $payment = $this->draftPayment('in', 1110000000);

        $posted = $this->payments()->post($payment, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000],
        ]);

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertStringStartsWith('RCV/', $posted->code);

        $journal = $this->singleJournalFor('payment', (int) $payment->id);
        $this->assertPostedAndBalanced($journal, '2026-04-05');

        $lines = $this->linesByAccount($journal);
        $this->assertSame(1110000000.0, $lines['1-1210']['debit']); // Dr Bank
        $this->assertSame(1110000000.0, $lines['1-1300']['credit']); // Cr Piutang Usaha

        $settled = $invoice->fresh();
        $this->assertSame(1110000000.0, (float) $settled->amount_paid);
        $this->assertSame(0.0, $settled->outstanding());
        $this->assertTrue($settled->isFullyPaid());
        $this->assertSame('2026-04-05', $settled->paid_at->toDateString());
    }

    public function test_a_partial_receipt_leaves_the_invoice_open_and_unstamped(): void
    {
        $invoice = $this->approvedInvoice(1000000000); // total 1.110.000.000
        $payment = $this->draftPayment('in', 500000000);

        $this->payments()->post($payment, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 500000000],
        ]);

        $settled = $invoice->fresh();
        $this->assertSame(500000000.0, (float) $settled->amount_paid);
        // 1.110.000.000 - 500.000.000 = 610.000.000
        $this->assertSame(610000000.0, $settled->outstanding());
        $this->assertFalse($settled->isFullyPaid());
        $this->assertNull($settled->paid_at);
    }

    public function test_a_second_receipt_closes_the_remaining_outstanding(): void
    {
        $invoice = $this->approvedInvoice(1000000000);

        $this->payments()->post($this->draftPayment('in', 500000000), [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 500000000],
        ]);
        $this->payments()->post($this->draftPayment('in', 610000000, '2026-05-05'), [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 610000000],
        ]);

        $settled = $invoice->fresh();
        // 500.000.000 + 610.000.000 = 1.110.000.000
        $this->assertSame(1110000000.0, (float) $settled->amount_paid);
        $this->assertSame(0.0, $settled->outstanding());
        $this->assertSame('2026-05-05', $settled->paid_at->toDateString());
    }

    public function test_a_disbursement_debits_payables_and_credits_the_bank(): void
    {
        // 100.000.000 + 11.000.000 - 2.650.000 = 108.350.000
        $bill = $this->approvedBill(100000000, 11000000, 2650000);
        $payment = $this->draftPayment('out', 108350000);
        $allocations = [
            ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 108350000],
        ];

        // A disbursement is only postable on somebody else's approval now, so
        // the happy path is draft -> submitted -> approved -> posted.
        $posted = $this->payments()->post(
            $this->approveOutgoingPayment($payment, $allocations),
            $allocations,
        );

        $this->assertStringStartsWith('PAY/', $posted->code);

        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertSame(108350000.0, $lines['2-1100']['debit']); // Dr Hutang Usaha
        $this->assertSame(108350000.0, $lines['1-1210']['credit']); // Cr Bank

        $settled = $bill->fresh();
        $this->assertSame(108350000.0, (float) $settled->amount_paid);
        $this->assertSame(0.0, $settled->outstanding());
        $this->assertSame('2026-04-05', $settled->paid_at->toDateString());
    }

    public function test_one_receipt_can_settle_several_invoices(): void
    {
        $first = $this->approvedInvoice(100000000);  // total 111.000.000
        $second = $this->approvedInvoice(200000000); // total 222.000.000
        $payment = $this->draftPayment('in', 333000000);

        $this->payments()->post($payment, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $first->id, 'amount' => 111000000],
            ['payable_type' => 'ar_invoice', 'payable_id' => $second->id, 'amount' => 222000000],
        ]);

        $journal = $this->singleJournalFor('payment', (int) $payment->id);

        // 111.000.000 + 222.000.000 = 333.000.000
        $this->assertSame(333000000.0, $journal->totalDebit());
        $this->assertSame(333000000.0, $journal->totalCredit());
        $this->assertCount(3, $journal->lines()->get()); // 1 bank + 2 piutang
        $this->assertSame(2, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertTrue($first->fresh()->isFullyPaid());
        $this->assertTrue($second->fresh()->isFullyPaid());
    }

    public function test_the_one_cent_allocation_tolerance_is_honoured(): void
    {
        // Faktur tanpa PPN supaya total persis 123.456,80.
        $invoice = $this->approvedInvoice(123456.80, 0.0);
        $payment = $this->draftPayment('in', 123456.78);

        // Alokasi 123.456,79 vs nilai pembayaran 123.456,78 => selisih 0,01.
        $posted = $this->payments()->post($payment, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 123456.79],
        ]);

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertSame(123456.79, (float) $invoice->fresh()->amount_paid);
    }

    public function test_a_two_cent_allocation_gap_is_refused(): void
    {
        $invoice = $this->approvedInvoice(123456.80, 0.0);
        $payment = $this->draftPayment('in', 123456.78);

        $this->expectExceptionMessage('must sum to the payment amount');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 123456.80],
            ]);
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    // ------------------------------------------------------------ guards

    public function test_allocations_must_sum_to_the_payment_amount(): void
    {
        $invoice = $this->approvedInvoice(1000000000); // total 1.110.000.000
        $payment = $this->draftPayment('in', 1110000000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must sum to the payment amount');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 500000000],
            ]);
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    public function test_a_payment_needs_at_least_one_allocation(): void
    {
        $payment = $this->draftPayment('in', 1000000);

        $this->expectExceptionMessage('needs at least one allocation to post');

        try {
            $this->payments()->post($payment, []);
        } finally {
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
        }
    }

    public function test_a_receipt_cannot_settle_a_vendor_bill(): void
    {
        $bill = $this->approvedBill(100000000);
        $payment = $this->draftPayment('in', 100000000);

        $this->expectExceptionMessage('A payment in can only settle ar_invoice documents.');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 100000000],
            ]);
        } finally {
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
            $this->assertSame(0.0, (float) $bill->fresh()->amount_paid);
            $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
        }
    }

    /**
     * Refused at SUBMIT, which is where a disbursement's allocations are now
     * typed — the clerk hears about it before an approver is asked to agree to
     * it, instead of after.
     */
    public function test_a_disbursement_cannot_settle_a_customer_invoice(): void
    {
        $invoice = $this->approvedInvoice(100000000);
        $payment = $this->draftPayment('out', 111000000);

        $this->expectExceptionMessage('A payment out can only settle ap_bill or gl_account documents.');

        try {
            $this->payments()->submit($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000],
            ], $this->financeUser());
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    public function test_overpaying_an_invoice_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000); // total 111.000.000
        $payment = $this->draftPayment('in', 200000000);

        $this->expectExceptionMessage('exceeds the outstanding');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 200000000],
            ]);
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    public function test_overpaying_the_remainder_of_a_partly_paid_invoice_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000); // total 111.000.000

        $this->payments()->post($this->draftPayment('in', 100000000), [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 100000000],
        ]);

        // Sisa 11.000.000; membayar 11.000.001 harus ditolak.
        $second = $this->draftPayment('in', 11000001, '2026-05-05');

        $this->expectExceptionMessage('exceeds the outstanding');

        try {
            $this->payments()->post($second, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 11000001],
            ]);
        } finally {
            $this->assertSame(PaymentStatus::Draft, $second->fresh()->status);
            $this->assertSame(100000000.0, (float) $invoice->fresh()->amount_paid);
        }
    }

    public function test_overpaying_a_vendor_bill_is_refused(): void
    {
        $bill = $this->approvedBill(100000000, 11000000, 2650000); // payable 108.350.000
        $payment = $this->draftPayment('out', 108350001);

        $this->expectExceptionMessage('exceeds the outstanding');

        try {
            $this->payments()->submit($payment, [
                ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 108350001],
            ], $this->financeUser());
        } finally {
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
            $this->assertSame(0.0, (float) $bill->fresh()->amount_paid);
            $this->assertNull($bill->fresh()->paid_at);
        }
    }

    public function test_a_zero_allocation_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000);
        $payment = $this->draftPayment('in', 111000000);

        $this->expectExceptionMessage('Allocation amounts must be positive.');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 0],
            ]);
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    public function test_a_negative_allocation_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000);
        $payment = $this->draftPayment('in', 111000000);

        $this->expectExceptionMessage('Allocation amounts must be positive.');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => -111000000],
            ]);
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    public function test_an_unapproved_invoice_cannot_receive_payments(): void
    {
        $invoice = $this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Faktur draf',
            'dpp' => 100000000,
        ]);
        $payment = $this->draftPayment('in', 111000000);

        $this->expectExceptionMessage('is not approved; it cannot receive payments');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000],
            ]);
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    public function test_an_unapproved_bill_cannot_be_paid(): void
    {
        $bill = $this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan draf',
            'dpp' => 100000000,
        ]);
        $payment = $this->draftPayment('out', 100000000);

        $this->expectExceptionMessage('is not approved; it cannot be paid');

        try {
            $this->payments()->submit($payment, [
                ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 100000000],
            ], $this->financeUser());
        } finally {
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
            $this->assertSame(0.0, (float) $bill->fresh()->amount_paid);
        }
    }

    public function test_a_payment_dated_in_a_closed_period_rolls_everything_back(): void
    {
        $invoice = $this->approvedInvoice(1000000000);
        FiscalPeriod::query()->where('year', 2026)->where('month', 4)->update(['status' => 'closed']);

        $payment = $this->draftPayment('in', 1110000000);

        $this->expectExceptionMessage('Periode fiskal 2026-04 sudah ditutup');

        try {
            $this->payments()->post($payment, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000],
            ]);
        } finally {
            $this->assertPaymentRolledBack($payment, $invoice);
        }
    }

    public function test_a_posted_payment_cannot_be_posted_updated_or_deleted_again(): void
    {
        $invoice = $this->approvedInvoice(100000000);
        $payment = $this->draftPayment('in', 111000000);

        // post() returns the reloaded row, exactly what a controller would hold
        // after route-model binding.
        $posted = $this->payments()->post($payment, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000],
        ]);

        foreach ([
            fn () => $this->payments()->post($posted, [
                ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000],
            ]),
            fn () => $this->payments()->update($posted, ['amount' => 1]),
            fn () => $this->payments()->delete($posted),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A posted payment must be immutable.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('is already posted', $e->getMessage());
            }
        }

        $fresh = $payment->fresh();
        $this->assertSame(111000000.0, (float) $fresh->amount);
        $this->assertNull($fresh->deleted_at);
        // Satu-satunya jurnal pembayaran tetap satu, tidak terduplikasi.
        $this->assertSame(1, Journal::query()->where('reference_type', 'payment')->count());
        $this->assertSame(111000000.0, (float) $invoice->fresh()->amount_paid);
    }

    /**
     * Nothing of a failed posting may survive: the payment stays draft with no
     * allocations, the document is untouched and no bank journal exists.
     */
    private function assertPaymentRolledBack(Payment $payment, ArInvoice $invoice): void
    {
        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
        $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());

        $fresh = $invoice->fresh();
        $this->assertSame(0.0, (float) $fresh->amount_paid);
        $this->assertNull($fresh->paid_at);
    }
}
