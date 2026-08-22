<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Services\PeriodCloseService;
use Modules\Finance\Support\SettleableLiabilities;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pelunasan kewajiban non-AP lewat pembayaran (payable_type 'gl_account').
 *
 * The headline case: Rp 166.638.981,43 of net June wages sits in 2-1110 Hutang
 * Gaji & Upah — which nothing had ever debited — so the biggest disbursement of
 * every month used to leave the books through a hand-keyed JV, bypassing the
 * maker-checker built for exactly it. Now it rides the same PAY vehicle:
 * draft -> submitted -> approved -> posted, with SettleableLiabilities deciding
 * WHICH accounts qualify and the posted month-end balance capping HOW MUCH.
 */
class PaymentAccountAllocationTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;

    /** Net June 2026 payroll on the demo dataset — the number this exists for. */
    private const NET_PAYROLL = 166638981.43;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->bank = $this->makeBankAccount('1-1210');
    }

    /**
     * The payroll accrual as PayrollPostingService books it: expense in, net
     * wages parked on 2-1110, dated on the LAST day of the month.
     */
    private function accruePayroll(float $net = self::NET_PAYROLL, string $date = '2026-06-30'): void
    {
        $this->postJournal(
            [['6-1100', $net, 0], ['2-1110', 0, $net]],
            $date,
            'Akrual gaji Juni 2026',
        );
    }

    private function draftPayment(string $direction, float $amount, string $date = '2026-06-25'): Payment
    {
        return $this->payments()->create([
            'direction' => $direction,
            'payment_date' => $date,
            'bank_account_id' => $this->bank->id,
            'amount' => $amount,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function accountAllocation(string $code, float $amount, ?string $remark = null): array
    {
        $row = ['payable_type' => 'gl_account', 'payable_id' => $this->accountId($code), 'amount' => $amount];

        if ($remark !== null) {
            $row['remark'] = $remark;
        }

        return [$row];
    }

    // ------------------------------------------------------- the journal shape

    public function test_paying_net_payroll_against_its_liability_account_books_dr_liability_cr_bank(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $allocations = $this->accountAllocation('2-1110', self::NET_PAYROLL);

        $posted = $this->payments()->post(
            $this->approveOutgoingPayment($payment, $allocations),
            $allocations,
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        // Dr 2-1110 Hutang Gaji & Upah / Cr 1-1210 Bank — the first thing that
        // has ever debited 2-1110 on this dataset's shape.
        $journal = $this->singleJournalFor('payment', (int) $payment->id);
        $lines = $this->linesByAccount($journal);

        $this->assertSame(self::NET_PAYROLL, $lines['2-1110']['debit']);
        $this->assertSame(self::NET_PAYROLL, $lines['1-1210']['credit']);
        // A balance-sheet event: project attribution happened at accrual time.
        $this->assertNull($lines['2-1110']['project_id']);
        $this->assertPostedAndBalanced($journal, '2026-06-25');
    }

    /**
     * The month-end ceiling window is what makes real payroll payable at all:
     * PYR/2026/06/002 pays 2026-06-25 against an accrual journal dated
     * 2026-06-30. An as-at-payment-date ceiling would refuse it.
     */
    public function test_a_payment_dated_before_the_month_end_accrual_still_fits_under_the_ceiling(): void
    {
        $this->accruePayroll(self::NET_PAYROLL, '2026-06-30');

        // Paid five days BEFORE the accrual's journal date, same month.
        $payment = $this->draftPayment('out', self::NET_PAYROLL, '2026-06-25');
        $allocations = $this->accountAllocation('2-1110', self::NET_PAYROLL);

        $posted = $this->payments()->post(
            $this->approveOutgoingPayment($payment, $allocations),
            $allocations,
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);
    }

    public function test_the_journal_line_carries_the_remark_or_a_default_naming_the_account(): void
    {
        $this->accruePayroll();
        $this->postJournal([['6-1100', 20000000, 0], ['2-1210', 0, 20000000]], '2026-06-30', 'PPh 21 Juni');

        // With a remark: the SSP reference is the line's description.
        $withRemark = $this->draftPayment('out', 20000000);
        $rows = $this->accountAllocation('2-1210', 20000000, 'SSP PPh 21 masa Juni 2026, NTPN 0123456789ABCDEF');
        $this->payments()->post($this->approveOutgoingPayment($withRemark, $rows), $rows);

        $journal = $this->singleJournalFor('payment', (int) $withRemark->id);
        $line = $journal->lines()->where('account_id', $this->accountId('2-1210'))->firstOrFail();
        $this->assertSame('SSP PPh 21 masa Juni 2026, NTPN 0123456789ABCDEF', $line->description);

        // Without one: "Pelunasan {account name}".
        $without = $this->draftPayment('out', self::NET_PAYROLL);
        $rows = $this->accountAllocation('2-1110', self::NET_PAYROLL);
        $this->payments()->post($this->approveOutgoingPayment($without, $rows), $rows);

        $journal = $this->singleJournalFor('payment', (int) $without->id);
        $line = $journal->lines()->where('account_id', $this->accountId('2-1110'))->firstOrFail();
        $this->assertSame('Pelunasan Hutang Gaji & Upah', $line->description);
    }

    public function test_submitting_stores_the_account_rows_with_their_remark_for_the_approver_to_read(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $this->payments()->submit(
            $payment,
            $this->accountAllocation('2-1110', self::NET_PAYROLL, 'Gaji bersih Juni 2026'),
            $this->financeUser(),
        );

        $stored = PaymentAllocation::query()->where('payment_id', $payment->id)->get();

        $this->assertCount(1, $stored);
        $this->assertSame(PaymentAllocation::TYPE_GL_ACCOUNT, $stored->first()->payable_type);
        $this->assertSame($this->accountId('2-1110'), (int) $stored->first()->payable_id);
        $this->assertSame(self::NET_PAYROLL, (float) $stored->first()->amount);
        $this->assertSame('Gaji bersih Juni 2026', $stored->first()->remark);
        // Nothing books until posting.
        $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
    }

    // ------------------------------------------------------- the allowlist

    /**
     * THE control exclusion. A gl_account debit to 2-1100 would move the GL
     * without settling any bill — precisely the manual-JV fraud shape the
     * sub-ledger tie-out exists to catch (the package-7 Rp 111.000.000 probe).
     */
    public function test_hutang_usaha_is_refused_because_vendors_are_paid_through_their_bills(): void
    {
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-10',
            'description' => 'Tagihan semen curah',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]));

        // 2-1100 carries a real posted balance — the allowlist, not the
        // ceiling, must be what refuses it.
        $payment = $this->draftPayment('out', 111000000);

        try {
            $this->payments()->submit($payment, $this->accountAllocation('2-1100', 111000000), $this->financeUser());
            $this->fail('Settling 2-1100 directly must be refused — it is the tie-out control exclusion.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2-1100', $e->getMessage());
            $this->assertStringContainsString('tidak termasuk kewajiban yang dapat dilunasi', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
        $this->assertSame(0.0, (float) $bill->fresh()->amount_paid);
    }

    public function test_an_expense_account_is_refused_even_though_it_is_active_and_postable(): void
    {
        $payment = $this->draftPayment('out', 1000000);

        $this->expectExceptionMessage('tidak termasuk kewajiban yang dapat dilunasi');

        $this->payments()->submit($payment, $this->accountAllocation('6-1100', 1000000), $this->financeUser());
    }

    public function test_an_inactive_account_is_refused_before_the_allowlist_is_even_consulted(): void
    {
        Account::query()->where('code', '2-1120')->update(['is_active' => false]);

        $payment = $this->draftPayment('out', 1000000);

        $this->expectExceptionMessage('sudah nonaktif');

        $this->payments()->submit($payment, $this->accountAllocation('2-1120', 1000000), $this->financeUser());
    }

    public function test_a_group_account_is_refused_as_a_group(): void
    {
        $payment = $this->draftPayment('out', 1000000);

        $this->expectExceptionMessage('akun kelompok');

        // 2-1200 Hutang Pajak is the PARENT of the tax liabilities.
        $this->payments()->submit($payment, $this->accountAllocation('2-1200', 1000000), $this->financeUser());
    }

    // ------------------------------------------------------- no mixing

    public function test_mixing_a_vendor_bill_and_a_liability_account_in_one_payment_is_refused(): void
    {
        $this->accruePayroll();
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-10',
            'description' => 'Tagihan besi beton',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]));

        $payment = $this->draftPayment('out', 277638981.43);

        try {
            $this->payments()->submit($payment, [
                ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 111000000],
                ['payable_type' => 'gl_account', 'payable_id' => $this->accountId('2-1110'), 'amount' => self::NET_PAYROLL],
            ], $this->financeUser());
            $this->fail('One disbursement mirrors one bank mutation to one beneficiary — a mixed set must be refused.');
        } catch (LogicException $e) {
            $this->assertSame(
                'Satu pembayaran melunasi tagihan vendor ATAU kewajiban non-AP, tidak keduanya — '
                .'pisahkan sesuai mutasi banknya.',
                $e->getMessage(),
            );
        }

        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
        $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
    }

    public function test_several_liability_accounts_in_one_payment_are_allowed(): void
    {
        $this->accruePayroll();
        $this->postJournal([['6-1100', 20000000, 0], ['2-1210', 0, 20000000]], '2026-06-30', 'PPh 21 Juni');

        $payment = $this->draftPayment('out', 186638981.43);
        $allocations = [
            ['payable_type' => 'gl_account', 'payable_id' => $this->accountId('2-1110'), 'amount' => self::NET_PAYROLL],
            ['payable_type' => 'gl_account', 'payable_id' => $this->accountId('2-1210'), 'amount' => 20000000],
        ];

        $posted = $this->payments()->post($this->approveOutgoingPayment($payment, $allocations), $allocations);

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertSame(self::NET_PAYROLL, $lines['2-1110']['debit']);
        $this->assertSame(20000000.0, $lines['2-1210']['debit']);
        $this->assertSame(186638981.43, $lines['1-1210']['credit']);
    }

    // ------------------------------------------------------- the ceiling

    public function test_allocating_more_than_the_posted_month_end_balance_is_refused(): void
    {
        $this->accruePayroll(); // 166.638.981,43

        // One rupiah over — the same 1-cent rounding tolerance every other
        // whole-cent guard grants applies here too.
        $payment = $this->draftPayment('out', 166638982.43);

        try {
            $this->payments()->submit($payment, $this->accountAllocation('2-1110', 166638982.43), $this->financeUser());
            $this->fail('A rupiah over the liability balance is an overpay and must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi saldo 2-1110', $e->getMessage());
            // The message names the window it consulted.
            $this->assertStringContainsString('per 2026-06-30', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
    }

    public function test_allocating_exactly_the_posted_balance_works(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $allocations = $this->accountAllocation('2-1110', self::NET_PAYROLL);

        $posted = $this->payments()->post($this->approveOutgoingPayment($payment, $allocations), $allocations);

        $this->assertSame(PaymentStatus::Posted, $posted->status);
    }

    /**
     * A liability accrued in a LATER month than the payment is economically a
     * prepayment; the window in the refusal names the balance it consulted.
     */
    public function test_paying_in_an_earlier_month_than_the_accrual_is_refused_naming_the_window(): void
    {
        $this->accruePayroll(self::NET_PAYROLL, '2026-07-31'); // July accrual

        $payment = $this->draftPayment('out', self::NET_PAYROLL, '2026-06-25'); // June payment

        $this->expectExceptionMessage('per 2026-06-30');

        $this->payments()->submit($payment, $this->accountAllocation('2-1110', self::NET_PAYROLL), $this->financeUser());
    }

    public function test_two_rows_against_the_same_account_are_summed_before_the_ceiling_check(): void
    {
        $this->accruePayroll(); // 166.638.981,43

        $payment = $this->draftPayment('out', 200000000);

        $this->expectExceptionMessage('melebihi saldo 2-1110');

        // Neither row alone exceeds the balance; together they do.
        $this->payments()->submit($payment, [
            ['payable_type' => 'gl_account', 'payable_id' => $this->accountId('2-1110'), 'amount' => 100000000],
            ['payable_type' => 'gl_account', 'payable_id' => $this->accountId('2-1110'), 'amount' => 100000000],
        ], $this->financeUser());
    }

    /**
     * Temuan #3: the ceiling used to bound the DEBIT leg by the payment
     * month's end as well, so a settlement already POSTED in a later month
     * was invisible — and a payment back-dated to the real June pay day
     * (exactly how duplicates get keyed) paid the June wages out of the bank
     * a second time with no guard firing.
     */
    public function test_a_back_dated_duplicate_after_a_later_month_settlement_is_refused(): void
    {
        $this->accruePayroll(self::NET_PAYROLL, '2026-06-30');

        // The June wages actually left the bank on 2026-07-05.
        $july = $this->draftPayment('out', self::NET_PAYROLL, '2026-07-05');
        $rows = $this->accountAllocation('2-1110', self::NET_PAYROLL);
        $this->payments()->post($this->approveOutgoingPayment($july, $rows), $rows);

        // The duplicate: its credit window still ends 2026-06-30, but July's
        // debit must count against it all the same.
        $duplicate = $this->draftPayment('out', self::NET_PAYROLL, '2026-06-25');

        try {
            $this->payments()->submit(
                $duplicate,
                $this->accountAllocation('2-1110', self::NET_PAYROLL),
                $this->financeUser(),
            );
            $this->fail('The June wages already left the bank on 2026-07-05; the back-dated duplicate must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi saldo 2-1110', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Draft, $duplicate->fresh()->status);
        // Only the first payment's journal exists — the wages left BCA once.
        $this->assertSame(1, Journal::query()->where('reference_type', 'payment')->count());
    }

    public function test_a_later_month_partial_settlement_shrinks_but_does_not_close_the_ceiling(): void
    {
        $this->accruePayroll(self::NET_PAYROLL, '2026-06-30'); // 166.638.981,43

        $july = $this->draftPayment('out', 100000000, '2026-07-05');
        $rows = $this->accountAllocation('2-1110', 100000000);
        $this->payments()->post($this->approveOutgoingPayment($july, $rows), $rows);

        // Debits shrink the ceiling, they never slam it shut: the remaining
        // 166.638.981,43 − 100.000.000 = 66.638.981,43 is still honestly
        // payable even on a June-dated payment.
        $remainder = $this->draftPayment('out', 66638981.43, '2026-06-25');
        $remainderRows = $this->accountAllocation('2-1110', 66638981.43);

        $posted = $this->payments()->post(
            $this->approveOutgoingPayment($remainder, $remainderRows),
            $remainderRows,
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);
    }

    public function test_the_account_set_must_still_sum_to_the_payment_amount(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('out', 200000000);

        $this->expectExceptionMessage('must sum to the payment amount');

        $this->payments()->submit($payment, $this->accountAllocation('2-1110', 100000000), $this->financeUser());
    }

    // ------------------------------------------------------- the race

    /**
     * The submit-time protection: a second payment against a balance that
     * other unposted payments have already claimed is refused before an
     * approver is ever asked to sign it.
     */
    public function test_a_second_payment_against_a_balance_already_claimed_by_an_unposted_one_is_refused_at_submit(): void
    {
        $this->accruePayroll();

        $first = $this->draftPayment('out', self::NET_PAYROLL);
        $this->payments()->submit($first, $this->accountAllocation('2-1110', self::NET_PAYROLL), $this->financeUser());

        $second = $this->draftPayment('out', self::NET_PAYROLL);

        try {
            $this->payments()->submit($second, $this->accountAllocation('2-1110', self::NET_PAYROLL), $this->financeUser());
            $this->fail('The balance is fully claimed by the first submitted payment.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi saldo 2-1110', $e->getMessage());
            $this->assertStringContainsString('pembayaran lain yang belum diposting', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Draft, $second->fresh()->status);
    }

    /**
     * The post-time protection — the one that matters when two requests race.
     * lockForUpdate() is a no-op on SQLite, so post() re-derives the ceiling
     * from posted journals inside its own transaction; the first payment's
     * debit shrinks the GL and the second is refused there. The second
     * payment's approved state is written directly because two sequential
     * service calls cannot reproduce what two CONCURRENT submits both reading
     * pending=0 produce in production.
     */
    public function test_when_two_approved_payments_chase_one_balance_the_second_is_refused_at_post_and_can_be_rejected(): void
    {
        $this->accruePayroll();

        $first = $this->draftPayment('out', self::NET_PAYROLL);
        $firstRows = $this->accountAllocation('2-1110', self::NET_PAYROLL);
        $this->approveOutgoingPayment($first, $firstRows);

        // The concurrent twin: same balance, approved state written directly.
        $second = $this->draftPayment('out', self::NET_PAYROLL);
        $second->forceFill(['status' => PaymentStatus::Approved])->save();
        $second->allocations()->create([
            'payable_type' => PaymentAllocation::TYPE_GL_ACCOUNT,
            'payable_id' => $this->accountId('2-1110'),
            'amount' => self::NET_PAYROLL,
        ]);

        // First posts; its debit empties 2-1110.
        $this->payments()->post($first->fresh(), $firstRows);

        try {
            $this->payments()->post($second->fresh(), $this->accountAllocation('2-1110', self::NET_PAYROLL));
            $this->fail('The GL re-read inside post() is the race protection; the loser must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi saldo 2-1110', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Approved, $second->fresh()->status);
        // Only the first payment's journal exists.
        $this->assertSame(1, Journal::query()->where('reference_type', 'payment')->count());

        // reject() accepting Approved is the exit for the wedged one.
        $this->payments()->reject($second->fresh(), $this->financeApprover(), 'Saldo 2-1110 sudah dilunasi pembayaran pertama.');
        $this->assertSame(PaymentStatus::Rejected, $second->fresh()->status);
    }

    // ------------------------------------------------------- lifecycle guards

    public function test_the_clerk_who_submitted_an_account_payment_cannot_approve_it(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $this->payments()->submit($payment, $this->accountAllocation('2-1110', self::NET_PAYROLL), $this->financeUser());

        $this->expectException(SelfApprovalException::class);

        $this->payments()->approve($payment->fresh(), $this->financeUser());
    }

    public function test_posting_a_different_account_set_from_the_one_approved_is_refused(): void
    {
        $this->accruePayroll();
        $this->postJournal([['6-1200', self::NET_PAYROLL, 0], ['2-1120', 0, self::NET_PAYROLL]], '2026-06-30', 'BPJS Juni');

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $this->approveOutgoingPayment($payment, $this->accountAllocation('2-1110', self::NET_PAYROLL));

        // Same amount, different liability — the substitution the signature
        // comparison exists to catch, gl_account rows included.
        $this->expectExceptionMessage('berbeda dari yang disetujui');

        try {
            $this->payments()->post($payment->fresh(), $this->accountAllocation('2-1120', self::NET_PAYROLL));
        } finally {
            $this->assertSame(PaymentStatus::Approved, $payment->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
        }
    }

    /**
     * The remark is a memo, not money: it sits outside the signature, so a
     * corrected NTPN string between approval and posting does not wedge the
     * payment — the row and the journal line carry the corrected text.
     */
    public function test_an_edited_remark_does_not_block_posting_and_the_corrected_text_is_what_books(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $this->approveOutgoingPayment(
            $payment,
            $this->accountAllocation('2-1110', self::NET_PAYROLL, 'NTPN salah ketik'),
        );

        $posted = $this->payments()->post(
            $payment->fresh(),
            $this->accountAllocation('2-1110', self::NET_PAYROLL, 'NTPN 0123456789ABCDEF'),
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertSame('NTPN 0123456789ABCDEF', $posted->allocations->first()->remark);

        $line = $this->singleJournalFor('payment', (int) $payment->id)
            ->lines()->where('account_id', $this->accountId('2-1110'))->firstOrFail();
        $this->assertSame('NTPN 0123456789ABCDEF', $line->description);
    }

    public function test_a_rejected_account_payment_keeps_its_allocations_for_the_clerk_to_correct(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $this->payments()->submit(
            $payment,
            $this->accountAllocation('2-1110', self::NET_PAYROLL, 'Gaji bersih Juni 2026'),
            $this->financeUser(),
        );

        $this->payments()->reject($payment->fresh(), $this->financeApprover(), 'Tanggal bayar salah.');

        $kept = PaymentAllocation::query()->where('payment_id', $payment->id)->get();
        $this->assertCount(1, $kept);
        $this->assertSame(PaymentAllocation::TYPE_GL_ACCOUNT, $kept->first()->payable_type);
        $this->assertSame('Gaji bersih Juni 2026', $kept->first()->remark);
    }

    // ------------------------------------------------------- receipts

    public function test_a_receipt_refuses_liability_account_rows_entirely(): void
    {
        $this->accruePayroll();

        $payment = $this->draftPayment('in', self::NET_PAYROLL);

        $this->expectExceptionMessage('A payment in can only settle ar_invoice documents.');

        try {
            $this->payments()->post($payment, $this->accountAllocation('2-1110', self::NET_PAYROLL));
        } finally {
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
            $this->assertSame(0, Journal::query()->where('reference_type', 'payment')->count());
        }
    }

    // ------------------------------------------------------- the tie-out stays tied

    /**
     * Safe by construction — subledgerOutstanding() filters payable_type to
     * ar_invoice/ap_bill and 2-1100 is excluded from the allowlist — but this
     * checklist item is the one place the package-7 manual-JV probe was
     * visible, so the construction gets a regression test anyway.
     */
    public function test_paying_payroll_through_an_account_allocation_leaves_the_ap_ar_tie_out_tied(): void
    {
        // A real bill puts Rp 111.000.000 on BOTH sides of the AP tie-out.
        $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'bill_date' => '2026-06-10',
            'description' => 'Tagihan baja ringan',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]));
        $this->accruePayroll();

        $glBefore = [
            '2-1100' => $this->postedBalance('2-1100'),
            '1-1300' => $this->postedBalance('1-1300'),
        ];

        $this->assertItem(2026, 6, 'subledger_tied', PeriodCloseService::WARN, PeriodCloseService::OK);

        $payment = $this->draftPayment('out', self::NET_PAYROLL);
        $allocations = $this->accountAllocation('2-1110', self::NET_PAYROLL);
        $this->payments()->post($this->approveOutgoingPayment($payment, $allocations), $allocations);

        // Still tied, and neither control account moved by a rupiah.
        $this->assertItem(2026, 6, 'subledger_tied', PeriodCloseService::WARN, PeriodCloseService::OK);
        $this->assertSame($glBefore['2-1100'], $this->postedBalance('2-1100'));
        $this->assertSame($glBefore['1-1300'], $this->postedBalance('1-1300'));
    }

    /** Posted debit-minus-credit of one account, the controlBalance() shape. */
    private function postedBalance(string $code): float
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return round(
            (float) $account->journalLines()
                ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
                ->where('fin_journals.status', 'posted')
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
                ->value('balance'),
            2,
        );
    }

    // ------------------------------------------------------- the registry itself

    public function test_the_allowlist_names_its_eight_liabilities_and_excludes_the_engine_owned_ones(): void
    {
        $this->assertSame(
            ['2-1110', '2-1120', '2-1210', '2-1220', '2-1230', '2-1240', '2-1300', '2-1600'],
            SettleableLiabilities::codes(),
        );

        // The exclusions are the control surface: each of these is owned by an
        // engine that settles it its own way — 2-1100 most of all.
        foreach (['2-1100', '2-1150', '2-1400', '2-1410', '2-1500', '2-1700', '2-2100', '1-1300'] as $code) {
            $this->assertFalse(SettleableLiabilities::contains($code), "{$code} must never be settleable.");
        }
    }
}
