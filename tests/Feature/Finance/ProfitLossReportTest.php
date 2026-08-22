<?php

namespace Tests\Feature\Finance;

use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Laba rugi. Income-natured sections read positive on the credit side,
 * cost-natured sections positive on the debit side, and the three profit lines
 * are pure subtraction:
 *
 *   gross     = revenue - cogs
 *   operating = gross - operating expenses
 *   net       = operating + other income/expense (netted credit - debit)
 */
class ProfitLossReportTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    private function seedMarchProfitAndLoss(): void
    {
        // Pendapatan konstruksi 1.000.000.000 + PPN keluaran 110.000.000.
        $this->postJournal([
            ['1-1300', 1110000000, 0],
            ['4-1100', 0, 1000000000],
            ['2-1300', 0, 110000000],
        ], '2026-03-10', 'Invoice termin 1');

        // HPP: material 300.000.000 + subkon 200.000.000.
        $this->postJournal([
            ['5-1100', 300000000, 0],
            ['5-1300', 200000000, 0],
            ['2-1100', 0, 500000000],
        ], '2026-03-15', 'Biaya proyek');

        // Beban operasional: gaji 100.000.000.
        $this->postJournal([
            ['6-1100', 100000000, 0],
            ['1-1210', 0, 100000000],
        ], '2026-03-20', 'Gaji kantor');

        // Pendapatan lain: bunga bank 5.000.000.
        $this->postJournal([
            ['1-1210', 5000000, 0],
            ['7-1100', 0, 5000000],
        ], '2026-03-25', 'Jasa giro');

        // Beban lain: admin bank 2.000.000.
        $this->postJournal([
            ['7-2100', 2000000, 0],
            ['1-1210', 0, 2000000],
        ], '2026-03-26', 'Biaya administrasi bank');
    }

    /**
     * @return array<string, float> amounts keyed by account code
     */
    private function amounts(array $section): array
    {
        $amounts = [];

        foreach ($section['rows'] as $row) {
            $amounts[$row['account_code']] = $row['amount'];
        }

        return $amounts;
    }

    public function test_revenue_reads_positive_on_the_credit_side(): void
    {
        $this->seedMarchProfitAndLoss();

        $report = $this->reports()->profitLoss('2026-03-01', '2026-03-31');

        $this->assertSame(1000000000.0, $this->amounts($report['revenue'])['4-1100']);
        $this->assertSame(1000000000.0, $report['revenue']['total']);
        // PPN keluaran adalah kewajiban, bukan pendapatan.
        $this->assertArrayNotHasKey('2-1300', $this->amounts($report['revenue']));
    }

    public function test_cogs_and_expenses_read_positive_on_the_debit_side(): void
    {
        $this->seedMarchProfitAndLoss();

        $report = $this->reports()->profitLoss('2026-03-01', '2026-03-31');
        $cogs = $this->amounts($report['cogs']);

        $this->assertSame(300000000.0, $cogs['5-1100']);
        $this->assertSame(200000000.0, $cogs['5-1300']);
        // 300.000.000 + 200.000.000 = 500.000.000
        $this->assertSame(500000000.0, $report['cogs']['total']);
        $this->assertSame(100000000.0, $this->amounts($report['operating_expenses'])['6-1100']);
        $this->assertSame(100000000.0, $report['operating_expenses']['total']);
    }

    public function test_other_income_nets_against_other_expense(): void
    {
        $this->seedMarchProfitAndLoss();

        $report = $this->reports()->profitLoss('2026-03-01', '2026-03-31');
        $other = $this->amounts($report['other']);

        $this->assertSame(5000000.0, $other['7-1100']);   // pendapatan bunga
        $this->assertSame(-2000000.0, $other['7-2100']);  // beban admin bank tampil negatif
        // 5.000.000 - 2.000.000 = 3.000.000
        $this->assertSame(3000000.0, $report['other']['total']);
    }

    public function test_gross_operating_and_net_profit_arithmetic(): void
    {
        $this->seedMarchProfitAndLoss();

        $report = $this->reports()->profitLoss('2026-03-01', '2026-03-31');

        // 1.000.000.000 - 500.000.000 = 500.000.000
        $this->assertSame(500000000.0, $report['gross_profit']);
        // 500.000.000 - 100.000.000 = 400.000.000
        $this->assertSame(400000000.0, $report['operating_profit']);
        // 400.000.000 + 3.000.000 = 403.000.000
        $this->assertSame(403000000.0, $report['net_profit']);
    }

    public function test_a_loss_making_period_reports_a_negative_net_profit(): void
    {
        // Biaya 250.000.000 tanpa pendapatan sama sekali.
        $this->postJournal([
            ['5-1100', 250000000, 0],
            ['2-1100', 0, 250000000],
        ], '2026-03-15', 'Biaya proyek tanpa penagihan');

        $report = $this->reports()->profitLoss('2026-03-01', '2026-03-31');

        // 0 - 250.000.000 = -250.000.000
        $this->assertSame(0.0, $report['revenue']['total']);
        $this->assertSame(-250000000.0, $report['gross_profit']);
        $this->assertSame(-250000000.0, $report['net_profit']);
    }

    public function test_the_window_excludes_postings_outside_the_dates(): void
    {
        $this->seedMarchProfitAndLoss();

        // Pendapatan April tidak boleh masuk laba rugi Maret.
        $this->postJournal([
            ['1-1300', 555000000, 0],
            ['4-1100', 0, 500000000],
            ['2-1300', 0, 55000000],
        ], '2026-04-01', 'Invoice termin 2');

        $march = $this->reports()->profitLoss('2026-03-01', '2026-03-31');
        $quarter = $this->reports()->profitLoss('2026-03-01', '2026-04-30');

        $this->assertSame(1000000000.0, $march['revenue']['total']);
        // 1.000.000.000 + 500.000.000 = 1.500.000.000
        $this->assertSame(1500000000.0, $quarter['revenue']['total']);
        $this->assertSame('2026-03-01', $march['from']);
        $this->assertSame('2026-03-31', $march['to']);
    }

    public function test_the_boundary_days_are_inclusive(): void
    {
        $this->postJournal([
            ['1-1300', 111000000, 0],
            ['4-1100', 0, 100000000],
            ['2-1300', 0, 11000000],
        ], '2026-03-01', 'Invoice hari pertama');
        $this->postJournal([
            ['1-1300', 222000000, 0],
            ['4-1100', 0, 200000000],
            ['2-1300', 0, 22000000],
        ], '2026-03-31', 'Invoice hari terakhir');

        // 100.000.000 + 200.000.000 = 300.000.000
        $this->assertSame(
            300000000.0,
            $this->reports()->profitLoss('2026-03-01', '2026-03-31')['revenue']['total'],
        );
    }

    public function test_the_project_filter_narrows_by_journal_line_project_id(): void
    {
        $alpha = $this->makeProject(['name' => 'Proyek Alpha']);
        $beta = $this->makeProject(['name' => 'Proyek Beta']);

        // Alpha: pendapatan 500.000.000, HPP 200.000.000.
        $this->postJournal([
            ['1-1300', 555000000, 0, $alpha->id],
            ['4-1100', 0, 500000000, $alpha->id],
            ['2-1300', 0, 55000000, $alpha->id],
        ], '2026-03-10', 'Invoice Alpha');
        $this->postJournal([
            ['5-1100', 200000000, 0, $alpha->id],
            ['2-1100', 0, 200000000, $alpha->id],
        ], '2026-03-12', 'Material Alpha');

        // Beta: pendapatan 300.000.000.
        $this->postJournal([
            ['1-1300', 333000000, 0, $beta->id],
            ['4-1100', 0, 300000000, $beta->id],
            ['2-1300', 0, 33000000, $beta->id],
        ], '2026-03-15', 'Invoice Beta');

        // Overhead kantor tanpa proyek.
        $this->postJournal([
            ['6-1100', 50000000, 0],
            ['1-1210', 0, 50000000],
        ], '2026-03-20', 'Gaji kantor');

        $alphaPl = $this->reports()->profitLoss('2026-03-01', '2026-03-31', (int) $alpha->id);
        $all = $this->reports()->profitLoss('2026-03-01', '2026-03-31');

        $this->assertSame((int) $alpha->id, $alphaPl['project_id']);
        $this->assertSame(500000000.0, $alphaPl['revenue']['total']);
        $this->assertSame(200000000.0, $alphaPl['cogs']['total']);
        // 500.000.000 - 200.000.000 = 300.000.000
        $this->assertSame(300000000.0, $alphaPl['gross_profit']);
        // Beban kantor tanpa project_id tidak dibebankan ke proyek.
        $this->assertSame(0.0, $alphaPl['operating_expenses']['total']);
        $this->assertSame(300000000.0, $alphaPl['net_profit']);

        // Tanpa filter: 500.000.000 + 300.000.000 = 800.000.000
        $this->assertSame(800000000.0, $all['revenue']['total']);
        $this->assertSame(50000000.0, $all['operating_expenses']['total']);
    }

    public function test_draft_journals_are_invisible_to_the_profit_and_loss(): void
    {
        $this->seedMarchProfitAndLoss();
        $this->draftJournal([
            ['4-1100', 0, 999000000],
            ['1-1300', 999000000, 0],
        ], '2026-03-28');

        $this->assertSame(
            1000000000.0,
            $this->reports()->profitLoss('2026-03-01', '2026-03-31')['revenue']['total'],
        );
    }
}
