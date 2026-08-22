<?php

namespace Tests\Unit\Projects;

use LogicException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Tests\ErpTestCase;

/**
 * generateWbsFromBoq(): BOQ sections become parent tasks, BOQ items become
 * leaves weighted by cost share (amount / boq total * 100). The LAST leaf
 * absorbs the rounding residue, so leaf weights sum to exactly 100.0000.
 */
class WbsGenerationTest extends ErpTestCase
{
    use ProjectsFixtures;

    public function test_leaf_weights_sum_to_exactly_one_hundred_when_the_split_is_not_exact(): void
    {
        $project = $this->makeProject();
        // Tiga pekerjaan senilai 100.000 masing-masing: 1/3 tidak habis dibagi.
        $boq = $this->makeBoqWithAmounts([
            'A' => ['A.1' => 100000, 'A.2' => 100000],
            'B' => ['B.1' => 100000],
        ]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);

        $weights = $project->wbsTasks()->whereNotNull('parent_id')->orderBy('wbs_code')
            ->pluck('weight_pct')->map(fn ($weight): float => (float) $weight)->all();

        // 100.000 / 300.000 * 100 = 33,3333 (dibulatkan 4 desimal) untuk dua daun pertama,
        // daun terakhir menyerap sisa: 100 - 66,6666 = 33,3334
        $this->assertSame([33.3333, 33.3333, 33.3334], $weights);
        $this->assertSame(100.0, $this->leafWeightTotal($project));
    }

    public function test_leaf_weights_are_the_cost_share_of_each_boq_item(): void
    {
        $project = $this->makeProject();
        $boq = $this->makeBoqWithAmounts([
            'A' => ['A.1' => 25000000],
            'B' => ['B.1' => 122397000, 'B.2' => 21250000],
        ]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);

        $weights = $project->wbsTasks()->whereNotNull('parent_id')->orderBy('wbs_code')
            ->pluck('weight_pct')->map(fn ($weight): float => (float) $weight)->all();

        // total 168.647.000
        //  25.000.000 / 168.647.000 * 100 = 14,823887... -> 14,8239
        // 122.397.000 / 168.647.000 * 100 = 72,575935... -> 72,5759
        // daun terakhir = 100 - 87,3998 = 12,6002 (hitungan naif 12,6003 -> beda 0,0001)
        $this->assertSame([14.8239, 72.5759, 12.6002], $weights);
        $this->assertSame(100.0, $this->leafWeightTotal($project));
    }

    public function test_a_parent_weight_is_the_sum_of_its_children(): void
    {
        $project = $this->makeProject();
        $boq = $this->makeBoqWithAmounts([
            'A' => ['A.1' => 100000, 'A.2' => 100000],
            'B' => ['B.1' => 100000],
        ]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);

        $parents = $project->wbsTasks()->whereNull('parent_id')->orderBy('wbs_code')
            ->pluck('weight_pct', 'wbs_code')->map(fn ($weight): float => (float) $weight)->all();

        // A: 33,3333 + 33,3333 = 66,6666 ; B: 33,3334
        $this->assertSame(['A' => 66.6666, 'B' => 33.3334], $parents);
        // Bobot induk juga berjumlah 100.
        $this->assertSame(100.0, round((float) $project->wbsTasks()->whereNull('parent_id')->sum('weight_pct'), 4));
    }

    public function test_sections_become_parents_and_items_become_leaves(): void
    {
        $project = $this->makeProject();
        $boq = $this->makeBoqWithAmounts([
            'A' => ['A.1' => 100000, 'A.2' => 100000],
            'B' => ['B.1' => 100000],
        ]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);

        $this->assertSame(5, $project->wbsTasks()->count()); // 2 induk + 3 daun
        $this->assertSame(2, $project->wbsTasks()->whereNull('parent_id')->count());
        $this->assertSame(3, $project->wbsTasks()->whereNotNull('parent_id')->count());

        /** @var WbsTask $parent */
        $parent = $project->wbsTasks()->where('wbs_code', 'A')->firstOrFail();
        $this->assertSame('Seksi A', $parent->name);
        $this->assertNull($parent->boq_item_id);
        $this->assertSame(2, $parent->children()->count());

        /** @var WbsTask $leaf */
        $leaf = $project->wbsTasks()->where('wbs_code', 'A.1')->firstOrFail();
        $this->assertSame($parent->id, (int) $leaf->parent_id);
        $this->assertSame('Pekerjaan A.1', $leaf->name);
        $this->assertSame(
            (int) $boq->items()->where('wbs_code', 'A.1')->firstOrFail()->id,
            (int) $leaf->boq_item_id,
        );
    }

    public function test_leaf_tasks_inherit_the_project_planned_dates(): void
    {
        $project = $this->makeProject(['start_date' => '2026-03-01', 'end_date' => '2026-11-30']);
        $boq = $this->makeBoqWithAmounts(['A' => ['A.1' => 100000]]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);

        /** @var WbsTask $leaf */
        $leaf = $project->wbsTasks()->where('wbs_code', 'A.1')->firstOrFail();

        $this->assertSame('2026-03-01', $leaf->planned_start->toDateString());
        $this->assertSame('2026-11-30', $leaf->planned_end->toDateString());
        $this->assertSame(0.0, (float) $leaf->progress_pct);
    }

