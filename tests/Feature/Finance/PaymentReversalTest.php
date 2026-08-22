<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\WithholdingType;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\ArRetention;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Models\BankStatementLine;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\Payment;
use Modules\Finance\Services\ArRetentionService;
use Modules\Finance\Services\PettyCashFundService;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pembalikan pembayaran yang sudah diposting.
 *
 * A posted payment was the one value-bearing document with no way back:
 * delete() demands draft and no reversal existed. One receipt allocated to the
 * wrong faktur therefore locked that invoice out of cancellation FOR EVER —
 * ArInvoiceService::cancel() refuses any invoice with amount_paid > 0, and
 * nothing could bring amount_paid down again — so the fictitious receivable
 * aged forever, the termin stayed stamped billed_at, and the replacement
 * invoice could never be raised. The AP side was the same trap with a vendor.
 *
 * What a reversal has to guarantee is asserted here: the posted journal is
 * never touched and its mirror is a NEW journal, the settled documents get
 * their money back so they become cancellable again, and who/when/why is on
 * the record. What it has to REFUSE matters just as much, and each refusal has
 * its works-twin beside it.
 */
class PaymentReversalTest extends ErpTestCase
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

    /** DPP 1.000.000.000 + PPN 11% = total 1.110.000.000. */
    private function approvedInvoice(float $dpp = 1000000000, float $retention = 0): ArInvoice
    {
        return $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Penagihan termin progres 40%',
            'dpp' => $dpp,
            'ppn_rate' => 11.0,
            'retention_withheld' => $retention,
        ]));
    }

    private function approvedBill(): ApBill
    {
        return $this->approveBill($this->apBills()->createFromPo(
            $this->makePurchaseOrder($this->vendor),
            ['bill_date' => '2026-03-10'],
        ));
    }

    /** A posted receipt settling $amount of $invoice. */
    private function postedReceipt(ArInvoice $invoice, float $amount, string $date = '2026-04-05'): Payment
    {
        $payment = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => $date,
            'bank_account_id' => $this->bank->id,
            'amount' => $amount,
        ]);

        return $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => $amount]],
        );
    }

    private function balanceOf(string $accountCode): float
    {
        $line = JournalLine::query()
            ->where('account_id', $this->accountId($accountCode))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return round((float) $line->d - (float) $line->c, 2);
    }

    // ------------------------------------------------------------- the ledger

    public function test_reversing_a_receipt_posts_a_mirror_journal_and_leaves_the_bank_where_it_started(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        // Dr 1-1210 Bank 1.110.000.000 / Cr 1-1300 Piutang Usaha.
        $this->assertEqualsWithDelta(1110000000.0, $this->balanceOf('1-1210'), 0.01);

        $this->payments()->reverse($payment, $this->financeUser(), 'Salah alokasi ke faktur pelanggan lain.');

        $reversal = $this->singleJournalFor('payment_reversal', (int) $payment->id);
        // Dibukukan pada TANGGAL PEMBAYARAN — periodenya masih terbuka dan
        // belum diukur PSAK 115 — supaya kedua sisi saling menghapus di dalam
        // satu bulan.
        $this->assertPostedAndBalanced($reversal, '2026-04-05');

        $lines = $this->linesByAccount($reversal);
        $this->assertEqualsWithDelta(1110000000.0, $lines['1-1210']['credit'], 0.01);
        $this->assertEqualsWithDelta(1110000000.0, $lines['1-1300']['debit'], 0.01);

        // 1.110.000.000 − 1.110.000.000 = 0: bank kembali seperti semula, dan
        // piutang kembali berdiri sebesar nilai fakturnya.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1210'), 0.01);
        $this->assertEqualsWithDelta(1110000000.0, $this->balanceOf('1-1300'), 0.01);
    }

    /**
     * A posted journal is immutable; the correction is a second document, or
     * the audit trail loses the mistake.
     */
    public function test_the_original_payment_journal_survives_the_reversal_untouched(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        $original = $this->singleJournalFor('payment', (int) $payment->id);
        $before = $this->linesByAccount($original);

        $this->payments()->reverse($payment, $this->financeUser(), 'Salah faktur.');

        $this->assertNotNull($original->fresh());
        $this->assertNull($original->fresh()->deleted_at);
        $this->assertSame($before, $this->linesByAccount($original->fresh()));

        // faktur + pembayaran + pembalik = 3.
        $this->assertSame(3, Journal::query()->count());
    }

    /**
     * A reversal dropped back into a month a posted PSAK 115 run has measured
     * would make that month's revenue negative while the next run books the
     * catch-up with nothing to offset it. reversalDate() moves it to today.
     */
    public function test_a_reversal_of_a_payment_in_a_closed_period_is_dated_today(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        FiscalPeriod::query()->where('year', 2026)->where('month', 4)
            ->update(['status' => 'closed']);

        $this->payments()->reverse($payment, $this->financeUser(), 'Ditemukan setelah tutup buku April.');

        $reversal = $this->singleJournalFor('payment_reversal', (int) $payment->id);
        $this->assertSame(now()->toDateString(), $reversal->journal_date->toDateString());
    }

    // ------------------------------------------------- releasing the documents

    /**
     * THE POINT OF THE WHOLE PACKAGE: amount_paid comes back off, which is
     * what lets the invoice be cancelled and its termin re-billed.
     */
    public function test_the_invoice_is_unpaid_again_and_becomes_cancellable(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        $this->assertTrue($invoice->fresh()->isFullyPaid());

        try {
            $this->arInvoices()->cancel($invoice->fresh(), $this->financeUser(), 'Salah pelanggan.');
            $this->fail('a paid invoice must not be cancellable');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah menerima pembayaran', $e->getMessage());
        }

        $this->payments()->reverse($payment, $this->financeUser(), 'Salah alokasi.');

        $settled = $invoice->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $settled->amount_paid, 0.01);
        $this->assertNull($settled->paid_at);
        $this->assertEqualsWithDelta(1110000000.0, $settled->outstanding(), 0.01);

        $cancelled = $this->arInvoices()->cancel($settled, $this->financeUser(), 'Salah pelanggan.');
        $this->assertSame(DocumentStatus::Cancelled, $cancelled->status);
    }

    public function test_a_partial_receipt_gives_back_only_its_own_share(): void
    {
        $invoice = $this->approvedInvoice();

        $first = $this->postedReceipt($invoice, 400000000, '2026-04-05');
        $second = $this->postedReceipt($invoice, 300000000, '2026-04-20');

        // 400 jt + 300 jt = 700 jt dibayar dari 1.110.000.000.
        $this->assertEqualsWithDelta(700000000.0, (float) $invoice->fresh()->amount_paid, 0.01);

        $this->payments()->reverse($second, $this->financeUser(), 'Uang muka pelanggan lain.');

        // Hanya 300 jt yang dikembalikan; yang pertama tetap berdiri.
        $this->assertEqualsWithDelta(400000000.0, (float) $invoice->fresh()->amount_paid, 0.01);
        $this->assertSame(PaymentStatus::Posted, $first->fresh()->status);
    }

    public function test_reversing_a_disbursement_reopens_the_vendor_bill(): void
    {
        $bill = $this->approvedBill();
        $outstanding = $bill->outstanding();

        $payment = $this->approvedOutgoingPayment(
            ['payment_date' => '2026-04-05', 'bank_account_id' => $this->bank->id, 'amount' => $outstanding],
            [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => $outstanding]],
        );

        $this->assertEqualsWithDelta(0.0, $bill->fresh()->outstanding(), 0.01);

        $this->payments()->reverse($payment, $this->financeUser(), 'Transfer ke rekening vendor yang salah.');

        $reopened = $bill->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $reopened->amount_paid, 0.01);
        $this->assertNull($reopened->paid_at);
        $this->assertEqualsWithDelta($outstanding, $reopened->outstanding(), 0.01);
    }

    /**
     * The withheld slice settled the invoice exactly like cash, so it has to
     * come off with it — otherwise the invoice stays part-paid by a tax nobody
     * withheld and 1-1700 keeps an asset the bukti potong no longer supports.
     */
    public function test_a_receipt_that_carried_withholdings_gives_the_whole_gross_back(): void
    {
        $invoice = $this->approvedInvoice();

        $payment = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-04-05',
            'bank_account_id' => $this->bank->id,
            'amount' => 973500000, // 1.110.000.000 − 26.500.000 PPh − 110.000.000 wapu
        ]);

        $this->payments()->post(
            $payment,
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 1110000000]],
            null,
            [
                [
                    'ar_invoice_id' => $invoice->id,
                    'type' => WithholdingType::PphFinal->value,
                    'amount' => 26500000,
                    'certificate_no' => '0031/PPH4-2/IV/2026',
                ],
                [
                    'ar_invoice_id' => $invoice->id,
                    'type' => WithholdingType::PpnWapu->value,
                    'amount' => 110000000,
                ],
            ],
        );

        $this->assertEqualsWithDelta(26500000.0, $this->balanceOf('1-1700'), 0.01);

        $this->payments()->reverse($payment, $this->financeUser(), 'Bukti potong milik faktur lain.');

        // Gross penuh kembali terutang, dan pajak dibayar dimuka kembali nol.
        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->amount_paid, 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1700'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1210'), 0.01);
        // PPN Keluaran kembali menjadi kewajiban kita: −110.000.000.
        $this->assertEqualsWithDelta(-110000000.0, $this->balanceOf('2-1300'), 0.01);
    }

    // -------------------------------------------------------------- the record

    public function test_the_reason_the_actor_and_the_moment_are_recorded_on_the_payment(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        $reversed = $this->payments()->reverse(
            $payment,
            $this->financeApprover(),
            'Dana masuk ternyata milik PT Sarana, bukan termin ini.',
        );

        $this->assertSame(PaymentStatus::Reversed, $reversed->status);
        $this->assertTrue($reversed->isReversed());
        $this->assertSame(
            'Dana masuk ternyata milik PT Sarana, bukan termin ini.',
            $reversed->reversal_reason,
        );
        $this->assertSame((int) $this->financeApprover()->id, (int) $reversed->reversed_by);
        $this->assertNotNull($reversed->reversed_at);

        // Jejak yang sama dengan yang ditulis approve/reject/cancel.
        $this->assertSame(1, $payment->approvals()->where('action', 'reversed')->count());
    }

    public function test_a_reversal_without_a_reason_is_refused(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        try {
            $this->payments()->reverse($payment, $this->financeUser(), '   ');
            $this->fail('a reversal must say why');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Alasan pembalikan', $e->getMessage());
            $this->assertSame(PaymentStatus::Posted, $payment->fresh()->status);
            $this->assertSame(2, Journal::query()->count()); // faktur + pembayaran, tanpa pembalik
        }
    }

    // --------------------------------------------------------- what it refuses

    public function test_an_unposted_payment_has_nothing_to_reverse(): void
    {
        $invoice = $this->approvedInvoice();

        $draft = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-04-05',
            'bank_account_id' => $this->bank->id,
            'amount' => 1110000000,
        ]);

        try {
            $this->payments()->reverse($draft, $this->financeUser(), 'Salah ketik.');
            $this->fail('a draft payment must not be reversible');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum diposting', $e->getMessage());
            $this->assertSame(PaymentStatus::Draft, $draft->fresh()->status);
        }

        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->amount_paid, 0.01);
    }

    public function test_a_payment_cannot_be_reversed_twice(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        $this->payments()->reverse($payment, $this->financeUser(), 'Salah alokasi.');

        try {
            $this->payments()->reverse($payment->fresh(), $this->financeUser(), 'Sekali lagi.');
            $this->fail('a reversed payment must not be reversed again');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah dibalik', $e->getMessage());
        }

        // Satu pembalik saja — bank tidak dikredit dua kali.
        $this->assertSame(
            1,
            Journal::query()->where('reference_type', 'payment_reversal')->count(),
        );
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1210'), 0.01);
    }

    /**
     * Retensi yang sudah dicairkan adalah uang yang benar-benar masuk lewat
     * jurnal terpisah. Membuka kembali termin yang MELAHIRKAN retensi itu akan
     * meninggalkan penerimaan tanpa dasar — dan invoicenya tetap tidak bisa
     * dibatalkan setelahnya, karena ArInvoiceService menolak dengan alasan yang
     * sama.
     */
    public function test_a_receipt_is_refused_when_the_invoices_retention_has_been_released(): void
    {
        $invoice = $this->approvedInvoice(1000000000, 50000000);
        // total = 1.000.000.000 + 110.000.000 − 50.000.000 retensi = 1.060.000.000
        $payment = $this->postedReceipt($invoice, 1060000000);

        /** @var ArRetention $retention */
        $retention = ArRetention::query()->where('source_invoice_id', $invoice->id)->firstOrFail();
        $this->openFiscalYear(2027); // masa pemeliharaan berakhir tahun berikutnya
        app(ArRetentionService::class)->release($retention, '2027-03-15', (int) $this->bank->id);

        try {
            $this->payments()->reverse($payment, $this->financeUser(), 'Salah alokasi.');
            $this->fail('a receipt whose retention has been released must not be reversible');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Retensi', $e->getMessage());
            $this->assertStringContainsString('sudah dicairkan', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Posted, $payment->fresh()->status);
        $this->assertEqualsWithDelta(1060000000.0, (float) $invoice->fresh()->amount_paid, 0.01);
    }

    /** The works-twin: an unreleased retention is no obstacle at all. */
    public function test_a_receipt_whose_retention_is_still_outstanding_reverses_normally(): void
    {
        $invoice = $this->approvedInvoice(1000000000, 50000000);
        $payment = $this->postedReceipt($invoice, 1060000000);

        $reversed = $this->payments()->reverse($payment, $this->financeUser(), 'Salah alokasi.');

        $this->assertSame(PaymentStatus::Reversed, $reversed->status);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->amount_paid, 0.01);
        // Piutang retensi tidak disentuh — ia lahir dari fakturnya, bukan dari
        // pembayarannya.
        $this->assertEqualsWithDelta(50000000.0, $this->balanceOf('1-1350'), 0.01);
    }

    /**
     * A matched statement line is the bank's own word that this payment
     * cleared. Reversing underneath it leaves the reconciliation pointing at a
     * document that settles nothing and injects a residual nobody can explain.
     */
    public function test_a_payment_already_matched_to_a_bank_statement_line_is_refused(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        BankStatementLine::query()->create([
            'bank_statement_id' => $this->makeStatementId(),
            'line_no' => 1,
            'entry_date' => '2026-04-05',
            'value_date' => '2026-04-05',
            'direction' => 'credit',
            'amount' => 1110000000,
            'description' => 'TRF MASUK PT NUSANTARA',
            'match_status' => 'matched',
            'matched_type' => BankStatementLine::MATCH_PAYMENT,
            'matched_id' => $payment->id,
        ]);

        try {
            $this->payments()->reverse($payment, $this->financeUser(), 'Salah alokasi.');
            $this->fail('a matched payment must not be reversible');
        } catch (LogicException $e) {
            $this->assertStringContainsString('dicocokkan dengan mutasi bank', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Posted, $payment->fresh()->status);
    }

    /**
     * A drawer transfer froze a pile of bons and settled kasbons as "already
     * reimbursed" and the drawer has been spent from since; pulling the cash
     * back out would drive 1-11xx negative on a day the cashier already
     * counted. The instrument is the opposite transfer, and the message says so.
     */
    public function test_a_petty_cash_transfer_is_not_reversed_but_transferred_back(): void
    {
        $fundAccount = Account::query()->create([
            'code' => '1-1110',
            'name' => 'Kas Kecil Kantor Pusat',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'parent_id' => $this->accountId('1-1100'),
        ]);

        $fund = app(PettyCashFundService::class)->create([
            'code' => 'KK-01',
            'name' => 'Kas Kecil Kantor Pusat',
            'coa_account_id' => $fundAccount->id,
            'custodian_id' => $this->financeUser()->id,
            'float_amount' => 5000000,
        ]);

        $topUp = $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-06-01',
            'bank_account_id' => $this->bank->id,
            'amount' => 5000000,
            'petty_cash_fund_id' => $fund->id,
        ]);

        $allocations = [['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 5000000]];
        $posted = $this->payments()->post(
            $this->approveOutgoingPayment($topUp, $allocations),
            $allocations,
        );

        try {
            $this->payments()->reverse($posted, $this->financeUser(), 'Laci tidak jadi dibuka.');
            $this->fail('a drawer transfer must not be reversed');
        } catch (LogicException $e) {
            $this->assertStringContainsString('transfer kas kecil', $e->getMessage());
            $this->assertStringContainsString('berlawanan arah', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Posted, $posted->fresh()->status);
    }

    /**
     * A reversed payment stops being a settlement everywhere the sub-ledger
     * tie-out and the aging report look, without any of them growing a filter:
     * they all already join fin_payments on status = posted.
     */
    public function test_a_reversed_payment_no_longer_counts_as_a_settlement(): void
    {
        $invoice = $this->approvedInvoice();
        $payment = $this->postedReceipt($invoice, 1110000000);

        $this->payments()->reverse($payment, $this->financeUser(), 'Salah alokasi.');

        $aging = $this->reports()->agingReport('ar', '2026-05-31');
        $row = collect($aging['rows'])->firstWhere('code', $invoice->code);

        $this->assertNotNull($row, 'faktur harus muncul kembali di aging');
        $this->assertEqualsWithDelta(1110000000.0, (float) $row['outstanding'], 0.01);
    }

    private function makeStatementId(): int
    {
        return (int) BankStatement::query()->create([
            'bank_account_id' => $this->bank->id,
            'source_format' => 'csv',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'opening_balance' => 0,
            'closing_balance' => 1110000000,
            'content_hash' => str_repeat('a', 64),
        ])->id;
    }
}
