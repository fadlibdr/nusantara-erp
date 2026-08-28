<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\FormPrintService;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\Projects\Models\OvertimePermit;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Feature\HrPayroll\PayrollFixtures;

/**
 * P0-C — Izin Kerja Lembur (ILB) menjadi transaksi, dan persetujuannya
 * mengumpankan jam per KARYAWAN ke hr_attendance_recaps.overtime_hours —
 * satu-satunya tulisan lintas modul paket ini, lewat service HrPayroll
 * (OvertimeRecapService) yang menyalin pola LeaveService::syncRecaps:
 * FORWARD-ONLY, periode payroll terposting DILEWATI DAN DILAPORKAN, tidak
 * pernah ditulis ulang; jumlahnya DIHITUNG ULANG utuh per (karyawan, periode)
 * dari semua ILB approved — bukan increment yang salah begitu ada yang
 * disinkron dua kali.
 *
 * Baris pekerja: employee_id XOR worker_name. Kru mandor non-karyawan nyata —
 * tercetak dan menandatangani lembarnya — tetapi tidak punya baris rekap.
 */
class OvertimePermitTest extends ErpTestCase
{
    use PayrollFixtures;

    private ?User $admin = null;

    private ?User $approver = null;

    private int $projectSequence = 0;

    // -------------------------------------------------------------- fixtures

