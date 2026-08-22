<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\ContractTermin;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\ArRetention;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Models\RevenueRecognitionRun;
use Modules\Finance\Services\ArRetentionService;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pembatalan dokumen yang sudah disetujui dan berjurnal.
 *
 * DocumentStatus::Cancelled was declared, defensively filtered on in a dozen
 * queries — and never set by any code path. A wrong AR invoice that had been
 * approved was therefore permanent: the fictitious receivable aged forever, the
 * contract termin kept its billed_at stamp so ArInvoiceService refused the
 * corrected invoice, and the only remaining move was a manual JV, which repairs
 * the general ledger and leaves the AR subledger disagreeing with it for good.
 * The AP side was worse: the PO stayed spent, an advance stayed frozen, and the
 * project P&L kept a cost the vendor never charged.
 *
 * What cancelling has to guarantee is asserted here: the ledger goes back to
 * exactly where it was (a NEW reversing journal — the posted one is never
 * touched), every derived lock is released so a replacement document can be
 * raised, and the document is gone from the aging report.
 */
class DocumentCancellationTest extends ErpTestCase
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
     * An approved AR invoice. With $retention the customer withholds retensi,
     * which raises the 1-1350 leg and a fin_ar_retentions row.
     */
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

    private function approvedPoBill(?Project $project = null): ApBill
    {
        $po = $this->makePurchaseOrder($this->vendor, [
            'project_id' => $project?->id,
        ]);

        return $this->approveBill($this->apBills()->createFromPo($po, ['bill_date' => '2026-03-10']));
    }

    private function financeUserId(): int
    {
        return (int) $this->financeUser()->id;
    }

    private function balanceOf(string $accountCode): float
    {
        $line = JournalLine::query()
            ->where('account_id', $this->accountId($accountCode))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return round((float) $line->d - (float) $line->c, 2);
    }

    /**
     * Every account the given journal touched now nets to zero across the whole
     * posted ledger — the ledger is back where it started.
     */
    private function assertLedgerNeutral(Journal $original): void
    {
        foreach ($original->lines()->with('account')->get() as $line) {
            $code = $line->account->code;
            $this->assertEqualsWithDelta(
                0.0,
                $this->balanceOf($code),
                0.01,
                "Akun {$code} masih menyimpan saldo setelah pembatalan.",
            );
        }
    }

    private function reversalFor(string $referenceType, int $referenceId): Journal
    {
        return $this->singleJournalFor($referenceType, $referenceId);
    }

    // ------------------------------------------------------------ AR: the ledger

    public function test_cancelling_an_invoice_posts_a_mirror_journal_and_empties_every_account_it_touched(): void
    {
        // DPP 1.000.000.000 + PPN 110.000.000 - retensi 50.000.000
        //   => total 1.060.000.000, dengan 1-1350 sebesar 50.000.000
        $invoice = $this->approvedInvoice(1000000000, 50000000);
        $original = $this->singleJournalFor('ar_invoice', (int) $invoice->id);

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Nilai termin salah, diganti invoice baru.');

        $reversal = $this->reversalFor('ar_invoice_cancellation', (int) $invoice->id);
        // Dibukukan pada TANGGAL FAKTUR, bukan tanggal pembatalan, supaya kedua
        // sisi saling menghapus di dalam satu periode.
        $this->assertPostedAndBalanced($reversal, '2026-03-10');

        $lines = $this->linesByAccount($reversal);
        $this->assertEqualsWithDelta(1060000000.0, $lines['1-1300']['credit'], 0.01); // Cr Piutang Usaha
        $this->assertEqualsWithDelta(50000000.0, $lines['1-1350']['credit'], 0.01);   // Cr Piutang Retensi
        $this->assertEqualsWithDelta(1000000000.0, $lines['4-1100']['debit'], 0.01);  // Dr Pendapatan
        $this->assertEqualsWithDelta(110000000.0, $lines['2-1300']['debit'], 0.01);   // Dr PPN Keluaran

        $this->assertLedgerNeutral($original);
    }

    /**
     * A posted journal is immutable — the correction is a second document, not
     * an edit of the first, or the audit trail would lose the mistake.
     */
    public function test_the_original_journal_survives_the_cancellation_untouched(): void
    {
        $invoice = $this->approvedInvoice();
        $original = $this->singleJournalFor('ar_invoice', (int) $invoice->id);
        $before = $this->linesByAccount($original);

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Salah pelanggan.');

        $this->assertNotNull($original->fresh());
        $this->assertNull($original->fresh()->deleted_at);
        $this->assertSame($before, $this->linesByAccount($original->fresh()));
        $this->assertSame(2, Journal::query()->count()); // asli + pembalik
    }

    public function test_the_reason_the_actor_and_the_moment_are_recorded_on_the_invoice(): void
    {
        $invoice = $this->approvedInvoice();

        $cancelled = $this->arInvoices()->cancel(
            $invoice,
            $this->financeUser(),
            'Termin belum memenuhi syarat BAP; ditagihkan terlalu dini.',
        );

        $this->assertSame(DocumentStatus::Cancelled, $cancelled->status);
        $this->assertSame(
            'Termin belum memenuhi syarat BAP; ditagihkan terlalu dini.',
            $cancelled->cancellation_reason,
        );
        $this->assertSame($this->financeUserId(), (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);

        // Jejaknya menyatu dengan riwayat persetujuan dokumen.
        $this->assertSame(
            'cancelled',
            $cancelled->approvals()->orderByDesc('id')->first()->action,
        );
    }

    // ------------------------------------------------- AR: releasing the locks

    /**
     * THE point of the package on the AR side: without freeing the termin the
     * replacement invoice is refused and the operator is stuck.
     */
    public function test_cancelling_frees_the_termin_so_a_replacement_invoice_can_be_issued(): void
    {
        /** @var ContractTermin $termin */
        $termin = $this->makeTermin($this->contract, 1, 'Progress 40%', 40);

        $invoice = $this->approveInvoice(
            $this->arInvoices()->createFromTermin($termin, ['invoice_date' => '2026-03-10'])
        );

        $this->assertSame('2026-03-10', $termin->fresh()->billed_at->toDateString());

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Progres fisik belum 40%, tagihan ditarik.');

        $this->assertNull($termin->fresh()->billed_at);

        $replacement = $this->arInvoices()->createFromTermin($termin, ['invoice_date' => '2026-04-01']);

        $this->assertSame(DocumentStatus::Draft, $replacement->status);
        $this->assertNotSame($invoice->code, $replacement->code);
        // 10.000.000.000 x 40% = 4.000.000.000
        $this->assertEqualsWithDelta(4000000000.0, (float) $replacement->dpp, 0.01);
    }

    public function test_the_retention_receivable_the_invoice_raised_is_released(): void
    {
        $invoice = $this->approvedInvoice(1000000000, 50000000);

        $this->assertSame(1, ArRetention::query()->where('source_invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(50000000.0, $this->balanceOf('1-1350'), 0.01);

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Retensi dipotong dua kali.');

        $this->assertSame(0, ArRetention::query()->where('source_invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1350'), 0.01);
    }

    /**
     * Released retention is money that actually arrived, through a journal of
     * its own. Deleting the invoice behind it would leave that receipt with
     * nothing to stand on.
     */
    public function test_an_invoice_whose_retention_was_already_collected_cannot_be_cancelled(): void
    {
        $invoice = $this->approvedInvoice(1000000000, 50000000);
        $retention = ArRetention::query()->where('source_invoice_id', $invoice->id)->firstOrFail();

        app(ArRetentionService::class)
            ->release($retention, '2026-05-10', (int) $this->bank->id, $this->financeUserId());

        $this->expectExceptionMessage('sudah dicairkan');

        try {
            $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Coba batalkan setelah retensi cair.');
        } finally {
            $this->assertSame(DocumentStatus::Approved, $invoice->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'ar_invoice_cancellation')->count());
        }
    }

    public function test_a_cancelled_invoice_can_no_longer_receive_a_payment(): void
    {
        $invoice = $this->approvedInvoice(100000000); // total 111.000.000
        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Dobel dengan INV sebelumnya.');

        $payment = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-04-05',
            'bank_account_id' => $this->bank->id,
            'amount' => 111000000,
        ]);

        $this->expectExceptionMessage('is not approved; it cannot receive payments');

        $this->payments()->post($payment, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000],
        ]);
    }

    // ------------------------------------------------------------- AR: guards

    public function test_an_invoice_with_a_payment_against_it_cannot_be_cancelled(): void
    {
        $invoice = $this->approvedInvoice(100000000); // total 111.000.000

        $this->payments()->post(
            $this->payments()->create([
                'direction' => 'in',
                'payment_date' => '2026-04-05',
                'bank_account_id' => $this->bank->id,
                'amount' => 50000000,
            ]),
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 50000000]],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sudah menerima pembayaran');

        try {
            $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Terlanjur dibayar sebagian.');
        } finally {
            $this->assertSame(DocumentStatus::Approved, $invoice->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'ar_invoice_cancellation')->count());
            $this->assertEqualsWithDelta(50000000.0, (float) $invoice->fresh()->amount_paid, 0.01);
        }
    }

    public function test_a_cancellation_without_a_reason_is_refused(): void
    {
        $invoice = $this->approvedInvoice();

        $this->expectExceptionMessage('Alasan pembatalan wajib diisi.');

        try {
            $this->arInvoices()->cancel($invoice, $this->financeUser(), '   ');
        } finally {
            $this->assertSame(DocumentStatus::Approved, $invoice->fresh()->status);
        }
    }

    /**
     * A draft has no journal to reverse; it is deleted, not cancelled.
     */
    public function test_an_unapproved_invoice_cannot_be_cancelled(): void
    {
        $invoice = $this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Faktur draf',
            'dpp' => 100000000,
        ]);

        $this->expectExceptionMessage('hanya invoice yang sudah disetujui yang dapat dibatalkan');

        try {
            $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Salah ketik nilai.');
        } finally {
            $this->assertSame(DocumentStatus::Draft, $invoice->fresh()->status);
        }
    }

    public function test_an_invoice_cannot_be_cancelled_twice(): void
    {
        $invoice = $this->approvedInvoice();
        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Salah pelanggan.');

        $this->expectExceptionMessage('berstatus cancelled');

        try {
            $this->arInvoices()->cancel($invoice->fresh(), $this->financeUser(), 'Sekali lagi.');
        } finally {
            // Satu-satunya jurnal pembalik tetap satu; tidak terduplikasi.
            $this->assertSame(1, Journal::query()->where('reference_type', 'ar_invoice_cancellation')->count());
        }
    }

    /**
     * Statements for a closed month have been issued, so the reversal cannot go
     * back into it — but refusing outright leaves no instrument at all, because
     * this application has no credit note. The cancellation IS the instrument;
     * it simply lands in the period it was discovered in.
     */
    public function test_an_invoice_in_a_closed_period_is_reversed_in_the_open_one(): void
    {
        $invoice = $this->approvedInvoice(); // invoice_date 2026-03-10
        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'closed']);

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Ketahuan salah setelah tutup buku.');

        $reversal = $this->reversalFor('ar_invoice_cancellation', (int) $invoice->id);
        $this->assertSame(now()->toDateString(), $reversal->journal_date->toDateString());
        $this->assertSame(DocumentStatus::Cancelled, $invoice->fresh()->status);

        // Maret tetap seperti yang sudah dilaporkan: hanya jurnal aslinya.
        $this->assertSame(1, Journal::query()->whereBetween('journal_date', ['2026-03-01', '2026-03-31'])->count());
    }

    /**
     * The expensive case. A posted PSAK 115 run can never be recalculated, so a
     * reversal dropped back into a measured month leaves that month's revenue
     * negative by the catch-up the run already booked, while the next run —
     * which recomputes billings live and finds none — books the offsetting
     * amount with nothing to cancel it against. One cancellation, two wrong
     * income statements.
     */
    public function test_an_invoice_whose_period_a_posted_revenue_run_has_measured_is_reversed_today(): void
    {
        $invoice = $this->approvedInvoice(); // invoice_date 2026-03-10

        RevenueRecognitionRun::query()->create([
            'code' => 'POC/2026/03/001',
            'period_year' => 2026,
            'period_month' => 3,
            'status' => PostingStatus::Posted,
            'total_adjustment' => 0,
            'posted_at' => now(),
        ]);

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Termin salah, POC Maret sudah diposting.');

        $reversal = $this->reversalFor('ar_invoice_cancellation', (int) $invoice->id);
        $this->assertSame(now()->toDateString(), $reversal->journal_date->toDateString());
    }

    /**
     * A run posted for an EARLIER period says nothing about this one, so the
     * clean same-period reversal is still available.
     */
    public function test_a_revenue_run_for_an_earlier_period_does_not_move_the_reversal(): void
    {
        $invoice = $this->approvedInvoice(); // invoice_date 2026-03-10

        RevenueRecognitionRun::query()->create([
            'code' => 'POC/2026/02/001',
            'period_year' => 2026,
            'period_month' => 2,
            'status' => PostingStatus::Posted,
            'total_adjustment' => 0,
            'posted_at' => now(),
        ]);

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Nilai termin salah.');

        $reversal = $this->reversalFor('ar_invoice_cancellation', (int) $invoice->id);
        $this->assertSame('2026-03-10', $reversal->journal_date->toDateString());
    }

    /** A cancelled invoice owes nothing — the reversal already took it out of 1-1300. */
    public function test_a_cancelled_invoice_reports_no_outstanding_and_is_not_fully_paid(): void
    {
        $invoice = $this->approvedInvoice(1000000000);
        $this->assertEqualsWithDelta(1110000000.0, $invoice->outstanding(), 0.01);

        $cancelled = $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Kontrak dibatalkan pelanggan.');

        $this->assertEqualsWithDelta(0.0, $cancelled->outstanding(), 0.01);
        $this->assertFalse($cancelled->isFullyPaid());
    }

    /**
     * Cancelling requires amount_paid to be zero, so a cancelled document always
     * satisfies "amount_paid < total" — without an explicit exclusion the unpaid
     * filter hands back receivables whose journal has already been reversed.
     */
    public function test_the_unpaid_filter_does_not_return_cancelled_invoices(): void
    {
        $invoice = $this->approvedInvoice();
        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Salah pelanggan.');

        $response = $this->actingAs($this->adminUser())->getJson('/api/finance/ar-invoices?unpaid=1');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    // ------------------------------------------------------------- AR: aging

    public function test_a_cancelled_invoice_disappears_from_the_ar_aging(): void
    {
        $invoice = $this->approvedInvoice(1000000000); // total 1.110.000.000

        $before = $this->reports()->agingReport('ar');
        $this->assertEqualsWithDelta(1110000000.0, $before['total_outstanding'], 0.01);
        $this->assertCount(1, $before['rows']);

        $this->arInvoices()->cancel($invoice, $this->financeUser(), 'Kontrak dibatalkan pelanggan.');

        $after = $this->reports()->agingReport('ar');
        $this->assertSame([], $after['rows']);
        $this->assertEqualsWithDelta(0.0, $after['total_outstanding'], 0.01);
    }

    // ------------------------------------------------------------ AP: the ledger

    public function test_cancelling_a_bill_posts_a_mirror_journal_and_empties_every_account_it_touched(): void
    {
        // PO: DPP 100.000.000 + PPN 11.000.000 = 111.000.000 terhutang.
        $bill = $this->approvedPoBill();
        $original = $this->singleJournalFor('ap_bill', (int) $bill->id);

        $this->apBills()->cancel($bill, $this->financeUser(), 'Faktur vendor ganda, tagihan ditarik.');

        $reversal = $this->reversalFor('ap_bill_cancellation', (int) $bill->id);
        $this->assertPostedAndBalanced($reversal, '2026-03-10');

        $lines = $this->linesByAccount($reversal);
        $this->assertEqualsWithDelta(111000000.0, $lines['2-1100']['debit'], 0.01); // Dr Hutang Usaha
        $this->assertEqualsWithDelta(11000000.0, $lines['1-1600']['credit'], 0.01); // Cr PPN Masukan

        $this->assertSame(DocumentStatus::Cancelled, $bill->fresh()->status);
        $this->assertLedgerNeutral($original);
    }

    /**
     * The project P&L is built from fin_project_costs, not from the GL, so a
     * surviving cost row would put the project above the ledger by exactly the
     * amount of the cancelled bill.
     */
    public function test_the_project_cost_the_bill_recorded_is_removed(): void
    {
        $project = $this->makeProject();
        $bill = $this->approvedPoBill($project);

        $this->assertEqualsWithDelta(
            100000000.0,
            (float) ProjectCost::query()
                ->where('reference_type', 'ap_bill')
                ->where('reference_id', $bill->id)
                ->sum('amount'),
            0.01,
        );

        $this->apBills()->cancel($bill, $this->financeUser(), 'Barang tidak pernah dikirim.');

        $this->assertSame(0, ProjectCost::query()
            ->where('reference_type', 'ap_bill')
            ->where('reference_id', $bill->id)
            ->count());
        $this->assertEqualsWithDelta(0.0, $this->projectCosts()->totalsByCategory((int) $project->id)['material'], 0.01);
    }

    /**
     * A PO has exactly one final bill. Cancelling has to give that slot back,
     * or the corrected vendor invoice can never be entered.
     */
    public function test_cancelling_frees_the_purchase_order_for_a_replacement_bill(): void
    {
        $po = $this->makePurchaseOrder($this->vendor);
        $bill = $this->approveBill($this->apBills()->createFromPo($po, ['bill_date' => '2026-03-10']));

        try {
            $this->apBills()->createFromPo($po, ['bill_date' => '2026-03-20']);
            $this->fail('A PO may carry only one live final bill.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('A bill already exists for PO', $e->getMessage());
        }

        $this->apBills()->cancel($bill, $this->financeUser(), 'Nilai faktur vendor berbeda dengan PO.');

        $replacement = $this->apBills()->createFromPo($po, ['bill_date' => '2026-03-20']);

        $this->assertSame(DocumentStatus::Draft, $replacement->status);
        // Nilai PO kembali utuh: 100.000.000 DPP, tidak ada uang muka.
        $this->assertEqualsWithDelta(100000000.0, (float) $replacement->dpp, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $bill->fresh()->gl_cleared_amount, 0.01);
    }

    /**
     * The final bill already credited 1-1500 against this prepayment. Reversing
     * the advance as well would credit it twice and drive the asset negative,
     * so the consuming document has to go first.
     */
    public function test_an_advance_already_netted_off_by_a_final_bill_cannot_be_cancelled(): void
    {
        $po = $this->makePurchaseOrder($this->vendor);

        // Uang muka 30% : DPP 30.000.000 + PPN 3.300.000
        $advance = $this->approveBill($this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-01',
            'is_advance' => true,
            'dpp' => 30000000,
        ]));

        // Tagihan final: 100.000.000 - 30.000.000 = 70.000.000
        $final = $this->approveBill($this->apBills()->createFromPo($po, ['bill_date' => '2026-03-10']));
        $this->assertEqualsWithDelta(30000000.0, (float) $final->fresh()->advance_applied_amount, 0.01);

        $this->expectExceptionMessage('sudah dinilai bersih dari uang muka');

        try {
            $this->apBills()->cancel($advance, $this->financeUser(), 'Uang muka salah nilai.');
        } finally {
            $this->assertSame(DocumentStatus::Approved, $advance->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'ap_bill_cancellation')->count());
        }
    }

    /**
     * The same refusal while the final bill is still a DRAFT — the case the old
     * `advance_applied_amount > 0` test could not see, because build() sets that
     * column to 0 and only approve() ever raises it.
     *
     * finalBillAmounts() priced the draft NET of the advance at create time:
     * Rp 111.000.000 of PO less a 30 % uang muka leaves Rp 77.700.000. Cancel
     * the advance underneath it and approving that draft records the vendor as
     * owed Rp 77.700.000 for work worth Rp 111.000.000 — the project's cost
     * short by Rp 30.000.000, input VAT short by Rp 3.300.000 — and
     * finalBillExists() then refuses both a replacement bill and a new advance.
     * The journal balances and nothing warns, which is why this has to be a
     * refusal rather than a report.
     */
    public function test_an_advance_cannot_be_cancelled_while_a_draft_final_bill_is_priced_net_of_it(): void
    {
        $po = $this->makePurchaseOrder($this->vendor);

        $advance = $this->approveBill($this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-01',
            'is_advance' => true,
            'dpp' => 30000000,
        ]));

        // Never submitted, never approved: advance_applied_amount is still 0.
        $final = $this->apBills()->createFromPo($po, ['bill_date' => '2026-03-10']);
        $this->assertSame(DocumentStatus::Draft, $final->status);
        $this->assertEqualsWithDelta(0.0, (float) $final->advance_applied_amount, 0.01);
        $this->assertEqualsWithDelta(70000000.0, (float) $final->dpp, 0.01);

        $this->expectExceptionMessage('sudah dinilai bersih dari uang muka');

        try {
            $this->apBills()->cancel($advance, $this->financeUser(), 'Vendor menarik permintaan DP.');
        } finally {
            $this->assertSame(DocumentStatus::Approved, $advance->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'ap_bill_cancellation')->count());
        }
    }

    /**
     * And the way out: withdraw the draft first, then the advance, then re-raise
     * the final — which finalBillAmounts() now prices at the full Rp 100.000.000
     * because no approved advance remains.
     */
    public function test_deleting_the_draft_final_frees_the_advance_and_the_reissue_is_priced_gross(): void
    {
        $po = $this->makePurchaseOrder($this->vendor);

        $advance = $this->approveBill($this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-01',
            'is_advance' => true,
            'dpp' => 30000000,
        ]));

        $this->apBills()->delete($this->apBills()->createFromPo($po, ['bill_date' => '2026-03-10']));
        $this->apBills()->cancel($advance, $this->financeUser(), 'Vendor menarik permintaan DP.');

        $reissued = $this->apBills()->createFromPo($po, ['bill_date' => '2026-03-12']);

        $this->assertSame(DocumentStatus::Cancelled, $advance->fresh()->status);
        $this->assertEqualsWithDelta(100000000.0, (float) $reissued->dpp, 0.01);
        $this->assertEqualsWithDelta(111000000.0, (float) $reissued->total_payable, 0.01);
    }

    public function test_an_advance_can_be_cancelled_once_the_final_bill_that_consumed_it_is(): void
    {
        $po = $this->makePurchaseOrder($this->vendor);

        $advance = $this->approveBill($this->apBills()->createFromPo($po, [
            'bill_date' => '2026-03-01',
            'is_advance' => true,
            'dpp' => 30000000,
        ]));
        $final = $this->approveBill($this->apBills()->createFromPo($po, ['bill_date' => '2026-03-10']));

        $this->apBills()->cancel($final, $this->financeUser(), 'Tagihan final salah nilai.');
        $this->apBills()->cancel($advance, $this->financeUser(), 'Uang muka batal, PO dibatalkan.');

        $this->assertSame(DocumentStatus::Cancelled, $advance->fresh()->status);
        // 1-1500 Uang Muka Proyek: didebit 30.000.000, dikredit oleh tagihan
        // final, lalu keduanya dibalik => nol.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('1-1500'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balanceOf('2-1100'), 0.01);
    }

    // ------------------------------------------------------------- AP: guards

    public function test_a_bill_with_a_payment_against_it_cannot_be_cancelled(): void
    {
        $bill = $this->approvedPoBill(); // total_payable 111.000.000

        $this->approvedOutgoingPayment(
            [
                'payment_date' => '2026-04-05',
                'bank_account_id' => $this->bank->id,
                'amount' => 50000000,
            ],
            [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 50000000]],
        );

        $this->expectExceptionMessage('sudah dibayar');

        try {
            $this->apBills()->cancel($bill, $this->financeUser(), 'Terlanjur dibayar sebagian.');
        } finally {
            $this->assertSame(DocumentStatus::Approved, $bill->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'ap_bill_cancellation')->count());
        }
    }

    public function test_an_unapproved_bill_cannot_be_cancelled(): void
    {
        $bill = $this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan draf',
            'dpp' => 100000000,
        ]);

        $this->expectExceptionMessage('hanya tagihan yang sudah disetujui yang dapat dibatalkan');

        try {
            $this->apBills()->cancel($bill, $this->financeUser(), 'Salah vendor.');
        } finally {
            $this->assertSame(DocumentStatus::Draft, $bill->fresh()->status);
        }
    }

    /** Sisi AP mengikuti aturan yang sama — lihat padanannya di sisi AR. */
    public function test_a_bill_in_a_closed_period_is_reversed_in_the_open_one(): void
    {
        $bill = $this->approvedPoBill(); // bill_date 2026-03-10
        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'closed']);

        $this->apBills()->cancel($bill, $this->financeUser(), 'Ketahuan salah setelah tutup buku.');

        $reversal = $this->reversalFor('ap_bill_cancellation', (int) $bill->id);
        $this->assertSame(now()->toDateString(), $reversal->journal_date->toDateString());
        $this->assertSame(DocumentStatus::Cancelled, $bill->fresh()->status);
    }

    public function test_a_cancelled_bill_reports_no_outstanding(): void
    {
        $bill = $this->approvedPoBill();
        $this->assertGreaterThan(0.0, $bill->outstanding());

        $cancelled = $this->apBills()->cancel($bill, $this->financeUser(), 'Vendor menarik fakturnya.');

        $this->assertEqualsWithDelta(0.0, $cancelled->outstanding(), 0.01);
        $this->assertFalse($cancelled->isFullyPaid());
    }

    public function test_the_unpaid_filter_does_not_return_cancelled_bills(): void
    {
        $bill = $this->approvedPoBill();
        $this->apBills()->cancel($bill, $this->financeUser(), 'Vendor menarik fakturnya.');

        $response = $this->actingAs($this->adminUser())->getJson('/api/finance/ap-bills?unpaid=1');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    // ------------------------------------------------------------- AP: aging

    public function test_a_cancelled_bill_disappears_from_the_ap_aging(): void
    {
        $bill = $this->approvedPoBill();

        $before = $this->reports()->agingReport('ap');
        $this->assertEqualsWithDelta(111000000.0, $before['total_outstanding'], 0.01);
        $this->assertCount(1, $before['rows']);

        $this->apBills()->cancel($bill, $this->financeUser(), 'Vendor menarik fakturnya.');

        $after = $this->reports()->agingReport('ap');
        $this->assertSame([], $after['rows']);
        $this->assertEqualsWithDelta(0.0, $after['total_outstanding'], 0.01);
    }

    // ------------------------------------------------------------- endpoints

    public function test_the_cancel_endpoint_requires_a_reason_and_books_the_reversal(): void
    {
        $invoice = $this->approvedInvoice();
        $user = $this->adminUser();

        $this->actingAs($user)
            ->postJson("/api/finance/ar-invoices/{$invoice->id}/cancel", [])
            ->assertStatus(422);

        $this->assertSame(DocumentStatus::Approved, $invoice->fresh()->status);

        $this->actingAs($user)
            ->postJson("/api/finance/ar-invoices/{$invoice->id}/cancel", [
                'reason' => 'Kontrak dibatalkan pelanggan sebelum pekerjaan dimulai.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(1, Journal::query()->where('reference_type', 'ar_invoice_cancellation')->count());
    }

    public function test_the_ap_cancel_endpoint_books_the_reversal(): void
    {
        $bill = $this->approvedPoBill();

        $this->actingAs($this->adminUser())
            ->postJson("/api/finance/ap-bills/{$bill->id}/cancel", [
                'reason' => 'Faktur vendor ganda, tagihan ditarik.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(1, Journal::query()->where('reference_type', 'ap_bill_cancellation')->count());
        $this->assertSame(DocumentStatus::Cancelled, $bill->fresh()->status);
    }
}
