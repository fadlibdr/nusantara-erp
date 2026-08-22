<?php

namespace Modules\Finance\Support;

use Carbon\CarbonImmutable;

/**
 * The statutory deadlines of the monthly tax masas, defined ONCE.
 *
 * These two rules lived only inside CashFlowService::projectTaxes(), where
 * they charged the 90-day cash projection; the kalender pajak register (#25)
 * needs the same dates per masa. Re-declaring them there is how the register
 * and the projection would one day disagree about when the same rupiah is due
 * — so the service now reads its "nearest deadline not yet passed" from here,
 * and the register reads its per-masa due dates from here, and there is no
 * second copy to drift.
 *
 * The rules (PMK 242/2014 s.t.d.d. PMK 18/2021):
 *
 *   PPh masa (21, 23, final 4(2))  disetor paling lambat TANGGAL 10 bulan
 *                                  berikutnya setelah masa pajak berakhir;
 *   PPN masa                       disetor paling lambat AKHIR bulan
 *                                  berikutnya, sebelum SPT masa PPN
 *                                  disampaikan.
 *
 * Statutory numbers are data, not code (docs/ARCHITECTURE.md) — but these are
 * not rates that change yearly, they are filing mechanics; a config knob would
 * only invite setting them wrong.
 */
class TaxDeadlines
{
    /** Setoran PPh untuk satu masa: tanggal 10 bulan berikutnya. */
    public static function pphDueForMasa(int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1)->addMonth()->startOfMonth()->addDays(9);
    }

    /** Setoran PPN untuk satu masa: akhir bulan berikutnya. */
    public static function ppnDueForMasa(int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, 1)->addMonth()->endOfMonth()->startOfDay();
    }

    /**
     * Tenggat tanggal 10 terdekat yang belum lewat — the form the cash
     * projection consumes: through the 10th it is THIS month's (last masa's
     * own deadline, days away); past it, the balance rolls to the next 10th.
     */
    public static function nearestPphDue(CarbonImmutable $today): CarbonImmutable
    {
        return $today->day <= 10
            ? $today->startOfMonth()->addDays(9)
            : $today->addMonth()->startOfMonth()->addDays(9);
    }

    /** PPN masa lalu jatuh tempo akhir bulan BERJALAN. */
    public static function nearestPpnDue(CarbonImmutable $today): CarbonImmutable
    {
        return $today->endOfMonth()->startOfDay();
    }
}
