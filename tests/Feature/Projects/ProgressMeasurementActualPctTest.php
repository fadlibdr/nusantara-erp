<?php

namespace Tests\Feature\Projects;

use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Models\WeeklyProgress;
use Modules\Projects\Services\BaselineService;
use Modules\Projects\Services\EvmService;
use Modules\Projects\Services\MeasurementService;
use Modules\Projects\Services\ProgressService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P3 — an APPROVED opname becomes the project's realisasi, and the row says so.
 *
 * The rule has two halves and the second is the one that keeps the first
 * honest: a week an opname covers carries the value-weighted measurement, a
 * week it does not keeps the supervisor's typed percentage, and
 * actual_pct_source tells the reader which of the two he is looking at. A curve
 * that silently mixes a measurement with an estimate is exactly the
 * plausible-looking cell PANDUAN §13.5 refuses to print.
 *
 * The arithmetic is deliberately checkable in the head. The BOQ is
 * Rp 1.000.000.000: A.1 galian 1.000 m3 x Rp 200.000 (20 % of the value) and
 * A.2 beton 500 m3 x Rp 1.600.000 (80 %). An opname of 500 m3 galian +
 * 100 m3 beton measures Rp 100.000.000 + Rp 160.000.000 = Rp 260.000.000, so
 * the project is 26,0000 % complete BY VALUE — while being 50 % and 20 % done
 * by volume on the two items, which is precisely why volume-weighting would be
 * the wrong answer.
 */
class ProgressMeasurementActualPctTest extends ErpTestCase
{
    use BaselineFixtures;
    use OpnameFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedOpnameWorld();
    }

    private function approvedOpname(array $lines, string $end = '2026-06-30'): ProgressMeasurement
    {
        $service = app(MeasurementService::class);

        $opname = $service->create([
            'project_id' => $this->project->id,
            'period_start' => '2026-06-01',
            'period_end' => $end,
            'items' => $lines,
        ]);

        $opname->submit($this->userWith('prj.create', 'Pengukur'));

        return $service->approve($opname, $this->userWith('prj.approve', 'Manajer Proyek'));
    }

    private function week(int $no, string $end, float $planned, float $actual): WeeklyProgress
    {
        return app(ProgressService::class)->recordWeekly([
            'project_id' => $this->project->id,
            'week_no' => $no,
            'period_start' => '2026-06-01',
            'period_end' => $end,
            'planned_pct' => $planned,
            'actual_pct' => $actual,
        ]);
    }

    public function test_a_week_without_an_opname_keeps_the_typed_percentage_and_says_so(): void
    {
        $week = $this->week(1, '2026-06-30', 30, 27.5);

        $this->assertSame('27.5000', $week->actual_pct);
        $this->assertSame(WeeklyProgress::SOURCE_WEEKLY, $week->actual_pct_source);
    }

    public function test_an_approved_opname_replaces_the_typed_percentage_and_relabels_the_row(): void
    {
        $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);

        // The supervisor still types 62 %; the measurement says 26 %.
        $week = $this->week(1, '2026-06-30', 30, 62);

        $this->assertSame('26.0000', $week->actual_pct);
        $this->assertSame(WeeklyProgress::SOURCE_MEASUREMENT, $week->actual_pct_source);
        $this->assertSame('-4.0000', $week->deviation_pct);
    }

    public function test_an_opname_that_is_not_approved_leaves_the_typed_percentage_alone(): void
    {
        $service = app(MeasurementService::class);
        $opname = $service->create([
            'project_id' => $this->project->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'items' => [$this->line('A.1', 500), $this->line('A.2', 100)],
        ]);
        $opname->submit($this->userWith('prj.create', 'Pengukur')); // submitted, not approved

        $week = $this->week(1, '2026-06-30', 30, 62);

        $this->assertSame('62.0000', $week->actual_pct);
        $this->assertSame(WeeklyProgress::SOURCE_WEEKLY, $week->actual_pct_source);
    }

    public function test_approving_an_opname_rewrites_the_weeks_it_covers_and_leaves_earlier_weeks_alone(): void
    {
        $earlier = $this->week(1, '2026-05-31', 10, 9);
        $covered = $this->week(2, '2026-06-30', 30, 62);

        $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);

        // Week 1 ends BEFORE the opname's period: no measurement covers it.
        $this->assertSame('9.0000', $earlier->refresh()->actual_pct);
        $this->assertSame(WeeklyProgress::SOURCE_WEEKLY, $earlier->actual_pct_source);

        $this->assertSame('26.0000', $covered->refresh()->actual_pct);
        $this->assertSame(WeeklyProgress::SOURCE_MEASUREMENT, $covered->actual_pct_source);
    }

    public function test_a_second_opname_moves_the_percentage_forward_cumulatively(): void
    {
        $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);
        $this->approvedOpname([$this->line('A.1', 200)], '2026-07-31'); // + Rp 40.000.000

        $week = $this->week(3, '2026-07-31', 40, 0);

        $this->assertSame('30.0000', $week->actual_pct);
        $this->assertSame(WeeklyProgress::SOURCE_MEASUREMENT, $week->actual_pct_source);
    }

    /**
     * The label the SPA and the print layer read is the curve's, not the row's,
     * and it has to tell the same truth.
     */
    public function test_the_evm_curve_reports_the_source_its_last_week_actually_used(): void
    {
        $this->seedWbs($this->project, [
            ['A.1', 20.0, '2026-02-02', '2026-06-30', 50],
            ['A.2', 80.0, '2026-03-02', '2027-06-30', 20],
        ]);
        $this->makeRap($this->project);

        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');
        $baseline = app(BaselineService::class)->snapshot($this->project->refresh(), ['effective_date' => '2026-02-02'], $maker);
        app(BaselineService::class)->submit($baseline, $maker);
        app(BaselineService::class)->approve($baseline, $checker);

        $this->week(1, '2026-06-30', 30, 62);

        $report = app(EvmService::class)->report($this->project->refresh(), '2026-07-01');
        $this->assertSame(WeeklyProgress::SOURCE_WEEKLY, $report['curve']['actual_pct_source']);

        $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);

        $report = app(EvmService::class)->report($this->project->refresh(), '2026-07-01');
        $this->assertSame(WeeklyProgress::SOURCE_MEASUREMENT, $report['curve']['actual_pct_source']);
    }

    /**
     * The header rollup is a DIFFERENT measurement basis (frozen WBS weights)
     * and must not be quietly overwritten by the opname — two numbers that mean
     * different things may not be made to say the same thing.
     */
    public function test_the_project_header_rollup_is_left_to_the_wbs(): void
    {
        $this->seedWbs($this->project, [
            ['A.1', 20.0, '2026-02-02', '2026-06-30', 50],
            ['A.2', 80.0, '2026-03-02', '2027-06-30', 20],
        ]);

        $before = round((float) $this->project->refresh()->actual_progress_pct, 4);

        $this->approvedOpname([$this->line('A.1', 500), $this->line('A.2', 100)]);

        $this->assertSame($before, round((float) $this->project->refresh()->actual_progress_pct, 4));
    }
}
