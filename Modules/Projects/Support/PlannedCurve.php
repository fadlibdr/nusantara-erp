<?php

namespace Modules\Projects\Support;

use Carbon\CarbonImmutable;

/**
 * The ONE implementation of the planned-curve maths.
 *
 * Same reason Modules\Finance\Support\MeasuredPeriods gives for existing: two
 * implementations of the same arithmetic eventually disagree, and the month
 * they disagreed about is the one nobody can explain. The baseline's stored
 * monthly points, the planned value at an arbitrary as_of and the chart all
 * come through here.
 *
 * The shape is not a fitted S — nothing is invented. Each leaf's weight_pct is
 * spread linearly across its own planned_start..planned_end window and the
 * windows are summed, so the curve is a straight consequence of the plan the
 * WBS already states. On PRJ-2026-001 that still produces a real S: 3,29% at
 * end-Feb-2026, 16,11% at end-Mar, 61,34% at end-Jul-2026, 92,92% at end-Oct
 * and 100% at 30-06-2027, because the two heavy structural packages (B.2 at
 * 28,03% and B.3 at 37,00%) overlap in the middle of the programme.
 *
 * Pure functions, no database.
 */
class PlannedCurve
{
    /**
     * Cumulative planned percentage at a date, from the frozen leaf tasks.
     *
     * Day counting is INCLUSIVE at both ends, so a one-day task is 100% on its
     * single day instead of dividing by zero.
     *
     * @param  iterable<int, mixed>  $tasks  leaf rows carrying weight_pct, planned_start, planned_end
     */
    public static function cumulativePct(iterable $tasks, string $asOf): float
    {
        $asOf = self::day($asOf);
        $total = 0.0;

        foreach (self::normalise($tasks) as $task) {
            $total += $task['weight_pct'] * self::elapsedFraction($task, $asOf);
        }

        return round($total, 4);
    }

    /**
     * One point per calendar month-end, from the month containing the earliest
     * planned_start to the month containing the latest planned_end.
     *
     * Monthly rather than weekly so the samples line up with the PSAK 115 run's
     * period (year/month) and stay small — PRJ-2026-001 yields 17 points rather
     * than roughly 78 weeks.
     *
     * @param  iterable<int, mixed>  $tasks
     * @return list<array{seq: int, period_end: string, planned_pct: float, planned_value: float}>
     */
    public static function monthlyPoints(iterable $tasks, float $bac): array
    {
        $tasks = self::normalise($tasks);

        if ($tasks === []) {
            return [];
        }

        $first = CarbonImmutable::parse(min(array_column($tasks, 'planned_start')))->endOfMonth();
        $last = CarbonImmutable::parse(max(array_column($tasks, 'planned_end')))->endOfMonth();

        $points = [];
        $seq = 0;
        $cursor = $first;

        while ($cursor->lessThanOrEqualTo($last)) {
            $seq++;
            $pct = self::cumulativePct($tasks, $cursor->toDateString());

            $points[] = [
                'seq' => $seq,
                'period_end' => $cursor->toDateString(),
                'planned_pct' => $pct,
                'planned_value' => round($pct / 100 * $bac, 2),
            ];

            $cursor = $cursor->startOfMonth()->addMonth()->endOfMonth();
        }

        return $points;
    }

    /**
     * Earliest planned_start and latest planned_end across the tasks.
     *
     * @param  iterable<int, mixed>  $tasks
     * @return array{0: string|null, 1: string|null}
     */
    public static function window(iterable $tasks): array
    {
        $tasks = self::normalise($tasks);

        if ($tasks === []) {
            return [null, null];
        }

        return [min(array_column($tasks, 'planned_start')), max(array_column($tasks, 'planned_end'))];
    }

    /**
     * @param  array{weight_pct: float, planned_start: string, planned_end: string}  $task
     */
    private static function elapsedFraction(array $task, string $asOf): float
    {
        $start = CarbonImmutable::parse($task['planned_start']);
        $end = CarbonImmutable::parse($task['planned_end']);

        $span = max(1, (int) $start->diffInDays($end) + 1);
        $elapsed = (int) $start->diffInDays(CarbonImmutable::parse($asOf), false) + 1;

        return max(0.0, min(1.0, $elapsed / $span));
    }

    /**
     * Every date in this class goes through Carbon, NEVER a raw string compare.
     *
     * SQLite stores prj_wbs_tasks.planned_end as '2027-06-30 00:00:00', and
     * '2027-06-30 00:00:00' <= '2027-06-30' is FALSE as a string — which would
     * silently drop the last day of every work package and leave the curve
     * short of 100% at the very date it must reach it.
     *
     * @param  iterable<int, mixed>  $tasks
     * @return list<array{weight_pct: float, planned_start: string, planned_end: string}>
     */
    private static function normalise(iterable $tasks): array
    {
        $rows = [];

        foreach ($tasks as $task) {
            $start = data_get($task, 'planned_start');
            $end = data_get($task, 'planned_end');

            if ($start === null || $end === null) {
                continue;
            }

            $rows[] = [
                'weight_pct' => (float) data_get($task, 'weight_pct', 0),
                'planned_start' => self::day($start),
                'planned_end' => self::day($end),
            ];
        }

        return $rows;
    }

    private static function day(mixed $value): string
    {
        return CarbonImmutable::parse((string) (is_object($value) && method_exists($value, 'toDateString')
            ? $value->toDateString()
            : $value))->toDateString();
    }
}