    private function project(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'PRJ-2026-083',
            'name' => 'Instalasi ELV Bank Artha',
            'type' => 'system_integration',
            'status' => 'active',
            'start_date' => '2026-01-05',
            'end_date' => '2026-12-20',
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

    private function approver(): User
    {
        if ($this->approver === null) {
            $role = Role::findOrCreate('project-manager-ilb', 'web');
            $role->syncPermissions(['prj.view', 'prj.update', 'prj.approve']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var User $user */
            $user = User::query()->create([
                'name' => 'Rina Wijaya',
                'email' => 'pm-ilb@test.local',
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->assignRole('project-manager-ilb');
            $this->approver = $user;
        }

        return $this->approver;
    }

    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'overtime_date' => '2026-06-10',
            'start_time' => '18:00',
            'end_time' => '21:00',
            'reason' => 'Kejar target pengecoran sebelum hujan musiman',
            'workers' => [],
        ], $overrides);
    }

    private function approvedPermit(array $workers, array $overrides = []): array
    {
        $this->admin();
        $project = $this->project(['code' => 'PRJ-2026-1'.str_pad((string) ++$this->projectSequence, 2, '0', STR_PAD_LEFT)]);

        $created = $this->postJson(
            '/api/projects/overtime-permits',
            $this->payload($project, $overrides + ['workers' => $workers]),
        )->assertCreated()->json('data');

        $this->postJson("/api/projects/overtime-permits/{$created['id']}/submit")->assertOk();

        Sanctum::actingAs($this->approver());

        return [$created, $this->postJson("/api/projects/overtime-permits/{$created['id']}/approve")];
    }

    private function recap(int $employeeId, int $year = 2026, int $month = 6): ?AttendanceRecap
    {
        return AttendanceRecap::query()
            ->where('employee_id', $employeeId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();
    }

    // ------------------------------------------------------- cycle and feed

    public function test_approval_feeds_employee_hours_to_the_recap_and_skips_crew_names(): void
    {
        $joko = $this->makeEmployee(['name' => 'Joko Susilo']);
        $made = $this->makeEmployee(['name' => 'Made Wirawan']);

        [$created, $response] = $this->approvedPermit([
            ['employee_id' => $joko->id, 'hours' => 3],
            ['employee_id' => $made->id, 'hours' => 2.5],
            // The mandor's own man: real on the sheet, absent from payroll.
            ['worker_name' => 'Paimin', 'hours' => 3],
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('ILB/', $created['code']);

        $this->assertSame(3.0, (float) $this->recap($joko->id)?->overtime_hours);
        $this->assertSame(2.5, (float) $this->recap($made->id)?->overtime_hours);
        // No third recap materialised for a name payroll does not know.
        $this->assertSame(2, AttendanceRecap::query()->count());

        $this->assertSame('approved', OvertimePermit::query()->findOrFail($created['id'])->status->value);
    }

    /**
     * FORWARD-ONLY: the recap of a month whose regular payroll run is already
     * approved is left alone, and the approver is TOLD — not silence, because
     * the approver is the one person who can still fix it by other means.
     */
    public function test_a_posted_payroll_period_is_skipped_and_reported(): void
    {
        $joko = $this->makeEmployee(['name' => 'Joko Susilo']);
        $this->makeRun(['period_month' => 6, 'status' => DocumentStatus::Approved]);

        [, $response] = $this->approvedPermit([
            ['employee_id' => $joko->id, 'hours' => 4],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('2026-06', (string) $response->json('message'));
        $this->assertStringContainsString('sudah diposting', (string) $response->json('message'));

        $this->assertNull($this->recap($joko->id), 'a posted period\'s recap must not be created behind the run');
        // The permit itself is still approved — the register keeps the truth
        // either way; only the recap of the posted period stays frozen.
        $this->assertSame(1, OvertimePermit::query()->where('status', 'approved')->count());
    }

    /**
     * The recompute is wholesale per (employee, period): approving a second
     * permit in the same month lands on the SUM of both permits' hours, and
     * nothing double-counts however often a sync runs.
     */
    public function test_a_second_permit_in_the_same_month_recomputes_instead_of_doubling(): void
    {
        $joko = $this->makeEmployee(['name' => 'Joko Susilo']);

        $this->approvedPermit([['employee_id' => $joko->id, 'hours' => 3]]);
        [, $second] = $this->approvedPermit(
            [['employee_id' => $joko->id, 'hours' => 2]],
            ['overtime_date' => '2026-06-17'],
        );

        $second->assertOk();
        $this->assertSame(5.0, (float) $this->recap($joko->id)?->overtime_hours);
    }

    public function test_rejection_feeds_nothing(): void
    {
        $this->admin();
        $joko = $this->makeEmployee(['name' => 'Joko Susilo']);
        $project = $this->project();

        $created = $this->postJson('/api/projects/overtime-permits', $this->payload($project, [
            'workers' => [['employee_id' => $joko->id, 'hours' => 3]],
        ]))->assertCreated()->json('data');
        $this->postJson("/api/projects/overtime-permits/{$created['id']}/submit")->assertOk();

        Sanctum::actingAs($this->approver());
        $this->postJson("/api/projects/overtime-permits/{$created['id']}/reject", ['note' => 'Bukan pekerjaan kritis'])
            ->assertOk();

        $this->assertSame(0, AttendanceRecap::query()->count());
    }

    // ------------------------------------------------------------ the gates

    public function test_a_worker_row_names_exactly_one_identity(): void
    {
        $this->admin();
        $joko = $this->makeEmployee(['name' => 'Joko Susilo']);
        $project = $this->project();

        // Both columns filled: whose hours are these?
        $both = $this->postJson('/api/projects/overtime-permits', $this->payload($project, [
            'workers' => [['employee_id' => $joko->id, 'worker_name' => 'Paimin', 'hours' => 2]],
        ]));
        $both->assertStatus(422);

        // Neither: an anonymous line on a sheet signed per person.
        $neither = $this->postJson('/api/projects/overtime-permits', $this->payload($project, [
            'workers' => [['hours' => 2]],
        ]));
        $neither->assertStatus(422);
    }

    public function test_zero_hours_are_refused(): void
    {
        $this->admin();
        $joko = $this->makeEmployee(['name' => 'Joko Susilo']);
        $project = $this->project();

        $this->postJson('/api/projects/overtime-permits', $this->payload($project, [
            'workers' => [['employee_id' => $joko->id, 'hours' => 0]],
        ]))->assertStatus(422);
    }

    /**
     * Overnight decision, written down: end_time < start_time means the shift
     * runs past midnight into the next calendar day (22:00 s/d 02:00 is four
     * real hours of concrete pouring). Only end == start is refused — a
     * zero-length lembur is a claim about nothing.
     */
    public function test_overnight_overtime_is_allowed_and_zero_length_is_refused(): void
    {
        $this->admin();
        $joko = $this->makeEmployee(['name' => 'Joko Susilo']);
        $project = $this->project();

        $this->postJson('/api/projects/overtime-permits', $this->payload($project, [
            'start_time' => '22:00',
            'end_time' => '02:00',
            'workers' => [['employee_id' => $joko->id, 'hours' => 4]],
        ]))->assertCreated();

        $zero = $this->postJson('/api/projects/overtime-permits', $this->payload($project, [
            'overtime_date' => '2026-06-11',
            'start_time' => '20:00',
            'end_time' => '20:00',
            'workers' => [['employee_id' => $joko->id, 'hours' => 1]],
        ]));
        $zero->assertStatus(422);
        $this->assertStringContainsString('20:00', json_encode($zero->json()));
    }

    // ------------------------------------------------------------- the sheet

    public function test_the_sheet_prints_every_worker_including_the_crew_payroll_never_sees(): void
    {
        $joko = $this->makeEmployee(['name' => 'Joko Susilo', 'position' => 'Teknisi ELV']);

        [$created] = $this->approvedPermit([
            ['employee_id' => $joko->id, 'hours' => 3],
            ['worker_name' => 'Paimin', 'hours' => 3],
        ]);

        $html = app(FormPrintService::class)->html('izin-lembur', ['id' => $created['id']]);

        $this->assertStringContainsString('IZIN KERJA LEMBUR', $html);
        $this->assertStringContainsString($created['code'], $html);
        $this->assertStringContainsString('Joko Susilo', $html);
        $this->assertStringContainsString('Teknisi ELV', $html, 'jabatan from the employee row');
        // The non-employee is REAL on paper even though no recap exists.
        $this->assertStringContainsString('Paimin', $html);
        $this->assertStringContainsString('Kejar target pengecoran', $html);
        $this->assertStringNotContainsString('dicetak kosong', $html);
    }
}
