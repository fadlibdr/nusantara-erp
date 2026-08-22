<?php

namespace Tests\Unit\Projects;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Projects\Enums\BastType;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * BAST — Berita Acara Serah Terima.
 *
 * BAST I hands the work over and starts masa pemeliharaan: the project moves to
 * "warranty", the actual end date is stamped and the retensi becomes due at
 * handover + warranty months. BAST II ends the warranty and closes the project.
 */
class BastHandoverTest extends ErpTestCase
{
    use ProjectsFixtures;

    private function makeBast(Project $project, string $type, array $data = []): Bast
    {
        return $this->projects()->createBast(array_merge([
            'project_id' => $project->id,
            'bast_type' => $type,
            'handover_date' => '2026-12-20',
            'customer_representative' => 'Ir. Bambang (Owner Rep.)',
        ], $data));
    }

    // -------------------------------------------------- retention release date

    public function test_bast_one_defaults_the_retention_release_to_handover_plus_warranty(): void
    {
        $project = $this->makeProject(['warranty_months' => 12]);

        $bast = $this->makeBast($project, 'bast1');

        // serah terima 20-12-2026 + 12 bulan pemeliharaan = 20-12-2027
        $this->assertSame('2027-12-20', $bast->retention_release_due->toDateString());
    }

    public function test_a_six_month_warranty_shifts_the_release_by_six_months(): void
    {
        $project = $this->makeProject(['warranty_months' => 6]);

        $bast = $this->makeBast($project, 'bast1', ['handover_date' => '2026-06-30']);

        // 30-06-2026 + 6 bulan pemeliharaan = 30-12-2026
        $this->assertSame('2026-12-30', $bast->retention_release_due->toDateString());
    }

    public function test_a_month_end_handover_into_a_shorter_month_clamps_to_that_month_end(): void
    {
        $project = $this->makeProject(['warranty_months' => 6]);

        $bast = $this->makeBast($project, 'bast1', ['handover_date' => '2026-08-31']);

        // 31-08-2026 + 6 bulan = 28-02-2027 (Februari 2027 hanya 28 hari)
        $this->assertSame('2027-02-28', $bast->retention_release_due->toDateString());
    }

    public function test_a_zero_month_warranty_releases_the_retention_on_handover_day(): void
    {
        $project = $this->makeProject(['warranty_months' => 0]);

        $bast = $this->makeBast($project, 'bast1');

        $this->assertSame('2026-12-20', $bast->retention_release_due->toDateString());
    }

    public function test_an_explicit_retention_release_date_beats_the_default(): void
    {
        $project = $this->makeProject(['warranty_months' => 12]);

        $bast = $this->makeBast($project, 'bast1', ['retention_release_due' => '2027-06-30']);

        $this->assertSame('2027-06-30', $bast->retention_release_due->toDateString());
    }

    public function test_bast_two_gets_no_default_retention_release_date(): void
    {
        $project = $this->makeProject(['warranty_months' => 12]);

        $bast = $this->makeBast($project, 'bast2');

        $this->assertNull($bast->retention_release_due);
    }

    public function test_a_new_bast_opens_as_a_numbered_draft(): void
    {
        $project = $this->makeProject();

        $bast = $this->makeBast($project, 'bast1');

        $this->assertSame(DocumentStatus::Draft, $bast->status);
        $this->assertSame(BastType::Bast1, $bast->bast_type);
        $this->assertStringStartsWith('BAST/', $bast->code);
    }

    // --------------------------------------------------- what approval changes

