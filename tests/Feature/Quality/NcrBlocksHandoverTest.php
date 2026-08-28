<?php

namespace Tests\Feature\Quality;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Exceptions\BastPrerequisiteException;
use Modules\Projects\Models\Bast;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\ProjectService;
use Modules\Quality\Models\Ncr;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P1-QC — the BAST I prerequisite extended to "no open NCR". A first handover
 * starts the masa pemeliharaan; it must not proceed while a nonconformance is
 * still open on the project. The refusal names the NCR, and verifying it clears
 * the block. (BAST II keeps its own defect gate — this is BAST I only.)
 *
 * Projects reads qc_ncr behind Schema::hasTable and never imports a Quality
 * class: the dependency arrow is Quality → Projects. This test exercises the two
 * modules together to prove the seam holds.
 */
class NcrBlocksHandoverTest extends ErpTestCase
{
    use QualityFixtures;

    private ProjectService $projects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->projects = app(ProjectService::class);
    }

    /** A prj.approve holder distinct from the admin who submits (maker-checker). */
    private function handoverApprover(): User
    {
        $role = Role::findOrCreate('pm-handover', 'web');
        $role->syncPermissions(['prj.view', 'prj.update', 'prj.approve']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Ir. Handoko',
            'email' => 'pm-handover@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function submittedBastOne(Project $project): Bast
    {
        $bast = $this->projects->createBast([
            'project_id' => $project->id,
            'bast_type' => 'bast1',
            'handover_date' => '2026-12-20',
            'customer_representative' => 'Ir. Bambang (Owner Rep.)',
        ]);
        $bast->submit($this->admin());

        return $bast->refresh();
    }

    private function openNcr(Project $project): Ncr
    {
        $this->admin();

        $response = $this->postJson('api/quality/ncr', [
            'project_id' => $project->id,
            'location_id' => $this->location($project)->id,
            'stage' => 'during',
            'description' => 'Bekisting kolom tidak tegak lurus',
            'responsible_employee_id' => $this->employee()->id,
        ]);

        $response->assertCreated();

        return Ncr::query()->findOrFail($response->json('data.id'));
    }

    public function test_bast_one_is_refused_while_an_ncr_is_open_and_the_message_names_it(): void
    {
        $project = $this->project();
        $ncr = $this->openNcr($project);
        $bast = $this->submittedBastOne($project);

        try {
            $this->projects->approveBast($bast, $this->handoverApprover());
            $this->fail('Expected BastPrerequisiteException while an NCR is open.');
        } catch (BastPrerequisiteException $e) {
            $this->assertStringContainsString('NCR masih terbuka', $e->getMessage());
            $this->assertStringContainsString($ncr->code, $e->getMessage());
        }

        $this->assertSame(DocumentStatus::Submitted, $bast->refresh()->status);
        $this->assertSame(ProjectStatus::Active, $project->refresh()->status);
    }

    public function test_an_under_correction_ncr_still_blocks(): void
    {
        $project = $this->project();
        $ncr = $this->openNcr($project);
        $this->admin();
        $this->postJson("api/quality/ncr/{$ncr->id}/start-correction")->assertOk();

        $bast = $this->submittedBastOne($project);

        $this->expectException(BastPrerequisiteException::class);
        $this->projects->approveBast($bast, $this->handoverApprover());
    }

    public function test_bast_one_proceeds_once_the_ncr_is_verified(): void
    {
        $project = $this->project();
        $ncr = $this->openNcr($project);
        $bast = $this->submittedBastOne($project);

        // Verify the NCR (a second holder), and the first handover proceeds.
        $this->approver();
        $this->postJson("api/quality/ncr/{$ncr->id}/verify", ['verified_at' => '2026-12-15'])->assertOk();

        $this->projects->approveBast($bast, $this->handoverApprover());

        $this->assertSame(DocumentStatus::Approved, $bast->refresh()->status);
        $this->assertSame(ProjectStatus::Warranty, $project->refresh()->status);
    }
}
