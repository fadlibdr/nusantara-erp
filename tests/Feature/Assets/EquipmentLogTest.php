<?php

namespace Tests\Feature\Assets;

use App\Models\User;
use LogicException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetCategory;
use Modules\Assets\Models\Deployment;
use Modules\Assets\Models\EquipmentLog;
use Modules\Assets\Services\DeploymentService;
use Modules\Assets\Services\EquipmentLogService;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\ProjectCost;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Log BBM & jam alat — the site register of fuel and hour-meter readings,
 * resolving deviasi #13 (owner decision, 22 Aug 2026).
 *
 * A REGISTER AND NOTHING ELSE. It moves no money, posts no journal, adjusts no
 * stock: the fuel COST already flows through petty cash under the BbmTol
 * category, and a second money path here would count the same solar twice.
 * What the company was missing is the operational half — how many hours the
 * machine actually ran and how many litres it drank — which is what
 * utilisation and own-vs-rent decisions are made from.
 *
 * The register's honesty rules, each pinned below:
 *
 *   HOUR METER IS A READING, NOT A DELTA, and meters only run forward. A new
 *   reading below an earlier one is a typo or the wrong machine, and silently
 *   accepting it poisons every utilisation number derived later — so it is
 *   refused with BOTH numbers in the message, and the same line read from the
 *   other side refuses a backfilled reading above the next recorded one.
 *
 *   THE DEPLOYMENT MUST HAVE BEEN ACTIVE ON THE LOG DATE. A reading dated
 *   after demobilisation (or before mobilisation) is a record about a machine
 *   that was not there. Late paperwork dated WITHIN the span stays welcome,
 *   exactly as DeploymentService accepts the storeman recording in July the
 *   machine that left in June.
 *
 *   TWO ROWS ON ONE DAY ARE TWO FACTS. Sites refuel morning and afternoon;
 *   a unique (deployment, date) key would force the afternoon top-up to
 *   overwrite the morning one or be thrown away.
 *
 *   NO UPDATE, NO DELETE. A register of readings is corrected by the next
 *   reading, not by editing history; both routes exist only to say so.
 */
class EquipmentLogTest extends ErpTestCase
{
    private EquipmentLogService $service;

    private Project $project;

    private ?User $recorder = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->service = app(EquipmentLogService::class);

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pembangunan Gedung Kantor Graha Sentosa',
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    // -------------------------------------------------------------- fixtures

