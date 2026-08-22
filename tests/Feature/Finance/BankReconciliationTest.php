<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Models\BankStatementLine;
use Modules\Finance\Models\Payment;
use Modules\Finance\Services\BankReconciliationService;
use Modules\Finance\Services\BankStatementImportService;
use Modules\Finance\Services\BankStatementMatchService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The bank reconciliation bridge:  G = S + O + B − C − D + E.
 *
 * Two properties matter, and they pull in opposite directions.
 *
 * The bridge must CLOSE — the residual must be zero — whenever the data is
 * consistent, because a reconciliation that reports an unexplained difference
 * every month teaches its reader to ignore the number.
 *
 * And the bridge must not close by CANCELLATION. Two open items that happen to
 * offset each other leave a residual of zero while a real error sits in plain
 * sight, so "the bridge closes" and "the account is reconciled" are reported as
 * two different facts.
 *
 * Most of the cases below are ones where a boolean match_status column gives
 * the wrong answer: membership in a category is a fact AS AT the cut-off date,
 * not a flag on a row.
 */
class BankReconciliationTest extends ErpTestCase
{
    use FinanceFixtures;

    private BankStatementImportService $imports;

    private BankStatementMatchService $matcher;

    private BankReconciliationService $recon;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->imports = app(BankStatementImportService::class);
        $this->matcher = app(BankStatementMatchService::class);
        $this->recon = app(BankReconciliationService::class);
        $this->bank = $this->makeBankAccount('1-1210');
    }

    public function test_an_untouched_account_reconciles_at_zero(): void
    {
        $this->importStatement('2026-03-01', '2026-03-31', 0, 0, []);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertSame(0, $report['summary']['open_items']);
        $this->assertTrue($report['summary']['fully_reconciled']);
    }

    public function test_a_fully_matched_statement_reconciles_with_no_timing_items(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $this->importStatement('2026-03-01', '2026-03-31', 0, 200_000_000, [
            ['2026-03-10', 'C', 200_000_000, 'TERMIN 1'],
        ]);
        $this->matchFirstLineTo($payment);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertSame(0.0, $report['bridge']['on_books_not_on_bank_debit']);
        $this->assertSame(0.0, $report['bridge']['on_bank_not_on_books_credit']);
        $this->assertSame(200_000_000.0, $report['bridge']['gl_balance']);
        $this->assertTrue($report['summary']['fully_reconciled']);
    }

    /**
     * The receipt is in the books and the bank has not shown it yet — a deposit
     * in transit, category B. Nothing here is unexplained.
     */
    public function test_a_payment_the_bank_has_not_shown_yet_is_a_deposit_in_transit(): void
    {
        $this->receipt('2026-03-30', 500_000_000);
        $this->importStatement('2026-03-01', '2026-03-31', 0, 0, []);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(500_000_000.0, $report['bridge']['on_books_not_on_bank_debit']);
        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertSame(1, $report['summary']['open_items']);
        $this->assertFalse($report['summary']['fully_reconciled']);
    }

    public function test_a_bank_charge_nobody_has_booked_is_reported_as_a_difference(): void
    {
        $this->importStatement('2026-03-01', '2026-03-31', 0, -2_500_000, [
            ['2026-03-31', 'D', 2_500_000, 'BIAYA ADM'],
        ]);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(2_500_000.0, $report['bridge']['on_bank_not_on_books_debit']);
        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertSame(0.0, $report['bridge']['gl_balance']);
    }

    /**
     * Marking a line "no match" is a review state, not an exclusion. It is
     * still money the bank moved, so it keeps counting — otherwise a clerk
     * could close a reconciliation by declaring the differences uninteresting.
     */
    public function test_marking_a_line_unmatchable_does_not_remove_it_from_the_bridge(): void
    {
        $this->importStatement('2026-03-01', '2026-03-31', 0, -2_500_000, [
            ['2026-03-31', 'D', 2_500_000, 'BIAYA ADM'],
        ]);

        $this->matcher->markNoMatch(BankStatementLine::query()->firstOrFail(), 'bank_charge', 'Belum dibukukan');

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(2_500_000.0, $report['bridge']['on_bank_not_on_books_debit']);
        $this->assertSame(1, $report['summary']['open_items']);
        $this->assertSame('bank_charge', $report['categories']['on_bank_not_on_books'][0]['note_reason']);
    }

    /**
     * Booking the charge as a JV and matching it is what makes the difference
     * go away. Nothing on the reconciliation screen can do that — only a
     * posting can.
     */
    public function test_a_booked_and_matched_bank_charge_leaves_no_difference(): void
    {
        $this->importStatement('2026-03-01', '2026-03-31', 0, -2_500_000, [
            ['2026-03-31', 'D', 2_500_000, 'BIAYA ADM'],
        ]);

        $journal = $this->postJournal([
            ['7-2100', 2_500_000, 0],
            ['1-1210', 0, 2_500_000],
        ], '2026-03-31', 'Biaya administrasi bank');

        $bankLine = $journal->lines->firstWhere('account_id', $this->accountId('1-1210'));

        $this->matcher->match(
            BankStatementLine::query()->firstOrFail(),
            BankStatementLine::MATCH_JOURNAL_LINE,
            $bankLine->id,
        );

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(0.0, $report['bridge']['on_bank_not_on_books_debit']);
        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertTrue($report['summary']['fully_reconciled']);
    }

    // -------------------------------------------------- membership as at a date

    /**
     * The bank books a charge on 28 February; the clerk books the journal on
     * 3 March and matches it. At a 28 February cut-off the pair is matched but
     * the journal is NOT in the books yet, so the charge is a timing item — not
     * a residual. Reading match_status as a boolean puts it in "unexplained"
     * every single month, which is the most common workflow there is.
     */
    public function test_a_match_whose_counterpart_is_booked_after_the_cut_off_is_a_timing_item(): void
    {
        $this->importStatement('2026-02-01', '2026-02-28', 0, -20_000_000, [
            ['2026-02-28', 'D', 20_000_000, 'BIAYA ADM FEBRUARI'],
        ]);

        $journal = $this->postJournal([
            ['7-2100', 20_000_000, 0],
            ['1-1210', 0, 20_000_000],
        ], '2026-03-03', 'Biaya admin Februari, dibukukan Maret');

        $this->matcher->match(
            BankStatementLine::query()->firstOrFail(),
            BankStatementLine::MATCH_JOURNAL_LINE,
            $journal->lines->firstWhere('account_id', $this->accountId('1-1210'))->id,
        );

        $report = $this->recon->reconcile($this->bank, '2026-02-28');

        $this->assertSame(20_000_000.0, $report['bridge']['on_bank_not_on_books_debit']);
        $this->assertSame(0.0, $report['bridge']['residual'], 'a known timing item must not land in the residual');
        $this->assertSame('2026-03-03', $report['categories']['on_bank_not_on_books'][0]['pending_counterpart_date']);
    }

    /**
     * The mirror: the ERP records a receipt on 30 March, the bank shows it on
     * 2 April. Both statements are imported and the pair is matched, but at a
     * 31 March cut-off it is still a deposit in transit.
     */
    public function test_a_match_whose_statement_line_is_in_a_later_period_is_still_outstanding(): void
    {
        $payment = $this->receipt('2026-03-30', 500_000_000);

        $this->importStatement('2026-03-01', '2026-03-31', 0, 0, []);
        $this->importStatement('2026-04-01', '2026-04-30', 0, 500_000_000, [
            ['2026-04-02', 'C', 500_000_000, 'TERMIN 1'],
        ]);

        $this->matcher->match(
            BankStatementLine::query()->firstOrFail(),
            BankStatementLine::MATCH_PAYMENT,
            $payment->id,
        );

        $march = $this->recon->reconcile($this->bank, '2026-03-31');
        $this->assertSame(500_000_000.0, $march['bridge']['on_books_not_on_bank_debit']);
        $this->assertSame(0.0, $march['bridge']['residual']);

        $april = $this->recon->reconcile($this->bank, '2026-04-30');
        $this->assertSame(0.0, $april['bridge']['on_books_not_on_bank_debit']);
        $this->assertSame(0.0, $april['bridge']['residual']);
        $this->assertTrue($april['summary']['fully_reconciled']);
    }

    /**
     * Ledger history from before the first imported statement can never be
     * matched — no statement line exists for it. Sweeping it into "deposits in
     * transit" is how a new installation is greeted by its entire opening
     * balance dressed up as a timing difference.
     */
    public function test_ledger_history_before_the_first_statement_is_an_opening_difference_not_a_deposit_in_transit(): void
    {
        $this->receipt('2026-02-27', 10_767_000_000);

        // The bank had already shown it: the March statement opens with it.
        $this->importStatement('2026-03-01', '2026-03-31', 10_767_000_000, 10_767_000_000, []);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(0.0, $report['bridge']['on_books_not_on_bank_debit'], 'a receipt that cleared last month is not in transit');
        $this->assertSame(0.0, $report['bridge']['opening_difference']);
        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertTrue($report['summary']['fully_reconciled']);
    }

    /**
     * And when the opening balances genuinely disagree, that is its own named
     * row rather than an unexplained residual.
     */
    public function test_an_opening_balance_that_disagrees_with_the_ledger_is_disclosed_as_such(): void
    {
        $this->receipt('2026-02-27', 10_767_000_000);
        $this->importStatement('2026-03-01', '2026-03-31', 10_000_000_000, 10_000_000_000, []);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(767_000_000.0, $report['bridge']['opening_difference']);
        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertFalse($report['summary']['opening_difference_explained']);
    }

    /**
     * A line the bank dated outside the statement's own period is still inside
     * the closing balance, so it must be inside the reconciliation too.
     * Scoping the statement side by line date instead of by statement makes the
     * two sides disagree about which movements exist.
     */
    public function test_a_line_dated_outside_its_statement_period_still_counts(): void
    {
        $this->importStatement('2026-03-01', '2026-03-31', 0, -1_000_000, [
            ['2026-04-02', 'D', 1_000_000, 'BIAYA DIBUKUKAN BANK TERLAMBAT'],
        ]);

        $report = $this->recon->reconcile($this->bank, '2026-03-31');

        $this->assertSame(1_000_000.0, $report['bridge']['on_bank_not_on_books_debit']);
        $this->assertSame(0.0, $report['bridge']['residual']);
    }

    // ------------------------------------------------------- honest reporting

    /**
     * The ERP recorded Rp 350 juta, the bank moved Rp 300 juta. Neither can
     * match the other, so both sit in the bridge and it closes at zero —
     * arithmetically fine, Rp 50 juta wrong. The residual cannot see it; the
     * open-item count and the near-miss list can.
     */
    public function test_a_booking_error_leaves_the_bridge_closed_but_the_account_unreconciled(): void
    {
        $this->receipt('2026-03-10', 350_000_000);
        $this->importStatement('2026-03-01', '2026-03-31', 0, 300_000_000, [
            ['2026-03-10', 'C', 300_000_000, 'TERMIN 1'],
        ]);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertTrue($report['summary']['bridge_closes']);
        $this->assertFalse($report['summary']['fully_reconciled'], 'two offsetting open items are not a reconciliation');
        $this->assertSame(2, $report['summary']['open_items']);

        $near = $report['possible_mismatches'];
        $this->assertCount(1, $near);
        $this->assertSame(50_000_000.0, $near[0]['difference']);
    }

    /**
     * The go-live shape: a receipt booked before the first statement was ever
     * imported, which the bank shows on the first statement. It IS matched, and
     * the ledger already carries it in the opening balance — so it is not a
     * timing item. Left in the "on the bank, not booked" bucket it would sit
     * there every month forever, with a counterpart date in the past presented
     * as pending, and the account could never reach reconciled.
     */
    public function test_a_match_to_a_counterpart_booked_before_the_first_statement_nets_into_the_opening(): void
    {
        $payment = $this->receipt('2026-02-25', 100_000_000);

        $this->importStatement('2026-03-01', '2026-03-31', 0, 100_000_000, [
            ['2026-03-02', 'C', 100_000_000, 'TERMIN 1'],
        ]);
        $this->matchFirstLineTo($payment);

        $report = $this->recon->reconcile($this->bank, '2026-03-31');

        $this->assertSame(0.0, $report['bridge']['on_bank_not_on_books_credit'], 'a receipt the ledger already carries is not outstanding');
        $this->assertSame(0.0, $report['bridge']['opening_difference'], 'and it explains the opening difference rather than adding to it');
        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertSame(0, $report['summary']['open_items']);
        $this->assertTrue($report['summary']['fully_reconciled']);
    }

    /** The same, in the other direction: a disbursement booked before the chain. */
    public function test_the_opening_netting_works_for_money_going_out_too(): void
    {
        $vendor = $this->makeVendor();
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'description' => 'Material',
            'dpp' => 100_000_000,
            'bill_date' => '2026-02-25',
            'vendor_invoice_no' => 'INV-OPEN',
        ]));
        $payment = $this->approvedOutgoingPayment(
            [
                'payment_date' => '2026-02-25',
                'bank_account_id' => $this->bank->id,
                'amount' => 100_000_000,
            ],
            [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => 100_000_000]],
        );

        $this->importStatement('2026-03-01', '2026-03-31', 0, -100_000_000, [
            ['2026-03-02', 'D', 100_000_000, 'PEMBAYARAN VENDOR'],
        ]);
        $this->matchFirstLineTo($payment);

        $report = $this->recon->reconcile($this->bank, '2026-03-31');

        $this->assertSame(0.0, $report['bridge']['on_bank_not_on_books_debit']);
        $this->assertSame(0.0, $report['bridge']['opening_difference']);
        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertTrue($report['summary']['fully_reconciled']);
    }

    /**
     * The opening difference is a plug: it has no rows behind it, so it is
     * driven to whatever makes the bridge close. An account carrying one is not
     * reconciled, however clean the rest of the arithmetic looks.
     */
    public function test_an_unexplained_opening_difference_stops_the_account_being_fully_reconciled(): void
    {
        $this->receipt('2026-02-27', 10_767_000_000);
        $this->importStatement('2026-03-01', '2026-03-31', 10_000_000_000, 10_000_000_000, []);

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(0.0, $report['bridge']['residual']);
        $this->assertSame(0, $report['summary']['open_items']);
        $this->assertSame(767_000_000.0, $report['bridge']['opening_difference']);
        $this->assertTrue($report['summary']['bridge_closes']);
        $this->assertFalse($report['summary']['fully_reconciled']);
    }

    public function test_the_report_refuses_an_account_with_no_statement_up_to_the_date(): void
    {
        $this->importStatement('2026-03-01', '2026-03-31', 0, 0, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Belum ada rekening koran/');

        $this->recon->reconcile($this->bank, '2026-02-28');
    }

    /**
     * The cut-off day itself must be included. journal_date is stored as text
     * with a time part, so a lexicographic '<=' against a bare date drops it —
     * and the default cut-off is a statement's own period_end, so the report
     * would exclude the statement it was derived from.
     */
    public function test_the_cut_off_day_is_included_on_both_sides(): void
    {
        $payment = $this->receipt('2026-03-31', 100_000_000);
        $this->importStatement('2026-03-01', '2026-03-31', 0, 100_000_000, [
            ['2026-03-31', 'C', 100_000_000, 'TERMIN AKHIR BULAN'],
        ]);
        $this->matchFirstLineTo($payment);

        $report = $this->recon->reconcile($this->bank, '2026-03-31');

        $this->assertSame(100_000_000.0, $report['bridge']['statement_closing']);
        $this->assertSame(100_000_000.0, $report['bridge']['gl_balance']);
        $this->assertTrue($report['summary']['fully_reconciled']);
    }

    public function test_a_draft_journal_on_the_bank_account_is_not_in_the_ledger_balance(): void
    {
        $this->importStatement('2026-03-01', '2026-03-31', 0, 0, []);
        $this->draftJournal([['1-1210', 5_000_000, 0], ['7-2100', 0, 5_000_000]], '2026-03-15');

        $report = $this->recon->reconcile($this->bank);

        $this->assertSame(0.0, $report['bridge']['gl_balance']);
        $this->assertSame(0, $report['summary']['open_items']);
    }

    /**
     * Two bank accounts on one COA would each report the other's movements as
     * their own timing differences, and neither number would look wrong.
     */
    public function test_two_bank_accounts_sharing_a_coa_account_are_refused_rather_than_mixed(): void
    {
        // Uniqueness is enforced by the form requests, not by a database index —
        // fin_bank_accounts soft-deletes and an index cannot see that. Creating
        // the model directly is therefore how an installation ends up here, and
        // the report has to refuse rather than mix the two accounts' movements.
        BankAccount::query()->create([
            'code' => 'BANK-DUP',
            'name' => 'BCA Payroll',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '5230000001',
            'account_name' => 'PT Nusantara Karya Integrasi',
            'coa_account_id' => $this->bank->coa_account_id,
            'is_active' => true,
        ]);

        $this->importStatement('2026-03-01', '2026-03-31', 0, 0, []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/lebih dari satu rekening bank/');

        $this->recon->reconcile($this->bank);
    }

    public function test_the_overview_reports_a_blocked_account_instead_of_failing(): void
    {
        $overview = $this->recon->overview('2026-03-31');

        $this->assertCount(1, $overview['rows']);
        $this->assertStringContainsString('Belum ada rekening koran', $overview['rows'][0]['blocked']);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  list<array{0: string, 1: string, 2: float, 3: string}>  $lines  [date, C|D, amount, description]
     */
    private function importStatement(string $start, string $end, float $opening, float $closing, array $lines): BankStatement
    {
        $body = [
            ':20:STMT'.str_replace('-', '', $start),
            ':25:BCA/1234567890',
            ':28C:'.str_pad((string) (BankStatement::query()->count() + 1), 5, '0', STR_PAD_LEFT).'/001',
            ':60F:'.$this->balanceTag($start, $opening),
        ];

        foreach ($lines as $index => [$date, $mark, $amount, $description]) {
            $body[] = ':61:'.date('ymd', strtotime($date)).$mark.number_format($amount, 2, ',', '')
                .'NTRFREF'.($index + 1).'//BANK'.($index + 1);
            $body[] = ':86:'.$description;
        }

        $body[] = ':62F:'.$this->balanceTag($end, $closing);

        return $this->imports->import($this->bank, 'mt940', implode("\n", $body));
    }

    private function balanceTag(string $date, float $amount): string
    {
        return ($amount < 0 ? 'D' : 'C').date('ymd', strtotime($date)).'IDR'.number_format(abs($amount), 2, ',', '');
    }

    /** A posted receipt: Dr 1-1210 Bank, Cr 1-1300 Piutang. */
    private function receipt(string $date, float $amount): Payment
    {
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer);

        $invoice = $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'description' => 'Termin',
            'dpp' => $amount,
            'ppn_rate' => 0.0,
            'invoice_date' => $date,
        ]));

        $payment = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => $date,
            'bank_account_id' => $this->bank->id,
            'amount' => $amount,
            'reference' => 'TRF '.$date,
        ]);

        return $this->payments()->post($payment, [[
            'payable_type' => 'ar_invoice',
            'payable_id' => $invoice->id,
            'amount' => $amount,
        ]]);
    }

    private function matchFirstLineTo(Payment $payment): void
    {
        $this->matcher->match(
            BankStatementLine::query()->orderBy('id')->firstOrFail(),
            BankStatementLine::MATCH_PAYMENT,
            $payment->id,
        );
    }
}
