<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Models\BankStatementLine;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\Payment;
use Modules\Finance\Services\BankStatementImportService;
use Modules\Finance\Services\BankStatementMatchService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Matching statement lines to what the ERP posted.
 *
 * A match is a claim that one bank movement and one document are the SAME
 * economic event. Everything below defends that "one": the moment the same
 * movement can be claimed twice — under two identities, or by two lines — a
 * genuine difference can be made to disappear behind a reconciliation that
 * balances, which is precisely the failure this feature exists to prevent.
 */
class BankStatementMatchTest extends ErpTestCase
{
    use FinanceFixtures;

    private BankStatementImportService $imports;

    private BankStatementMatchService $matcher;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->imports = app(BankStatementImportService::class);
        $this->matcher = app(BankStatementMatchService::class);
        $this->bank = $this->makeBankAccount('1-1210');
    }

    public function test_a_posted_payment_of_the_same_amount_and_side_can_be_matched(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $line = $this->lineFor('2026-03-10', 'C', 200_000_000);

        $matched = $this->matcher->match($line, BankStatementLine::MATCH_PAYMENT, $payment->id);

        $this->assertTrue($matched->isMatched());
        $this->assertSame($payment->id, (int) $matched->matched_id);
    }

    public function test_a_draft_payment_cannot_be_matched(): void
    {
        $payment = $this->payments()->create([
            'direction' => 'in',
            'payment_date' => '2026-03-10',
            'bank_account_id' => $this->bank->id,
            'amount' => 200_000_000,
        ]);
        $line = $this->lineFor('2026-03-10', 'C', 200_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/belum diposting/');

        $this->matcher->match($line, BankStatementLine::MATCH_PAYMENT, $payment->id);
    }

    public function test_a_payment_on_a_different_bank_account_cannot_be_matched(): void
    {
        $other = $this->makeBankAccount('1-1220', ['name' => 'Mandiri Proyek']);
        $payment = $this->receipt('2026-03-10', 200_000_000, $other);
        $line = $this->lineFor('2026-03-10', 'C', 200_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/rekening bank yang berbeda/');

        $this->matcher->match($line, BankStatementLine::MATCH_PAYMENT, $payment->id);
    }

    public function test_a_payment_on_the_wrong_side_cannot_be_matched(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $line = $this->lineFor('2026-03-10', 'D', 200_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Penerimaan/');

        $this->matcher->match($line, BankStatementLine::MATCH_PAYMENT, $payment->id);
    }

    /**
     * Partial matching is a different feature. Allowing it here would let a
     * Rp 50 juta booking error be reconciled away instead of reported.
     */
    public function test_an_amount_that_differs_by_any_cent_is_refused(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $line = $this->lineFor('2026-03-10', 'C', 199_999_999.99);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Nilai tidak sama/');

        $this->matcher->match($line, BankStatementLine::MATCH_PAYMENT, $payment->id);
    }

    public function test_one_payment_cannot_be_claimed_by_two_statement_lines(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);

        $statement = $this->importStatement([
            ['2026-03-10', 'C', 200_000_000],
            ['2026-03-11', 'C', 200_000_000],
        ], 0, 400_000_000);

        $this->matcher->match($statement->lines[0], BankStatementLine::MATCH_PAYMENT, $payment->id);

        $this->expectException(LogicException::class);

        $this->matcher->match($statement->lines[1], BankStatementLine::MATCH_PAYMENT, $payment->id);
    }

    public function test_a_line_that_is_already_matched_cannot_be_matched_again(): void
    {
        $first = $this->receipt('2026-03-10', 200_000_000);
        $second = $this->receipt('2026-03-11', 200_000_000);
        $line = $this->lineFor('2026-03-10', 'C', 200_000_000);

        $this->matcher->match($line, BankStatementLine::MATCH_PAYMENT, $first->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah dicocokkan/');

        $this->matcher->match($line->refresh(), BankStatementLine::MATCH_PAYMENT, $second->id);
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * The bank debits Rp 300 juta twice. The ERP has one payment for it. Its
     * bank journal line is reachable as a journal_line counterpart, and the
     * unique index treats ('payment', 7) and ('journal_line', 42) as different
     * claims — so without this guard the duplicate debit can be "matched"
     * against the legitimate payment's own GL line, the bank error vanishes
     * from the reconciliation, and the bridge closes on a Rp 300 juta loss.
     */
    public function test_a_payments_own_journal_line_is_not_available_as_a_separate_counterpart(): void
    {
        $payment = $this->disbursement('2026-03-10', 300_000_000);

        $statement = $this->importStatement([
            ['2026-03-10', 'D', 300_000_000],
            ['2026-03-10', 'D', 300_000_000],   // the bank's duplicate
        ], 1_000_000_000, 400_000_000);

        $this->matcher->match($statement->lines[0], BankStatementLine::MATCH_PAYMENT, $payment->id);

        $bankLine = $this->singleJournalFor('payment', $payment->id)
            ->lines->firstWhere('account_id', $this->accountId('1-1210'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cocokkan ke pembayarannya/');

        $this->matcher->match($statement->lines[1], BankStatementLine::MATCH_JOURNAL_LINE, $bankLine->id);
    }

    public function test_a_journal_line_on_another_account_cannot_be_matched(): void
    {
        $journal = $this->postJournal([
            ['7-2100', 2_500_000, 0],
            ['1-1220', 0, 2_500_000],
        ], '2026-03-31', 'Biaya bank rekening lain');

        $line = $this->lineFor('2026-03-31', 'D', 2_500_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak menyentuh akun bank/');

        $this->matcher->match(
            $line,
            BankStatementLine::MATCH_JOURNAL_LINE,
            $journal->lines->firstWhere('account_id', $this->accountId('1-1220'))->id,
        );
    }

    // ---------------------------------------------------------- suggestions

    public function test_a_same_day_exact_amount_payment_is_suggested_with_high_confidence(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000, null, 'TRF BCA 1003/8891');
        $statement = $this->importStatement([['2026-03-10', 'C', 200_000_000, 'TRF BCA 1003/8891']], 0, 200_000_000);

        $suggestions = $this->matcher->suggestForStatement($statement);
        $first = $suggestions[$statement->lines[0]->id][0];

        $this->assertSame($payment->id, $first['matched_id']);
        $this->assertSame('high', $first['confidence']);
    }

    public function test_a_payment_outside_the_date_window_is_not_suggested(): void
    {
        $this->receipt('2026-02-01', 200_000_000);
        $statement = $this->importStatement([['2026-03-10', 'C', 200_000_000]], 0, 200_000_000);

        $this->assertSame([], $this->matcher->suggestForStatement($statement)[$statement->lines[0]->id]);
    }

    public function test_a_payment_of_a_different_amount_is_not_suggested(): void
    {
        $this->receipt('2026-03-10', 199_000_000);
        $statement = $this->importStatement([['2026-03-10', 'C', 200_000_000]], 0, 200_000_000);

        $this->assertSame([], $this->matcher->suggestForStatement($statement)[$statement->lines[0]->id]);
    }

    public function test_an_already_claimed_payment_is_not_suggested_for_another_line(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $statement = $this->importStatement([
            ['2026-03-10', 'C', 200_000_000],
            ['2026-03-11', 'C', 200_000_000],
        ], 0, 400_000_000);

        $this->matcher->match($statement->lines[0], BankStatementLine::MATCH_PAYMENT, $payment->id);

        $suggestions = $this->matcher->suggestForStatement($statement->refresh());
        $this->assertSame([], $suggestions[$statement->lines[1]->id]);
    }

    /**
     * A window of 14 days must not leave a candidate 10 days out scoring zero
     * on the date and rendered to the operator as a weak guess — the bands are
     * derived from the setting, not hard-coded against its default.
     */
    public function test_widening_the_date_window_does_not_break_the_scoring(): void
    {
        config()->set('erp.reconciliation.match_date_window_days', 14);

        $this->receipt('2026-03-20', 200_000_000);
        $statement = $this->importStatement([['2026-03-10', 'C', 200_000_000]], 0, 200_000_000);

        $suggestion = $this->matcher->suggestForStatement($statement)[$statement->lines[0]->id][0];

        $this->assertSame(10, $suggestion['days_apart']);
        $this->assertGreaterThanOrEqual(20, $suggestion['score']);
    }

    // ------------------------------------------------------------ lifecycle

    public function test_unmatching_frees_the_counterpart_for_another_line(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $statement = $this->importStatement([
            ['2026-03-10', 'C', 200_000_000],
            ['2026-03-11', 'C', 200_000_000],
        ], 0, 400_000_000);

        $this->matcher->match($statement->lines[0], BankStatementLine::MATCH_PAYMENT, $payment->id);
        $this->matcher->unmatch($statement->lines[0]->refresh());

        $matched = $this->matcher->match($statement->lines[1], BankStatementLine::MATCH_PAYMENT, $payment->id);

        $this->assertTrue($matched->isMatched());
    }

    public function test_a_no_match_reason_must_be_one_of_the_declared_ones(): void
    {
        $line = $this->lineFor('2026-03-10', 'D', 2_500_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak dikenal/');

        $this->matcher->markNoMatch($line, 'karena-saya-bilang-begitu');
    }

    public function test_a_matched_line_must_be_unmatched_before_it_can_be_marked_no_match(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $line = $this->lineFor('2026-03-10', 'C', 200_000_000);
        $this->matcher->match($line, BankStatementLine::MATCH_PAYMENT, $payment->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Batalkan pencocokan/');

        $this->matcher->markNoMatch($line->refresh(), 'bank_error');
    }

    /**
     * The import wrote no journal, and neither does matching. If either did,
     * this feature would be a second posting path that bypasses AR/AP
     * settlement entirely.
     */
    public function test_importing_and_matching_write_nothing_to_the_ledger(): void
    {
        $payment = $this->receipt('2026-03-10', 200_000_000);
        $before = JournalLine::query()->count();

        $statement = $this->importStatement([
            ['2026-03-10', 'C', 200_000_000],
            ['2026-03-15', 'D', 2_500_000],
        ], 0, 197_500_000);

        $this->matcher->match($statement->lines[0], BankStatementLine::MATCH_PAYMENT, $payment->id);
        $this->matcher->markNoMatch($statement->lines[1]->refresh(), 'bank_charge');

        $this->assertSame($before, JournalLine::query()->count());
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  list<array{0: string, 1: string, 2: float, 3?: string}>  $lines
     */
    private function importStatement(array $lines, float $opening, float $closing): BankStatement
    {
        $body = [
            ':20:STMT'.BankStatement::query()->count(),
            ':25:BCA/1234567890',
            ':28C:'.str_pad((string) (BankStatement::query()->count() + 1), 5, '0', STR_PAD_LEFT).'/001',
            ':60F:C260301IDR'.number_format($opening, 2, ',', ''),
        ];

        foreach ($lines as $index => $line) {
            [$date, $mark, $amount] = $line;
            $body[] = ':61:'.date('ymd', strtotime($date)).$mark.number_format($amount, 2, ',', '')
                .'NTRFREF'.($index + 1).'//BANK'.($index + 1);
            $body[] = ':86:'.($line[3] ?? 'Mutasi '.($index + 1));
        }

        $body[] = ':62F:C260331IDR'.number_format($closing, 2, ',', '');

        return $this->imports->import($this->bank, 'mt940', implode("\n", $body));
    }

    private function lineFor(string $date, string $mark, float $amount): BankStatementLine
    {
        $movement = $mark === 'C' ? $amount : -$amount;

        return $this->importStatement([[$date, $mark, $amount]], 1_000_000_000, 1_000_000_000 + $movement)
            ->lines->first();
    }

    private function receipt(string $date, float $amount, ?BankAccount $bank = null, ?string $reference = null): Payment
    {
        $bank ??= $this->bank;
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
            'bank_account_id' => $bank->id,
            'amount' => $amount,
            'reference' => $reference,
        ]);

        return $this->payments()->post($payment, [[
            'payable_type' => 'ar_invoice',
            'payable_id' => $invoice->id,
            'amount' => $amount,
        ]]);
    }

    private function disbursement(string $date, float $amount): Payment
    {
        $vendor = $this->makeVendor();

        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $vendor->id,
            'description' => 'Material',
            'dpp' => $amount,
            'bill_date' => $date,
            'vendor_invoice_no' => 'INV-'.$date,
        ]));

        return $this->approvedOutgoingPayment(
            [
                'payment_date' => $date,
                'bank_account_id' => $this->bank->id,
                'amount' => $amount,
            ],
            [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => $amount]],
        );
    }
}