    public function test_generating_links_the_boq_and_resets_the_project_progress(): void
    {
        $project = $this->makeProject(['actual_progress_pct' => 42.5]);
        $boq = $this->makeBoqWithAmounts(['A' => ['A.1' => 100000]]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);

        $fresh = Project::query()->findOrFail($project->id);

        $this->assertSame($boq->id, (int) $fresh->boq_id);
        $this->assertSame(0.0, (float) $fresh->actual_progress_pct);
    }

    public function test_regenerating_replaces_the_previous_wbs_instead_of_duplicating_it(): void
    {
        $project = $this->makeProject();
        $boq = $this->makeBoqWithAmounts([
            'A' => ['A.1' => 100000, 'A.2' => 100000],
            'B' => ['B.1' => 100000],
        ]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);
        $firstIds = $project->wbsTasks()->pluck('id')->all();

        $this->projects()->generateWbsFromBoq($project->refresh(), $boq->id);
        $secondIds = $project->wbsTasks()->pluck('id')->all();

        $this->assertSame(5, $project->wbsTasks()->count());
        $this->assertSame(5, WbsTask::query()->count());
        $this->assertSame([], array_intersect($firstIds, $secondIds));
        $this->assertSame(100.0, $this->leafWeightTotal($project));
    }

    public function test_regenerating_from_a_different_boq_reweights_everything(): void
    {
        $project = $this->makeProject();
        $first = $this->makeBoqWithAmounts(['A' => ['A.1' => 100000, 'A.2' => 100000]]);
        $second = $this->makeBoqWithAmounts(['A' => ['A.1' => 750000, 'A.2' => 250000]]);

        $this->projects()->generateWbsFromBoq($project, $first->id);
        $this->projects()->generateWbsFromBoq($project->refresh(), $second->id);

        $weights = $project->wbsTasks()->whereNotNull('parent_id')->orderBy('wbs_code')
            ->pluck('weight_pct')->map(fn ($weight): float => (float) $weight)->all();

        // 750.000 / 1.000.000 = 75% ; daun terakhir menyerap sisa 25%
        $this->assertSame([75.0, 25.0], $weights);
    }

    public function test_a_single_item_boq_puts_the_whole_weight_on_one_leaf(): void
    {
        $project = $this->makeProject();
        $boq = $this->makeBoqWithAmounts(['A' => ['A.1' => 123456789]]);

        $this->projects()->generateWbsFromBoq($project, $boq->id);

        $this->assertSame(100.0, (float) $project->wbsTasks()->where('wbs_code', 'A.1')->firstOrFail()->weight_pct);
        $this->assertSame(100.0, (float) $project->wbsTasks()->where('wbs_code', 'A')->firstOrFail()->weight_pct);
    }

    public function test_generating_without_any_boq_throws_and_creates_no_tasks(): void
    {
        $project = $this->makeProject();

        try {
            $this->projects()->generateWbsFromBoq($project);
            $this->fail('Expected LogicException when no BOQ is linked.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('No BOQ found', $e->getMessage());
        }

        $this->assertSame(0, WbsTask::query()->count());
    }

    public function test_generating_from_a_zero_priced_boq_throws_and_keeps_the_old_wbs(): void
    {
        $project = $this->makeProject();
        $priced = $this->makeBoqWithAmounts(['A' => ['A.1' => 100000]]);
        $this->projects()->generateWbsFromBoq($project, $priced->id);

        $free = $this->makeBoqWithAmounts(['A' => ['A.1' => 0, 'A.2' => 0]]);

        try {
            $this->projects()->generateWbsFromBoq($project->refresh(), $free->id);
            $this->fail('Expected LogicException for a BOQ without priced items.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('no priced items', $e->getMessage());
        }

        // WBS lama masih utuh: 1 induk + 1 daun berbobot 100.
        $this->assertSame(2, $project->wbsTasks()->count());
        $this->assertSame(100.0, $this->leafWeightTotal($project));
    }

    public function test_generating_from_an_empty_boq_throws(): void
    {
        $project = $this->makeProject();
        $boq = $this->boqs()->create(['title' => 'RAB kosong', 'sections' => []]);

        $this->expectException(LogicException::class);

        $this->projects()->generateWbsFromBoq($project, $boq->id);
    }

    public function test_the_boq_is_resolved_from_the_project_link_when_no_id_is_given(): void
    {
        $boq = $this->makeBoqWithAmounts(['A' => ['A.1' => 100000, 'A.2' => 300000]]);
        $project = $this->makeProject(['boq_id' => $boq->id]);

        $this->projects()->generateWbsFromBoq($project);

        $weights = $project->wbsTasks()->whereNotNull('parent_id')->orderBy('wbs_code')
            ->pluck('weight_pct')->map(fn ($weight): float => (float) $weight)->all();

        // 100.000 / 400.000 = 25% ; sisa 75%
        $this->assertSame([25.0, 75.0], $weights);
    }
}
