<?php

namespace Tests\Unit\Finance;

use Carbon\CarbonImmutable;
use Modules\Finance\Support\TaxDeadlines;
use PHPUnit\Framework\TestCase;

/**
 * The statutory masa deadlines, in ONE place (TaxDeadlines), pinned here so
 * neither consumer — CashFlowService's projection nor the kalender pajak
 * register — can drift from the other. The rules themselves are the ones
 * CashFlowService::projectTaxes() has encoded all along: PPh masa disetor
 * paling lambat tanggal 10 bulan berikutnya, PPN neto paling lambat akhir
 * bulan berikutnya (PMK 242/2014 jo. PMK 18/2021).
 */
class TaxDeadlinesTest extends TestCase
{
    public function test_pph_of_a_masa_is_due_the_tenth_of_the_following_month(): void
    {
        $this->assertSame('2026-08-10', TaxDeadlines::pphDueForMasa(2026, 7)->toDateString());
        // December rolls into the next YEAR, not month 13.
        $this->assertSame('2027-01-10', TaxDeadlines::pphDueForMasa(2026, 12)->toDateString());
    }

    public function test_ppn_of_a_masa_is_due_the_end_of_the_following_month(): void
    {
        $this->assertSame('2026-08-31', TaxDeadlines::ppnDueForMasa(2026, 7)->toDateString());
        // Februari: the "end of month" is 28, not a phantom 31.
        $this->assertSame('2026-02-28', TaxDeadlines::ppnDueForMasa(2026, 1)->toDateString());
        $this->assertSame('2027-01-31', TaxDeadlines::ppnDueForMasa(2026, 12)->toDateString());
    }

    public function test_the_nearest_deadlines_match_what_the_cash_projection_always_used(): void
    {
        // On the 2nd, last masa's PPh is due THIS month's 10th — days away.
        $this->assertSame(
            '2026-08-10',
            TaxDeadlines::nearestPphDue(CarbonImmutable::parse('2026-08-02'))->toDateString(),
        );
        // Past the 10th, the balance rolls to the NEXT 10th.
        $this->assertSame(
            '2026-09-10',
            TaxDeadlines::nearestPphDue(CarbonImmutable::parse('2026-08-11'))->toDateString(),
        );
        // PPN of the prior masa is due the end of the CURRENT month.
        $this->assertSame(
            '2026-08-31',
            TaxDeadlines::nearestPpnDue(CarbonImmutable::parse('2026-08-02'))->toDateString(),
        );

        // And the nearest-form agrees with the per-masa form it is cut from:
        // on 2 Aug the nearest 10th IS masa Juli's own deadline.
        $this->assertEquals(
            TaxDeadlines::pphDueForMasa(2026, 7)->toDateString(),
            TaxDeadlines::nearestPphDue(CarbonImmutable::parse('2026-08-02'))->toDateString(),
        );
    }
}
