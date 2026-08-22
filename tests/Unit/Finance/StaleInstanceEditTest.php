<?php

namespace Tests\Unit\Finance;

use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * ONE defect in three services: update() and delete() used to assert on the
 * caller's in-memory instance, outside any transaction, while post() and
 * approve() assert on a re-read inside one.
 *
 * A route-bound model is read several DB round-trips before the handler acts
 * on it — SubstituteBindings, then the permission lookup, then the request's
 * own Rule::exists queries — and anything that lands in that window is
 * invisible to the copy in hand. Dewi presses Simpan on draft JV/2026/08/0009;
 * Budi presses Posting in his own tab; Dewi's guard passes on the pre-post
 * copy and syncLines() rewrites a POSTED ledger entry, re-dated into a CLOSED
 * month. The Hapus button in the same window soft-deletes a posted journal,
 * and every GL reader filters whereNull('deleted_at'), so the entry ceases to
 * exist while its source document still says posted.
 *
 * Each test here posts or approves a SECOND instance of the same row between
 * the read and the call. That is the whole point: the existing
 * JournalServiceTest::test_a_posted_journal_cannot_be_updated / _deleted pass
 * the instance post() RETURNED, whose in-memory status is already posted, so
 * they exercise the guard on a fresh instance and can never reach this path.
 *
 * lockForUpdate() is a silent no-op on SQLite, so what is under test is not
 * the lock — it is the status RE-CHECK on the RE-READ inside the transaction.
 */
class StaleInstanceEditTest extends ErpTestCase
{
    use FinanceFixtures;

    private Customer $customer;

