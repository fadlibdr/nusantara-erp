<?php

namespace Tests\Feature\Quality;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Location;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Models\InspectionTemplate;
use Modules\Subcontract\Models\Subcontract;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * P1-QC fixtures — a plain project, hierarchical locations, checklist templates,
 * and two logins so maker-checker on an inspection can be exercised both ways.
 * Deliberately dumb: rows are assembled, never derived; every expected number or
 * message is spelled out in the test that asserts it.
 */
trait QualityFixtures
{
    private ?User $admin = null;

    private ?User $approver = null;

    private function project(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-091',
            'name' => 'Gedung Perkantoran Cikarang',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-12-31',
            'warranty_months' => 12,
            'contract_value' => 20_000_000_000,
            'retention_pct' => 5,
        ], $attributes));
    }

    private function location(Project $project, array $attributes = []): Location
    {
        return Location::query()->create(array_merge([
            'project_id' => $project->id,
            'kind' => 'floor',
            'code' => 'LT-'.fake()->unique()->numerify('###'),
            'name' => 'Lantai 1 Zona A',
            'sort_order' => 1,
        ], $attributes));
    }

    private function template(InspectionStage $stage, array $attributes = []): InspectionTemplate
    {
        /** @var InspectionTemplate $template */
        $template = InspectionTemplate::query()->create(array_merge([
            'code' => 'Q'.fake()->unique()->numerify('##'),
            'work_package' => 'Pengecoran kolom struktur',
            'stage' => $stage,
        ], $attributes));

        $template->items()->create(['sort_order' => 1, 'check_text' => 'Kebersihan bekisting', 'acceptance' => 'Bersih', 'tolerance' => null]);
        $template->items()->create(['sort_order' => 2, 'check_text' => 'Selimut beton', 'acceptance' => 'Sesuai gambar', 'tolerance' => '± 5 mm']);

        return $template->load('items');
    }

    private function employee(array $attributes = []): Employee
    {
        return Employee::query()->create(array_merge([
            'code' => 'EMP-'.fake()->unique()->numerify('####'),
            'name' => 'Agus Prasetyo',
            'nik_ktp' => fake()->unique()->numerify('################'),
            'gender' => 'male',
            'birth_date' => '1988-05-01',
            'ptkp_status' => 'K/1',
            'join_date' => '2020-01-01',
            'employment_type' => 'tetap',
            'position' => 'Site Manager',
            'department' => 'proyek',
        ], $attributes));
    }

    private function subcontract(array $attributes = []): Subcontract
    {
        return Subcontract::query()->create(array_merge([
            'code' => 'SPK/2026/III/'.fake()->unique()->numerify('####'),
            'vendor_id' => 9001,
            'title' => 'Pekerjaan struktur beton',
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'status' => 'draft',
        ], $attributes));
    }

    private function admin(): User
    {
        if ($this->admin === null) {
            $this->admin = $this->adminUser();
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    /** A second pair of eyes: qc.approve, but not the admin who created the docs. */
    private function approver(): User
    {
        if ($this->approver === null) {
            $role = Role::findOrCreate('qc-lead', 'web');
            $role->syncPermissions(['qc.view', 'qc.update', 'qc.approve']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var User $user */
            $user = User::query()->create([
                'name' => 'Ir. Sinta Melati',
                'email' => 'qc-lead@test.local',
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->assignRole($role);
            $this->approver = $user;
        }

        Sanctum::actingAs($this->approver);

        return $this->approver;
    }
}
