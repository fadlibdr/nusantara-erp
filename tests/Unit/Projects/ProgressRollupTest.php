<?php

namespace Tests\Unit\Projects;

use LogicException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Tests\ErpTestCase;

/**
 * Progress roll-up:
 *
 *   parent progress  = sum(child progress * child weight) / sum(child weight)
 *   project actual   = sum(leaf progress * leaf weight / 100)
 *
 * Progress is entered on leaves only, and is clamped to 0..100.
 */
class ProgressRollupTest extends ErpTestCase
{
    use ProjectsFixtures;

    private Project $project;

    /** @var array<string, WbsTask> */
    private array $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();

        // Bobot daun sengaja tidak rata: 40 + 20 + 25 + 15 = 100
        $this->tasks = $this->makeWbsTree($this->project, [
            'A' => ['A.1' => 40.0, 'A.2' => 20.0],
            'B' => ['B.1' => 25.0, 'B.2' => 15.0],
        ]);
    }

    private function progressOf(string $code): float
    {
        return (float) $this->project->wbsTasks()->where('wbs_code', $code)->firstOrFail()->progress_pct;
    }

    private function projectActual(): float
    {
        return (float) Project::query()->findOrFail($this->project->id)->actual_progress_pct;
    }

    public function test_the_fixture_tree_starts_at_zero_with_leaf_weights_summing_to_one_hundred(): void
    {
        $this->assertSame(100.0, $this->leafWeightTotal($this->project));
        $this->assertSame(60.0, (float) $this->tasks['A']->weight_pct);
        $this->assertSame(40.0, (float) $this->tasks['B']->weight_pct);
        $this->assertSame(0.0, $this->projectActual());
    }

    public function test_progress_on_one_leaf_rolls_up_to_its_parent_as_a_weighted_average(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 50);

        // A = (50 x 40 + 0 x 20) / (40 + 20) = 2.000 / 60 = 33,3333
        $this->assertSame(33.3333, $this->progressOf('A'));
        // Cabang lain tidak tersentuh.
        $this->assertSame(0.0, $this->progressOf('B'));
    }

    public function test_the_project_actual_is_the_weighted_sum_of_the_leaves(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 50);

        // 50 x 40 / 100 = 20
        $this->assertSame(20.0, $this->projectActual());

        $this->progress()->updateTaskProgress($this->tasks['A.2'], 100);

        // A = (50 x 40 + 100 x 20) / 60 = 4.000 / 60 = 66,6667
        $this->assertSame(66.6667, $this->progressOf('A'));
        // proyek = 20 + (100 x 20 / 100) = 40
        $this->assertSame(40.0, $this->projectActual());

        $this->progress()->updateTaskProgress($this->tasks['B.1'], 80);

        // B = (80 x 25 + 0 x 15) / 40 = 2.000 / 40 = 50
        $this->assertSame(50.0, $this->progressOf('B'));
        // proyek = 40 + (80 x 25 / 100) = 60
        $this->assertSame(60.0, $this->projectActual());
    }

    public function test_completing_every_leaf_puts_the_project_at_one_hundred(): void
    {
        foreach (['A.1', 'A.2', 'B.1', 'B.2'] as $code) {
            $this->progress()->updateTaskProgress($this->tasks[$code], 100);
        }

        $this->assertSame(100.0, $this->progressOf('A'));
        $this->assertSame(100.0, $this->progressOf('B'));
        $this->assertSame(100.0, $this->projectActual());
    }

    public function test_roll_up_walks_the_whole_ancestor_chain_on_a_three_level_tree(): void
    {
        $project = $this->makeProject(['name' => 'Proyek tiga tingkat']);

        $root = $project->wbsTasks()->create(['wbs_code' => 'R', 'name' => 'Total', 'weight_pct' => 100, 'sort_order' => 1]);
        $c1 = $project->wbsTasks()->create(['parent_id' => $root->id, 'wbs_code' => 'R.1', 'name' => 'Struktur', 'weight_pct' => 60, 'sort_order' => 1]);
        $c2 = $project->wbsTasks()->create(['parent_id' => $root->id, 'wbs_code' => 'R.2', 'name' => 'Arsitektur', 'weight_pct' => 40, 'sort_order' => 2]);
        $l1 = $project->wbsTasks()->create(['parent_id' => $c1->id, 'wbs_code' => 'R.1.1', 'name' => 'Pondasi', 'weight_pct' => 36, 'sort_order' => 1]);
        $project->wbsTasks()->create(['parent_id' => $c1->id, 'wbs_code' => 'R.1.2', 'name' => 'Kolom', 'weight_pct' => 24, 'sort_order' => 2]);

        // Bobot daun: 36 + 24 + 40 = 100
        $this->assertSame(100.0, $this->leafWeightTotal($project));

        $this->progress()->updateTaskProgress($l1, 100);

        // R.1 = (100 x 36 + 0 x 24) / 60 = 3.600 / 60 = 60
        $this->assertSame(60.0, (float) $c1->refresh()->progress_pct);
        // R   = (60 x 60 + 0 x 40) / 100 = 36
        $this->assertSame(36.0, (float) $root->refresh()->progress_pct);
        // proyek = 100 x 36 / 100 = 36
        $this->assertSame(36.0, (float) Project::query()->findOrFail($project->id)->actual_progress_pct);

        $this->progress()->updateTaskProgress($c2, 50);

        // R = (60 x 60 + 50 x 40) / 100 = (3.600 + 2.000) / 100 = 56
        $this->assertSame(56.0, (float) $root->refresh()->progress_pct);
        // proyek = 36 + 50 x 40 / 100 = 56
        $this->assertSame(56.0, (float) Project::query()->findOrFail($project->id)->actual_progress_pct);
    }

    // ------------------------------------------------------------------ guards

    public function test_entering_progress_on_a_task_with_children_throws_and_changes_nothing(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 50);

        try {
            $this->progress()->updateTaskProgress($this->tasks['A'], 95);
            $this->fail('Expected LogicException when reporting on a parent task.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('progress is entered on leaf tasks', $e->getMessage());
        }

        // A tetap hasil roll-up 33,3333, bukan 95.
        $this->assertSame(33.3333, $this->progressOf('A'));
        $this->assertSame(20.0, $this->projectActual());
    }

    public function test_progress_above_one_hundred_is_clamped(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 150);

        $this->assertSame(100.0, $this->progressOf('A.1'));
        // proyek = 100 x 40 / 100 = 40
        $this->assertSame(40.0, $this->projectActual());
    }

    public function test_negative_progress_is_clamped_to_zero(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 60);
        $this->progress()->updateTaskProgress($this->tasks['A.1']->refresh(), -25);

        $this->assertSame(0.0, $this->progressOf('A.1'));
        $this->assertSame(0.0, $this->projectActual());
    }

    public function test_progress_is_stored_with_four_decimals(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 33.987654);

        // dibulatkan ke 4 desimal: 33,9877
        $this->assertSame(33.9877, $this->progressOf('A.1'));
        // proyek = 33,9877 x 40 / 100 = 13,59508 -> 13,5951
        $this->assertSame(13.5951, $this->projectActual());
    }

    // -------------------------------------------------------- actual date stamps

    public function test_starting_a_task_stamps_the_actual_start_date(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 10, '2026-03-02');

        /** @var WbsTask $task */
        $task = $this->tasks['A.1']->refresh();

        $this->assertSame('2026-03-02', $task->actual_start->toDateString());
        $this->assertNull($task->actual_end);
    }

    public function test_finishing_a_task_stamps_the_actual_end_date(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 100, '2026-03-02', '2026-04-15');

        /** @var WbsTask $task */
        $task = $this->tasks['A.1']->refresh();

        $this->assertSame('2026-03-02', $task->actual_start->toDateString());
        $this->assertSame('2026-04-15', $task->actual_end->toDateString());
    }

    public function test_a_task_left_at_zero_gets_no_start_date(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 0);

        $this->assertNull($this->tasks['A.1']->refresh()->actual_start);
    }

    public function test_the_first_reported_start_date_is_not_overwritten_by_later_reports(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 10, '2026-03-02');
        $this->progress()->updateTaskProgress($this->tasks['A.1']->refresh(), 60);

        $this->assertSame('2026-03-02', $this->tasks['A.1']->refresh()->actual_start->toDateString());
    }

    // ------------------------------------------------------------ bulk recompute

    public function test_recalc_rollups_repairs_a_tree_edited_behind_the_service(): void
    {
        // Someone updated the leaf rows directly, so parents and header are stale.
        WbsTask::query()->where('wbs_code', 'A.1')->update(['progress_pct' => 50]);
        WbsTask::query()->where('wbs_code', 'A.2')->update(['progress_pct' => 100]);
        WbsTask::query()->where('wbs_code', 'B.1')->update(['progress_pct' => 80]);

        $this->progress()->recalcWbsRollups($this->project);

        // A = (50 x 40 + 100 x 20) / 60 = 66,6667 ; B = (80 x 25 + 0 x 15) / 40 = 50
        $this->assertSame(66.6667, $this->progressOf('A'));
        $this->assertSame(50.0, $this->progressOf('B'));
        // proyek = 20 + 20 + 20 + 0 = 60
        $this->assertSame(60.0, $this->projectActual());
    }

    public function test_recalc_project_progress_repairs_a_drifted_header(): void
    {
        $this->progress()->updateTaskProgress($this->tasks['A.1'], 50);

        Project::query()->whereKey($this->project->id)->update(['actual_progress_pct' => 99]);

        $this->progress()->recalcProjectProgress($this->project->refresh());

        $this->assertSame(20.0, $this->projectActual());
    }
}
