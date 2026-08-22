<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\LeaveRequest;
use Tests\ErpTestCase;

/**
 * The leave workflow over HTTP: server-computed day counts, the saldo guards at
 * submit and approve, maker-checker, and the recap feed with its forward-only
 * stop at posted payroll periods.
 */
class LeaveRequestApiTest extends ErpTestCase
{
    use PayrollFixtures;

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user);

        return $user;
    }

    /** The second pair of eyes maker-checker demands. */
    private function leaveApprover(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'manajer-hr@test.local'],
            ['name' => 'Manajer HR', 'password' => 'password', 'is_active' => true],
        );
        $user->assignRole('admin');

        return $user;
    }

    private function postLeave(array $overrides = []): array
    {
        $response = $this->postJson('/api/hr/leave-requests', array_merge([
            'leave_type' => 'tahunan',
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-10',
            'reason' => 'Cuti keluarga',
        ], $overrides));

        $response->assertCreated();

        return $response->json('data');
    }

    public function test_day_count_is_computed_server_side_and_skips_sundays(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee(['join_date' => '2020-01-01']);

        // Mon 2026-06-08 through Sun 2026-06-14, with a typed day_count the
        // server must ignore: 1 day over that range would hand back saldo
        // nobody kept.
        $data = $this->postLeave([
            'employee_id' => $employee->id,
            'end_date' => '2026-06-14',
            'day_count' => 1,
        ]);

        $this->assertSame(6, $data['day_count']);
        $this->assertSame('draft', $data['status']);
        $this->assertStringStartsWith('CTI/', $data['code']);
    }

    public function test_overlapping_requests_for_the_same_employee_are_refused(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee(['join_date' => '2020-01-01']);
        $this->postLeave(['employee_id' => $employee->id]);

        $response = $this->postJson('/api/hr/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type' => 'izin',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Urusan keluarga',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('bertabrakan', (string) $response->json('message'));
    }

    public function test_submit_is_refused_before_twelve_months_of_service(): void
    {
        $this->actAsAdmin();
        // Joined 2026-01-01: by the leave's start date the masa kerja is seven
        // months — UU 13/2003 Pasal 79 says the right does not exist yet.
        $employee = $this->makeEmployee(['join_date' => '2026-01-01']);
        $data = $this->postLeave(['employee_id' => $employee->id, 'start_date' => '2026-08-10', 'end_date' => '2026-08-11']);

        $response = $this->postJson("/api/hr/leave-requests/{$data['id']}/submit");

        $response->assertStatus(422);
        $this->assertStringContainsString('belum genap 12 bulan', (string) $response->json('message'));
        $this->assertStringContainsString('2027-01-01', (string) $response->json('message'));
    }

    public function test_approve_rechecks_the_saldo_after_a_sibling_was_approved(): void
    {
        $admin = $this->actAsAdmin();
        $approver = $this->leaveApprover();
        $employee = $this->makeEmployee(['join_date' => '2025-01-01']);

        // Eleven working days (Mon 2026-03-02 .. Fri 2026-03-13, one Sunday in
        // between), then two more — both submitted while the saldo still reads
        // twelve.
        $big = $this->postLeave(['employee_id' => $employee->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-13']);
        $small = $this->postLeave(['employee_id' => $employee->id, 'start_date' => '2026-04-06', 'end_date' => '2026-04-07']);

        $this->postJson("/api/hr/leave-requests/{$big['id']}/submit")->assertOk();
        $this->postJson("/api/hr/leave-requests/{$small['id']}/submit")->assertOk();

        Sanctum::actingAs($approver);
        $this->postJson("/api/hr/leave-requests/{$big['id']}/approve")->assertOk();

        // The big one consumed eleven of twelve; the small one's two days no
        // longer fit and the approval must say so, not honour it.
        $response = $this->postJson("/api/hr/leave-requests/{$small['id']}/approve");

        $response->assertStatus(422);
        $this->assertStringContainsString('Saldo cuti tahunan tidak cukup', (string) $response->json('message'));
        $this->assertStringContainsString('sisa 1 hari', (string) $response->json('message'));

        $this->assertSame('submitted', LeaveRequest::query()->findOrFail($small['id'])->status->value);
    }

    public function test_the_submitter_cannot_approve_their_own_leave(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee(['join_date' => '2020-01-01']);
        $data = $this->postLeave(['employee_id' => $employee->id]);

        $this->postJson("/api/hr/leave-requests/{$data['id']}/submit")->assertOk();
        $response = $this->postJson("/api/hr/leave-requests/{$data['id']}/approve");

        $response->assertStatus(422);
        $this->assertStringContainsString('tidak boleh disetujui oleh pengajunya sendiri', (string) $response->json('message'));
    }

    public function test_approved_leave_fills_the_monthly_recap_by_type(): void
    {
        $this->actAsAdmin();
        $approver = $this->leaveApprover();
        $employee = $this->makeEmployee(['join_date' => '2020-01-01']);

        $annual = $this->postLeave(['employee_id' => $employee->id]); // 8–10 Jun = 3 hari
        $sick = $this->postLeave([
            'employee_id' => $employee->id,
            'leave_type' => 'sakit',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-16',
            'reason' => 'Tifus, surat dokter menyusul',
        ]);

        foreach ([$annual, $sick] as $row) {
            $this->postJson("/api/hr/leave-requests/{$row['id']}/submit")->assertOk();
        }

        Sanctum::actingAs($approver);
        $this->postJson("/api/hr/leave-requests/{$annual['id']}/approve")->assertOk();
        $this->postJson("/api/hr/leave-requests/{$sick['id']}/approve")->assertOk();

        $recap = AttendanceRecap::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', 2026)
            ->where('period_month', 6)
            ->firstOrFail();

        $this->assertSame(3, $recap->leave_days);
        $this->assertSame(2, $recap->sick_days);
    }

    /**
     * FORWARD-ONLY: a month whose regular payroll run is already approved
     * keeps its recap. The June half of a June–July leave lands; the July half
     * is skipped and the approver is told, because July's payroll is posted
     * and its recap is the record of what that run was computed from.
     */
    public function test_recap_of_a_posted_payroll_period_is_left_alone_and_reported(): void
    {
        $admin = $this->actAsAdmin();
        $approver = $this->leaveApprover();
        $employee = $this->makeEmployee(['join_date' => '2020-01-01']);

        $july = $this->makeRun(['period_month' => 7]);
        $july->submit($admin);
        $july->approve($approver);

        // Mon 2026-06-29 .. Thu 2026-07-02: two working days in each month.
        $leave = $this->postLeave([
            'employee_id' => $employee->id,
            'start_date' => '2026-06-29',
            'end_date' => '2026-07-02',
        ]);
        $this->postJson("/api/hr/leave-requests/{$leave['id']}/submit")->assertOk();

        Sanctum::actingAs($approver);
        $response = $this->postJson("/api/hr/leave-requests/{$leave['id']}/approve");

        $response->assertOk();
        $this->assertStringContainsString('2026-07', (string) $response->json('message'));
        $this->assertStringContainsString('sudah diposting', (string) $response->json('message'));

        $juneRecap = AttendanceRecap::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', 2026)->where('period_month', 6)
            ->first();
        $this->assertNotNull($juneRecap);
        $this->assertSame(2, $juneRecap->leave_days);

        $this->assertNull(
            AttendanceRecap::query()
                ->where('employee_id', $employee->id)
                ->where('period_year', 2026)->where('period_month', 7)
                ->first(),
            'The recap of a posted payroll period must not be created behind the posted run.',
        );

        // The register itself still holds the whole approved document.
        $this->assertSame('approved', LeaveRequest::query()->findOrFail($leave['id'])->status->value);
    }

    public function test_an_approved_request_cannot_be_edited_or_deleted(): void
    {
        $admin = $this->actAsAdmin();
        $approver = $this->leaveApprover();
        $employee = $this->makeEmployee(['join_date' => '2020-01-01']);
        $data = $this->postLeave(['employee_id' => $employee->id]);

        $this->postJson("/api/hr/leave-requests/{$data['id']}/submit")->assertOk();
        Sanctum::actingAs($approver);
        $this->postJson("/api/hr/leave-requests/{$data['id']}/approve")->assertOk();

        $this->putJson("/api/hr/leave-requests/{$data['id']}", [
            'leave_type' => 'tahunan',
            'start_date' => '2026-06-08',
            'end_date' => '2026-06-09',
            'reason' => 'Dipangkas',
        ])->assertStatus(422);

        $this->deleteJson("/api/hr/leave-requests/{$data['id']}")->assertStatus(422);

        $this->assertSame(3, LeaveRequest::query()->findOrFail($data['id'])->day_count);
    }

    public function test_a_range_longer_than_ninety_days_reads_as_a_typo(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee(['join_date' => '2020-01-01']);

        $response = $this->postJson('/api/hr/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type' => 'tahunan',
            'start_date' => '2026-06-08',
            'end_date' => '2027-06-08',
            'reason' => 'Salah ketik tahun',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('melebihi 90 hari', (string) $response->json('message'));
    }
}
