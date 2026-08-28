<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Services\FormPrintService;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WorkPermit;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * P0-C — Izin Kerja Lapangan (IKL) menjadi transaksi.
 *
 * Sebelum paket ini Form F/IK dicetak sebagai PAD KOSONG berjangkar pada id
 * proyek; kini satu baris prj_work_permits adalah satu izin satu shift, dan
 * lembarnya dicetak DARI baris itu. Yang dijaga di sini:
 *
 *  - siklus penuh draft → submitted → approved lewat prj.approve, dengan
 *    maker-checker: pengaju tidak boleh menyetujui izinnya sendiri;
 *  - valid_from < valid_until, dan permit_date di dalam waktu pelaksanaan
 *    proyek (inklusif kedua ujungnya — hari pertama dan hari terakhir proyek
 *    adalah hari kerja; proyek tanpa tanggal tidak menegakkan batas apa pun);
 *  - lembar cetak berisi baris izinnya — dan kalimat "dicetak kosong" hilang.
 */
class WorkPermitTest extends ErpTestCase
{
    private ?User $admin = null;

    private ?User $approver = null;

    // -------------------------------------------------------------- fixtures

    private function project(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-081',
            'name' => 'Gedung Serbaguna Karawang',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-12-31',
            'warranty_months' => 12,
        ], $attributes));
    }

    private function mandor(): Employee
    {
        return Employee::query()->firstOrCreate(['code' => 'EMP-7001'], [
            'name' => 'Sutrisno Hadi',
            'nik_ktp' => '3216012504780001',
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
            $role = Role::findOrCreate('direktur', 'web');
            $role->syncPermissions(['prj.view', 'prj.update', 'prj.approve']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var User $user */
            $user = User::query()->create([
                'name' => 'Ir. Bambang Sutrisno',
                'email' => 'direktur-ikl@test.local',
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->assignRole('direktur');
            $this->approver = $user;
        }

        return $this->approver;
    }

    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'permit_date' => '2026-06-15',
            'shift' => 'pagi',
            'work_description' => 'Pengecoran kolom lantai 3 zona B',
            'hazard_notes' => "Bekerja di ketinggian\nTertimpa material",
            'ppe_required' => ['Helm proyek', 'Full body harness', 'Sepatu safety'],
            'valid_from' => '2026-06-15 08:00',
            'valid_until' => '2026-06-15 17:00',
            'requested_by' => $this->mandor()->id,
        ], $overrides);
    }

    // ------------------------------------------------------------ the cycle

    public function test_the_full_cycle_runs_draft_submit_approve(): void
    {
        $this->admin();
        $project = $this->project();

        $created = $this->postJson('/api/projects/work-permits', $this->payload($project))
            ->assertCreated()
            ->json('data');

        $this->assertStringStartsWith('IKL/', $created['code']);
        $this->assertSame('draft', $created['status']);

        $this->postJson("/api/projects/work-permits/{$created['id']}/submit")->assertOk();

        Sanctum::actingAs($this->approver());
        $this->postJson("/api/projects/work-permits/{$created['id']}/approve")->assertOk();

        $permit = WorkPermit::query()->findOrFail($created['id']);
        $this->assertSame('approved', $permit->status->value);
        $this->assertSame(1, $permit->approvals()->where('action', 'approved')->count());
    }

    /** Maker-checker: the person who submitted the permit may not approve it. */
    public function test_the_submitter_cannot_approve_their_own_permit(): void
    {
        $this->admin();
        $project = $this->project();

        $created = $this->postJson('/api/projects/work-permits', $this->payload($project))
            ->assertCreated()->json('data');
        $this->postJson("/api/projects/work-permits/{$created['id']}/submit")->assertOk();

        $response = $this->postJson("/api/projects/work-permits/{$created['id']}/approve");

        $response->assertStatus(422);
        $this->assertStringContainsString('pengajunya sendiri', (string) $response->json('message'));
        $this->assertSame('submitted', WorkPermit::query()->findOrFail($created['id'])->status->value);
    }

    // ------------------------------------------------------------ the gates

    public function test_valid_until_must_be_after_valid_from(): void
    {
        $this->admin();
        $project = $this->project();

        $response = $this->postJson('/api/projects/work-permits', $this->payload($project, [
            'valid_from' => '2026-06-15 17:00',
            'valid_until' => '2026-06-15 08:00',
        ]));

        $response->assertStatus(422);
        $message = json_encode($response->json());
        $this->assertStringContainsString('17:00', (string) $message);
        $this->assertStringContainsString('08:00', (string) $message);
    }

    public function test_a_permit_dated_outside_the_execution_window_is_refused(): void
    {
        $this->admin();
        $project = $this->project();

        $response = $this->postJson('/api/projects/work-permits', $this->payload($project, [
            'permit_date' => '2027-01-15',
            'valid_from' => '2027-01-15 08:00',
            'valid_until' => '2027-01-15 17:00',
        ]));

        $response->assertStatus(422);
        $message = json_encode($response->json());
        $this->assertStringContainsString('2026-03-01', (string) $message);
        $this->assertStringContainsString('2026-12-31', (string) $message);
    }

    /**
     * Edge decision, written down: the window is INCLUSIVE of both ends — the
     * first and the last day of the job are working days, and a permit for
     * either is exactly what the form exists for. A project with no dates yet
     * enforces nothing: no window, no gate.
     */
    public function test_the_window_edges_are_working_days_and_an_undated_project_enforces_nothing(): void
    {
        $this->admin();
        $project = $this->project();

        $this->postJson('/api/projects/work-permits', $this->payload($project, [
            'permit_date' => '2026-03-01',
            'valid_from' => '2026-03-01 08:00',
            'valid_until' => '2026-03-01 17:00',
        ]))->assertCreated();

        $this->postJson('/api/projects/work-permits', $this->payload($project, [
            'permit_date' => '2026-12-31',
            'valid_from' => '2026-12-31 08:00',
            'valid_until' => '2026-12-31 17:00',
        ]))->assertCreated();

        $undated = $this->project(['code' => 'PRJ-2026-082', 'start_date' => null, 'end_date' => null]);

        $this->postJson('/api/projects/work-permits', $this->payload($undated, [
            'project_id' => $undated->id,
            'permit_date' => '2031-01-01',
            'valid_from' => '2031-01-01 08:00',
            'valid_until' => '2031-01-01 17:00',
        ]))->assertCreated();
    }

    // ------------------------------------------------------------- the sheet

    public function test_the_sheet_prints_from_the_permit_rows_and_stops_claiming_to_be_blank(): void
    {
        $this->admin();
        $project = $this->project();

        $created = $this->postJson('/api/projects/work-permits', $this->payload($project))
            ->assertCreated()->json('data');

        $html = app(FormPrintService::class)->html('izin-kerja', ['id' => $created['id']]);

        $this->assertStringContainsString('IZIN KERJA LAPANGAN', $html);
        $this->assertStringContainsString($created['code'], $html);
        $this->assertStringContainsString('Pengecoran kolom lantai 3 zona B', $html);
        $this->assertStringContainsString('Bekerja di ketinggian', $html);
        $this->assertStringContainsString('Full body harness', $html);
        $this->assertStringContainsString('Sutrisno Hadi', $html, 'pemohon prints from requested_by');
        $this->assertStringContainsString('15 Juni 2026', $html, 'the permit date, not print-day');
        $this->assertStringContainsString('Pagi', $html, 'the shift');
        // The blank-pad sentence is gone WITH the blank-pad behaviour.
        $this->assertStringNotContainsString('dicetak kosong', $html);
        $this->assertStringNotContainsString('belum menyimpan data', $html);
    }

    public function test_the_print_endpoint_needs_prj_view(): void
    {
        $user = $this->admin();
        $project = $this->project();
        $created = $this->postJson('/api/projects/work-permits', $this->payload($project))
            ->assertCreated()->json('data');

        $user->roles->first()->revokePermissionTo('prj.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->refresh())
            ->get("/api/core/print/forms/izin-kerja/{$created['id']}")
            ->assertForbidden();
    }
}
