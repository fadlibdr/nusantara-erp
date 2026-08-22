<?php

namespace Modules\Core\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Support\CalendarEvents;

/**
 * The month behind the kalender screen and the dashboard mini-grid.
 *
 * DeadlineController answers "what is overdue or at risk"; this endpoint
 * answers "what happens WHEN". Events are filtered to the module .view
 * permissions the CALLER holds — seeing a plan is reading (the GlobalSearch
 * rule) — so "nothing here" and "nothing you may see" read the same.
 *
 * meta.as_of is the server's today (app clock, Asia/Jakarta) and the ONLY
 * "today" clients may use for the today-ring and the 'Hari ini' button: the
 * demo's browsers are not on Jakarta clocks, and a client-side new Date()
 * would ring the wrong day.
 */
class CalendarController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $month = $request->query('month');

        if ($month !== null && (! is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1)) {
            return $this->error('Parameter month harus berformat YYYY-MM.');
        }

        $today = CarbonImmutable::today();
        $month ??= $today->format('Y-m');
        $from = CarbonImmutable::parse($month.'-01');

        $window = CalendarEvents::window($from, $from->endOfMonth());
        $user = $request->user();

        $visible = array_values(array_filter(
            $window['events'],
            static fn (array $event): bool => $user !== null && $user->can($event['permission']),
        ));

        // Legend counts are tallied BEFORE the cap: the chips must stay true
        // even for a truncated month ('Menampilkan 500 dari N agenda').
        $departments = [];

        foreach ($visible as $event) {
            $departments[$event['department']] = ($departments[$event['department']] ?? 0) + 1;
        }

        // The cap runs AFTER the permission filter, so events a caller may see
        // are never crowded out of the 500 by events they may not.
        $data = array_map(static function (array $event): array {
            // The permission has done its filtering job; the client gets facts.
            unset($event['permission']);

            return $event;
        }, array_slice($visible, 0, CalendarEvents::MAX_EVENTS));

        return $this->ok($data, null, [
            'month' => $month,
            'as_of' => $today->toDateString(),
            // (object) so an empty month serialises as {} — the legend
            // iterates Object.entries either way.
            'departments' => (object) $departments,
            'count' => count($data),
            'total' => count($visible),
            // Capped is true for BOTH loss shapes: the 500-event merge cap and
            // a single source that filled its own per-source limit (whose tail
            // was dropped before the merge, so total alone can't reveal it).
            // Tanpa ini bulan yang terpotong berkata "semua sumber terbaca"
            // sementara hari-hari penagihan terpadatnya kosong.
            'capped' => count($visible) > CalendarEvents::MAX_EVENTS || $window['truncated'] !== [],
            'checked' => $window['checked'],
            // Mid-migration is normal in this repository, not an alarm.
            'skipped' => count($window['skipped']),
            'truncated_sources' => count($window['truncated']),
        ]);
    }
}
