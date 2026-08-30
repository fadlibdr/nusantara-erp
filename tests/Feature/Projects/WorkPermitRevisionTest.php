<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WorkPermit;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P8 — revisi generik (D9) pada Izin Kerja Lapangan. Pola DrawingSubmittal,
 * kata demi kata: revisi adalah BARIS BARU bernomor baru; pendahulunya distempel
 * superseded_at + superseded_by_id, mempertahankan nomor dan seluruh riwayat
 * persetujuannya, dan tetap bisa dicetak — tetapi menolak setiap aksi yang kini
 * milik revisi hidupnya.
 */
class WorkPermitRevisionTest extends ErpTestCase
{
    private ?User $admin = null;

    private ?User $approver = null;

    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-088',
            'name' => 'Gudang Distribusi Cikarang',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function mandor(): Employee
    {
        return Employee::query()->firstOrCreate(['code' => 'EMP-7010'], [
            'name' => 'Sutrisno Hadi',
            'nik_ktp' => '3216012504780002',
            'gender' => 'male',
            'birth_date' => '1978-04-25',
            'ptkp_status' => 'K/2',
            'join_date' => '2021-01-04',
            'employment_type' => 'tetap',
            'position' => 'Mandor Sipil',
            'department' => 'proyek',
            'base_salary' => 7_500_000,
        ]);
    }

    private function admin(): User
    {
        if ($this->admin === null) {
            $this->admin = $this->adminUser();
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    private function approver(): User
    {
        if ($this->approver === null) {
            $role = Role::findOrCreate('direktur-rev', 'web');
            $role->syncPermissions(['prj.view', 'prj.update', 'prj.approve']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var User $user */
            $user = User::query()->create([
                'name' => 'Ir. Bambang Sutrisno',
                'email' => 'direktur-rev@test.local',
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->assignRole('direktur-rev');
            $this->approver = $user;
        }

        return $this->approver;
    }

    private function approvedPermit(): WorkPermit
    {
        $this->admin();

        $created = $this->postJson('/api/projects/work-permits', [
            'project_id' => $this->project()->id,
            'permit_date' => '2026-06-15',
            'shift' => 'pagi',
            'work_description' => 'Pengecoran kolom lantai 3 zona B',
            'ppe_required' => ['Helm proyek', 'Full body harness'],
            'valid_from' => '2026-06-15 08:00',
            'valid_until' => '2026-06-15 17:00',
            'requested_by' => $this->mandor()->id,
        ])->assertCreated()->json('data');

        $this->postJson("/api/projects/work-permits/{$created['id']}/submit")->assertOk();

        Sanctum::actingAs($this->approver());
        $this->postJson("/api/projects/work-permits/{$created['id']}/approve")->assertOk();

        Sanctum::actingAs($this->admin);

        return WorkPermit::query()->findOrFail($created['id']);
    }

    public function test_revising_creates_a_new_draft_row_and_stamps_the_predecessor(): void
    {
        $permit = $this->approvedPermit();
        $oldCode = $permit->code;

        $revised = $this->postJson("/api/projects/work-permits/{$permit->id}/revise")
            ->assertCreated()->json('data');

        // The revision is a NEW row with its OWN number and a fresh draft cycle.
        $this->assertNotSame($permit->id, $revised['id']);
        $this->assertNotSame($oldCode, $revised['code']);
        $this->assertSame('draft', $revised['status']);
        $this->assertSame(1, $revised['revision']);
        $this->assertSame('Pengecoran kolom lantai 3 zona B', $revised['work_description']);

        // The predecessor keeps its number and its approved status, and is
        // stamped as superseded by exactly this successor.
        $permit->refresh();
        $this->assertSame($oldCode, $permit->code);
        $this->assertSame('approved', $permit->status->value);
        $this->assertNotNull($permit->superseded_at);
        $this->assertSame($revised['id'], $permit->superseded_by_id);
    }

    public function test_the_predecessor_keeps_its_approval_history(): void
    {
        $permit = $this->approvedPermit();
        $before = $permit->approvals()->pluck('action')->all();
        $this->assertContains('approved', $before);

        $this->postJson("/api/projects/work-permits/{$permit->id}/revise")->assertCreated();

        // Not one core_approvals row moved: the history belongs to the sheet
        // that was actually approved.
        $this->assertSame($before, $permit->refresh()->approvals()->pluck('action')->all());
    }

    public function test_a_superseded_permit_refuses_the_actions_its_successor_owns(): void
    {
        $permit = $this->approvedPermit();
        $successor = $this->postJson("/api/projects/work-permits/{$permit->id}/revise")
            ->assertCreated()->json('data');

        $submit = $this->postJson("/api/projects/work-permits/{$permit->id}/submit");
        $submit->assertStatus(422);
        $this->assertStringContainsString('telah digantikan revisi', (string) $submit->json('message'));
        $this->assertStringContainsString($successor['code'], (string) $submit->json('message'));

        Sanctum::actingAs($this->approver());
        $this->postJson("/api/projects/work-permits/{$permit->id}/approve")->assertStatus(422);
        $this->postJson("/api/projects/work-permits/{$permit->id}/reject")->assertStatus(422);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/projects/work-permits/{$permit->id}", ['work_description' => 'Diubah diam-diam'])
            ->assertStatus(422);
        $this->deleteJson("/api/projects/work-permits/{$permit->id}")->assertStatus(422);

        // And a second revision must branch from the LIVE row, never the old one.
        $again = $this->postJson("/api/projects/work-permits/{$permit->id}/revise");
        $again->assertStatus(422);
        $this->assertStringContainsString('telah digantikan revisi', (string) $again->json('message'));
    }

    public function test_the_second_revision_counts_on_from_the_live_row(): void
    {
        $permit = $this->approvedPermit();
        $first = $this->postJson("/api/projects/work-permits/{$permit->id}/revise")
            ->assertCreated()->json('data');
        $second = $this->postJson("/api/projects/work-permits/{$first['id']}/revise")
            ->assertCreated()->json('data');

        $this->assertSame(2, $second['revision']);

        // The chain reads forward: R0 -> R1 -> R2, and only R2 is live.
        $this->assertSame($first['id'], WorkPermit::query()->findOrFail($permit->id)->superseded_by_id);
        $this->assertSame($second['id'], WorkPermit::query()->findOrFail($first['id'])->superseded_by_id);
        $this->assertNull(WorkPermit::query()->findOrFail($second['id'])->superseded_at);
    }

    public function test_revise_requires_prj_create(): void
    {
        $permit = $this->approvedPermit();

        $role = Role::findOrCreate('hanya-lihat-prj', 'web');
        $role->syncPermissions(['prj.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $viewer */
        $viewer = User::query()->create([
            'name' => 'Pengamat',
            'email' => 'pengamat-prj@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/projects/work-permits/{$permit->id}/revise")->assertForbidden();
    }
}
