<?php

namespace Tests\Feature\HrPayroll;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\LeaveType;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Services\LeaveService;
use Tests\ErpTestCase;

/**
 * The saldo arithmetic at its boundaries. UU 13/2003 Pasal 79: the right to 12
 * hari cuti tahunan EXISTS only after 12 months masa kerja — 11 months is 0
 * hari, not 11/12 of the year — and every entitlement year runs join-date
 * anniversary to anniversary.
 */
class LeaveBalanceTest extends ErpTestCase
{
    use PayrollFixtures;

    private function leaveService(): LeaveService
    {
        return app(LeaveService::class);
    }

    /**
     * An approved cuti tahunan row planted directly, bypassing the guards —
     * these tests exercise the arithmetic, not the workflow.
     */
    private function plantApprovedTahunan(Employee $employee, string $start, string $end, int $days): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'code' => 'CTI/TEST/'.str_pad((string) (LeaveRequest::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'employee_id' => $employee->id,
            'leave_type' => LeaveType::Tahunan,
            'start_date' => $start,
            'end_date' => $end,
            'day_count' => $days,
            'reason' => 'Cuti keluarga',
            'status' => DocumentStatus::Approved,
        ]);
    }

    public function test_eleven_months_of_service_means_zero_days(): void
    {
        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);

        $balance = $this->leaveService()->balance($employee, Carbon::parse('2026-06-01'));

        $this->assertFalse($balance['eligible']);
        $this->assertSame(0, $balance['entitled']);
        $this->assertSame(0, $balance['remaining']);
        $this->assertSame('2026-07-01', $balance['eligible_from']);
    }

    public function test_twelve_months_to_the_day_grants_the_full_twelve(): void
    {
        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);

        $balance = $this->leaveService()->balance($employee, Carbon::parse('2026-07-01'));

        $this->assertTrue($balance['eligible']);
        $this->assertSame(12, $balance['entitled']);
        $this->assertSame(12, $balance['remaining']);
        $this->assertSame('2026-07-01', $balance['window_start']);
        $this->assertSame('2027-07-01', $balance['window_end']);
    }

    public function test_approved_annual_leave_debits_the_window_it_starts_in(): void
    {
        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);
        $this->plantApprovedTahunan($employee, '2026-08-10', '2026-08-12', 3);

        $balance = $this->leaveService()->balance($employee, Carbon::parse('2026-09-01'));

        $this->assertSame(3, $balance['used']);
        $this->assertSame(9, $balance['remaining']);
    }

    public function test_sick_leave_never_debits_the_saldo(): void
    {
        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);
        LeaveRequest::query()->create([
            'code' => 'CTI/TEST/SICK',
            'employee_id' => $employee->id,
            'leave_type' => LeaveType::Sakit,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'day_count' => 3,
            'reason' => 'Demam berdarah, surat dokter terlampir',
            'status' => DocumentStatus::Approved,
        ]);

        $balance = $this->leaveService()->balance($employee, Carbon::parse('2026-09-01'));

        $this->assertSame(0, $balance['used']);
        $this->assertSame(12, $balance['remaining']);
    }

    /**
     * Default policy: NO carry-over. The nine unused days die on the second
     * anniversary and the new window opens at twelve, not twenty-one.
     */
    public function test_without_carry_over_the_remainder_dies_on_the_anniversary(): void
    {
        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);
        $this->plantApprovedTahunan($employee, '2026-08-10', '2026-08-12', 3);

        // Last day of the first entitlement year: still debited.
        $before = $this->leaveService()->balance($employee, Carbon::parse('2027-06-30'));
        $this->assertSame(9, $before['remaining']);

        // First day of the second entitlement year: fresh twelve.
        $after = $this->leaveService()->balance($employee, Carbon::parse('2027-07-01'));
        $this->assertSame('2027-07-01', $after['window_start']);
        $this->assertSame(0, $after['used']);
        $this->assertSame(0, $after['carried_over']);
        $this->assertSame(12, $after['remaining']);
    }

    public function test_carry_over_when_switched_on_brings_last_years_remainder_only(): void
    {
        config()->set('erp.hr.leave.carry_over', true);

        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);
        $this->plantApprovedTahunan($employee, '2026-08-10', '2026-08-12', 3);

        $balance = $this->leaveService()->balance($employee, Carbon::parse('2027-07-01'));

        $this->assertSame(9, $balance['carried_over']);
        $this->assertSame(21, $balance['remaining']);
    }

    public function test_submitted_requests_show_as_pending_without_debiting(): void
    {
        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);
        LeaveRequest::query()->create([
            'code' => 'CTI/TEST/PEND',
            'employee_id' => $employee->id,
            'leave_type' => LeaveType::Tahunan,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'day_count' => 3,
            'reason' => 'Cuti keluarga',
            'status' => DocumentStatus::Submitted,
        ]);

        $balance = $this->leaveService()->balance($employee, Carbon::parse('2026-09-01'));

        $this->assertSame(3, $balance['pending']);
        $this->assertSame(0, $balance['used']);
        $this->assertSame(12, $balance['remaining']);
    }

    /**
     * The endpoint the employee-detail saldo card reads. ?as_of pins the
     * entitlement window, so the card can also answer for a future request.
     */
    public function test_the_balance_endpoint_serves_the_same_arithmetic(): void
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user);

        $employee = $this->makeEmployee(['join_date' => '2025-07-01']);
        $this->plantApprovedTahunan($employee, '2026-08-10', '2026-08-12', 3);

        $response = $this->getJson("/api/hr/employees/{$employee->id}/leave-balance?as_of=2026-09-01");

        $response->assertOk();
        $this->assertTrue($response->json('data.eligible'));
        $this->assertSame(3, $response->json('data.used'));
        $this->assertSame(9, $response->json('data.remaining'));
    }

    /**
     * Six-day week (the default): only Sunday is a rest day, so Mon–Sun spans
     * six working days. On the five-day override, Saturday stops counting too.
     */
    public function test_working_days_follow_the_configured_work_week(): void
    {
        // 2026-06-08 is a Monday, 2026-06-14 the following Sunday.
        $from = Carbon::parse('2026-06-08');
        $to = Carbon::parse('2026-06-14');

        $this->assertSame(6, LeaveService::workingDays($from, $to));

        config()->set('erp.hr.leave.workweek_days', 5);
        $this->assertSame(5, LeaveService::workingDays($from, $to));
    }
}
