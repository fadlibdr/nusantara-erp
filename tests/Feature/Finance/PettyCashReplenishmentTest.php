<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Http\Resources\PaymentResource;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Payment;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Models\PettyCashVoucher;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The second pair of eyes of kas kecil: replenishment as an ordinary PAY.
 *
 * The imprest identity IS the amount guard — a top-up moves exactly
 * float − drawer balance, checked when the clerk types it, frozen with the
 * stamped voucher pile the approver reads, and re-verified inside post()'s
 * transaction so a drawer that moved between approval and post wedges the
 * payment into "ajukan ulang" instead of moving the wrong amount of money.
 */
class PettyCashReplenishmentTest extends ErpTestCase
{
    use FinanceFixtures;
    use PeriodFixtures;
    use PettyCashFixtures;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->bank = $this->makeBankAccount('1-1210');
    }

    /** A drawer that has spent Rp 1.200.000 of its Rp 5.000.000 float. */
    private function spentFund(): PettyCashFund
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $voucher = $this->makeVoucher($fund, ['amount' => 1200000, 'description' => 'Bon minggu ke-2 Juni']);
        $this->vouchers()->post($voucher, $this->custodianUser());

        return $fund;
    }

    private function replenishmentPayment(PettyCashFund $fund, float $amount): Payment
    {
        return $this->payments()->create([
            'direction' => 'out',
            'payment_date' => '2026-06-20',
            'bank_account_id' => $this->bank->id,
            'amount' => $amount,
            'petty_cash_fund_id' => $fund->id,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fundAllocation(PettyCashFund $fund, float $amount): array
    {
        return [['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => $amount]];
    }

    // ------------------------------------------------------ the imprest amount

    public function test_replenishment_moves_exactly_float_minus_balance_and_restores_the_float(): void
    {
        $fund = $this->spentFund();

        // 5.000.000 float − 3.800.000 in the drawer = 1.200.000, the bons.
        $this->assertSame(1200000.0, $this->funds()->replenishmentDue($fund));

        $payment = $this->replenishmentPayment($fund, 1200000);
        $allocations = $this->fundAllocation($fund, 1200000);

        $posted = $this->payments()->post(
            $this->approveOutgoingPayment($payment, $allocations),
            $allocations,
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        // Dr fund leaf / Cr 1-1210 Bank, and the drawer is whole again.
        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $payment->id));
        $this->assertSame(1200000.0, $lines[$fund->coaAccount->code]['debit']);
        $this->assertSame(1200000.0, $lines['1-1210']['credit']);
        $this->assertSame(5000000.0, $this->funds()->balance($fund));
    }

    public function test_a_replenishment_over_or_under_the_imprest_amount_is_refused_at_submit(): void
    {
        $fund = $this->spentFund();

        // Rp 1.500.000 against a Rp 1.200.000 gap: refused when TYPED, before
        // any approver signs it.
        $payment = $this->replenishmentPayment($fund, 1500000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/float dikurangi saldo laci/');

        $this->payments()->submit($payment, $this->fundAllocation($fund, 1500000), $this->financeUser());
    }

    public function test_mixing_a_fund_allocation_with_a_vendor_bill_is_refused(): void
    {
        $fund = $this->spentFund();
        $vendor = $this->makeVendor();
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'vendor_invoice_no' => 'SDU-2026-0601',
            'bill_date' => '2026-06-05',
            'description' => 'Semen 400 sak',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]));

        $payment = $this->replenishmentPayment($fund, 1200000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tepat SATU baris petty_cash_fund/');

        $this->payments()->submit($payment, [
            ['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 700000],
            ['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 500000],
        ], $this->financeUser());
    }

    // ------------------------------------------------ the unreplenished figure

    /**
     * Temuan #8: the stamp falls at SUBMIT, before any money moves, and the
     * "bon belum diganti" figure used to key on the stamp — it dropped to
     * zero for the whole submitted→approved window and snapped back on
     * reject, oscillating purely on approval state. "Belum diganti" means
     * not yet REIMBURSED: the figure holds until the stamping payment POSTS.
     */
    public function test_the_unreplenished_figure_holds_until_the_replenishment_posts(): void
    {
        $fund = $this->spentFund(); // Rp 1.200.000 of posted bons

        $payment = $this->replenishmentPayment($fund, 1200000);
        $this->payments()->submit($payment, $this->fundAllocation($fund, 1200000), $this->financeUser());

        // Stamped, but not a rupiah has moved — still awaiting reimbursement.
        $this->assertSame(1200000.0, $this->funds()->unreplenishedVoucherTotal($fund));

        $this->payments()->approve($payment->refresh(), $this->financeApprover());
        $this->assertSame(1200000.0, $this->funds()->unreplenishedVoucherTotal($fund));

        // Only the POSTED bank transfer replaces the drawer's money.
        $this->payments()->post($payment->refresh(), $this->fundAllocation($fund, 1200000));
        $this->assertSame(0.0, $this->funds()->unreplenishedVoucherTotal($fund));
    }

    // ---------------------------------------------- settled kasbon in the set

    /**
     * Temuan #7: settlement spend lives in fin_kasbon_lines, never vouchers,
     * so the old float − bon − kasbon identity rang a permanent false
     * "Identitas imprest tidak menutup" after every settled kasbon, and the
     * replenishment approver saw Rp 200.000 of paper backing a Rp 1.000.000
     * transfer. The identity and the review set both carry the spend now.
     */
    public function test_a_settled_kasbon_keeps_the_imprest_identity_closed_and_joins_the_review_set(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        // Kasbon Rp 1.000.000, dipertanggungjawabkan Rp 800.000 — kembalian
        // Rp 200.000 masuk laci.
        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());
        $this->kasbons()->settle($kasbon, [
            ['category' => 'material', 'description' => 'Semen 10 sak', 'amount' => 800000],
        ], '2026-06-12', $this->custodianUser());

        // Identitas menutup: 5.000.000 − 0 bon − 0 kasbon berjalan −
        // 800.000 belanja kasbon = 4.200.000 = saldo GL.
        $this->assertSame(800000.0, $this->funds()->settledKasbonSpendTotal($fund));
        $this->assertSame(4200000.0, $this->funds()->balance($fund));
        $this->assertSame(4200000.0, $this->funds()->imprestExpectation($fund));

        // Plus a Rp 200.000 bon: replenishment due 800.000 + 200.000.
        $voucher = $this->makeVoucher($fund, ['voucher_date' => '2026-06-14', 'amount' => 200000, 'description' => 'Ojek dokumen tender']);
        $this->vouchers()->post($voucher, $this->custodianUser());

        $this->assertSame(4000000.0, $this->funds()->imprestExpectation($fund));
        $this->assertSame(1000000.0, $this->funds()->replenishmentDue($fund));

        $payment = $this->replenishmentPayment($fund, 1000000);
        $this->payments()->submit($payment, $this->fundAllocation($fund, 1000000), $this->financeUser());

        // The frozen review set explains the WHOLE transfer: Rp 200.000 of
        // bons + Rp 800.000 of settled-kasbon receipts = Rp 1.000.000.
        $this->assertSame($payment->id, (int) $kasbon->refresh()->replenishment_payment_id);

        $resource = (new PaymentResource(Payment::query()->findOrFail($payment->id)))->resolve();
        $this->assertSame(200000.0, round((float) collect($resource['covered_vouchers'])->sum('amount'), 2));
        $this->assertSame(800000.0, round((float) collect($resource['covered_kasbons'])->sum('spend'), 2));

        // While the transfer waits, the figures hold (the temuan-#8 rule
        // applies to the kasbon term too) and the identity stays closed.
        $this->assertSame(800000.0, $this->funds()->settledKasbonSpendTotal($fund));
        $this->assertSame(4000000.0, $this->funds()->imprestExpectation($fund));

        $this->payments()->approve($payment->refresh(), $this->financeApprover());
        $this->payments()->post($payment->refresh(), $this->fundAllocation($fund, 1000000));

        // Posted: the drawer is whole and every term of the identity clears.
        $this->assertSame(5000000.0, $this->funds()->balance($fund));
        $this->assertSame(0.0, $this->funds()->unreplenishedVoucherTotal($fund));
        $this->assertSame(0.0, $this->funds()->settledKasbonSpendTotal($fund));
        $this->assertSame(5000000.0, $this->funds()->imprestExpectation($fund));
    }

    public function test_reject_releases_the_settled_kasbon_stamp_as_well(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());
        $this->kasbons()->settle($kasbon, [
            ['category' => 'material', 'description' => 'Pasir 1 rit', 'amount' => 800000],
        ], '2026-06-12', $this->custodianUser());

        $payment = $this->replenishmentPayment($fund, 800000);
        $this->payments()->submit($payment, $this->fundAllocation($fund, 800000), $this->financeUser());
        $this->assertSame($payment->id, (int) $kasbon->refresh()->replenishment_payment_id);

        // Rejected: the kasbon rejoins the unreimbursed pile, exactly like a
        // bon, so the corrected top-up freezes a complete set.
        $this->payments()->reject($payment->refresh(), $this->financeApprover(), 'Scan bukti pertanggungjawaban belum lengkap');
        $this->assertNull($kasbon->refresh()->replenishment_payment_id);
        $this->assertSame(800000.0, $this->funds()->settledKasbonSpendTotal($fund));
    }

    // ------------------------------------------------------- the frozen pile

    public function test_submit_freezes_the_voucher_pile_and_reject_releases_it(): void
    {
        $fund = $this->spentFund();
        $voucher = PettyCashVoucher::query()->where('fund_id', $fund->id)->firstOrFail();

        $payment = $this->replenishmentPayment($fund, 1200000);
        $this->payments()->submit($payment, $this->fundAllocation($fund, 1200000), $this->financeUser());

        // The bon is stamped: this is the pile the approver reviews.
        $this->assertSame($payment->id, (int) $voucher->refresh()->replenishment_payment_id);

        $this->payments()->reject($payment->refresh(), $this->financeApprover(), 'Struk bon belum lengkap, lampirkan dulu');

        // Rejected: the pile dissolves so the corrected top-up can freeze its own.
        $this->assertNull($voucher->refresh()->replenishment_payment_id);
    }

    public function test_the_submitter_cannot_approve_their_own_replenishment(): void
    {
        $fund = $this->spentFund();

        $payment = $this->replenishmentPayment($fund, 1200000);
        $this->payments()->submit($payment, $this->fundAllocation($fund, 1200000), $this->financeUser());

        $this->expectException(SelfApprovalException::class);

        $this->payments()->approve($payment->refresh(), $this->financeUser());
    }

    public function test_a_drawer_that_moved_after_approval_wedges_the_post_and_reject_frees_it(): void
    {
        $fund = $this->spentFund();

        $payment = $this->replenishmentPayment($fund, 1200000);
        $allocations = $this->fundAllocation($fund, 1200000);
        $this->approveOutgoingPayment($payment, $allocations);

        // Between approval and post the custodian keys another Rp 300.000 bon:
        // the gap is now 1.500.000 and the approved 1.200.000 is wrong money.
        $late = $this->makeVoucher($fund, ['amount' => 300000, 'description' => 'Bon susulan ojek dokumen']);
        $this->vouchers()->post($late, $this->custodianUser());

        try {
            $this->payments()->post($payment->refresh(), $allocations);
            $this->fail('Expected the imprest re-check to refuse.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('ajukan ulang', $e->getMessage());
        }

        // reject() accepting Approved is the wedged payment's exit; the pile
        // unfreezes so the NEXT submission stamps all the bons, late one included.
        $rejected = $this->payments()->reject($payment->refresh(), $this->financeApprover(), 'Saldo laci berubah — ajukan ulang jumlah baru');
        $this->assertSame(PaymentStatus::Rejected, $rejected->status);
        $this->assertSame(0, PettyCashVoucher::query()->whereNotNull('replenishment_payment_id')->count());
    }

    // ------------------------------------------------------ the drawer return

    public function test_a_drawer_return_posts_from_draft_and_refuses_more_than_the_drawer_holds(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        // Shrinking the float: Rp 2.000.000 goes back to the bank as an RCV.
        $receipt = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-06-25',
            'bank_account_id' => $this->bank->id,
            'amount' => 2000000,
            'petty_cash_fund_id' => $fund->id,
        ]);

        $posted = $this->payments()->post($receipt, $this->fundAllocation($fund, 2000000));

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        // Dr bank / Cr fund — mirror of the top-up.
        $lines = $this->linesByAccount($this->singleJournalFor('payment', (int) $receipt->id));
        $this->assertSame(2000000.0, $lines['1-1210']['debit']);
        $this->assertSame(2000000.0, $lines[$fund->coaAccount->code]['credit']);
        $this->assertSame(3000000.0, $this->funds()->balance($fund));

        // And the drawer cannot return cash it does not hold.
        $tooBig = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-06-26',
            'bank_account_id' => $this->bank->id,
            'amount' => 3500000,
            'petty_cash_fund_id' => $fund->id,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi saldo laci/');

        $this->payments()->post($tooBig, $this->fundAllocation($fund, 3500000));
    }

    /**
     * Temuan #4: the drawer-return guard compared each ROW against a balance
     * re-read from the POSTED GL — this journal has not posted yet, so two
     * Rp 3.000.000 rows against a Rp 3.000.000 drawer each saw a full drawer,
     * passed, and posted Cr fund 6.000.000: drawer at −3.000.000 while the
     * bank was debited cash the drawer never held. The rows are summed now.
     */
    public function test_a_drawer_return_split_into_rows_cannot_exceed_the_drawer_in_total(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 3000000, '2026-06-01');

        $receipt = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-06-25',
            'bank_account_id' => $this->bank->id,
            'amount' => 6000000,
            'petty_cash_fund_id' => $fund->id,
        ]);

        try {
            $this->payments()->post($receipt, [
                ['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 3000000],
                ['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 3000000],
            ]);
            $this->fail('Neither row alone exceeds the drawer; together they empty it twice — must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('melebihi saldo laci', $e->getMessage());
            // The refusal names the accumulated total, not the passing row.
            $this->assertStringContainsString('6000000', $e->getMessage());
        }

        // Nothing half-posted: the receipt is still a draft, the drawer whole.
        $this->assertSame(PaymentStatus::Draft, $receipt->fresh()->status);
        $this->assertSame(3000000.0, $this->funds()->balance($fund));
    }

    public function test_a_split_drawer_return_within_the_balance_still_posts(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        // Rp 2.000.000 + Rp 1.000.000 = Rp 3.000.000 of a Rp 5.000.000
        // drawer: the accumulator must sum, not over-refuse.
        $receipt = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-06-25',
            'bank_account_id' => $this->bank->id,
            'amount' => 3000000,
            'petty_cash_fund_id' => $fund->id,
        ]);

        $posted = $this->payments()->post($receipt, [
            ['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 2000000],
            ['payable_type' => 'petty_cash_fund', 'payable_id' => $fund->id, 'amount' => 1000000],
        ]);

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertSame(2000000.0, $this->funds()->balance($fund));
    }

    // ------------------------------------------- kasbon beredar tidak diganti

    /**
     * Temuan T21. An ISSUED kasbon is drawer money that left as an ADVANCE: it
     * still belongs to the drawer, it sits on 1-1370 in an employee's name, and
     * NO bon evidences it. Priced at the old float − balance the top-up
     * reimbursed it anyway — the bank transferred Rp 1.200.000 while
     * stampCoveredVouchers() showed the approver Rp 200.000 of paper.
     */
    public function test_the_replenishment_amount_excludes_an_advance_still_in_an_employees_pocket(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        $this->vouchers()->post(
            $this->makeVoucher($fund, ['voucher_date' => '2026-06-14', 'amount' => 200000]),
            $this->custodianUser(),
        );

        // Laci: 5.000.000 − 1.000.000 uang muka − 200.000 bon = 3.800.000.
        $this->assertSame(3800000.0, $this->funds()->balance($fund));

        // Yang perlu diganti hanyalah bonnya: 5.000.000 − 3.800.000 −
        // 1.000.000 kasbon beredar = 200.000, bukan 1.200.000.
        $this->assertSame(200000.0, $this->funds()->replenishmentDue($fund));
    }

    public function test_a_top_up_that_reimburses_an_outstanding_kasbon_is_refused_at_submit(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        $this->vouchers()->post(
            $this->makeVoucher($fund, ['voucher_date' => '2026-06-14', 'amount' => 200000]),
            $this->custodianUser(),
        );

        $payment = $this->replenishmentPayment($fund, 1200000);

        try {
            $this->payments()->submit($payment, $this->fundAllocation($fund, 1200000), $this->financeUser());
            $this->fail('a top-up that reimburses an outstanding kasbon must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('kasbon beredar', $e->getMessage());
            $this->assertStringContainsString('200000', $e->getMessage());
            $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
        }
    }

    /**
     * The works-test of the same guard, and the whole point of it: after the
     * corrected Rp 200.000 top-up the imprest identity CLOSES — which it never
     * did under float − balance, leaving the cashier screen alarming for ever
     * and the drawer above its float once the kasbon settled.
     */
    public function test_the_corrected_top_up_posts_and_leaves_the_imprest_identity_closed(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $kasbon = $this->makeKasbon($fund, $this->makeEmployee(), ['amount' => 1000000]);
        $this->kasbons()->issue($kasbon, $this->custodianUser());

        $this->vouchers()->post(
            $this->makeVoucher($fund, ['voucher_date' => '2026-06-14', 'amount' => 200000]),
            $this->custodianUser(),
        );

        $payment = $this->replenishmentPayment($fund, 200000);
        $allocations = $this->fundAllocation($fund, 200000);

        $posted = $this->payments()->post(
            $this->approveOutgoingPayment($payment, $allocations),
            $allocations,
        );

        $this->assertSame(PaymentStatus::Posted, $posted->status);

        // Laci 3.800.000 + 200.000 = 4.000.000; harapan imprest
        // 5.000.000 − 0 bon − 1.000.000 kasbon beredar − 0 = 4.000.000.
        $this->assertSame(4000000.0, $this->funds()->balance($fund));
        $this->assertSame(4000000.0, $this->funds()->imprestExpectation($fund));

        // Dan begitu kasbon dipertanggungjawabkan, belanjanya masih dapat
        // diganti — di bawah aturan lama laci sudah di ATAS float dan tidak
        // ada isi ulang yang mungkin lagi.
        $this->kasbons()->settle($kasbon->refresh(), [
            ['category' => 'material', 'description' => 'Semen 10 sak', 'amount' => 800000],
        ], '2026-06-18', $this->custodianUser());

        $this->assertSame(800000.0, $this->funds()->replenishmentDue($fund));
    }

    // ------------------------------------------- setoran laci bertanggal mundur

    /**
     * Temuan T22. settleFund() read the drawer UNDATED while the journal it
     * feeds carries payment_date, so a return back-dated before the drawer was
     * funded passed a guard that was looking at the wrong day.
     */
    public function test_a_drawer_return_dated_before_the_drawer_was_funded_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        // Hari ini laci berisi 5.000.000 — tapi pada 2026-05-20 laci kosong.
        $receipt = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-05-20',
            'bank_account_id' => $this->bank->id,
            'amount' => 3000000,
            'petty_cash_fund_id' => $fund->id,
        ]);

        try {
            $this->payments()->post($receipt, $this->fundAllocation($fund, 3000000));
            $this->fail('a back-dated drawer return must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('2026-05-20', $e->getMessage());
            $this->assertSame(PaymentStatus::Draft, $receipt->fresh()->status);
        }

        // Tidak ada yang setengah terposting: laci utuh dan 1-11xx tidak
        // pernah minus di neraca Mei.
        $this->assertSame(5000000.0, $this->funds()->balance($fund));
        $this->assertSame(0.0, $this->funds()->balance($fund, '2026-05-31'));
    }

    public function test_a_drawer_return_dated_after_the_funding_still_posts(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000, '2026-06-01');

        $receipt = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-06-20',
            'bank_account_id' => $this->bank->id,
            'amount' => 3000000,
            'petty_cash_fund_id' => $fund->id,
        ]);

        $posted = $this->payments()->post($receipt, $this->fundAllocation($fund, 3000000));

        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertSame(2000000.0, $this->funds()->balance($fund));
        // Saldo per tanggal setoran juga tidak pernah negatif.
        $this->assertSame(2000000.0, $this->funds()->balance($fund, '2026-06-20'));
    }
}
