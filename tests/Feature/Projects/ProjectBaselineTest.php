<?php

namespace Tests\Feature\Projects;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\BacSource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Services\BaselineService;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Baseline proyek — the frozen plan every EVM number is measured against.
 *
 * The property under test throughout is one sentence: a baseline that can be
 * quietly rewritten proves nothing. PRJ-2026-001 is the live illustration —
 * prj_projects.planned_progress_pct reads 2,0000 while its own latest weekly
 * row reads 62,0000, and ProgressService::recordWeekly can overwrite either at
 * any time. An extension-of-time claim built on a number like that is worth
 * nothing to an arbitrator, so what is guarded here is that an approved
 * baseline cannot be edited, cannot be deleted, cannot be re-snapshotted, and
 * cannot be superseded out of existence.
 */
class ProjectBaselineTest extends ErpTestCase
{
    use BaselineFixtures;

    private BaselineService $service;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->service = app(BaselineService::class);
        $this->project = $this->grahaProject();
    }

    /** Snapshot → submit → approve, by two different people. */
    private function freeze(array $data = []): ProjectBaseline
    {
        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');

        $baseline = $this->service->snapshot($this->project, array_merge([
            'effective_date' => '2026-02-02',
        ], $data), $maker);

        $this->service->submit($baseline, $maker);

        return $this->service->approve($baseline, $checker);
    }

    // ----------------------------------------------------------- what is BAC

    public function test_a_baseline_freezes_the_rap_total_as_the_budget_at_completion(): void
    {
        $this->makeRap($this->project, self::RAP_TOTAL, 'approved');

        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        $this->assertSame('42173913043.47', (string) $baseline->bac);
        $this->assertSame(BacSource::RapApproved, $baseline->bac_source);
        $this->assertSame(0, (int) $baseline->revision_no);
        $this->assertSame(DocumentStatus::Draft, $baseline->status);
        // The contract value and the two finish dates are frozen alongside it:
        // the WBS ends 30-06-2027 while the contract ends 31-07-2027, and an
        // EOT argument turns on exactly that gap.
        $this->assertSame('48500000000.00', (string) $baseline->contract_value);
        $this->assertSame('2027-06-30', $baseline->planned_finish->toDateString());
        $this->assertSame('2027-07-31', $baseline->contract_finish->toDateString());
        $this->assertSame(8, (int) $baseline->leaf_task_count);
    }

    /**
     * PRJ-2026-002 in the live data: no RAP row at all. This is where EVM and
     * PSAK 115 deliberately diverge — para 45 lets Finance keep recognising
     * revenue at zero margin without an estimate, but EV = physical% x BAC is
     * undefined without one, and substituting the contract value would turn CPI
     * into a margin ratio and mislabel it cost performance.
     */
    public function test_a_project_with_no_rap_at_all_cannot_be_baselined_because_there_is_no_budget_at_completion(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/belum punya RAP.*BAC.*tidak ada/s');

        $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);
    }

    /** A rejected budget is nobody's estimate of anything. */
    public function test_a_project_whose_only_rap_is_rejected_cannot_be_baselined(): void
    {
        $this->makeRap($this->project, self::RAP_TOTAL, 'rejected');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/belum punya RAP/');

        $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);
    }

    /**
     * The live demo's own case: RAP/2026/0001 is 'submitted'. Allowed, because
     * refusing it would leave the only project with a budget unbaselineable —
     * but flagged, exactly as the POC line flags eac_source.
     */
    public function test_a_baseline_taken_from_an_unapproved_rap_is_allowed_and_carries_the_warning(): void
    {
        $this->makeRap($this->project, self::RAP_TOTAL, 'submitted');

        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        $this->assertSame(BacSource::RapUnapproved, $baseline->bac_source);
        $this->assertSame('submitted', $baseline->cost_budget_status);
        $this->assertTrue($baseline->bac_source->isProvisional());
        $this->assertStringContainsString('belum disetujui', implode(' ', $baseline->warnings()));
    }

    public function test_an_explicit_budget_override_is_recorded_as_such_rather_than_attributed_to_a_rap(): void
    {
        $this->makeRap($this->project, self::RAP_TOTAL, 'submitted');

        $baseline = $this->service->snapshot($this->project, [
            'effective_date' => '2026-02-02',
            'bac_override' => 40_000_000_000,
        ]);

        $this->assertSame(BacSource::Override, $baseline->bac_source);
        $this->assertSame('40000000000.00', (string) $baseline->bac);
    }

    // ---------------------------------------------------------- plan quality

    public function test_a_baseline_is_refused_when_leaf_weights_do_not_sum_to_one_hundred(): void
    {
        $this->makeRap($this->project);
        $this->project->wbsTasks()->where('wbs_code', 'C.2')->update(['weight_pct' => 9.9]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Bobot tugas daun berjumlah .*bukan 100%/');

        $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);
    }

    public function test_a_baseline_is_refused_when_a_leaf_task_has_no_planned_dates(): void
    {
        $this->makeRap($this->project);
        $this->project->wbsTasks()->where('wbs_code', 'B.3')->update(['planned_end' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/B\.3 belum punya tanggal rencana/');

        $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);
    }

    // ------------------------------------------------------------- the curve

    /**
     * The EVM identity PV(finish) = BAC. It holds by construction because leaf
     * weights close on exactly 100,0000 and every window has ended by the last
     * planned_end — 30-06-2027 on this project.
     */
    public function test_the_frozen_curve_reaches_exactly_one_hundred_percent_on_the_last_planned_end_date(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        // reorder() first: points() carries a default ->orderBy('seq') asc and
        // a bare orderByDesc() would only be APPENDED to it, handing back the
        // FIRST point — the same trap ProgressService documents for week_no.
        $last = $baseline->points()->reorder()->orderByDesc('seq')->first();

        $this->assertSame('2027-06-30', $last->period_end->toDateString());
        $this->assertSame(100.0, round((float) $last->planned_pct, 4));
        $this->assertSame(round(self::RAP_TOTAL, 2), round((float) $last->planned_value, 2));
        $this->assertSame(100.0, $baseline->plannedPctAt('2027-06-30'));
    }

    public function test_the_frozen_curve_never_reports_progress_before_the_first_planned_start(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        $this->assertSame(0.0, $baseline->plannedPctAt('2026-02-01'));
        $this->assertSame(0.0, $baseline->plannedValueAt('2026-02-01'));
        // The first day is inclusive on both ends, so it is already earning.
        $this->assertGreaterThan(0.0, $baseline->plannedPctAt('2026-02-02'));
    }

    /**
     * The demo curve, month by month. These are the numbers the live site
     * draws, and they are a real S because B.2 (28,03%) and B.3 (37,00%)
     * overlap in the middle of the programme.
     */
    public function test_the_frozen_curve_reproduces_the_demo_dataset_month_by_month(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        $points = $baseline->points()->get()->keyBy(fn ($point): string => $point->period_end->toDateString());

        $this->assertSame(3.2874, round((float) $points['2026-02-28']->planned_pct, 4));
        $this->assertSame(16.1077, round((float) $points['2026-03-31']->planned_pct, 4));
        $this->assertSame(61.3353, round((float) $points['2026-07-31']->planned_pct, 4));
        $this->assertSame(92.9224, round((float) $points['2026-10-31']->planned_pct, 4));
        $this->assertCount(17, $points);
    }

    // ------------------------------------------------------------- lifecycle

    public function test_a_draft_baseline_can_be_edited_resnapshotted_and_deleted(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        $updated = $this->service->update($baseline, ['notes' => 'Menunggu tanda tangan MK.']);
        $this->assertSame('Menunggu tanda tangan MK.', $updated->notes);

        // A leaf moved after the draft was taken is picked up by resnapshot.
        $this->project->wbsTasks()->where('wbs_code', 'C.2')->update(['planned_end' => '2027-08-31']);
        $resnapshotted = $this->service->resnapshot($updated);
        $this->assertSame('2027-08-31', $resnapshotted->planned_finish->toDateString());

        $this->service->delete($resnapshotted);
        $this->assertDatabaseCount('prj_baselines', 0);
        $this->assertDatabaseCount('prj_baseline_tasks', 0);
        $this->assertDatabaseCount('prj_baseline_points', 0);
    }

    /**
     * SUBMITTED is out of the maker's reach too. A submitted baseline sits in
     * the approver's queue; a maker who can still resnapshot it there replaces
     * the entire frozen content AFTER submission, and the core_approvals trail
     * still reads submitted → approved — indistinguishable from an honest
     * review. Rejection is the one road back, exactly as every other document
     * lifecycle in this repo requires.
     */
    public function test_a_submitted_baseline_cannot_be_edited_resnapshotted_or_deleted_while_it_waits(): void
    {
        $this->makeRap($this->project);
        $maker = $this->userWith('prj.create', 'Perencana');

        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02'], $maker);
        $this->service->submit($baseline, $maker);

        foreach ([
            fn () => $this->service->update($baseline, ['notes' => 'Diubah diam-diam.']),
            fn () => $this->service->resnapshot($baseline),
            fn () => $this->service->delete($baseline),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail("A submitted baseline was modified in the approver's queue.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('sedang menunggu persetujuan', $e->getMessage());
            }
        }

        $this->assertSame(DocumentStatus::Submitted, $baseline->refresh()->status);
        $this->assertDatabaseCount('prj_baselines', 1);
    }

    /** Rejection hands it back: everything a draft can do, a rejected one can. */
    public function test_a_rejected_baseline_returns_to_the_maker_and_can_be_edited_again(): void
    {
        $this->makeRap($this->project);
        $maker = $this->userWith('prj.create', 'Perencana');
        $checker = $this->userWith('prj.approve', 'Direktur');

        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02'], $maker);
        $this->service->submit($baseline, $maker);
        $this->service->reject($baseline, $checker, 'Kurva belum disepakati MK.');

        $updated = $this->service->update($baseline, ['notes' => 'Kurva disesuaikan hasil rapat MK.']);
        $this->assertSame('Kurva disesuaikan hasil rapat MK.', $updated->notes);

        $this->project->wbsTasks()->where('wbs_code', 'C.2')->update(['planned_end' => '2027-08-31']);
        $this->assertSame('2027-08-31', $this->service->resnapshot($updated)->planned_finish->toDateString());
    }

    /**
     * bac_override on update() re-freezes the curve with the header. Rewriting
     * the header alone left the final frozen point reading 100% =
     * Rp 42.173.913.043,47 under a header claiming Rp 60.000.000.000 — the EVM
     * tile and the chart beside it disagreeing by Rp 17,8 miliar, and once
     * approved the contradiction was permanent.
     */
    public function test_a_bac_override_on_update_rewrites_the_frozen_curve_to_the_new_budget(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        $updated = $this->service->update($baseline, ['bac_override' => 60_000_000_000]);

        $this->assertSame('60000000000.00', (string) $updated->bac);
        $this->assertSame(BacSource::Override, $updated->bac_source);

        // reorder() first — see the curve test above for the seq-ordering trap.
        $last = $updated->points()->reorder()->orderByDesc('seq')->first();
        $this->assertSame(100.0, round((float) $last->planned_pct, 4));
        $this->assertSame(60000000000.0, round((float) $last->planned_value, 2));
        // Task rows were rewritten in the same transaction, so the header
        // counters and the frozen rows cannot disagree either.
        $this->assertSame((int) $updated->leaf_task_count, $updated->tasks()->where('is_leaf', true)->count());
    }

    public function test_an_approved_baseline_cannot_be_edited(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah disetujui dan tidak dapat diubah/');

        $this->service->update($baseline, ['notes' => 'Diubah diam-diam.']);
    }

    public function test_an_approved_baseline_cannot_be_resnapshotted(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah disetujui dan tidak dapat diambil ulang/');

        $this->service->resnapshot($baseline);
    }

    /**
     * There are no soft deletes on prj_baselines to route around this, which is
     * the other half of the guarantee: soft-deleting an approved baseline would
     * silently promote its predecessor back to "current".
     */
    public function test_an_approved_baseline_cannot_be_deleted(): void
    {
        $this->makeRap($this->project);
        $baseline = $this->freeze();

        try {
            $this->service->delete($baseline);
            $this->fail('An approved baseline was deleted.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak dapat dihapus', $e->getMessage());
        }

        $this->assertDatabaseCount('prj_baselines', 1);
        $this->assertFalse(in_array('deleted_at', DB::getSchemaBuilder()->getColumnListing('prj_baselines'), true));
    }

    public function test_the_person_who_submitted_a_baseline_cannot_approve_it(): void
    {
        $this->makeRap($this->project);
        $maker = $this->userWith('prj.create', 'Perencana');

        $baseline = $this->service->snapshot($this->project, ['effective_date' => '2026-02-02'], $maker);
        $this->service->submit($baseline, $maker);

        $this->expectException(SelfApprovalException::class);

        $this->service->approve($baseline, $maker);
    }

    // ----------------------------------------------------------- re-baseline

    public function test_approving_a_second_baseline_supersedes_the_first_and_leaves_exactly_one_current_baseline(): void
    {
        $this->makeRap($this->project);
        $first = $this->freeze();

        $second = $this->freeze([
            'effective_date' => '2026-09-01',
            'reason' => 'Perpanjangan waktu 60 hari akibat keterlambatan lahan.',
        ]);

        $first->refresh();

        $this->assertSame(1, (int) $second->revision_no);
        $this->assertNotNull($first->superseded_at);
        $this->assertSame($second->id, (int) $first->superseded_by_id);
        $this->assertFalse($first->isCurrent());
        $this->assertTrue($second->isCurrent());
        $this->assertSame($second->id, $this->service->currentFor($this->project)->id);
        $this->assertSame(1, ProjectBaseline::query()
            ->where('project_id', $this->project->id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->count());
    }

    /**
     * Superseding writes ONLY superseded_at + superseded_by_id. Revision 0 is
     * the document an EOT claim is built from; if approving a revision could
     * touch a byte of it, the claim would be built on the thing being disputed.
     */
    public function test_revision_zero_stays_readable_after_two_re_baselines(): void
    {
        $this->makeRap($this->project);
        $original = $this->freeze();

        $frozenBac = (string) $original->bac;
        $frozenFinish = $original->planned_finish->toDateString();
        $frozenTasks = $original->tasks()->get()->map(fn ($task): string => $task->wbs_code.':'.$task->weight_pct)->all();

        // The world moves: scope is reweighted and the programme is extended.
        $this->project->wbsTasks()->where('wbs_code', 'C.2')->update(['planned_end' => '2027-12-31']);
        $this->freeze(['effective_date' => '2026-09-01', 'reason' => 'Addendum I']);
        $this->freeze(['effective_date' => '2027-01-05', 'reason' => 'Addendum II']);

        $original->refresh();

        $this->assertSame(0, (int) $original->revision_no);
        $this->assertSame(DocumentStatus::Approved, $original->status);
        $this->assertSame($frozenBac, (string) $original->bac);
        $this->assertSame($frozenFinish, $original->planned_finish->toDateString());
        $this->assertSame($frozenTasks, $original->tasks()->get()->map(fn ($task): string => $task->wbs_code.':'.$task->weight_pct)->all());
        $this->assertSame($original->id, $this->service->originalFor($this->project)->id);
    }

    public function test_a_re_baseline_without_a_reason_is_refused(): void
    {
        $this->makeRap($this->project);
        $this->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/revisi 1.*wajib menyebutkan alasan/s');

        $this->service->snapshot($this->project, ['effective_date' => '2026-09-01']);
    }

    public function test_a_re_baseline_records_the_reason_the_reference_document_and_both_approvers(): void
    {
        $this->makeRap($this->project);
        $this->freeze();

        $maker = $this->userWith('prj.create', 'Perencana Dua');
        $checker = $this->userWith('prj.approve', 'Direktur Dua');

        $second = $this->service->snapshot($this->project, [
            'effective_date' => '2026-09-01',
            'reason' => 'Perpanjangan waktu 60 hari yang disetujui MK.',
            'reference_type' => 'CCO',
            'reference_no' => 'CCO/2026/IX/0001',
        ], $maker);
        $this->service->submit($second, $maker);
        $second = $this->service->approve($second, $checker, 'Disetujui rapat direksi.');

        $this->assertSame('CCO/2026/IX/0001', $second->reference_no);
        $this->assertSame('CCO', $second->reference_type);
        $this->assertSame($maker->id, (int) $second->created_by);
        $this->assertSame($checker->id, (int) $second->approved_by);
        $this->assertNotNull($second->approved_at);

        $trail = $second->approvals()->orderBy('id')->get();
        $this->assertSame(['submitted', 'approved'], $trail->pluck('action')->all());
        $this->assertSame([$maker->id, $checker->id], $trail->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * unique(project_id, revision_no) is the real concurrency defence, because
     * lockForUpdate() is a silent no-op on SQLite. The loser of the race gets a
     * sentence an operator can act on rather than a raw constraint violation.
     */
    public function test_two_baselines_cannot_claim_the_same_revision_number_for_one_project(): void
    {
        $this->makeRap($this->project);
        $this->service->snapshot($this->project, ['effective_date' => '2026-02-02']);

        // Exactly what a concurrent request does: computes revision 0 from a
        // read that happened before the other request's insert landed.
        try {
            ProjectBaseline::query()->create([
                'project_id' => $this->project->id,
                'revision_no' => 0,
                'status' => DocumentStatus::Draft,
                'effective_date' => '2026-02-02',
                'bac' => self::RAP_TOTAL,
                'bac_source' => BacSource::RapUnapproved,
                'planned_start' => '2026-02-02',
                'planned_finish' => '2027-06-30',
                'planned_duration_days' => 514,
                'leaf_task_count' => 8,
                'leaf_weight_total' => 100,
            ]);
            $this->fail('Two baselines claimed revision 0 for one project.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }

        $this->assertSame(1, ProjectBaseline::query()->where('project_id', $this->project->id)->count());
    }

    // --------------------------------------------------------------- the API

    public function test_the_endpoint_refuses_a_baseline_for_a_project_without_a_rap_with_an_indonesian_message(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->postJson('/api/projects/baselines', [
                'project_id' => $this->project->id,
                'effective_date' => '2026-02-02',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('belum punya RAP', $response->json('message'));
    }

    public function test_the_endpoint_creates_a_draft_and_lists_it(): void
    {
        $this->makeRap($this->project);
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/api/projects/baselines', [
                'project_id' => $this->project->id,
                'effective_date' => '2026-02-02',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.revision_no', 0)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.bac_source', 'rap_unapproved')
            ->assertJsonPath('data.leaf_task_count', 8);

        $this->actingAs($admin)
            ->getJson('/api/projects/baselines?project_id='.$this->project->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