    public function test_approving_bast_one_moves_the_project_into_warranty(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Finishing]);
        $bast = $this->makeBast($project, 'bast1');
        $bast->submit();

        $this->projects()->approveBast($bast, $this->makeUser());

        $fresh = Project::query()->findOrFail($project->id);

        $this->assertSame(ProjectStatus::Warranty, $fresh->status);
        $this->assertSame(DocumentStatus::Approved, $bast->refresh()->status);
    }

    public function test_approving_bast_one_stamps_the_actual_end_date_from_the_handover(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Finishing]);
        $bast = $this->makeBast($project, 'bast1', ['handover_date' => '2026-12-20']);
        $bast->submit();

        $this->projects()->approveBast($bast, $this->makeUser());

        $this->assertSame(
            '2026-12-20',
            Project::query()->findOrFail($project->id)->actual_end_date->toDateString(),
        );
    }

    public function test_an_actual_end_date_already_on_record_is_not_overwritten(): void
    {
        $project = $this->makeProject([
            'status' => ProjectStatus::Finishing,
            'actual_end_date' => '2026-11-30',
        ]);
        $bast = $this->makeBast($project, 'bast1', ['handover_date' => '2026-12-20']);
        $bast->submit();

        $this->projects()->approveBast($bast, $this->makeUser());

        $this->assertSame(
            '2026-11-30',
            Project::query()->findOrFail($project->id)->actual_end_date->toDateString(),
        );
    }

    public function test_approving_bast_two_closes_the_project(): void
    {
        // BAST II is now gated: it is by definition the END of the masa
        // pemeliharaan BAST I started, so an approved BAST I has to be on record
        // and the WBS has to say the work is finished. Both were absent here and
        // the approval went through anyway, which is the hole being closed.
        $project = $this->makeProject(['status' => ProjectStatus::Warranty, 'actual_progress_pct' => 100]);
        $first = $this->makeBast($project, 'bast1', ['handover_date' => '2026-12-20']);
        $first->submit();
        $this->projects()->approveBast($first, $this->makeUser('direktur@test.local'));

        $bast = $this->makeBast($project, 'bast2', ['handover_date' => '2027-12-20']);
        $bast->submit();

        $this->projects()->approveBast($bast, $this->makeUser());

        $this->assertSame(ProjectStatus::Closed, Project::query()->findOrFail($project->id)->status);
    }

    public function test_the_full_handover_sequence_walks_finishing_to_warranty_to_closed(): void
    {
        $project = $this->makeProject([
            'status' => ProjectStatus::Finishing,
            'warranty_months' => 12,
            'actual_progress_pct' => 100,
        ]);

        $first = $this->makeBast($project, 'bast1', ['handover_date' => '2026-12-20']);
        $first->submit();
        $this->projects()->approveBast($first, $this->makeUser());

        $this->assertSame(ProjectStatus::Warranty, Project::query()->findOrFail($project->id)->status);

        $second = $this->makeBast($project, 'bast2', ['handover_date' => '2027-12-20']);
        $second->submit();
        $this->projects()->approveBast($second, $this->makeUser('direktur@test.local'));

        $this->assertSame(ProjectStatus::Closed, Project::query()->findOrFail($project->id)->status);
        $this->assertSame(2, $project->basts()->count());
    }

    // ------------------------------------------------------------------ guards

    public function test_approving_a_draft_bast_throws_and_leaves_the_project_alone(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Finishing]);
        $bast = $this->makeBast($project, 'bast1');

        try {
            $this->projects()->approveBast($bast, $this->makeUser());
            $this->fail('Expected LogicException when approving a draft BAST.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('while status is draft', $e->getMessage());
        }

        $fresh = Project::query()->findOrFail($project->id);

        $this->assertSame(ProjectStatus::Finishing, $fresh->status);
        $this->assertNull($fresh->actual_end_date);
        $this->assertSame(DocumentStatus::Draft, Bast::query()->findOrFail($bast->id)->status);
    }

    public function test_a_rejected_bast_does_not_move_the_project(): void
    {
        $project = $this->makeProject(['status' => ProjectStatus::Finishing]);
        $bast = $this->makeBast($project, 'bast1');
        $bast->submit();
        $bast->reject($this->makeUser(), 'Masih ada defect list terbuka.');

        $fresh = Project::query()->findOrFail($project->id);

        $this->assertSame(DocumentStatus::Rejected, $bast->refresh()->status);
        $this->assertSame(ProjectStatus::Finishing, $fresh->status);
        $this->assertNull($fresh->actual_end_date);
    }
}
