<?php

namespace Modules\Finance\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Services\NotificationService;
use Modules\Finance\Services\PeriodCloseService;

/**
 * Keeps the fiscal calendar a few months ahead of the business.
 *
 * The failure this prevents has a date on it: 1 January. Every posting in the
 * system goes through JournalService::assertPeriodOpen(), which needs a
 * fin_fiscal_periods row for the document's month; the demo calendar stops at
 * December 2026, so on 1 January 2027 the first invoice, the first bill and the
 * first payment of the year all refuse at once, on the busiest morning of the
 * accounting year, with a message about a table nobody outside Finance knows
 * exists.
 *
 * Three months ahead, daily, has two properties a monthly job does not: the
 * next year's calendar exists from 1 October, and a server that was down for
 * two months heals on its first run rather than leaving the gap open until
 * somebody trips over it.
 *
 * It creates and never touches: firstOrCreate leaves an existing row's status
 * exactly as it is, so cron can never reopen a period somebody closed.
 */
class EnsureFiscalCalendarCommand extends Command
{
    protected $signature = 'fin:ensure-calendar {--months= : How many months ahead to create}';

    protected $description = 'Create the fiscal periods for the months ahead so postings never hit a missing period';

    public function handle(PeriodCloseService $periods, NotificationService $notifications): int
    {
        // config(), not Erp::, for the same reason erp:backup-watch reads its
        // threshold that way: erp.closing.* is an install-time constant and is
        // deliberately absent from the settings registry, because an operator
        // who can silence the close reminder from a web form will.
        $months = (int) ($this->option('months') ?? config('erp.closing.calendar_months_ahead', 3));

        $created = $periods->ensureCalendar($months);

        if ($created === []) {
            // Quiet when there is nothing to do — this runs every day.
            $this->info("Fiscal calendar already covers the next {$months} month(s).");

            return self::SUCCESS;
        }

        $labels = array_map(fn (array $row): string => sprintf('%04d-%02d', $row['year'], $row['month']), $created);
        $this->info('Created fiscal periods: '.implode(', ', $labels).'.');

        /*
         * A NEW YEAR IS WORTH TELLING SOMEBODY ABOUT. Finance should learn that
         * 2027 exists in October, while there is time to decide whether the
         * numbering formats and the opening balances are right — not discover
         * it from a posting that suddenly works.
         */
        $newYears = array_values(array_unique(array_column(
            array_filter($created, fn (array $row): bool => $row['new_year']),
            'year',
        )));

        foreach ($newYears as $year) {
            $notifications->system(
                'fin.post',
                "Kalender fiskal {$year} dibuat",
                "Periode fiskal untuk {$year} mulai dibuat otomatis supaya dokumen bertanggal tahun itu "
                    .'dapat diposting. Periksa format penomoran dokumen dan saldo awal di Keuangan › '
                    .'Periode Fiskal sebelum tahun berjalan.',
                'periods',
            );
        }

        return self::SUCCESS;
    }
}
