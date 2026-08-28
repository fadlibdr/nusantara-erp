<?php

namespace Tests\Feature\Engineering;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Engineering\Models\Drawing;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Models\MaterialSubmittal;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * P1-ENG fixtures, in the WorkPermitTest style: a plain project (no EVM, no
 * BaselineFixtures — nothing here computes earned value), one admin login and
 * one separate eng.approve holder so maker-checker on the DECISION RECORDING
 * can be exercised both ways.
 *
 * Deliberately dumb: rows are assembled, never derived. Every expected number
 * or message is spelled out in the test asserting it.
 */
trait EngineeringFixtures
{
    private ?User $admin = null;

    private ?User $recorder = null;

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

    /**
     * A second pair of eyes: holds eng.approve (and enough view/update to
     * navigate) but is NOT the admin who created the documents, so recording a
     * decision or approving an IPP does not trip maker-checker by accident.
     */
    private function recorder(): User
    {
        if ($this->recorder === null) {
            $role = Role::findOrCreate('mk-recorder', 'web');
            $role->syncPermissions(['eng.view', 'eng.update', 'eng.approve']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var User $user */
            $user = User::query()->create([
                'name' => 'Ir. Ratna Dewi',
                'email' => 'document-control@test.local',
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->assignRole($role);
            $this->recorder = $user;
        }

        Sanctum::actingAs($this->recorder);

        return $this->recorder;
    }

    private function drawing(Project $project, array $attributes = []): Drawing
    {
        return Drawing::query()->create(array_merge([
            'project_id' => $project->id,
            'number' => 'GPC-ST-101',
            'title' => 'Denah Pondasi Bore Pile',
            'discipline' => 'struktur',
            'planned_submit_date' => '2026-03-01',
        ], $attributes));
    }

    /** A submittal created through the API by the ADMIN login. */
    private function submittal(Drawing $drawing, array $attributes = []): DrawingSubmittal
    {
        $this->admin();

        $response = $this->postJson('api/engineering/drawing-submittals', array_merge([
            'drawing_id' => $drawing->id,
            'revision' => 'R0',
            'submitted_at' => '2026-03-05',
            'reviewer_party' => 'mk',
        ], $attributes));

        $response->assertCreated();

        return DrawingSubmittal::query()->findOrFail($response->json('data.id'));
    }

    private function materialSubmittal(Project $project, array $attributes = []): MaterialSubmittal
    {
        $this->admin();

        $response = $this->postJson('api/engineering/material-submittals', array_merge([
            'project_id' => $project->id,
            'material_name' => 'Besi Beton Ulir D16',
            'brand' => 'Krakatau Steel',
            'spec_reference' => 'SNI 2052:2017',
            'submitted_at' => '2026-03-05',
            'reviewer_party' => 'mk',
        ], $attributes));

        $response->assertCreated();

        return MaterialSubmittal::query()->findOrFail($response->json('data.id'));
    }
}
