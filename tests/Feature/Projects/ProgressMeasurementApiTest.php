<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Location;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Modules\Quality\Services\NcrService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P3 — the wiring: the endpoints exist, they carry the house permissions, and
 * the refusals reach the caller as 422 in Indonesian rather than as a 500.
 *
 * The rules themselves are proved against the services
 * (ProgressMeasurementCeilingTest, ZoneCertificateTest); what is proved here is
 * that a user can actually reach them, and that a user who should not cannot.
 */
class ProgressMeasurementApiTest extends ErpTestCase
{
    use OpnameFixtures;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOpnameWorld();
    }

    private function admin(): User
    {
        $this->admin ??= $this->adminUser();
        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    /** A second body, so maker-checker has somebody to hand the sheet to. */
    private function approver(): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('penyetuju-opname', 'web');
        $role->givePermissionTo(['prj.approve', 'prj.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Manajer Proyek',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(float $qty = 400): array
    {
        return [
            'project_id' => $this->project->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'items' => [
                ['boq_item_id' => $this->boqItems['A.1']->id, 'qty_this' => $qty],
            ],
        ];
    }

    public function test_an_opname_can_be_raised_submitted_and_approved_through_the_api(): void
    {
        $this->admin();

        $created = $this->postJson('api/projects/progress-measurements', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->assertStringStartsWith('OPN/', $created['code']);
        $this->assertSame('draft', $created['status']);
        $this->assertSame('400.000', $created['items'][0]['qty_this']);

        $this->postJson("api/projects/progress-measurements/{$created['id']}/submit")->assertOk();

        $this->approver();
        $approved = $this->postJson("api/projects/progress-measurements/{$created['id']}/approve")
            ->assertOk()
            ->json('data');

        $this->assertSame('approved', $approved['status']);
    }

    public function test_the_ceiling_reaches_the_caller_as_a_422_in_indonesian(): void
    {
        $this->admin();

        $response = $this->postJson('api/projects/progress-measurements', $this->payload(1400))
            ->assertStatus(422);

        $this->assertStringContainsString(
            'melampaui volume kontrak + CCO disetujui',
            json_encode($response->json(), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_raising_an_opname_needs_prj_create(): void
    {
        $this->approver(); // holds prj.approve and prj.view, not prj.create

        $this->postJson('api/projects/progress-measurements', $this->payload())->assertForbidden();
    }

    public function test_a_bapp_marked_done_over_an_open_ncr_is_refused_by_the_endpoint(): void
    {
        $this->admin();

        $zone = Location::query()->create([
            'project_id' => $this->project->id,
            'kind' => 'zone',
            'code' => 'Z-A',
            'name' => 'Lantai 3 Zona A',
        ]);

        $employee = Employee::query()->create([
            'code' => 'EMP-9001',
            'name' => 'Agus Prasetyo',
            'nik_ktp' => '3201010101010001',
            'gender' => 'male',
            'birth_date' => '1988-05-01',
            'ptkp_status' => 'K/1',
            'join_date' => '2020-01-01',
            'employment_type' => 'tetap',
            'position' => 'Site Manager',
            'department' => 'proyek',
        ]);

        $ncr = app(NcrService::class)->create([
            'project_id' => $this->project->id,
            'location_id' => $zone->id,
            'stage' => 'during',
            'description' => 'Selimut beton kurang dari toleransi.',
            'responsible_employee_id' => $employee->id,
        ]);

        $response = $this->postJson('api/projects/zone-certificates', [
            'project_id' => $this->project->id,
            'location_id' => $zone->id,
            'status' => 'done',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            $ncr->code,
            json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        // The honest mark is accepted without argument.
        $this->postJson('api/projects/zone-certificates', [
            'project_id' => $this->project->id,
            'location_id' => $zone->id,
            'status' => 'waiting_repair',
            'certified_at' => '2026-06-20',
            'certified_by_party' => 'mk',
        ])->assertCreated()->assertJsonPath('data.blocks_billing', true);
    }

    /**
     * A LINE MAY ONLY MEASURE ITS OWN PROJECT'S GROUND.
     *
     * A foreign location_id on an opname line is not a cosmetic slip. The owner
     * claim's kriteria #6 gate reads the BAPP of THIS project's zones, so a line
     * pointing at another project's zone sits outside the gate permanently — it
     * can never be refused for an unfinished zone because no BAPP of this
     * project will ever mention that location — and the signed backsheet prints
     * a stranger's zone path above our signature.
     *
     * ZoneCertificateService::locationOf already refuses this for a BAPP; the
     * two documents have to agree about what a zone is.
     */
    public function test_a_line_measuring_another_projects_location_is_refused_on_store(): void
    {
        $this->admin();

        $payload = $this->payload();
        $payload['items'][0]['location_id'] = $this->alienZone()->id;

        $response = $this->postJson('api/projects/progress-measurements', $payload)
            ->assertStatus(422);

        $body = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Both projects named: which location, and whose it actually is.
        $this->assertStringContainsString('Z-LAIN', $body);
        $this->assertStringContainsString($this->project->code, $body);
        $this->assertStringContainsString('PRJ-2026-002', $body);
    }

    /** The same gap was open on the update request. */
    public function test_a_line_measuring_another_projects_location_is_refused_on_update(): void
    {
        $this->admin();

        $created = $this->postJson('api/projects/progress-measurements', $this->payload())
            ->assertCreated()
            ->json('data');

        $payload = $this->payload();
        $payload['items'][0]['location_id'] = $this->alienZone()->id;

        $this->putJson("api/projects/progress-measurements/{$created['id']}", $payload)
            ->assertStatus(422);

        // And the stored line was not rewritten by the refused edit.
        $this->assertDatabaseHas('prj_progress_measurement_items', [
            'progress_measurement_id' => $created['id'],
            'location_id' => null,
        ]);
    }

    /** A location that IS in the project passes, on both requests. */
    public function test_a_location_inside_the_project_is_accepted_on_both_requests(): void
    {
        $this->admin();

        $zone = Location::query()->create([
            'project_id' => $this->project->id,
            'kind' => 'zone',
            'code' => 'Z-C',
            'name' => 'Lantai 2 Zona C',
        ]);

        $payload = $this->payload();
        $payload['items'][0]['location_id'] = $zone->id;

        $created = $this->postJson('api/projects/progress-measurements', $payload)
            ->assertCreated()
            ->json('data');

        $this->assertSame($zone->id, $created['items'][0]['location_id']);

        $updated = $this->putJson("api/projects/progress-measurements/{$created['id']}", $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame($zone->id, $updated['items'][0]['location_id']);
    }

    /** A zone of a DIFFERENT project, named so the refusal can name it. */
    private function alienZone(): Location
    {
        $other = Project::query()->create([
            'code' => 'PRJ-2026-002',
            'name' => 'Pembangunan Gudang Cikarang',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2027-03-01',
        ]);

        return Location::query()->create([
            'project_id' => $other->id,
            'kind' => 'zone',
            'code' => 'Z-LAIN',
            'name' => 'Zona proyek lain',
        ]);
    }

    public function test_the_contract_variation_register_refuses_a_second_row_for_the_same_pair(): void
    {
        $this->admin();

        $variation = $this->makeVariation('A.1', 500);

        $this->postJson('api/projects/contract-variations', [
            'contract_id' => $this->contract->id,
            'change_order_id' => $variation->change_order_id,
            'boq_item_id' => $this->boqItems['A.1']->id,
            'qty_change' => 200,
        ])->assertStatus(422);
    }
}
