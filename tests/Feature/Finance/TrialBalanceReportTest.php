<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Services\PeriodCloseService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Neraca saldo per bulan. The invariant under test is per row —
 * opening + period movement = closing — and in total: the report must balance
 * after any realistic set of postings.
 */
class TrialBalanceReportTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        // Februari: setoran modal 500.000.000.
        $this->postJournal([
            ['1-1210', 500000000, 0],
            ['3-1100', 0, 500000000],
        ], '2026-02-01', 'Setoran modal');

        // Maret: penagihan termin (DPP 1.000.000.000 + PPN 110.000.000).
        $this->postJournal([
            ['1-1300', 1110000000, 0],
            ['4-1100', 0, 1000000000],
            ['2-1300', 0, 110000000],
        ], '2026-03-10', 'Invoice termin 1');

        // Maret: tagihan material 300.000.000.
        $this->postJournal([
            ['5-1100', 300000000, 0],
            ['2-1100', 0, 300000000],
        ], '2026-03-15', 'Tagihan material');

        // Maret: pelunasan sebagian 200.000.000.
        $this->postJournal([
            ['1-1210', 200000000, 0],
            ['1-1300', 0, 200000000],
        ], '2026-03-20', 'Penerimaan bank');
    }

    /**
     * @return array<string, array<string, float>> rows keyed by account code
     */
    private function rowsByCode(array $report): array
    {
        $rows = [];

        foreach ($report['rows'] as $row) {
            $rows[$row['account_code']] = $row;
        }

        return $rows;
    }

    public function test_opening_plus_movement_equals_closing_for_every_account(): void
    {
        $report = $this->reports()->trialBalance(2026, 3);

        $this->assertNotEmpty($report['rows']);

        foreach ($report['rows'] as $row) {
            $opening = $row['opening_debit'] - $row['opening_credit'];
            $closing = $row['closing_debit'] - $row['closing_credit'];

            $this->assertEqualsWithDelta(
                $closing,
                round($opening + $row['debit'] - $row['credit'], 2),
                0.001,
                "Account {$row['account_code']} does not roll forward.",
            );
        }
    }

    public function test_the_march_trial_balance_carries_the_february_opening(): void
    {
        $rows = $this->rowsByCode($this->reports()->trialBalance(2026, 3));

        // Bank: saldo awal 500.000.000 (Februari) + mutasi debit 200.000.000
        // => saldo akhir 700.000.000.
        $this->assertSame(500000000.0, $rows['1-1210']['opening_debit']);
        $this->assertSame(200000000.0, $rows['1-1210']['debit']);
        $this->assertSame(0.0, $rows['1-1210']['credit']);
        $this->assertSame(700000000.0, $rows['1-1210']['closing_debit']);

        // Modal: saldo awal kredit 500.000.000, tanpa mutasi Maret.
        $this->assertSame(500000000.0, $rows['3-1100']['opening_credit']);
        $this->assertSame(0.0, $rows['3-1100']['debit']);
        $this->assertSame(500000000.0, $rows['3-1100']['closing_credit']);

        // Piutang: 1.110.000.000 - 200.000.000 = 910.000.000 debit.
        $this->assertSame(0.0, $rows['1-1300']['opening_debit']);
        $this->assertSame(1110000000.0, $rows['1-1300']['debit']);
        $this->assertSame(200000000.0, $rows['1-1300']['credit']);
        $this->assertSame(910000000.0, $rows['1-1300']['closing_debit']);
    }

    public function test_the_report_totals_balance(): void
    {
        $report = $this->reports()->trialBalance(2026, 3);

        // Mutasi Maret: debit 200.000.000 + 1.110.000.000 + 300.000.000 = 1.610.000.000
        $this->assertSame(1610000000.0, $report['totals']['debit']);
        // kredit 1.000.000.000 + 110.000.000 + 300.000.000 + 200.000.000 = 1.610.000.000
        $this->assertSame(1610000000.0, $report['totals']['credit']);

        // Saldo akhir debit: 700.000.000 + 910.000.000 + 300.000.000 = 1.910.000.000
        $this->assertSame(1910000000.0, $report['totals']['closing_debit']);
        // Saldo akhir kredit: 500.000.000 + 1.000.000.000 + 110.000.000 + 300.000.000
        $this->assertSame(1910000000.0, $report['totals']['closing_credit']);

        $this->assertSame(500000000.0, $report['totals']['opening_debit']);
        $this->assertSame(500000000.0, $report['totals']['opening_credit']);
        $this->assertTrue($report['balanced']);
    }

    public function test_february_sees_only_the_february_postings(): void
    {
        $report = $this->reports()->trialBalance(2026, 2);
        $rows = $this->rowsByCode($report);

        $this->assertSame(['1-1210', '3-1100'], array_keys($rows));
        $this->assertSame(0.0, $rows['1-1210']['opening_debit']);
        $this->assertSame(500000000.0, $rows['1-1210']['debit']);
        $this->assertTrue($report['balanced']);
    }

    public function test_a_month_without_movement_still_shows_the_carried_balances(): void
    {
        $report = $this->reports()->trialBalance(2026, 4);
        $rows = $this->rowsByCode($report);

        $this->assertSame(0.0, $report['totals']['debit']);
        $this->assertSame(0.0, $report['totals']['credit']);
        // Saldo akhir April = saldo akhir Maret.
        $this->assertSame(700000000.0, $rows['1-1210']['opening_debit']);
        $this->assertSame(700000000.0, $rows['1-1210']['closing_debit']);
        $this->assertTrue($report['balanced']);
    }

    public function test_accounts_without_balance_or_movement_are_left_out(): void
    {
        $rows = $this->rowsByCode($this->reports()->trialBalance(2026, 3));

        // Tidak pernah tersentuh oleh jurnal mana pun.
        $this->assertArrayNotHasKey('1-1500', $rows);
        $this->assertArrayNotHasKey('6-2100', $rows);
        // Akun induk tidak postable dan tidak pernah muncul.
        $this->assertArrayNotHasKey('1-0000', $rows);
    }

    public function test_draft_journals_never_reach_the_trial_balance(): void
    {
        $this->draftJournal([
            ['1-1500', 999000000, 0],
            ['2-1100', 0, 999000000],
        ], '2026-03-25');

        $report = $this->reports()->trialBalance(2026, 3);
        $rows = $this->rowsByCode($report);

        $this->assertArrayNotHasKey('1-1500', $rows);
        $this->assertSame(1610000000.0, $report['totals']['debit']);
    }

    // ------------------------------------------ the balanced flag's tolerance

    /**
     * The works-test. JournalService::assertBalanced deliberately posts a
     * journal whose debit and credit differ by one cent, and two of them are
     * enough to put the ledger Rp 0,02 out. The `balanced` flag is a
     * NON-OVERRIDABLE close blocker, so while it hard-coded a ledger-wide
     * 0.01 those two ordinary adjusting JVs wedged the month's close for ever
     * — and, because periods close oldest-first, every later month with it.
     * The report now tolerates what the posting guard granted: one cent per
     * journal that carries a gap.
     */
    public function test_two_journals_the_posting_guard_allowed_do_not_unbalance_the_report(): void
    {
        foreach (['2026-03-24', '2026-03-25'] as $date) {
            $this->postJournal([
                ['6-4100', 100.01, 0],
                ['1-1210', 0, 100.00],
            ], $date, 'Penyesuaian dari spreadsheet');
        }

        $report = $this->reports()->trialBalance(2026, 3);

        // Buku besarnya memang selisih Rp 0,02 dan laporannya mengakuinya…
        $this->assertSame(
            0.02,
            round($report['totals']['closing_debit'] - $report['totals']['closing_credit'], 2),
        );
        // …tapi itu persis jatah yang diberikan penjaga posting: 2 x Rp 0,01.
        $this->assertSame(0.02, $report['rounding_tolerance']);
        $this->assertTrue($report['balanced']);

        // Dan penutupan periode tidak lagi terkunci karenanya.
        $item = collect(app(PeriodCloseService::class)->checklist(2026, 3))
            ->firstWhere('key', 'trial_balance_balanced');
        $this->assertSame(PeriodCloseService::OK, $item['status']);
    }

    /**
     * The refused-test, and the reason the tolerance is CAPPED per journal
     * rather than summed. itemTrialBalance exists to catch a ledger broken by
     * direct database surgery; a journal edited to be Rp 1.000 out must fail
     * the flag, not raise the tolerance by its own corruption.
     */
    public function test_a_journal_broken_beyond_the_posting_guards_allowance_still_fails_the_flag(): void
    {
        $journal = $this->postJournal([
            ['6-4100', 100.01, 0],
            ['1-1210', 0, 100.00],
        ], '2026-03-24', 'Penyesuaian dari spreadsheet');

        // Bedah database: satu baris debit ditambah Rp 1.000 tanpa lawan.
        DB::table('fin_journal_lines')
            ->where('journal_id', $journal->id)
            ->where('debit', '>', 0)
            ->update(['debit' => 1100.01]);

        $report = $this->reports()->trialBalance(2026, 3);

        $this->assertSame(0.01, $report['rounding_tolerance']);
        $this->assertFalse($report['balanced']);

        $item = collect(app(PeriodCloseService::class)->checklist(2026, 3))
            ->firstWhere('key', 'trial_balance_balanced');
        $this->assertSame(PeriodCloseService::FAIL, $item['status']);
        $this->assertSame(PeriodCloseService::BLOCK, $item['severity']);
    }

    /**
     * The ordinary ledger forgives nothing at all — the tolerance is derived,
     * not a standing allowance.
     */
    public function test_a_ledger_of_exactly_balanced_journals_tolerates_nothing(): void
    {
        $report = $this->reports()->trialBalance(2026, 3);

        $this->assertSame(0.0, $report['rounding_tolerance']);
        $this->assertTrue($report['balanced']);

        $sheet = $this->reports()->balanceSheet('2026-03-31');
        $this->assertSame(0.0, $sheet['rounding_tolerance']);
        $this->assertTrue($sheet['balanced']);
    }

    public function test_a_month_outside_one_to_twelve_is_refused(): void
    {
        foreach ([0, 13] as $month) {
            try {
                $this->reports()->trialBalance(2026, $month);
                $this->fail("Month {$month} should be refused.");
            } catch (LogicException $e) {
                $this->assertSame('Month must be 1-12.', $e->getMessage());
            }
        }
    }
}
