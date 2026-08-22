<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * GET projects?mine=1 — 'Proyek saya' (Temuan 80).
 *
 * The linkage is users.employee_id → prj_projects.project_manager_id, both
 * hr_employees semantics without a DB constraint. A PM's daftar proyek and
 * dashboard toggle both ride this one server-side filter; without it every PM
 * reads every project and the screen gets noisier with each new site.
 */
class MyProjectsFilterTest extends ErpTestCase
{
    private function actingAsWithEmployee(?int $employeeId): User
    {
        $user = User::query()->create([
            'name' => 'Rina Wijaya',
            'email' => 'pm-'.($employeeId ?? 'none').'@test.local',
            'password' => 'password',
            'employee_id' => $employeeId,
            'is_active' => true,
        ]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function project(string $code, ?int $managerId): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => "Proyek {$code}",
            'type' => 'construction',
            'status' => 'active',
            'contract_value' => 1_000_000_000,
            'project_manager_id' => $managerId,
        ]);
    }

    private function codes(string $query = ''): array
    {
        $rows = $this->getJson('api/projects'.$query)->assertOk()->json('data');

        return collect($rows)->pluck('code')->sort()->values()->all();
    }

    public function test_mine_returns_only_projects_the_caller_manages(): void
    {
        $this->actingAsWithEmployee(42);
        $this->project('PRJ-2026-001', 42);
        $this->project('PRJ-2026-002', 77);
        $this->project('PRJ-2026-003', null);

        $this->assertSame(['PRJ-2026-001'], $this->codes('?mine=1'));
    }

    public function test_mine_false_returns_the_projects_the_caller_does_not_manage(): void
    {
        // The list filter is a Ya/Tidak select, so 'Tidak' must honestly mean
        // "bukan proyek saya" — not silently show everything, own projects
        // included.
        $this->actingAsWithEmployee(42);
        $this->project('PRJ-2026-001', 42);
        $this->project('PRJ-2026-002', 77);
        $this->project('PRJ-2026-003', null);

        $this->assertSame(['PRJ-2026-002', 'PRJ-2026-003'], $this->codes('?mine=0'));
    }

    public function test_without_the_parameter_the_list_is_unfiltered(): void
    {
        $this->actingAsWithEmployee(42);
        $this->project('PRJ-2026-001', 42);
        $this->project('PRJ-2026-002', 77);

        $this->assertSame(['PRJ-2026-001', 'PRJ-2026-002'], $this->codes());
    }

    public function test_mine_is_empty_for_a_user_without_an_employee_link(): void
    {
        // No employee link means the account manages no projects. All projects
        // would be the comfortable answer and the wrong one — it is exactly the
        // admin account that would read a full list as "these are mine".
        $this->actingAsWithEmployee(null);
        $this->project('PRJ-2026-001', 42);

        $this->assertSame([], $this->codes('?mine=1'));
    }
}
