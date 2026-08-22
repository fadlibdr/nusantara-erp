<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\WithholdingType;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PaymentWithholding;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Penerimaan termin yang dipotong pajak oleh pemberi kerja.
 *
 * A badan-usaha owner MUST withhold PPh final jasa konstruksi (PP 9/2022) out
 * of every progress payment, and a BUMN/government owner also collects the PPN
 * itself (wapu, PMK 231/2019). The transfer that lands in the bank is therefore
 * never the invoice amount — on a 1,11 miliar termin from a BUMN it is
 * 973,5 juta — while the invoice is nevertheless SETTLED IN FULL: the owner
 * paid the rest of it to the state on our behalf.
 *
 * Before this existed the only recordable options were both wrong: allocate the
 * net and leave the invoice a few percent short forever (aging report lies,
 * collections chase money nobody owes), or allocate the gross and overstate the
 * bank (reconciliation never closes). Account 1-1700 Pajak Dibayar Dimuka PPh
 * sat in the chart of accounts unused by a single line of code, which meant the
 * withheld PPh — a real, creditable asset — was simply lost.
 *
 * Every expected number below is spelled out with its arithmetic.
 */
class PaymentWithholdingTest extends ErpTestCase
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

    // -------------------------------------------------------------- fixtures

    /**
     * An approved AR invoice, total = dpp + dpp * ppn_rate / 100.
     */
    private function approvedInvoice(float $dpp, float $ppnRate = 11.0): ArInvoice
    {
        return $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Penagihan termin progres 40%',
            'dpp' => $dpp,
            'ppn_rate' => $ppnRate,
        ]));
    }

    private function draftReceipt(float $cash, string $date = '2026-04-05'): Payment
    {
        return $this->payments()->create([
            'direction' => 'in',
            'payment_date' => $date,
            'bank_account_id' => $this->bank->id,
            'amount' => $cash,
        ]);
    }

    private function pphFinal(ArInvoice $invoice, float $amount, ?string $certificateNo = '0031/PPH4-2/IV/2026'): array
    {
        return [
            'ar_invoice_id' => $invoice->id,
            'type' => WithholdingType::PphFinal->value,
            'amount' => $amount,
            'certificate_no' => $certificateNo,
            'certificate_date' => '2026-04-05',
        ];
    }

    private function pph23(ArInvoice $invoice, float $amount, ?string $certificateNo = '0114/PPH23/IV/2026'): array
    {
        return [
            'ar_invoice_id' => $invoice->id,
            'type' => WithholdingType::Pph23->value,
            'amount' => $amount,
            'certificate_no' => $certificateNo,
            'certificate_date' => '2026-04-05',
        ];
    }

    private function ppnWapu(ArInvoice $invoice, float $amount, ?string $certificateNo = null): array
    {
        return [
            'ar_invoice_id' => $invoice->id,
            'type' => WithholdingType::PpnWapu->value,
            'amount' => $amount,
            'certificate_no' => $certificateNo,
        ];
    }

    /**
     * Net movement on one COA account across the whole posted ledger.
     */
    private function balanceOf(string $accountCode): float
    {
        $line = JournalLine::query()
            ->where('account_id', $this->accountId($accountCode))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return round((float) $line->d - (float) $line->c, 2);
    }

    // ------------------------------------------------------------ happy paths

    /**
     * The case the whole package exists for: a BUMN owner transfers the net and
     * the invoice must still come out fully settled.
     */
    public function test_a_receipt_net_of_pph_and_wapu_ppn_settles_the_invoice_in_full(): void
    {
        // DPP 1.000.000.000 + PPN 11% 110.000.000 = total 1.110.000.000
        $invoice = $this->approvedInvoice(1000000000);

        // PPh final 2,65% x 1.000.000.000 = 26.500.000 (PP 9/2022, pelaksana
        // bersertifikat); PPN 110.000.000 dipungut sendiri oleh pemilik.
        // Kas = 1.110.000.000 - 26.500.000 - 110.000.000 = 973.500.000
        $payment = $this->draftReceipt(973500000);

        $posted = $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            null,
            [
                $this->pphFinal($invoice, 26500000),
                $this->ppnWapu($invoice, 110000000),
            ],
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        $settled = $invoice->fresh();
        $this->assertEqualsWithDelta(1110000000.0, (float) $settled->amount_paid, 0.01);
        $this->assertEqualsWithDelta(0.0, $settled->outstanding(), 0.01);
        $this->assertTrue($settled->isFullyPaid());
        $this->assertSame('2026-04-05', $settled->paid_at->toDateString());
    }

    public function test_the_receipt_journal_debits_the_bank_the_prepaid_pph_and_the_output_ppn(): void
    {
        $invoice = $this->approvedInvoice(1000000000);
        $payment = $this->draftReceipt(973500000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            null,
            [
                $this->pphFinal($invoice, 26500000),
                $this->ppnWapu($invoice, 110000000),
            ],
        );

        $journal = $this->singleJournalFor('payment', (int) $payment->id);
        $this->assertPostedAndBalanced($journal, '2026-04-05');

        $lines = $this->linesByAccount($journal);
        $this->assertEqualsWithDelta(973500000.0, $lines['1-1210']['debit'], 0.01);  // Dr Bank
        $this->assertEqualsWithDelta(26500000.0, $lines['1-1700']['debit'], 0.01);   // Dr Pajak Dibayar Dimuka PPh
        $this->assertEqualsWithDelta(110000000.0, $lines['2-1300']['debit'], 0.01);  // Dr PPN Keluaran (wapu)
        $this->assertEqualsWithDelta(1110000000.0, $lines['1-1300']['credit'], 0.01); // Cr Piutang Usaha

        // 973.500.000 + 26.500.000 + 110.000.000 = 1.110.000.000
        $this->assertEqualsWithDelta(1110000000.0, $journal->totalDebit(), 0.01);
        $this->assertEqualsWithDelta(1110000000.0, $journal->totalCredit(), 0.01);
    }

    /**
     * The wapu leg is not decoration: the invoice credited 2-1300 with output
     * VAT we would otherwise have to deposit ourselves. Because the owner
     * deposited it, that liability has to end at zero — booking the collection
     * anywhere else would leave us owing DJP money that is already paid.
     */
    public function test_wapu_ppn_discharges_the_output_vat_liability_the_invoice_raised(): void
    {
        $invoice = $this->approvedInvoice(1000000000);

        // Faktur mengkredit 2-1300 sebesar 110.000.000.
        $this->assertEqualsWithDelta(-110000000.0, $this->balanceOf('2-1300'), 0.01);

        $this->payments()->post(
            $this->draftReceipt(973500000),
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            null,
            [
                $this->pphFinal($invoice, 26500000),
                $this->ppnWapu($invoice, 110000000),
            ],
        );

        // -110.000.000 + 110.000.000 = 0
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1300'), 0.01);
        // PPh yang dipotong tetap tercatat sebagai aset yang dapat dikreditkan.
        $this->assertEqualsWithDelta(26500000.0, $this->balanceOf('1-1700'), 0.01);
    }

    /**
     * A private badan usaha withholds PPh but is not a pemungut PPN — it
     * transfers the VAT to us and we deposit it.
     */
    public function test_a_non_wapu_customer_withholds_only_pph_final(): void
    {
        $invoice = $this->approvedInvoice(1000000000); // total 1.110.000.000

        // Kas = 1.110.000.000 - 26.500.000 = 1.083.500.000
        $payment = $this->draftReceipt(1083500000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            null,
            [$this->pphFinal($invoice, 26500000)],
        );

        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertEqualsWithDelta(1083500000.0, $lines['1-1210']['debit'], 0.01);
        $this->assertEqualsWithDelta(26500000.0, $lines['1-1700']['debit'], 0.01);
        $this->assertArrayNotHasKey('2-1300', $lines);
        $this->assertTrue($invoice->fresh()->isFullyPaid());

        // PPN keluaran tetap menjadi kewajiban kita: -110.000.000.
        $this->assertEqualsWithDelta(-110000000.0, $this->balanceOf('2-1300'), 0.01);
    }

    /**
     * The archive requirement: the bukti potong number and date are the only
     * evidence supporting the PPh credit in the annual return, so they have to
     * survive on the row and not merely in a journal description.
     */
    public function test_each_withholding_is_stored_with_its_certificate_number_and_date(): void
    {
        $invoice = $this->approvedInvoice(1000000000);
        $payment = $this->draftReceipt(973500000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            null,
            [
                $this->pphFinal($invoice, 26500000, '0031/PPH4-2/IV/2026'),
                $this->ppnWapu($invoice, 110000000, '0012/SSP-WAPU/IV/2026'),
            ],
        );

        $rows = PaymentWithholding::query()->where('payment_id', $payment->id)->get()->keyBy(
            fn (PaymentWithholding $row): string => $row->type->value
        );

        $this->assertCount(2, $rows);

        $pph = $rows[WithholdingType::PphFinal->value];
        $this->assertSame((int) $invoice->id, (int) $pph->ar_invoice_id);
        $this->assertEqualsWithDelta(26500000.0, (float) $pph->amount, 0.01);
        $this->assertSame('0031/PPH4-2/IV/2026', $pph->certificate_no);
        $this->assertSame('2026-04-05', $pph->certificate_date->toDateString());
        $this->assertSame('1-1700', $pph->type->accountCode());

        $ppn = $rows[WithholdingType::PpnWapu->value];
        $this->assertSame('0012/SSP-WAPU/IV/2026', $ppn->certificate_no);
        $this->assertSame('2-1300', $ppn->type->accountCode());
    }

    public function test_a_part_payment_with_withholding_leaves_the_rest_outstanding(): void
    {
        $invoice = $this->approvedInvoice(100000000); // total 111.000.000

        // Separuh tagihan: alokasi 55.500.000, PPh final 2,65% x 50.000.000 =
        // 1.325.000, kas = 55.500.000 - 1.325.000 = 54.175.000
        $payment = $this->draftReceipt(54175000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 55500000]],
            null,
            [$this->pphFinal($invoice, 1325000)],
        );

        $settled = $invoice->fresh();
        $this->assertEqualsWithDelta(55500000.0, (float) $settled->amount_paid, 0.01);
        // 111.000.000 - 55.500.000 = 55.500.000
        $this->assertEqualsWithDelta(55500000.0, $settled->outstanding(), 0.01);
        $this->assertNull($settled->paid_at);
    }

    public function test_one_receipt_can_carry_withholding_for_several_invoices(): void
    {
        $first = $this->approvedInvoice(100000000);  // total 111.000.000
        $second = $this->approvedInvoice(200000000); // total 222.000.000

        // PPh 2,65%: 2.650.000 + 5.300.000 = 7.950.000
        // Kas = 111.000.000 + 222.000.000 - 7.950.000 = 325.050.000
        $payment = $this->draftReceipt(325050000);

        $this->payments()->post(
            $payment,
            [
                ['payable_type' => 'ar_invoice', 'payable_id' => $first->id, 'amount' => 111000000],
                ['payable_type' => 'ar_invoice', 'payable_id' => $second->id, 'amount' => 222000000],
            ],
            null,
            [
                $this->pphFinal($first, 2650000),
                $this->pphFinal($second, 5300000),
            ],
        );

        $this->assertTrue($first->fresh()->isFullyPaid());
        $this->assertTrue($second->fresh()->isFullyPaid());
        $this->assertEqualsWithDelta(7950000.0, $this->balanceOf('1-1700'), 0.01);

        $journal = $this->singleJournalFor('payment', (int) $payment->id);
        $this->assertEqualsWithDelta(333000000.0, $journal->totalDebit(), 0.01);
        $this->assertEqualsWithDelta(333000000.0, $journal->totalCredit(), 0.01);
    }

    // ------------------------------------------------------------------ guards

    public function test_a_withholding_larger_than_the_remaining_invoice_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000); // total 111.000.000
        $payment = $this->draftReceipt(1000000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('melebihi sisa tagihan');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000]],
                null,
                [$this->pphFinal($invoice, 120000000)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    /**
     * The withheld part is a slice OF what is being settled, never an extra on
     * top of it. Allocating the net and then withholding as well would settle
     * more than the payment ever covered.
     */
    public function test_a_withholding_larger_than_its_own_allocation_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000); // total 111.000.000
        $payment = $this->draftReceipt(1000000);

        $this->expectExceptionMessage('melebihi nilai yang dialokasikan');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 50000000]],
                null,
                [$this->pphFinal($invoice, 60000000)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    public function test_pph_final_without_a_bukti_potong_number_is_refused(): void
    {
        $invoice = $this->approvedInvoice(1000000000);
        $payment = $this->draftReceipt(1083500000);

        $this->expectExceptionMessage('Nomor bukti potong wajib diisi');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
                null,
                [$this->pphFinal($invoice, 26500000, null)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    /**
     * The wapu SSP routinely arrives after the transfer, so demanding it up
     * front would block the receipt that already happened.
     */
    public function test_wapu_ppn_without_a_certificate_is_accepted(): void
    {
        $invoice = $this->approvedInvoice(1000000000);

        // Kas = 1.110.000.000 - 110.000.000 = 1.000.000.000
        $posted = $this->payments()->post(
            $this->draftReceipt(1000000000),
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            null,
            [$this->ppnWapu($invoice, 110000000)],
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertTrue($invoice->fresh()->isFullyPaid());
        $this->assertNull(PaymentWithholding::query()->first()->certificate_no);
    }

    /**
     * PPh we withhold FROM a vendor is already carried on the bill (2-12xx).
     * Entering it here as well would credit that liability twice.
     */
    public function test_a_disbursement_cannot_carry_a_withholding(): void
    {
        $invoice = $this->approvedInvoice(100000000);
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan vendor',
            'dpp' => 100000000,
        ]));

        $payment = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-04-05',
            'bank_account_id' => $this->bank->id,
            'amount' => 100000000,
        ]);

        $allocations = [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 100000000]];
        $approved = $this->approveOutgoingPayment($payment, $allocations);

        $this->expectExceptionMessage('Potongan pajak hanya berlaku pada penerimaan');

        try {
            $this->payments()->post(
                $approved,
                $allocations,
                null,
                [$this->pphFinal($invoice, 2650000)],
            );
        } finally {
            // The approval survives the refusal: nothing about the disbursement
            // changed, only the bukti potong that does not belong on it.
            $this->assertSame(PaymentStatus::Approved, $payment->fresh()->status);
            $this->assertSame(0, PaymentWithholding::query()->count());
            $this->assertEqualsWithDelta(0.0, (float) $bill->fresh()->amount_paid, 0.01);
            $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
        }
    }

    public function test_a_withholding_for_an_invoice_this_payment_does_not_settle_is_refused(): void
    {
        $settled = $this->approvedInvoice(100000000);   // total 111.000.000
        $other = $this->approvedInvoice(200000000);     // tidak dilunasi di sini
        $payment = $this->draftReceipt(108350000);

        $this->expectExceptionMessage('harus mengacu pada invoice yang dilunasi');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $settled->id, 'amount' => 111000000]],
                null,
                [$this->pphFinal($other, 2650000)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $settled);
            $this->assertEqualsWithDelta(0.0, (float) $other->fresh()->amount_paid, 0.01);
        }
    }

    public function test_the_allocation_must_equal_the_cash_plus_the_withholding(): void
    {
        $invoice = $this->approvedInvoice(1000000000); // total 1.110.000.000

        // Kas 1.000.000.000 + potongan 26.500.000 = 1.026.500.000, bukan
        // 1.110.000.000 yang dialokasikan.
        $payment = $this->draftReceipt(1000000000);

        $this->expectExceptionMessage('ditambah potongan pajak');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
                null,
                [$this->pphFinal($invoice, 26500000)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    /**
     * The rule that held before withholding existed still holds when nothing is
     * withheld — same message, so nothing that depended on it moved.
     */
    public function test_without_withholding_the_original_sum_guard_is_untouched(): void
    {
        $invoice = $this->approvedInvoice(1000000000);
        $payment = $this->draftReceipt(1000000000);

        $this->expectExceptionMessage('must sum to the payment amount');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    public function test_an_unknown_withholding_type_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000);
        $payment = $this->draftReceipt(108350000);

        $this->expectExceptionMessage('Jenis potongan pajak tidak dikenal');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000]],
                null,
                [[
                    'ar_invoice_id' => $invoice->id,
                    'type' => 'pph_21',
                    'amount' => 2650000,
                    'certificate_no' => '0031/IV/2026',
                ]],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    public function test_a_zero_withholding_is_refused(): void
    {
        $invoice = $this->approvedInvoice(100000000);
        $payment = $this->draftReceipt(111000000);

        $this->expectExceptionMessage('Nilai potongan pajak harus lebih besar dari nol.');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000]],
                null,
                [$this->pphFinal($invoice, 0)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    /**
     * A receipt dated in a closed period must take its withholding rows down
     * with it — they are written before the journal is posted.
     */
    public function test_a_receipt_into_a_closed_period_rolls_the_withholding_back_too(): void
    {
        $invoice = $this->approvedInvoice(1000000000);
        FiscalPeriod::query()->where('year', 2026)->where('month', 4)->update(['status' => 'closed']);

        $payment = $this->draftReceipt(1083500000);

        $this->expectExceptionMessage('Periode fiskal 2026-04 sudah ditutup');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
                null,
                [$this->pphFinal($invoice, 26500000)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    // -------------------------------------------------- PPh 23 jasa integrator

    /**
     * The other half of what this company sells. Jasa konstruksi is withheld at
     * PPh final 4(2); jasa integrasi sistem — pemasangan jaringan, pemeliharaan
     * perangkat, konsultasi teknis — is withheld at PPh Pasal 23, 2% of the
     * service value for a provider with an NPWP. Before Pph23 existed the only
     * recordable choice was to enter it as PPh final, which parks a CREDITABLE
     * tax in the same balance as a FINAL one.
     */
    public function test_a_service_receipt_withheld_at_pph_23_settles_the_invoice_in_full(): void
    {
        // DPP jasa 500.000.000 + PPN 11% 55.000.000 = total 555.000.000
        $invoice = $this->approvedInvoice(500000000);

        // PPh 23 = 2% x 500.000.000 = 10.000.000
        // Kas = 555.000.000 − 10.000.000 = 545.000.000
        $payment = $this->draftReceipt(545000000);

        $posted = $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 555000000]],
            null,
            [$this->pph23($invoice, 10000000)],
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        $settled = $invoice->fresh();
        $this->assertEqualsWithDelta(555000000.0, (float) $settled->amount_paid, 0.01);
        $this->assertEqualsWithDelta(0.0, $settled->outstanding(), 0.01);
        $this->assertTrue($settled->isFullyPaid());
    }

    /**
     * 1-1710, NOT 1-1700. PPh 23 is a kredit pajak subtracted from the year's
     * PPh Badan; PPh final 4(2) discharges the tax on that income for good.
     * One balance holding both makes the SPT Tahunan either claim a credit that
     * does not exist or forfeit one that does.
     */
    public function test_pph_23_lands_on_its_own_prepaid_account_apart_from_pph_final(): void
    {
        $invoice = $this->approvedInvoice(500000000);
        $payment = $this->draftReceipt(545000000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 555000000]],
            null,
            [$this->pph23($invoice, 10000000)],
        );

        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertEqualsWithDelta(545000000.0, $lines['1-1210']['debit'], 0.01);  // Dr Bank
        $this->assertEqualsWithDelta(10000000.0, $lines['1-1710']['debit'], 0.01);   // Dr Pajak Dibayar Dimuka PPh 23
        $this->assertEqualsWithDelta(555000000.0, $lines['1-1300']['credit'], 0.01); // Cr Piutang Usaha
        $this->assertArrayNotHasKey('1-1700', $lines);

        $this->assertEqualsWithDelta(10000000.0, $this->balanceOf('1-1710'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1700'), 0.01);
    }

    /**
     * The certificate discipline the PPh final case already has: the bukti
     * potong number is the ONLY evidence supporting the credit, so a PPh 23
     * withholding without one is an unrecoverable loss dressed up as an asset.
     */
    public function test_pph_23_without_a_bukti_potong_number_is_refused(): void
    {
        $invoice = $this->approvedInvoice(500000000);
        $payment = $this->draftReceipt(545000000);

        $this->expectExceptionMessage('bukti potong PPh 23');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 555000000]],
                null,
                [$this->pph23($invoice, 10000000, null)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    /**
     * The archive requirement: the number and its date have to survive on the
     * row, not merely in a journal description.
     */
    public function test_the_pph_23_certificate_is_stored_on_the_withholding_row(): void
    {
        $invoice = $this->approvedInvoice(500000000);
        $payment = $this->draftReceipt(545000000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 555000000]],
            null,
            [$this->pph23($invoice, 10000000)],
        );

        /** @var PaymentWithholding $withholding */
        $withholding = PaymentWithholding::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(WithholdingType::Pph23, $withholding->type);
        $this->assertSame('1-1710', $withholding->type->accountCode());
        $this->assertSame('bukti potong PPh 23', $withholding->type->certificateLabel());
        $this->assertSame('0114/PPH23/IV/2026', $withholding->certificate_no);
        $this->assertSame('2026-04-05', $withholding->certificate_date->toDateString());
    }

    /**
     * A BUMN owner buying integration services withholds PPh 23 AND collects
     * the PPN itself, so the two ride one receipt and the invoice still comes
     * out settled in full.
     */
    public function test_pph_23_and_wapu_ppn_ride_the_same_receipt(): void
    {
        $invoice = $this->approvedInvoice(500000000); // total 555.000.000

        // Kas = 555.000.000 − 10.000.000 PPh 23 − 55.000.000 PPN = 490.000.000
        $payment = $this->draftReceipt(490000000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 555000000]],
            null,
            [
                $this->pph23($invoice, 10000000),
                $this->ppnWapu($invoice, 55000000),
            ],
        );

        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertEqualsWithDelta(490000000.0, $lines['1-1210']['debit'], 0.01);
        $this->assertEqualsWithDelta(10000000.0, $lines['1-1710']['debit'], 0.01);
        $this->assertEqualsWithDelta(55000000.0, $lines['2-1300']['debit'], 0.01);

        $this->assertTrue($invoice->fresh()->isFullyPaid());
        // PPN keluaran yang disetor pemilik habis: −55.000.000 + 55.000.000 = 0.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1300'), 0.01);
    }

    /**
     * Nothing of a failed posting may survive: the payment stays draft with no
     * allocations and no withholdings, the invoice is untouched, and no bank
     * journal exists.
     */
    private function assertPostingRolledBack(Payment $payment, ArInvoice $invoice): void
    {
        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
        $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, PaymentWithholding::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());

        $fresh = $invoice->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $fresh->amount_paid, 0.01);
        $this->assertNull($fresh->paid_at);
    }
}
