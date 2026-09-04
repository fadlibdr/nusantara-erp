<?php

namespace Modules\Core\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Services\NotificationService;
use Modules\Core\Support\WatchedDeadlines;

/**
 * Tells the one person who can act about every date that is about to pass —
 * and the ones that already passed in silence.
 *
 * The registry (WatchedDeadlines) is the feature; this command is one loop
 * over it. On the demo data it earns its keep the first morning it runs:
 * PO/2026/II/0001 (Rp 232,5 jt) was promised 1 Mar 2026 and nothing anywhere
 * said so for 153 days, and both kontrak employees have no PKWT end date on
 * file — the state PP 35/2021 quietly converts into a permanent contract.
 *
 * REPEAT DESIGN, stated honestly: NotificationService::system() suppresses
 * only while a copy sits UNREAD — once read, the next daily run would insert
 * a fresh copy, which on a 60-day lead is a nag every single morning. So each
 * tier passes a renag window: read or not, the same title is not re-sent
 * within 7 days (MENIPIS, TANPA_TANGGAL) or 3 days (LEWAT). Escalation needs
 * no special case — MENIPIS and LEWAT carry different stable titles, so a
 * deadline crossing the line fires immediately even while the old tier sits
 * unread. Both suppressions are scoped by a content fingerprint (which rows,
 * how many): a third PO joining "Total 2 PO." changes the fingerprint and
 * fires at once instead of hiding behind the stale unread copy. And nothing
 * depends on the inbox at all: the tenggat screen (GET core/deadlines)
 * re-runs the same scan live.
 *
 * A table or scope column that does not exist yet is a SKIP line and a
 * SUCCESS exit — two other teams migrate this repository daily and
 * mid-migration is normal. A watcher whose in-scope rows ALL lack the watched
 * date is a BLIND line: on live data termin_due found 13 unbilled termins
 * with 13 NULL due_dates, and that silence must read as "data missing", never
 * as "all is well".
 */
class DeadlineWatchCommand extends Command
{
    /*
     * --dry-run: cetak yang AKAN dikirim, tanpa mengirim — bentuk yang sama
     * dengan erp:approval-watch sejak patch 2 Sep 2026. Ada karena penerimaan
     * T3.1 (RECAP-UX-PROSES-2026-09) berbunyi "dry-run produksi menyebut
     * BIL/2026/VII/0002": membaca temuan pagi ini di produksi tidak boleh
     * bergantung pada dedupe jadwal 08:30, dan tidak boleh menulis
     * core_notifications hanya untuk dibaca.
     */
    protected $signature = 'erp:deadline-watch {--dry-run : Tampilkan saja, jangan kirim notifikasi}';

    protected $description = 'Notify whoever can act when a watched date is approaching or already past';

    /**
     * Days before the same title may be re-sent to someone who already read
     * it. Overdue nags harder than approaching — 3 days vs a week.
     */
    private const RENAG_DAYS = [
        WatchedDeadlines::MENIPIS => 7,
        WatchedDeadlines::LEWAT => 3,
        WatchedDeadlines::TANPA_TANGGAL => 7,
    ];

    public function handle(NotificationService $notifications): int
    {
        $today = CarbonImmutable::today();
        $scan = WatchedDeadlines::scan($today);

        foreach ($scan['skipped'] as $skip) {
            $this->line("SKIP {$skip['key']}: {$skip['reason']} — another team is likely mid-migration, not an alarm.");
        }

        foreach ($scan['undated'] as $blind) {
            $this->warn("BLIND {$blind['key']}: {$blind['count']} row(s) in scope but every {$blind['table']}.{$blind['column']} is NULL — this watcher sees nothing until the dates are entered; silence here is missing data, not all clear.");
        }

        $dryRun = (bool) $this->option('dry-run');

        foreach ($scan['findings'] as $finding) {
            $this->warn("{$finding['key']} [{$finding['tier']}]: {$finding['count']} row(s) -> {$finding['permission']}");

            if ($dryRun) {
                // The body, not only the count: a dry-run is read to learn
                // WHICH rows the morning would name, the way
                // erp:approval-watch --dry-run prints its document codes.
                $this->line('  '.WatchedDeadlines::body($finding));

                continue;
            }

            $notifications->system(
                $finding['permission'],
                $finding['title'],
                WatchedDeadlines::body($finding),
                $finding['link'],
                self::RENAG_DAYS[$finding['tier']],
                WatchedDeadlines::signature($finding),
            );
        }

        $this->info(sprintf(
            'Checked %d watcher(s), skipped %d, blind %d, raised %d alarm group(s).%s',
            $scan['checked'],
            count($scan['skipped']),
            count($scan['undated']),
            count($scan['findings']),
            $dryRun ? ' Dry-run: tidak ada notifikasi dikirim.' : '',
        ));

        return self::SUCCESS;
    }
}