    private function asset(array $attributes = []): Asset
    {
        $category = AssetCategory::query()->firstOrCreate(
            ['code' => 'CAT-ALAT'],
            ['name' => 'Alat Berat', 'useful_life_months_default' => 96],
        );

        return Asset::query()->create(array_merge([
            'code' => 'AST-'.str_pad((string) (Asset::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'Excavator Komatsu PC200-8',
            'category_id' => $category->id,
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => 960_000_000,
            'salvage_value' => 0,
            'useful_life_months' => 96,
            'accumulated_depreciation' => 0,
            'book_value' => 960_000_000,
            'status' => 'available',
        ], $attributes));
    }

    private function deployment(array $attributes = []): Deployment
    {
        return app(DeploymentService::class)->deploy($this->asset(), array_merge([
            'project_id' => (int) $this->project->id,
            'deployed_from' => '2026-03-02',
        ], $attributes));
    }

    /** The site clerk whose id lands in logged_by when the service is called directly. */
    private function recorder(): User
    {
        return $this->recorder ??= User::query()->create([
            'name' => 'Agus Prasetyo',
            'email' => 'agus@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    /** A user holding exactly the seeded role's permissions (RoleSeeder shapes). */
    private function userWithRole(string $role, array $permissions): User
    {
        $model = Role::findOrCreate($role, 'web');
        $model->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => ucfirst($role),
            'email' => "{$role}@test.local",
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($model);

        return $user;
    }

    private function siteManager(): User
    {
        return $this->userWithRole('site-manager', ['prj.view', 'prj.create', 'prj.update', 'inv.view', 'inv.create']);
    }

    private function projectManager(): User
    {
        return $this->userWithRole('project-manager', [
            'prj.view', 'prj.create', 'prj.update', 'prj.approve',
            'est.view', 'est.create', 'est.update',
            'scm.view', 'scm.create', 'scm.update',
            'inv.view', 'inv.create', 'inv.update',
            'ast.view', 'ast.create', 'ast.update',
        ]);
    }

    private function teknisi(): User
    {
        return $this->userWithRole('teknisi', ['svc.view', 'svc.create', 'svc.update', 'inv.view', 'inv.post']);
    }

    private function warehouse(): User
    {
        return $this->userWithRole('warehouse', ['inv.view', 'inv.create', 'inv.update', 'inv.delete', 'prj.view']);
    }

    // ------------------------------------------------------------- recording

    public function test_a_reading_is_recorded_with_its_author(): void
    {
        $deployment = $this->deployment();
        $siteManager = $this->siteManager();

        $response = $this->actingAs($siteManager)->postJson('api/assets/equipment-logs', [
            'deployment_id' => $deployment->id,
            'log_date' => '2026-07-01',
            'hour_meter' => 1200.5,
            'fuel_liters' => 120,
            'notes' => 'Isi solar pagi sebelum galian.',
        ]);

        $response->assertCreated();

        $log = EquipmentLog::query()->sole();
        $this->assertSame($deployment->id, $log->deployment_id);
        $this->assertSame('2026-07-01', $log->log_date->toDateString());
        $this->assertEqualsWithDelta(1200.5, (float) $log->hour_meter, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $log->fuel_liters, 0.001);
        $this->assertSame($siteManager->id, $log->logged_by);
    }

    /**
     * THE REGISTER MOVES NO MONEY. The BbmTol petty-cash category already
     * carries the fuel cost; a journal or project-cost row born here would
     * count the same solar twice. This is the pin that keeps it a register.
     */
    public function test_recording_a_reading_posts_nothing_anywhere(): void
    {
        $this->service->record($this->deployment(), [
            'log_date' => '2026-07-01',
            'hour_meter' => 1200.5,
            'fuel_liters' => 120,
        ], $this->recorder());

        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0, ProjectCost::query()->count());
    }

    /**
     * Morning and afternoon top-ups happen on real sites; a unique
     * (deployment, date) key would force the second to overwrite the first.
     */
    public function test_two_readings_on_the_same_day_are_two_rows(): void
    {
        $deployment = $this->deployment();

        $this->service->record($deployment, ['log_date' => '2026-07-01', 'fuel_liters' => 80], $this->recorder());
        $this->service->record($deployment, ['log_date' => '2026-07-01', 'fuel_liters' => 60, 'hour_meter' => 1210], $this->recorder());

        $this->assertSame(2, EquipmentLog::query()->where('deployment_id', $deployment->id)->count());
    }

    // ------------------------------------------------------------- monotonic

    public function test_a_lower_reading_than_the_latest_earlier_one_is_refused_quoting_both(): void
    {
        $deployment = $this->deployment();
        $this->service->record($deployment, ['log_date' => '2026-07-01', 'hour_meter' => 1200.5], $this->recorder());

        try {
            $this->service->record($deployment, ['log_date' => '2026-07-02', 'hour_meter' => 1180], $this->recorder());
            $this->fail('A reading below the latest earlier one was accepted.');
        } catch (LogicException $e) {
            // Both numbers, so the operator sees the typo without opening the list.
            $this->assertStringContainsString('1.180', $e->getMessage());
            $this->assertStringContainsString('1.200,5', $e->getMessage());
        }

        $this->assertSame(1, EquipmentLog::query()->count());
    }

    /** Same-day rows are ordered by entry: the afternoon reading may not run backwards either. */
    public function test_a_second_same_day_reading_below_the_morning_one_is_refused(): void
    {
        $deployment = $this->deployment();
        $this->service->record($deployment, ['log_date' => '2026-07-01', 'hour_meter' => 1200.5], $this->recorder());

        $this->expectException(LogicException::class);
        $this->service->record($deployment, ['log_date' => '2026-07-01', 'hour_meter' => 1199], $this->recorder());
    }

    /**
     * The same forward-only line read from the other side: a backfilled
     * reading ABOVE the next recorded one breaks the sequence just as surely.
     */
    public function test_a_backfilled_reading_above_the_next_recorded_one_is_refused(): void
    {
        $deployment = $this->deployment();
        $this->service->record($deployment, ['log_date' => '2026-07-10', 'hour_meter' => 1250], $this->recorder());

        try {
            $this->service->record($deployment, ['log_date' => '2026-07-05', 'hour_meter' => 1300], $this->recorder());
            $this->fail('A backfilled reading above the next recorded one was accepted.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('1.300', $e->getMessage());
            $this->assertStringContainsString('1.250', $e->getMessage());
        }
    }

    /** A fuel-only row states no meter and therefore breaks no meter sequence. */
    public function test_a_fuel_only_row_is_never_measured_against_the_meter(): void
    {
        $deployment = $this->deployment();
        $this->service->record($deployment, ['log_date' => '2026-07-01', 'hour_meter' => 1200.5], $this->recorder());

        $log = $this->service->record($deployment, ['log_date' => '2026-07-02', 'fuel_liters' => 90], $this->recorder());

        $this->assertNull($log->hour_meter);
    }

    // ------------------------------------------------------- deployment span

    public function test_a_reading_dated_after_demobilisation_is_refused(): void
    {
        $deployment = $this->deployment();
        app(DeploymentService::class)->returnDeployment($deployment, '2026-07-15');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/demobilisasi/i');
        $this->service->record($deployment->refresh(), ['log_date' => '2026-07-20', 'fuel_liters' => 50], $this->recorder());
    }

    public function test_a_reading_dated_before_mobilisation_is_refused(): void
    {
        $deployment = $this->deployment(); // deployed_from 2026-03-02

        $this->expectException(LogicException::class);
        $this->service->record($deployment, ['log_date' => '2026-03-01', 'fuel_liters' => 50], $this->recorder());
    }

    /**
     * Late paperwork dated within the span is welcome even after the machine
     * came back — the same stance returnDeployment takes on the storeman who
     * records in July the machine that left in June. The machine WAS there.
     */
    public function test_a_backfilled_reading_within_a_returned_deployments_span_is_accepted(): void
    {
        $deployment = $this->deployment();
        app(DeploymentService::class)->returnDeployment($deployment, '2026-07-15');

        $log = $this->service->record($deployment->refresh(), [
            'log_date' => '2026-07-10',
            'fuel_liters' => 70,
        ], $this->recorder());

        $this->assertSame('2026-07-10', $log->log_date->toDateString());
    }

    public function test_a_future_dated_reading_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->service->record($this->deployment(), [
            'log_date' => now()->addDay()->toDateString(),
            'fuel_liters' => 50,
        ], $this->recorder());
    }

    // ------------------------------------------------------------ validation

    public function test_a_log_with_neither_meter_nor_fuel_is_refused(): void
    {
        $deployment = $this->deployment();

        $this->actingAs($this->siteManager())->postJson('api/assets/equipment-logs', [
            'deployment_id' => $deployment->id,
            'log_date' => '2026-07-01',
            'notes' => 'Alat beroperasi normal.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['hour_meter', 'fuel_liters']);

        $this->assertSame(0, EquipmentLog::query()->count());
    }

    // ----------------------------------------------------------- permissions

    /**
     * The people already at the site log the readings: both project roles hold
     * prj.update, which is the gate. A teknisi is a ServiceDesk role with no
     * business on a construction deployment, and the storeman reads the
     * register (prj.view) but does not write hour meters he never operates.
     */
    public function test_site_manager_and_project_manager_can_log_but_teknisi_and_warehouse_cannot(): void
    {
        $deployment = $this->deployment();
        $payload = fn (string $date) => [
            'deployment_id' => $deployment->id,
            'log_date' => $date,
            'fuel_liters' => 40,
        ];

        $this->actingAs($this->siteManager())->postJson('api/assets/equipment-logs', $payload('2026-07-01'))
            ->assertCreated();
        $this->actingAs($this->projectManager())->postJson('api/assets/equipment-logs', $payload('2026-07-02'))
            ->assertCreated();
        $this->actingAs($this->teknisi())->postJson('api/assets/equipment-logs', $payload('2026-07-03'))
            ->assertForbidden();
        $this->actingAs($this->warehouse())->postJson('api/assets/equipment-logs', $payload('2026-07-03'))
            ->assertForbidden();

        $this->assertSame(2, EquipmentLog::query()->count());
    }

    /**
     * Reading takes ast.view OR prj.view — the register belongs to the machine
     * (Assets) but is written and read at the site (Projects). warehouse
     * proves the prj.view arm, the finance shape proves the ast.view arm, and
     * teknisi (neither) proves the gate still says no.
     */
    public function test_reading_the_register_takes_ast_view_or_prj_view(): void
    {
        $deployment = $this->deployment();
        $this->service->record($deployment, ['log_date' => '2026-07-01', 'fuel_liters' => 80], $this->recorder());

        $finance = $this->userWithRole('finance', [
            'fin.view', 'fin.create', 'fin.update', 'fin.delete', 'fin.post',
            'crm.view', 'prc.view', 'scm.view', 'hr.view', 'ast.view', 'ast.post',
        ]);

        $this->actingAs($this->warehouse())->getJson('api/assets/equipment-logs')->assertOk();
        $this->actingAs($finance)->getJson('api/assets/equipment-logs')->assertOk();
        $this->actingAs($this->siteManager())->getJson('api/assets/equipment-logs')->assertOk();
        $this->actingAs($this->teknisi())->getJson('api/assets/equipment-logs')->assertForbidden();
    }

    // ------------------------------------------------------ no edit, no delete

    /**
     * A register of readings is corrected by the NEXT reading, never by
     * editing history. The routes exist so the refusal can say that in words
     * instead of a bare 404/405 that reads as a broken deploy.
     */
    public function test_update_and_delete_are_refused_with_the_correction_rule(): void
    {
        $deployment = $this->deployment();
        $log = $this->service->record($deployment, ['log_date' => '2026-07-01', 'hour_meter' => 1200.5], $this->recorder());

        $admin = $this->adminUser();

        $update = $this->actingAs($admin)->putJson("api/assets/equipment-logs/{$log->id}", ['hour_meter' => 1300]);
        $update->assertUnprocessable();
        $this->assertStringContainsString('pembacaan berikutnya', (string) $update->json('message'));

        $delete = $this->actingAs($admin)->deleteJson("api/assets/equipment-logs/{$log->id}");
        $delete->assertUnprocessable();
        $this->assertStringContainsString('pembacaan berikutnya', (string) $delete->json('message'));

        $this->assertEqualsWithDelta(1200.5, (float) $log->refresh()->hour_meter, 0.001);
        $this->assertSame(1, EquipmentLog::query()->count());
    }

    // -------------------------------------------------------------- listing

    public function test_the_register_lists_by_deployment_and_date_window(): void
    {
        $first = $this->deployment();
        $second = app(DeploymentService::class)->deploy($this->asset(), [
            'project_id' => (int) $this->project->id,
            'deployed_from' => '2026-04-01',
        ]);

        $this->service->record($first, ['log_date' => '2026-07-01', 'fuel_liters' => 80], $this->recorder());
        $this->service->record($first, ['log_date' => '2026-07-20', 'fuel_liters' => 60], $this->recorder());
        $this->service->record($second, ['log_date' => '2026-07-05', 'fuel_liters' => 90], $this->recorder());

        $admin = $this->adminUser();

        $byDeployment = $this->actingAs($admin)->getJson("api/assets/equipment-logs?deployment_id={$first->id}");
        $byDeployment->assertOk();
        $this->assertCount(2, $byDeployment->json('data'));

        $byWindow = $this->actingAs($admin)
            ->getJson("api/assets/equipment-logs?deployment_id={$first->id}&date_from=2026-07-10&date_to=2026-07-31");
        $byWindow->assertOk();
        $this->assertCount(1, $byWindow->json('data'));
        $this->assertSame('2026-07-20', $byWindow->json('data.0.log_date'));
    }
}
