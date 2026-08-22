<?php

namespace Modules\Finance\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Services\NotificationService;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Services\PeriodCloseService;

/**
 * Nags when a month that has ended is still open.
 *
 * A close nobody is reminded of is a close that happens when somebody asks for
 * a report and finds June still moving. The demo dataset shows the shape of the
 * problem: every 2026 month from February onwards is open, and nothing anywhere
 * says so.
 *
 * The checklist is computed for the OLDEST offending period ONLY. It is the
 * heaviest read in the Finance module (a bank reconciliation per account, a
 * trial balance, a tax-export overview), and the oldest one is the only one
 * that can be closed next anyway — ordering is enforced. That keeps this
 * command's cost bounded at one checklist per day no matter how many months
 * have been let slide.
 *
 * NotificationService::system() dedupes on (event, title, unread), so this nags
 * once and then stays quiet until somebody reads it.
 */
class CloseWatchCommand extends Command
{
    protected $signature = 'fin:close-watch';

    protected $description = 'Remind the finance team when a fiscal period has ended but is still open';

    public function handle(PeriodCloseService $periods, NotificationService $notifications): int
    {
        // An install-time constant read through config(), never the settings
        // registry — see EnsureFiscalCalendarCommand for the reason.
        $days = (int) config('erp.closing.reminder_days_after_period_end', 10);
        $overdue = $periods->overdue($days);

        if ($overdue->isEmpty()) {
            $this->info('Every ended fiscal period is closed.');

            return self::SUCCESS;
        }

        /** @var FiscalPeriod $oldest */
        $oldest = $overdue->first();
        $items = $periods->checklist($oldest->year, $oldest->month);
        $blockers = array_filter(
            $items,
            fn (array $item): bool => $item['severity'] === PeriodCloseService::BLOCK
                && $item['status'] === PeriodCloseService::FAIL,
        );

        $age = (int) CarbonImmutable::parse($oldest->periodEnd())->diffInDays(CarbonImmutable::today());
        $others = $overdue->count() - 1;

        $body = "Periode {$oldest->code()} berakhir {$age} hari lalu dan belum ditutup — "
            .($blockers === []
                ? 'tidak ada penghalang, tinggal ditutup.'
                : count($blockers).' penghalang: '.implode('; ', array_column($blockers, 'short')).'.')
            .($others > 0 ? " Ada {$others} periode lain yang juga sudah berakhir dan masih terbuka." : '');

        $notifications->system(
            'fin.post',
            "Periode {$oldest->code()} belum ditutup",
            $body,
            'periods',
        );

        $this->warn($body);

        return self::SUCCESS;
    }
}
