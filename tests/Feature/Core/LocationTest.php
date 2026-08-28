<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Location;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P1-ENG — core_locations: the hierarchical site breakdown (tower → lantai →
 * zona → as → ruang) that Engineering, Quality and Projects will all point at.
 *
 * It lives in CORE because three modules need it, and Core may depend on none
 * of them — so project_id is a bare indexed column, and there is no Eloquent
 * relation from Location to Project. The hierarchy invariants live on the
 * MODEL (saving/deleting hooks), not in a service, because the master-data
 * importer writes rows directly and a guard the importer never calls is a
 * guard that does not guard.
 */
class LocationTest extends ErpTestCase
{
    private ?User $admin = null;

    private function admin(): User
    {
        if ($this->admin === null) {
            $this->admin = $this->adminUser();
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    private function project(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-094',
            'name' => 'Menara Kuningan',
            'type' => 'construction',
            'status' => 'active',
        ], $attributes));
    }

    private function location(Project $project, array $attributes = []): Location
    {
        return Location::query()->create(array_merge([
            'project_id' => $project->id,
            'kind' => 'tower',
            'code' => 'MK-T1',
            'name' => 'Tower 1',
        ], $attributes));
    }

    public function test_a_hierarchy_builds_through_the_api(): void
    {
        $this->admin();
        $project = $this->project();

        $tower = $this->postJson('api/core/locations', [
            'project_id' => $project->id,
            'kind' => 'tower',
            'code' => 'MK-T1',
            'name' => 'Tower 1',
        ]);
        $tower->assertCreated();

        $floor = $this->postJson('api/core/locations', [
            'project_id' => $project->id,
            'parent_id' => $tower->json('data.id'),
            'kind' => 'floor',
            'code' => 'MK-T1-L03',
            'name' => 'Lantai 3',
            'sort_order' => 3,
        ]);
        $floor->assertCreated();
        $this->assertSame('Lantai', $floor->json('data.kind_label'));
        $this->assertSame('MK-T1', $floor->json('data.parent_code'));
    }

    public function test_a_parent_on_another_project_is_refused(): void
    {
        $this->admin();
        $projectA = $this->project();
        $projectB = $this->project(['code' => 'PRJ-2026-095', 'name' => 'Proyek Lain']);
        $tower = $this->location($projectA);

        $response = $this->postJson('api/core/locations', [
            'project_id' => $projectB->id,
            'parent_id' => $tower->id,
            'kind' => 'floor',
            'code' => 'MK-T9-L01',
            'name' => 'Lantai 1',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('proyek yang sama', (string) $response->json('message'));
    }

    public function test_a_location_cannot_become_its_own_ancestor(): void
    {
        $this->admin();
        $project = $this->project();
        $tower = $this->location($project);
        $floor = $this->location($project, ['parent_id' => $tower->id, 'kind' => 'floor', 'code' => 'MK-T1-L01', 'name' => 'Lantai 1']);

        $response = $this->putJson("api/core/locations/{$tower->id}", [
            'parent_id' => $floor->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('siklus', (string) $response->json('message'));
    }

    public function test_deleting_a_location_with_children_is_refused_with_the_count(): void
    {
        $this->admin();
        $project = $this->project();
        $tower = $this->location($project);
        $this->location($project, ['parent_id' => $tower->id, 'kind' => 'floor', 'code' => 'MK-T1-L01', 'name' => 'Lantai 1']);
        $this->location($project, ['parent_id' => $tower->id, 'kind' => 'floor', 'code' => 'MK-T1-L02', 'name' => 'Lantai 2']);

        $response = $this->deleteJson("api/core/locations/{$tower->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('2 sub-lokasi', (string) $response->json('message'));
        $this->assertNotNull(Location::query()->find($tower->id));
    }

    public function test_writing_locations_needs_prj_create_not_core(): void
    {
        // Locations are PROJECT site data maintained by the project side —
        // the ProjectPhotoController precedent for prj.* on a Core route.
        $this->admin(); // seeds the permission table first
        $role = Role::findOrCreate('viewer-only', 'web');
        $role->syncPermissions(['prj.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $viewer */
        $viewer = User::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer-loc@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $viewer->assignRole($role);

        $this->admin();
        $project = $this->project();

        Sanctum::actingAs($viewer);
        $this->getJson('api/core/locations?project_id='.$project->id)->assertOk();
        $this->postJson('api/core/locations', [
            'project_id' => $project->id,
            'kind' => 'tower',
            'code' => 'MK-TX',
            'name' => 'Tower X',
        ])->assertForbidden();
    }

    public function test_locations_import_through_master_data_and_converge_on_a_second_run(): void
    {
        $this->admin();
        $project = $this->project();

        $csv = "kode,nama,proyek_kode,jenis,induk_kode,urutan\n"
            ."MK-T1,Tower 1,{$project->code},tower,,1\n"
            ."MK-T1-L01,Lantai 1,{$project->code},floor,MK-T1,1\n";

        // First pass: the tower lands; the floor's parent lookup cannot see a
        // row the same commit has not written yet, so the row is reported.
        $first = $this->postJson('api/core/master-data/locations/import', [
            'filename' => 'lokasi.csv',
            'content' => base64_encode($csv),
        ]);
        $first->assertOk();
        $this->assertSame(1, $first->json('data.created'));
        $this->assertSame(1, $first->json('data.skipped'));

        // Second pass converges: the tower updates, the floor resolves.
        $second = $this->postJson('api/core/master-data/locations/import', [
            'filename' => 'lokasi.csv',
            'content' => base64_encode($csv),
        ]);
        $second->assertOk();
        $this->assertSame(1, $second->json('data.created'));
        $this->assertSame(1, $second->json('data.updated'));

        $floor = Location::query()->where('code', 'MK-T1-L01')->firstOrFail();
        $this->assertSame('MK-T1', Location::query()->find($floor->parent_id)?->code);
        $this->assertSame($project->id, (int) $floor->project_id);
    }
}
