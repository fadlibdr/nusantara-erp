<?php

namespace Modules\Assets\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use LogicException;
use Modules\Assets\Services\DeploymentService;
use Modules\Finance\Models\FiscalPeriod;

/**
 * Monthly accrual of the internal plant charge (T43), runnable by hand.
 *
 * The failure this prevents: an open deployment used to contribute Rp 0 to
 * project cost until demobilisation, then the whole span landed in one row —
 * months of understated AC/CPI/EAC on any project with company plant on site.
 * accrueMonth() fixes the shape, but only for months somebody actually runs;
 * this command is how a month gets run — the daily schedule covers the month
 * that just ended, and explicit `ast:accrue-plant 2026 3` runs are how the
 * open back months on an existing installation are caught up, oldest first,
 * while their periods are still open.
 *
 * THE PERIOD-CLOSE CHECKLIST IS THE ARBITER, NOT THIS COMMAND. A cron can
 * miss a month forever (server down across a month end, a period created
 * late); the plant_accrued checklist item cannot, because the closer has to
 * read it before the month becomes unrepairable. This command is the hands,
 * the checklist is the eyes.
 */
class AccruePlantCommand extends Command
{
    protected $signature = 'ast:accrue-plant {year?} {month?}';

    protected $description = 'Accrue the internal plant charge of open deployments for one month (default: the month that just ended)';

    public function handle(DeploymentService $deployments): int
    {
        $explicit = $this->argument('year') !== null || $this->argument('month') !== null;
        $target = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $year = (int) ($this->argument('year') ?? $target->year);
        $month = (int) ($this->argument('month') ?? $target->month);

        /*
         * The scheduled run (no arguments) keeps accruing "the month that just
         * ended" every day — idempotently — until finance closes it. Once the
         * period is closed that is a finished state, not an error: erroring
         * daily from cron for the rest of the month would teach everyone to
         * ignore this command's output. An EXPLICIT run into a closed month
         * still refuses loudly below, because a human asked for something the
         * period gate forbids and deserves the sentence saying why.
         */
        if (! $explicit) {
            $period = FiscalPeriod::query()->where('year', $year)->where('month', $month)->first();

            if ($period !== null && ! $period->isOpen()) {
                $this->info(sprintf('Period %04d-%02d is already closed — nothing left to accrue.', $year, $month));

                return self::SUCCESS;
            }
        }

        try {
            $rows = $deployments->accrueMonth($year, $month);
        } catch (LogicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->info(sprintf('No open deployment to accrue for %04d-%02d.', $year, $month));

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s — %d day(s), Rp %s',
                $row['code'],
                $row['days'],
                number_format($row['amount'], 0, ',', '.'),
            ));
        }

        $this->info(sprintf(
            'Accrued %d deployment(s) for %04d-%02d — Rp %s in total.',
            count($rows),
            $year,
            $month,
            number_format(array_sum(array_column($rows, 'amount')), 0, ',', '.'),
        ));

        return self::SUCCESS;
    }
}
