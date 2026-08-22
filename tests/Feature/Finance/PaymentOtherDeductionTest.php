<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\WithholdingType;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Models\PaymentWithholding;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Temuan #15 — denda keterlambatan (liquidated damages) pada penerimaan.
 *
 * Kontrak konstruksi Indonesia lazim memuat denda 1‰ per hari keterlambatan
 * (plafon 5%), dan pemilik MEMOTONGNYA dari pembayaran termin. Sebelum baris
 * ini ada, penerimaan semacam itu tidak bisa dicatat sama sekali: alokasi
 * wajib pas dengan kas (atau kas + potongan PAJAK), sehingga invoice
 * menggantung 'kurang bayar' selamanya atau dikoreksi lewat JV manual di luar
 * alur — piutang dan umur piutang menyesatkan persis di kontrak yang sedang
 * bermasalah.
 *
 * 'other_deduction' menumpang mekanisme potongan yang sudah ada — identitas
 * alokasi = kas + Σ potongan tidak berubah — tetapi BUKAN pajak: tidak ada
 * bukti potong, dan yang wajib justru ALASAN tertulis, karena tanpa alasan
 * baris ini adalah selisih tanpa cerita. Beban mendarat di 7-2400 Beban Denda
 * & Potongan Lain-lain (satu rak dengan 7-2300 Beban Pajak Final — potongan
 * non-operasional atas termin kita; chart tersemai tidak punya akun denda).
 */
class PaymentOtherDeductionTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        // 7-2400 reaches a live DB through migration 2026_08_08_001121 and a
        // fresh install through the ChartOfAccountsSeeder mirror. The suite's
        // in-memory chart is seeded AFTER migrations ran on an empty DB (the
        // migration early-returns there), and the seeder mirror travels as an
        // orchestrator seam — so the account is provisioned here in exactly
        // the shape both of them write.
        Account::query()->updateOrCreate(['code' => '7-2400'], [
            'name' => 'Beban Denda & Potongan Lain-lain',
            'account_type' => 'other',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'parent_id' => $this->accountId('7-0000'),
        ]);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer);
        $this->bank = $this->makeBankAccount('1-1210');
    }

    // -------------------------------------------------------------- fixtures

    private function approvedInvoice(float $dpp): ArInvoice
    {
        return $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Penagihan termin progres 60%',
            'dpp' => $dpp,
            'ppn_rate' => 11.0,
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

    private function denda(ArInvoice $invoice, float $amount, ?string $reason = 'Denda keterlambatan 10 hari × 1‰ (pasal 12 kontrak)'): array
    {
        return [
            'ar_invoice_id' => $invoice->id,
            'type' => WithholdingType::OtherDeduction->value,
            'amount' => $amount,
            'reason' => $reason,
        ];
    }

    private function balanceOf(string $accountCode): float
    {
        $line = JournalLine::query()
            ->where('account_id', $this->accountId($accountCode))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return round((float) $line->d - (float) $line->c, 2);
    }

    // ------------------------------------------------------------ happy path

    /**
     * The case the finding names: the owner pays the invoice minus the denda,
     * and the invoice must still come out fully settled — the shortfall is a
     * cost we bear, not a receivable anyone will ever pay.
     */
    public function test_a_receipt_short_by_liquidated_damages_settles_the_invoice_in_full(): void
    {
        // DPP 1.000.000.000 + PPN 11% 110.000.000 = total 1.110.000.000
        $invoice = $this->approvedInvoice(1_000_000_000);

        // Denda 10 hari × 1‰ × 1.000.000.000 = 10.000.000
        // Kas = 1.110.000.000 − 10.000.000 = 1.100.000.000
        $payment = $this->draftReceipt(1_100_000_000);

        $posted = $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
            null,
            [$this->denda($invoice, 10_000_000)],
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        $settled = $invoice->fresh();
        $this->assertEqualsWithDelta(1_110_000_000.0, (float) $settled->amount_paid, 0.01);
        $this->assertEqualsWithDelta(0.0, $settled->outstanding(), 0.01);
        $this->assertTrue($settled->isFullyPaid());
    }

    public function test_the_deduction_journal_debits_the_denda_expense_account(): void
    {
        $invoice = $this->approvedInvoice(1_000_000_000);
        $payment = $this->draftReceipt(1_100_000_000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
            null,
            [$this->denda($invoice, 10_000_000)],
        );

        $journal = $this->singleJournalFor('payment', (int) $payment->id);
        $this->assertPostedAndBalanced($journal, '2026-04-05');

        $lines = $this->linesByAccount($journal);
        $this->assertEqualsWithDelta(1_100_000_000.0, $lines['1-1210']['debit'], 0.01);  // Dr Bank
        $this->assertEqualsWithDelta(10_000_000.0, $lines['7-2400']['debit'], 0.01);     // Dr Beban Denda
        $this->assertEqualsWithDelta(1_110_000_000.0, $lines['1-1300']['credit'], 0.01); // Cr Piutang Usaha

        // 1.100.000.000 + 10.000.000 = 1.110.000.000
        $this->assertEqualsWithDelta(1_110_000_000.0, $journal->totalDebit(), 0.01);
    }

    /** The audit trail lives on the row, not merely in a journal description. */
    public function test_the_written_reason_survives_on_the_row(): void
    {
        $invoice = $this->approvedInvoice(1_000_000_000);
        $payment = $this->draftReceipt(1_100_000_000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
            null,
            [$this->denda($invoice, 10_000_000)],
        );

        /** @var PaymentWithholding $row */
        $row = PaymentWithholding::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(WithholdingType::OtherDeduction, $row->type);
        $this->assertSame('Denda keterlambatan 10 hari × 1‰ (pasal 12 kontrak)', $row->reason);
        $this->assertSame('7-2400', $row->type->accountCode());
        $this->assertNull($row->certificate_no, 'a denda has no bukti potong');
    }

    /** A badan-usaha owner withholds PPh AND deducts its denda from one transfer. */
    public function test_a_denda_can_ride_beside_a_tax_withholding(): void
    {
        $invoice = $this->approvedInvoice(1_000_000_000); // total 1.110.000.000

        // PPh final 2,65% × 1.000.000.000 = 26.500.000; denda 10.000.000.
        // Kas = 1.110.000.000 − 26.500.000 − 10.000.000 = 1.073.500.000
        $payment = $this->draftReceipt(1_073_500_000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
            null,
            [
                [
                    'ar_invoice_id' => $invoice->id,
                    'type' => WithholdingType::PphFinal->value,
                    'amount' => 26_500_000,
                    'certificate_no' => '0031/PPH4-2/IV/2026',
                    'certificate_date' => '2026-04-05',
                ],
                $this->denda($invoice, 10_000_000),
            ],
        );

        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertEqualsWithDelta(1_073_500_000.0, $lines['1-1210']['debit'], 0.01);
        $this->assertEqualsWithDelta(26_500_000.0, $lines['1-1700']['debit'], 0.01);
        $this->assertEqualsWithDelta(10_000_000.0, $lines['7-2400']['debit'], 0.01);
        $this->assertTrue($invoice->fresh()->isFullyPaid());
    }

    // ---------------------------------------------------------------- guards

    /**
     * Without a written reason the deduction is a difference with no story:
     * an auditor finds a 7-2400 debit and nobody can say who deducted it or
     * under which contract clause. Refused before anything is settled.
     */
    public function test_a_deduction_without_a_written_reason_is_refused(): void
    {
        $invoice = $this->approvedInvoice(1_000_000_000);
        $payment = $this->draftReceipt(1_100_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Alasan potongan lain-lain wajib diisi');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
                null,
                [$this->denda($invoice, 10_000_000, null)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    /** A reason of pure whitespace is not a reason. */
    public function test_a_blank_reason_is_refused_too(): void
    {
        $invoice = $this->approvedInvoice(1_000_000_000);
        $payment = $this->draftReceipt(1_100_000_000);

        $this->expectExceptionMessage('Alasan potongan lain-lain wajib diisi');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
                null,
                [$this->denda($invoice, 10_000_000, '   ')],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    /**
     * The allocation identity is untouched by the new kind: cash + every
     * deduction must still equal the allocation, or the posting is refused.
     */
    public function test_the_allocation_identity_still_holds(): void
    {
        $invoice = $this->approvedInvoice(1_000_000_000);

        // Kas 1.000.000.000 + denda 10.000.000 = 1.010.000.000 ≠ 1.110.000.000.
        $payment = $this->draftReceipt(1_000_000_000);

        $this->expectExceptionMessage('ditambah potongan pajak');

        try {
            $this->payments()->post(
                $payment,
                [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
                null,
                [$this->denda($invoice, 10_000_000)],
            );
        } finally {
            $this->assertPostingRolledBack($payment, $invoice);
        }
    }

    // -------------------------------------------------------------- reversal

    /**
     * reverse() must mirror the deduction exactly as it was booked: the mirror
     * credits 7-2400 back to zero and the invoice reopens — a reversal that
     * left the denda expense standing would book the penalty twice once the
     * corrected receipt is posted.
     */
    public function test_reversing_the_receipt_mirrors_the_deduction(): void
    {
        $invoice = $this->approvedInvoice(1_000_000_000);
        $payment = $this->draftReceipt(1_100_000_000);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000_000]],
            null,
            [$this->denda($invoice, 10_000_000)],
        );

        $this->assertEqualsWithDelta(10_000_000.0, $this->balanceOf('7-2400'), 0.01);

        $reversed = $this->payments()->reverse(
            $payment->refresh(),
            $this->financeApprover(),
            'Salah faktur — potongan milik invoice termin berikutnya',
        );

        $this->assertSame(PaymentStatus::Reversed, $reversed->status);
        // 10.000.000 − 10.000.000 = 0: the mirror credited the denda back.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('7-2400'), 0.01);

        $reopened = $invoice->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $reopened->amount_paid, 0.01);
        $this->assertNull($reopened->paid_at);

        // The row survives as the record of what was mis-deducted.
        $this->assertSame(1, PaymentWithholding::query()->where('payment_id', $payment->id)->count());
    }

    /**
     * Nothing of a failed posting may survive — same discipline as the tax
     * withholding suite.
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