    private Contract $contract;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->customer = $this->makeCustomer();
        $this->contract = $this->makeContract($this->customer);
        $this->vendor = $this->makeVendor();
    }

    // ------------------------------------------------------------ journals

    public function test_a_journal_posted_after_the_caller_read_it_cannot_be_rewritten(): void
    {
        $stale = $this->draftJournal([
            ['6-4100', 5000000, 0],
            ['1-1210', 0, 5000000],
        ], '2026-08-03');

        // The second actor, on his own instance of the same row.
        $this->journals()->post(Journal::query()->findOrFail($stale->id));

        // The first actor's copy still believes what it read.
        $this->assertSame(PostingStatus::Draft, $stale->status);

        $this->expectExceptionMessage('is already posted');

        try {
            $this->journals()->update($stale, [
                // 2026-01 is a month whose trial balance has already been
                // reported; assertPeriodOpen() is never reached on this path.
                'journal_date' => '2026-01-15',
                'description' => 'Bayar sewa alat (diubah setelah posting)',
                'lines' => [
                    ['account_id' => $this->accountId('6-4100'), 'debit' => 500000000, 'credit' => 0],
                    ['account_id' => $this->accountId('1-1210'), 'debit' => 0, 'credit' => 500000000],
                ],
            ]);
        } finally {
            $fresh = $stale->fresh();

            $this->assertSame(PostingStatus::Posted, $fresh->status);
            $this->assertSame('2026-08-03', $fresh->journal_date->toDateString());
            $this->assertSame(5000000.0, $fresh->totalDebit());
            $this->assertCount(2, $fresh->lines()->get());
        }
    }

    public function test_a_journal_posted_after_the_caller_read_it_cannot_be_deleted(): void
    {
        $stale = $this->draftJournal([
            ['6-4100', 5000000, 0],
            ['1-1210', 0, 5000000],
        ], '2026-08-03');

        $this->journals()->post(Journal::query()->findOrFail($stale->id));

        $this->assertSame(PostingStatus::Draft, $stale->status);

        $this->expectExceptionMessage('is already posted');

        try {
            $this->journals()->delete($stale);
        } finally {
            // Still visible to every report that filters deleted_at.
            $this->assertDatabaseHas('fin_journals', ['id' => $stale->id, 'deleted_at' => null]);
        }
    }

    public function test_a_journal_still_draft_at_the_moment_of_the_call_is_updated_and_deleted(): void
    {
        $journal = $this->draftJournal([
            ['6-4100', 5000000, 0],
            ['1-1210', 0, 5000000],
        ], '2026-08-03');

        $updated = $this->journals()->update($journal, [
            'description' => 'Koreksi sebelum posting',
            'lines' => [
                ['account_id' => $this->accountId('6-4100'), 'debit' => 7500000, 'credit' => 0],
                ['account_id' => $this->accountId('1-1210'), 'debit' => 0, 'credit' => 7500000],
            ],
        ]);

        $this->assertSame('Koreksi sebelum posting', $updated->description);
        $this->assertSame(7500000.0, $updated->totalDebit());

        $this->journals()->delete($updated);

        $this->assertSoftDeleted('fin_journals', ['id' => $updated->id]);
    }

    // ------------------------------------------------------------ AR invoices

    public function test_an_invoice_approved_after_the_caller_read_it_cannot_be_rewritten(): void
    {
        $stale = $this->manualInvoice();

        // Approving posts Dr 1-1300 / Cr 4-1100 + 2-1300 off dpp and ppn_rate.
        $this->approveInvoice(ArInvoice::query()->findOrFail($stale->id));

        $this->assertSame(DocumentStatus::Draft, $stale->status);

        $this->expectExceptionMessage('can no longer be edited');

        try {
            $this->arInvoices()->update($stale, ['dpp' => 250000000]);
        } finally {
            $fresh = $stale->fresh();

            // The posted journal says 1.000.000.000 + 110.000.000; the invoice
            // must keep saying the same thing.
            $this->assertSame(DocumentStatus::Approved, $fresh->status);
            $this->assertSame(1000000000.0, (float) $fresh->dpp);
            $this->assertSame(1110000000.0, (float) $fresh->total);
        }
    }

    public function test_an_invoice_approved_after_the_caller_read_it_cannot_be_deleted(): void
    {
        $stale = $this->manualInvoice();

        $this->approveInvoice(ArInvoice::query()->findOrFail($stale->id));

        $this->assertSame(DocumentStatus::Draft, $stale->status);

        $this->expectExceptionMessage('can no longer be edited');

        try {
            $this->arInvoices()->delete($stale);
        } finally {
            // The receivable is in the ledger; the document may not disappear
            // from the aging while 1-1300 still carries it.
            $this->assertDatabaseHas('fin_ar_invoices', ['id' => $stale->id, 'deleted_at' => null]);
        }
    }

    public function test_an_invoice_still_draft_at_the_moment_of_the_call_is_updated_and_deleted(): void
    {
        $invoice = $this->manualInvoice();

        // 250.000.000 + 11% = 277.500.000
        $updated = $this->arInvoices()->update($invoice, ['dpp' => 250000000]);

        $this->assertSame(250000000.0, (float) $updated->dpp);
        $this->assertSame(277500000.0, (float) $updated->total);

        $this->arInvoices()->delete($updated);

        $this->assertSoftDeleted('fin_ar_invoices', ['id' => $updated->id]);
    }

    // ------------------------------------------------------------ AP bills

    public function test_a_bill_approved_after_the_caller_read_it_cannot_be_rewritten(): void
    {
        $stale = $this->manualBill();

        $this->approveBill(ApBill::query()->findOrFail($stale->id));

        $this->assertSame(DocumentStatus::Draft, $stale->status);

        $this->expectExceptionMessage('can no longer be edited');

        try {
            $this->apBills()->update($stale, ['dpp' => 250000000]);
        } finally {
            $fresh = $stale->fresh();

            // 100.000.000 + 11.000.000 PPN, exactly what approve() booked.
            $this->assertSame(DocumentStatus::Approved, $fresh->status);
            $this->assertSame(100000000.0, (float) $fresh->dpp);
            $this->assertSame(111000000.0, (float) $fresh->total_payable);
        }
    }

    public function test_a_bill_approved_after_the_caller_read_it_cannot_be_deleted(): void
    {
        $stale = $this->manualBill();

        $this->approveBill(ApBill::query()->findOrFail($stale->id));

        $this->assertSame(DocumentStatus::Draft, $stale->status);

        $this->expectExceptionMessage('can no longer be edited');

        try {
            $this->apBills()->delete($stale);
        } finally {
            $this->assertDatabaseHas('fin_ap_bills', ['id' => $stale->id, 'deleted_at' => null]);
        }
    }

    public function test_a_bill_still_draft_at_the_moment_of_the_call_is_updated_and_deleted(): void
    {
        $bill = $this->manualBill();

        // 250.000.000 + 11.000.000 PPN yang tidak diubah = 261.000.000
        $updated = $this->apBills()->update($bill, ['dpp' => 250000000]);

        $this->assertSame(250000000.0, (float) $updated->dpp);
        $this->assertSame(261000000.0, (float) $updated->total_payable);

        $this->apBills()->delete($updated);

        $this->assertSoftDeleted('fin_ap_bills', ['id' => $updated->id]);
    }

    // ------------------------------------------------------------ payments

    /**
     * The worst landing of the family. post() has already booked the bank leg
     * and bumped amount_paid on every document the allocations named, so
     * re-writing amount or payment_date afterwards leaves the posted journal
     * saying one number and the payment saying another — with the bank
     * reconciliation and the AR sub-ledger permanently apart.
     */
    public function test_a_payment_posted_after_the_caller_read_it_cannot_be_rewritten(): void
    {
        $invoice = $this->approveInvoice($this->manualInvoice());
        $bank = $this->makeBankAccount('1-1210');

        $stale = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-08-03',
            'bank_account_id' => $bank->id,
            'amount' => 500000000,
        ]);

        // The second actor, on his own instance of the same row.
        $this->payments()->post(
            Payment::query()->findOrFail($stale->id),
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 500000000]],
        );

        $this->assertSame(PaymentStatus::Draft, $stale->status);

        $this->expectExceptionMessage('is already posted');

        try {
            $this->payments()->update($stale, [
                'amount' => 900000000,
                'payment_date' => '2026-01-15',
            ]);
        } finally {
            $fresh = $stale->fresh();

            $this->assertSame(PaymentStatus::Posted, $fresh->status);
            $this->assertSame(500000000.0, (float) $fresh->amount);
            $this->assertSame('2026-08-03', $fresh->payment_date->toDateString());
        }
    }

    /**
     * Deleting instead: every GL and sub-ledger reader joins fin_payments, so
     * a soft-deleted posted payment leaves bank cash the trial balance carries
     * and no document explains.
     */
    public function test_a_payment_posted_after_the_caller_read_it_cannot_be_deleted(): void
    {
        $invoice = $this->approveInvoice($this->manualInvoice());
        $bank = $this->makeBankAccount('1-1210');

        $stale = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-08-03',
            'bank_account_id' => $bank->id,
            'amount' => 500000000,
        ]);

        $this->payments()->post(
            Payment::query()->findOrFail($stale->id),
            [['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 500000000]],
        );

        $this->assertSame(PaymentStatus::Draft, $stale->status);

        $this->expectExceptionMessage('is already posted');

        try {
            $this->payments()->delete($stale);
        } finally {
            $this->assertDatabaseHas('fin_payments', ['id' => $stale->id, 'deleted_at' => null]);
            $this->assertSame(PaymentStatus::Posted, $stale->fresh()->status);
        }
    }

    /**
     * The works-twin: a payment that really is still a draft when the call
     * lands is edited and deleted exactly as before — the re-read changes what
     * is CHECKED, not what is allowed.
     */
    public function test_a_payment_still_draft_at_the_moment_of_the_call_is_updated_and_deleted(): void
    {
        $bank = $this->makeBankAccount('1-1210');

        $payment = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-08-03',
            'bank_account_id' => $bank->id,
            'amount' => 500000000,
        ]);

        $updated = $this->payments()->update($payment, [
            'amount' => 750000000,
            'reference' => 'TRF/0815/BCA',
        ]);

        $this->assertSame(750000000.0, (float) $updated->amount);
        $this->assertSame('TRF/0815/BCA', $updated->reference);

        $this->payments()->delete($updated);

        $this->assertSoftDeleted('fin_payments', ['id' => $updated->id]);
    }

    // ------------------------------------------------------------ fixtures

    private function manualInvoice(): ArInvoice
    {
        return $this->arInvoices()->create([
            'customer_id' => $this->customer->id,
            'contract_id' => $this->contract->id,
            'invoice_date' => '2026-08-03',
            'description' => 'Penagihan progres pekerjaan Agustus 2026',
            'dpp' => 1000000000,
        ]);
    }

    private function manualBill(): ApBill
    {
        return $this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-08-03',
            'description' => 'Tagihan jasa pemasangan panel',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]);
    }
}
