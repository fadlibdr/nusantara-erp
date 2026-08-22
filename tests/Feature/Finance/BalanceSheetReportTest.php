<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Neraca. This ledger never hard-closes its P&L accounts, so
 * Assets = Liabilities + Equity only holds because the cumulative profit is
 * presented as "Laba Tahun Berjalan" inside equity. Contra assets (akumulasi
 * penyusutan) are asset-typed with a credit normal balance and must therefore
 * show as NEGATIVE rows inside assets rather than as liabilities.
 */
class BalanceSheetReportTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        // Setoran modal 1.000.000.000.
        $this->postJournal([
            ['1-1210', 1000000000, 0],
            ['3-1100', 0, 1000000000],
        ], '2026-01-05', 'Setoran modal');

        // Beli bangunan 500.000.000 tunai.
        $this->postJournal([
            ['1-2200', 500000000, 0],
            ['1-1210', 0, 500000000],
        ], '2026-02-10', 'Pembelian bangunan kantor');

        // Penagihan termin: DPP 1.000.000.000 + PPN 110.000.000.
        $this->postJournal([
            ['1-1300', 1110000000, 0],
            ['4-1100', 0, 1000000000],
            ['2-1300', 0, 110000000],
        ], '2026-03-10', 'Invoice termin 1');

        // Tagihan material 300.000.000 (HPP).
        $this->postJournal([
            ['5-1100', 300000000, 0],
            ['2-1100', 0, 300000000],
        ], '2026-03-15', 'Tagihan material');

        // Penyusutan bangunan 10.000.000 (kontra-aset).
        $this->postJournal([
            ['6-3100', 10000000, 0],
            ['1-2210', 0, 10000000],
        ], '2026-03-31', 'Penyusutan Maret');
    }

    /**
     * @return array<string, float> balances keyed by account code
     */
    private function balances(array $section): array
    {
        $balances = [];

        foreach ($section['rows'] as $row) {
            $balances[$row['account_code'] ?? $row['account_name']] = $row['balance'];
        }

        return $balances;
    }

    public function test_assets_equal_liabilities_plus_equity(): void
    {
        $report = $this->reports()->balanceSheet('2026-03-31');

        // Aset: bank 1.000.000.000 - 500.000.000 = 500.000.000; piutang
        // 1.110.000.000; bangunan 500.000.000; akumulasi -10.000.000
        // => 2.100.000.000
        $this->assertSame(2100000000.0, $report['assets']['total']);
        // Kewajiban: PPN keluaran 110.000.000 + hutang usaha 300.000.000
        $this->assertSame(410000000.0, $report['liabilities']['total']);
        // Ekuitas: modal 1.000.000.000 + laba berjalan 690.000.000
        $this->assertSame(1690000000.0, $report['equity']['total']);
        // 410.000.000 + 1.690.000.000 = 2.100.000.000
        $this->assertSame(2100000000.0, $report['liabilities_and_equity']);
        $this->assertTrue($report['balanced']);
        $this->assertSame('2026-03-31', $report['as_of']);
    }

    public function test_the_accumulated_depreciation_shows_negative_inside_assets(): void
    {
        $report = $this->reports()->balanceSheet('2026-03-31');
        $assets = $this->balances($report['assets']);

        $this->assertSame(500000000.0, $assets['1-2200']);   // bangunan bruto
        $this->assertSame(-10000000.0, $assets['1-2210']);   // akumulasi penyusutan
        // Nilai buku 500.000.000 - 10.000.000 = 490.000.000
        $this->assertSame(490000000.0, round($assets['1-2200'] + $assets['1-2210'], 2));
        // Kontra-aset tidak boleh nyasar ke kewajiban.
        $this->assertArrayNotHasKey('1-2210', $this->balances($report['liabilities']));
    }

    public function test_the_current_year_result_equals_the_cumulative_profit_and_loss(): void
    {
        $report = $this->reports()->balanceSheet('2026-03-31');
        $equity = $this->balances($report['equity']);

        // Pendapatan 1.000.000.000 - HPP 300.000.000 - penyusutan 10.000.000
        // = 690.000.000
        $this->assertSame(690000000.0, $equity['Laba Tahun Berjalan']);

        $pl = $this->reports()->profitLoss('2026-01-01', '2026-03-31');
        $this->assertSame($pl['net_profit'], $equity['Laba Tahun Berjalan']);
    }

    public function test_profit_and_loss_accounts_never_appear_as_balance_sheet_rows(): void
    {
        $report = $this->reports()->balanceSheet('2026-03-31');
        $codes = array_merge(
            array_keys($this->balances($report['assets'])),
            array_keys($this->balances($report['liabilities'])),
            array_keys($this->balances($report['equity'])),
        );

        $this->assertNotContains('4-1100', $codes);
        $this->assertNotContains('5-1100', $codes);
        $this->assertNotContains('6-3100', $codes);
    }

    public function test_an_earlier_as_of_date_ignores_later_postings(): void
    {
        $report = $this->reports()->balanceSheet('2026-02-28');
        $assets = $this->balances($report['assets']);

        // Sampai akhir Februari: bank 500.000.000 + bangunan 500.000.000.
        $this->assertSame(1000000000.0, $report['assets']['total']);
        $this->assertArrayNotHasKey('1-1300', $assets);
        // Belum ada laba: kewajiban 0, ekuitas = modal 1.000.000.000.
        $this->assertSame(0.0, $report['liabilities']['total']);
        $this->assertSame(1000000000.0, $report['equity']['total']);
        $this->assertSame(0.0, $this->balances($report['equity'])['Laba Tahun Berjalan']);
        $this->assertTrue($report['balanced']);
    }

    public function test_the_sheet_still_balances_after_settling_a_receivable(): void
    {
        // Pelunasan sebagian 600.000.000: aset berpindah, total tetap.
        $this->postJournal([
            ['1-1210', 600000000, 0],
            ['1-1300', 0, 600000000],
        ], '2026-04-05', 'Penerimaan pelunasan');

        $report = $this->reports()->balanceSheet('2026-04-30');
        $assets = $this->balances($report['assets']);

        // Bank 500.000.000 + 600.000.000 = 1.100.000.000; piutang
        // 1.110.000.000 - 600.000.000 = 510.000.000.
        $this->assertSame(1100000000.0, $assets['1-1210']);
        $this->assertSame(510000000.0, $assets['1-1300']);
        $this->assertSame(2100000000.0, $report['assets']['total']);
        $this->assertTrue($report['balanced']);
    }

    public function test_draft_journals_do_not_unbalance_the_sheet(): void
    {
        $this->draftJournal([
            ['1-1500', 750000000, 0],
            ['2-1100', 0, 750000000],
        ], '2026-03-20');

        $report = $this->reports()->balanceSheet('2026-03-31');

        $this->assertSame(2100000000.0, $report['assets']['total']);
        $this->assertTrue($report['balanced']);
    }

    // ------------------------------------------------- the year boundary

    /**
     * The whole point of the split. Nothing at all is posted in 2027 and the
     * books were never closed, yet on 2 January the director opens the neraca:
     * "Laba Tahun Berjalan" must read nil for a year two days old, and the
     * Rp 690.000.000 the company really did earn must be somewhere.
     */
    public function test_last_years_profit_becomes_retained_earnings_on_the_first_of_january(): void
    {
        $report = $this->reports()->balanceSheet('2027-01-02');
        $equity = $this->balances($report['equity']);

        $this->assertSame(0.0, $equity['Laba Tahun Berjalan']);
        $this->assertSame(690000000.0, $equity['Laba Ditahan (belum dijurnal tutup)']);
        // Ekuitas total tidak berubah: 1.000.000.000 modal + 690.000.000.
        $this->assertSame(1690000000.0, $report['equity']['total']);
        $this->assertSame(2100000000.0, $report['assets']['total']);
        $this->assertTrue($report['balanced']);
    }

    /**
     * The works-pair on the other side of the boundary: a 2027 posting shows
     * up in the current-year row and nowhere else.
     */
    public function test_this_years_result_counts_only_this_years_postings(): void
    {
        $this->openFiscalYear(2027);

        // Pendapatan 2027 sebesar 200.000.000 (piutang baru).
        $this->postJournal([
            ['1-1300', 200000000, 0],
            ['4-1100', 0, 200000000],
        ], '2027-02-10', 'Invoice termin 2027');

        $report = $this->reports()->balanceSheet('2027-03-31');
        $equity = $this->balances($report['equity']);

        $this->assertSame(200000000.0, $equity['Laba Tahun Berjalan']);
        $this->assertSame(690000000.0, $equity['Laba Ditahan (belum dijurnal tutup)']);
        // 1.690.000.000 + 200.000.000 = 1.890.000.000
        $this->assertSame(1890000000.0, $report['equity']['total']);
        $this->assertTrue($report['balanced']);

        // Dan sama dengan laba rugi tahun berjalan yang dihitung terpisah.
        $pl = $this->reports()->profitLoss('2027-01-01', '2027-03-31');
        $this->assertSame($pl['net_profit'], $equity['Laba Tahun Berjalan']);
    }

    /**
     * The refused-pair: in the company's first year there is no prior year, so
     * the row must not appear at all. A permanent Rp 0,00 "Laba Ditahan" line
     * asks the reader the opposite question.
     */
    public function test_the_first_year_shows_no_retained_earnings_row(): void
    {
        $equity = $this->balances($this->reports()->balanceSheet('2026-03-31')['equity']);

        $this->assertArrayNotHasKey('Laba Ditahan (belum dijurnal tutup)', $equity);
        $this->assertSame(690000000.0, $equity['Laba Tahun Berjalan']);
    }

    /**
     * The synthetic row is a stand-in for a jurnal penutup, not a competitor
     * to one. When the accountant finally keys the real closing entry the
     * prior-year P&L movement nets to nil, so the synthetic row falls to nil
     * in the same instant 3-2100 Laba Ditahan appears under its own code —
     * equity total identical, no double count, nothing to special-case.
     */
    public function test_a_real_closing_journal_replaces_the_synthetic_row_without_double_counting(): void
    {
        $before = $this->reports()->balanceSheet('2027-01-02');

        // Jurnal penutup 31 Desember: tutup 4-1100 ke 3-2100 lewat 5-1100 dan
        // 6-3100. 1.000.000.000 - 300.000.000 - 10.000.000 = 690.000.000.
        $this->postJournal([
            ['4-1100', 1000000000, 0],
            ['5-1100', 0, 300000000],
            ['6-3100', 0, 10000000],
            ['3-2100', 0, 690000000],
        ], '2026-12-31', 'Jurnal penutup 2026');

        $report = $this->reports()->balanceSheet('2027-01-02');
        $equity = $this->balances($report['equity']);

        $this->assertArrayNotHasKey('Laba Ditahan (belum dijurnal tutup)', $equity);
        $this->assertSame(690000000.0, $equity['3-2100']);
        $this->assertSame(0.0, $equity['Laba Tahun Berjalan']);
        $this->assertSame($before['equity']['total'], $report['equity']['total']);
        $this->assertSame(2100000000.0, $report['assets']['total']);
        $this->assertTrue($report['balanced']);
    }

    // ---------------------------------------------------------------- the endpoint

    /**
     * A balance sheet is a point-in-time snapshot, so the endpoint must answer
     * without being told a date — exactly like the AR/AP aging reports, which
     * report as of today and take no parameter. Requiring as_of made
     * GET /api/finance/reports/balance-sheet a 422 for every caller that just
     * wanted the current neraca.
     */
    public function test_the_endpoint_defaults_to_today_when_no_as_of_is_given(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        $response = $this->getJson('/api/finance/reports/balance-sheet');

        $response->assertOk();

        // Today is after every posting in setUp(), so the whole ledger is in.
        // Cast: JSON has no int/float distinction for a whole number, so the
        // decoded total comes back as an int.
        $this->assertSame(Carbon::today()->toDateString(), $response->json('data.as_of'));
        $this->assertSame(2100000000.0, (float) $response->json('data.assets.total'));
        $this->assertTrue($response->json('data.balanced'));
    }

    public function test_the_endpoint_still_honours_an_explicit_as_of_date(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        // 2026-02-28 predates the March invoice, so only modal and the building
        // are in: 1.000.000.000 - 500.000.000 bank + 500.000.000 bangunan.
        $response = $this->getJson('/api/finance/reports/balance-sheet?as_of=2026-02-28');

        $response->assertOk();
        $this->assertSame('2026-02-28', $response->json('data.as_of'));
        $this->assertSame(1000000000.0, (float) $response->json('data.assets.total'));
    }

    public function test_the_endpoint_still_rejects_a_malformed_as_of_date(): void
    {
        $this->actingAs($this->adminUser(), 'sanctum');

        $this->getJson('/api/finance/reports/balance-sheet?as_of=bukan-tanggal')
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of');
    }
}
