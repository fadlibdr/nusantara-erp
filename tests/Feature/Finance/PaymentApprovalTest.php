<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Core\Models\Notification;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Procurement\Models\Vendor;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pemisahan tugas pada uang keluar: a disbursement now walks
 * draft -> submitted -> approved -> posted, and its allocations are attached at
 * SUBMIT so the approver can see which vendor bills the money settles.
 *
 * A receipt (RCV) keeps draft -> posted. Money arriving is already corroborated
 * by a document the company does not control — the bank statement the
 * reconciliation bridge matches it against — while money leaving has no
 * corroboration at all until after it has left.
 */
class PaymentApprovalTest extends ErpTestCase
{
    use FinanceFixtures;

    private Vendor $vendor;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->vendor = $this->makeVendor();
        $this->bank = $this->makeBankAccount('1-1210');
    }

    /** DPP 100.000.000 + PPN 11.000.000 = payable 111.000.000. */
    private function approvedBill(float $dpp = 100000000, float $ppn = 11000000): ApBill
    {
        return $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan vendor',
            'dpp' => $dpp,
            'ppn_amount' => $ppn,
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

    /** @return array<int, array<string, mixed>> */
    private function allocation(ApBill $bill, float $amount): array
    {
        return [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => $amount]];
    }

    // ------------------------------------------------------- the gate itself

    public function test_a_draft_disbursement_cannot_be_posted(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);

        try {
            $this->payments()->post($payment, $this->allocation($bill, 111000000));
            $this->fail('A draft disbursement must not reach the ledger.');
        } catch (LogicException $e) {
            $this->assertSame(
                "Pembayaran {$payment->code} belum disetujui, jadi belum boleh diposting.",
                $e->getMessage(),
            );
        }

        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
        $this->assertSame(0.0, (float) $bill->fresh()->amount_paid);
        $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
    }

    public function test_a_submitted_disbursement_cannot_be_posted_before_someone_approves_it(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $allocations = $this->allocation($bill, 111000000);

        $this->payments()->submit($payment, $allocations, $this->financeUser());

        $this->expectExceptionMessage('belum disetujui, jadi belum boleh diposting');

        try {
            $this->payments()->post($payment->fresh(), $allocations);
        } finally {
            $this->assertSame(PaymentStatus::Submitted, $payment->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
        }
    }

    // ------------------------------------------------------- submit

    public function test_submitting_stores_the_allocations_so_the_approver_can_see_what_they_are_approving(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);

        $this->payments()->submit($payment, $this->allocation($bill, 111000000), $this->financeUser());

        $stored = PaymentAllocation::query()->where('payment_id', $payment->id)->get();

        $this->assertCount(1, $stored);
        $this->assertSame($bill->id, (int) $stored->first()->payable_id);
        $this->assertSame(111000000.0, (float) $stored->first()->amount);
        $this->assertSame(PaymentStatus::Submitted, $payment->fresh()->status);

        // The bill itself is untouched — nothing is settled until posting.
        $this->assertSame(0.0, (float) $bill->fresh()->amount_paid);
    }

    public function test_submitting_records_who_asked_for_the_money(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);

        $this->payments()->submit($payment, $this->allocation($bill, 111000000), $this->financeUser());

        $approval = $payment->approvals()->orderBy('id')->first();

        $this->assertSame('submitted', $approval->action);
        $this->assertSame($this->financeUser()->id, (int) $approval->user_id);
    }

    public function test_submitting_a_set_that_overpays_the_bill_is_refused(): void
    {
        $bill = $this->approvedBill(); // payable 111.000.000
        $payment = $this->draftPayment('out', 111000001);

        $this->expectExceptionMessage('exceeds the outstanding');

        try {
            $this->payments()->submit($payment, $this->allocation($bill, 111000001), $this->financeUser());
        } finally {
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
            $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        }
    }

    public function test_submitting_a_set_that_does_not_sum_to_the_amount_is_refused(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);

        $this->expectExceptionMessage('must sum to the payment amount');

        try {
            $this->payments()->submit($payment, $this->allocation($bill, 50000000), $this->financeUser());
        } finally {
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
            $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        }
    }

    /**
     * Two lines of Rp 60 juta against one Rp 111 juta bill overpay it together
     * even though neither does alone.
     */
    public function test_two_lines_against_the_same_bill_are_summed_before_the_overpay_check(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 120000000);

        $this->expectExceptionMessage('exceeds the outstanding');

        $this->payments()->submit($payment, [
            ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 60000000],
            ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 60000000],
        ], $this->financeUser());
    }

    public function test_a_submitted_disbursement_cannot_be_edited_or_deleted(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $this->payments()->submit($payment, $this->allocation($bill, 111000000), $this->financeUser());

        foreach ([
            fn () => $this->payments()->update($payment->fresh(), ['amount' => 1]),
            fn () => $this->payments()->delete($payment->fresh()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A submitted payment must be frozen, or the approval means nothing.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('is already submitted', $e->getMessage());
            }
        }

        $fresh = $payment->fresh();
        $this->assertSame(111000000.0, (float) $fresh->amount);
        $this->assertNull($fresh->deleted_at);
    }

    // ------------------------------------------------------- approve

    public function test_the_clerk_who_submitted_the_disbursement_cannot_approve_it(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $this->payments()->submit($payment, $this->allocation($bill, 111000000), $this->financeUser());

        try {
            $this->payments()->approve($payment->fresh(), $this->financeUser());
            $this->fail('The submitter must not be able to approve their own disbursement.');
        } catch (SelfApprovalException $e) {
            $this->assertStringContainsString('Pembayaran keluar', $e->getMessage());
            $this->assertStringContainsString($payment->code, $e->getMessage());
            $this->assertStringContainsString('Dewi Lestari', $e->getMessage());
            $this->assertStringContainsString('fin.approve', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Submitted, $payment->fresh()->status);
    }

    public function test_a_second_person_can_approve_it_and_then_it_posts_exactly_as_before(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $allocations = $this->allocation($bill, 111000000);

        $this->payments()->submit($payment, $allocations, $this->financeUser());
        $this->payments()->approve($payment->fresh(), $this->financeApprover(), 'Sesuai kontrak dan berita acara.');

        $this->assertSame(PaymentStatus::Approved, $payment->fresh()->status);

        $posted = $this->payments()->post($payment->fresh(), $allocations);

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        // Dr 2-1100 Hutang Usaha / Cr 1-1210 Bank — unchanged by the new stage.
        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertSame(111000000.0, $lines['2-1100']['debit']);
        $this->assertSame(111000000.0, $lines['1-1210']['credit']);
        $this->assertTrue($bill->fresh()->isFullyPaid());
    }

    public function test_the_trail_carries_both_the_submitter_and_the_approver(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);

        $this->payments()->submit($payment, $this->allocation($bill, 111000000), $this->financeUser());
        $this->payments()->approve($payment->fresh(), $this->financeApprover(), 'Setuju bayar.');

        $trail = $payment->approvals()->orderBy('id')->get();

        $this->assertSame(['submitted', 'approved'], $trail->pluck('action')->all());
        $this->assertSame($this->financeUser()->id, (int) $trail[0]->user_id);
        $this->assertSame($this->financeApprover()->id, (int) $trail[1]->user_id);
        $this->assertSame('Setuju bayar.', $trail[1]->note);
    }

    public function test_a_draft_disbursement_cannot_be_approved_without_being_submitted(): void
    {
        $payment = $this->draftPayment('out', 111000000);

        $this->expectExceptionMessage('belum diajukan');

        $this->payments()->approve($payment, $this->financeApprover());
    }

    // ------------------------------------------------------- the set that was approved

    public function test_posting_a_different_allocation_set_from_the_one_approved_is_refused(): void
    {
        $approvedBill = $this->approvedBill();
        $otherBill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);

        $this->approveOutgoingPayment($payment, $this->allocation($approvedBill, 111000000));

        try {
            // Same amount, different vendor bill — the exact substitution the
            // set comparison exists to catch.
            $this->payments()->post($payment->fresh(), $this->allocation($otherBill, 111000000));
            $this->fail('Posting a set the approver never saw must be refused.');
        } catch (LogicException $e) {
            $this->assertSame(
                "Alokasi pembayaran {$payment->code} berbeda dari yang disetujui. "
                    .'Ajukan ulang bila alokasinya berubah.',
                $e->getMessage(),
            );
        }

        $this->assertSame(PaymentStatus::Approved, $payment->fresh()->status);
        $this->assertSame(0.0, (float) $otherBill->fresh()->amount_paid);
        $this->assertSame(0.0, (float) $approvedBill->fresh()->amount_paid);
        $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
    }

    public function test_the_approved_set_may_be_re_sent_in_any_order(): void
    {
        $first = $this->approvedBill(50000000, 0);
        $second = $this->approvedBill(61000000, 0);
        $payment = $this->draftPayment('out', 111000000);

        $submitted = [
            ['payable_type' => 'ap_bill', 'payable_id' => $first->id, 'amount' => 50000000],
            ['payable_type' => 'ap_bill', 'payable_id' => $second->id, 'amount' => 61000000],
        ];

        $this->approveOutgoingPayment($payment, $submitted);

        // The SPA re-sends what it read back; the row order is not a promise.
        $posted = $this->payments()->post($payment->fresh(), array_reverse($submitted));

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertTrue($first->fresh()->isFullyPaid());
        $this->assertTrue($second->fresh()->isFullyPaid());
    }

    // ------------------------------------------------------- reject

    public function test_a_rejected_disbursement_goes_back_to_the_clerk_editable_and_re_submittable(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $this->payments()->submit($payment, $this->allocation($bill, 111000000), $this->financeUser());

        $this->payments()->reject($payment->fresh(), $this->financeApprover(), 'Rekening tujuan tidak cocok.');

        $this->assertSame(PaymentStatus::Rejected, $payment->fresh()->status);
        // The allocations are kept so the clerk corrects rather than retypes.
        $this->assertSame(1, PaymentAllocation::query()->where('payment_id', $payment->id)->count());

        // Editable again…
        $this->payments()->update($payment->fresh(), ['reference' => 'TRF-002']);
        $this->assertSame('TRF-002', $payment->fresh()->reference);

        // …and re-submittable, replacing the previous set rather than adding to it.
        $this->payments()->submit($payment->fresh(), $this->allocation($bill, 111000000), $this->financeUser());

        $this->assertSame(PaymentStatus::Submitted, $payment->fresh()->status);
        $this->assertSame(1, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
    }

    /**
     * The exit for an approved-but-unpostable disbursement. An approved
     * payment whose bill gets cancelled (vendor double-billed) can never be
     * posted, and update/delete/submit all refuse `approved` — so before
     * reject() accepted Approved, the document was wedged forever and, through
     * DanglingDocuments, permanently blocked closing its fiscal month.
     */
    public function test_an_approved_disbursement_whose_bill_was_cancelled_can_be_rejected_and_re_submitted(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $this->approveOutgoingPayment($payment, $this->allocation($bill, 111000000));

        // Nothing is settled yet (amount_paid 0), so the bill may be cancelled
        // out from under the approved payment.
        $this->apBills()->cancel($bill->fresh(), $this->financeApprover(), 'Vendor menagih dua kali.');

        try {
            $this->payments()->post($payment->fresh(), $this->allocation($bill, 111000000));
            $this->fail('Posting against a cancelled bill must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('is not approved', $e->getMessage());
        }

        // The approver sends it back — no money moves, nothing is lost.
        $this->payments()->reject(
            $payment->fresh(),
            $this->financeApprover(),
            'Tagihan sumber dibatalkan; alihkan ke tagihan yang benar.',
        );
        $this->assertSame(PaymentStatus::Rejected, $payment->fresh()->status);

        // …and the clerk corrects the SAME document against the right bill.
        $replacement = $this->approvedBill();
        $this->payments()->submit($payment->fresh(), $this->allocation($replacement, 111000000), $this->financeUser());
        $this->assertSame(PaymentStatus::Submitted, $payment->fresh()->status);

        // The trail kept every act, the approval and its reversal included.
        $this->assertSame(
            ['submitted', 'approved', 'rejected', 'submitted'],
            $payment->approvals()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_approve_still_refuses_anything_that_is_not_submitted(): void
    {
        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $this->approveOutgoingPayment($payment, $this->allocation($bill, 111000000));

        // Approving twice would stamp a second authority on one act.
        try {
            $this->payments()->approve($payment->fresh(), $this->financeApprover());
            $this->fail('An approved payment must not be approvable again.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum diajukan', $e->getMessage());
            $this->assertStringContainsString('disetujui', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Approved, $payment->fresh()->status);

        // And reject() widened only as far as Approved: money that has LEFT
        // cannot be un-decided by a status flip.
        $posted = $this->payments()->post($payment->fresh(), $this->allocation($bill, 111000000));

        try {
            $this->payments()->reject($posted, $this->financeApprover(), 'Terlambat, uang sudah keluar.');
            $this->fail('A posted payment must not be rejectable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum bisa ditolak', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Posted, $payment->fresh()->status);
    }

    // ------------------------------------------------------- receipts are untouched

    public function test_a_receipt_still_posts_straight_from_draft(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer);

        /** @var ArInvoice $invoice */
        $invoice = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-03-10',
            'description' => 'Termin 1',
            'dpp' => 100000000,
            'ppn_rate' => 11.0,
        ]));

        $payment = $this->draftPayment('in', 111000000);

        $posted = $this->payments()->post($payment, [
            ['payable_type' => 'ar_invoice', 'payable_id' => $invoice->id, 'amount' => 111000000],
        ]);

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertSame(DocumentStatus::Approved, $invoice->fresh()->status);
        $this->assertTrue($invoice->fresh()->isFullyPaid());
        // No approval stage means no approval trail.
        $this->assertSame(0, $payment->approvals()->count());
    }

    public function test_a_receipt_refuses_submit_approve_and_reject_in_indonesian(): void
    {
        $payment = $this->draftPayment('in', 111000000);
        $expected = "Penerimaan {$payment->code} tidak melalui tahap persetujuan; langsung diposting.";

        foreach ([
            fn () => $this->payments()->submit($payment, [], $this->financeUser()),
            fn () => $this->payments()->approve($payment, $this->financeApprover()),
            fn () => $this->payments()->reject($payment, $this->financeApprover(), 'Tidak jadi.'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A receipt has no approval stage.');
            } catch (LogicException $e) {
                $this->assertSame($expected, $e->getMessage());
            }
        }

        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
    }

    // ------------------------------------------------------- notifications

    /**
     * A submitted disbursement has to reach the people who can act on it.
     * Payment does not use the Approvable trait, so it would have been easy for
     * it to be the one document type that silently notifies nobody.
     */
    public function test_submitting_a_disbursement_notifies_the_people_who_can_approve_it(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('finance-manager', 'web');
        $role->givePermissionTo('fin.approve');

        /** @var User $manager */
        $manager = User::query()->create([
            'name' => 'Ratna Kusumawardani',
            'email' => 'ratna@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $manager->assignRole($role);

        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);

        $this->payments()->submit($payment, $this->allocation($bill, 111000000), $this->financeUser());

        // Scoped to the payment: the bill above is also a fin.approve document
        // and lands in the same inbox.
        $notice = Notification::query()
            ->where('user_id', $manager->id)
            ->where('document_type', Payment::class)
            ->where('document_id', $payment->id)
            ->get();

        $this->assertCount(1, $notice);
        $this->assertStringContainsString('Pembayaran keluar', (string) $notice->first()->title);
        $this->assertStringContainsString($payment->code, (string) $notice->first()->title);
        $this->assertSame("#/d/finance/payments/{$payment->id}", $notice->first()->link);
    }

    // ------------------------------------------------------- the escape hatch

    public function test_turning_segregation_off_lets_one_person_walk_a_disbursement_alone(): void
    {
        $this->setSetting('approvals.segregation_of_duties', false);

        $bill = $this->approvedBill();
        $payment = $this->draftPayment('out', 111000000);
        $allocations = $this->allocation($bill, 111000000);

        $this->payments()->submit($payment, $allocations, $this->financeUser());
        $this->payments()->approve($payment->fresh(), $this->financeUser());
        $posted = $this->payments()->post($payment->fresh(), $allocations);

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        // And the trail still says, permanently, that it was the same person.
        $this->assertSame(
            [$this->financeUser()->id, $this->financeUser()->id],
            array_map('intval', $payment->approvals()->orderBy('id')->pluck('user_id')->all()),
        );
    }
}
