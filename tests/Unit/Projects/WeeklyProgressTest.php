<?php

namespace Tests\Unit\Projects;

use Modules\Projects\Models\Project;
use Modules\Projects\Models\WeeklyProgress;
use Tests\ErpTestCase;

/**
 * Laporan mingguan / kurva-S:
 *
 *   one row per (project, week_no), upserted
 *   deviation_pct = actual_pct - planned_pct
 *   the project header planned percentage follows the LATEST reported week
 */
class WeeklyProgressTest extends ErpTestCase
{
    use ProjectsFixtures;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    private function recordWeek(int $weekNo, float $planned, float $actual, array $extra = []): WeeklyProgress
    {
        return $this->progress()->recordWeekly(array_merge([
            'project_id' => $this->project->id,
            'week_no' => $weekNo,
            'period_start' => '2026-02-'.str_pad((string) (2 + ($weekNo - 1) * 7), 2, '0', STR_PAD_LEFT),
            'period_end' => '2026-02-'.str_pad((string) (8 + ($weekNo - 1) * 7), 2, '0', STR_PAD_LEFT),
            'planned_pct' => $planned,
            'actual_pct' => $actual,
        ], $extra));
    }

    private function headerPlanned(): float
    {
        return (float) Project::query()->findOrFail($this->project->id)->planned_progress_pct;
    }

    // ---------------------------------------------------------------- deviation

    public function test_deviation_is_actual_minus_planned_when_behind_schedule(): void
    {
        $week = $this->recordWeek(1, 25.5, 22.75);

        // 22,75 - 25,5 = -2,75 (terlambat)
        $this->assertSame(-2.75, (float) $week->deviation_pct);
    }

    public function test_deviation_is_positive_when_ahead_of_schedule(): void
    {
        $week = $this->recordWeek(1, 20, 26.4);

        // 26,4 - 20 = 6,4 (lebih cepat)
        $this->assertSame(6.4, (float) $week->deviation_pct);
    }

    public function test_deviation_is_zero_when_exactly_on_plan(): void
    {
        $week = $this->recordWeek(1, 37.5, 37.5);

        $this->assertSame(0.0, (float) $week->deviation_pct);
    }

    public function test_percentages_and_deviation_are_rounded_to_four_decimals(): void
    {
        $week = $this->recordWeek(1, 33.987654, 30.123456);

        // 33,987654 -> 33,9877 ; 30,123456 -> 30,1235 ; selisih 30,1235 - 33,9877 = -3,8642
        $this->assertSame(33.9877, (float) $week->planned_pct);
        $this->assertSame(30.1235, (float) $week->actual_pct);
        $this->assertSame(-3.8642, (float) $week->deviation_pct);
    }

    // ------------------------------------------------------------------- upsert

    public function test_recording_the_same_week_twice_updates_the_row_in_place(): void
    {
        $first = $this->recordWeek(1, 10, 8);
        $second = $this->recordWeek(1, 10, 9.5);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WeeklyProgress::query()->count());
        $this->assertSame(9.5, (float) $second->refresh()->actual_pct);
        // 9,5 - 10 = -0,5
        $this->assertSame(-0.5, (float) $second->deviation_pct);
    }

    public function test_different_weeks_produce_separate_rows(): void
    {
        $this->recordWeek(1, 10, 8);
        $this->recordWeek(2, 25, 22);
        $this->recordWeek(3, 40, 41);

        $this->assertSame(3, WeeklyProgress::query()->count());
        $this->assertSame(
            [1, 2, 3],
            $this->project->weeklyProgress()->pluck('week_no')->map(fn ($no): int => (int) $no)->all(),
        );
    }

    public function test_the_same_week_number_on_another_project_is_a_separate_row(): void
    {
        $other = $this->makeProject(['name' => 'Proyek lain']);

        $this->recordWeek(1, 10, 8);
        $this->progress()->recordWeekly([
            'project_id' => $other->id,
            'week_no' => 1,
            'period_start' => '2026-02-02',
            'period_end' => '2026-02-08',
            'planned_pct' => 30,
            'actual_pct' => 28,
        ]);

        $this->assertSame(2, WeeklyProgress::query()->count());
        $this->assertSame(10.0, $this->headerPlanned());
        $this->assertSame(30.0, (float) Project::query()->findOrFail($other->id)->planned_progress_pct);
    }

    public function test_the_period_and_notes_are_stored_verbatim(): void
    {
        $week = $this->recordWeek(1, 10, 8, ['notes' => 'Hujan tiga hari, pengecoran mundur.']);

        $this->assertSame('2026-02-02', $week->period_start->toDateString());
        $this->assertSame('2026-02-08', $week->period_end->toDateString());
        $this->assertSame('Hujan tiga hari, pengecoran mundur.', $week->notes);
    }

    // -------------------------------------------------- the project header follows

    public function test_the_only_reported_week_sets_the_project_planned_percentage(): void
    {
        $this->recordWeek(1, 10, 8);

        $this->assertSame(10.0, $this->headerPlanned());
    }

    public function test_correcting_that_week_moves_the_project_planned_percentage_with_it(): void
    {
        $this->recordWeek(1, 10, 8);
        $this->recordWeek(1, 12.5, 8);

        $this->assertSame(12.5, $this->headerPlanned());
    }

    public function test_only_the_latest_week_updates_the_project_planned_percentage(): void
    {
        $this->recordWeek(1, 10, 8);
        $this->recordWeek(2, 25, 22);

        // Minggu terakhir yang dilaporkan adalah minggu 2 -> header ikut 25.
        $this->assertSame(25.0, $this->headerPlanned());

        // Mengoreksi minggu 1 tidak boleh menarik header mundur.
        $this->recordWeek(1, 11, 9);
        $this->assertSame(25.0, $this->headerPlanned());
    }

    // ------------------------------------------------------------------ s-curve

    public function test_the_s_curve_lists_every_week_in_order_with_its_deviation(): void
    {
        $this->recordWeek(2, 25, 22);
        $this->recordWeek(1, 10, 8);
        $this->recordWeek(3, 40, 41);

        $curve = $this->progress()->sCurveData($this->project->refresh());

        $this->assertSame([1, 2, 3], array_column($curve['weeks'], 'week_no'));
        // 8 - 10 = -2 ; 22 - 25 = -3 ; 41 - 40 = 1
        $this->assertSame([-2.0, -3.0, 1.0], array_column($curve['weeks'], 'deviation_pct'));
        $this->assertSame($this->project->id, $curve['project_id']);
    }

    public function test_the_project_deviation_helper_is_actual_minus_planned(): void
    {
        $project = $this->makeProject([
            'planned_progress_pct' => 42.5,
            'actual_progress_pct' => 38.25,
        ]);

        // 38,25 - 42,5 = -4,25
        $this->assertSame(-4.25, $project->progressDeviation());
    }
}
