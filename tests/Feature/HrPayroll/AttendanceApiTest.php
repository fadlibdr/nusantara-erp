<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\HrPayroll\Models\Attendance;
use Tests\ErpTestCase;

/**
 * The absensi harian register: one sheet (one date, many employees) posted in
 * one request, idempotent against the (employee, date) unique key. Deliberately
 * NOT a payroll input — see AttendanceService's docblock.
 */
class AttendanceApiTest extends ErpTestCase
{
    use PayrollFixtures;

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_site_sheet_is_saved_in_one_request(): void
    {
        $clerk = $this->actAsAdmin();
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();

        $response = $this->postJson('/api/hr/attendances/bulk', [
            'date' => '2026-08-03',
            'project_id' => null,
            'entries' => [
                ['employee_id' => $a->id, 'status' => 'hadir'],
                ['employee_id' => $b->id, 'status' => 'absen', 'note' => 'Tanpa kabar'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.created'));
        $this->assertSame(0, $response->json('data.updated'));

        $row = Attendance::query()->where('employee_id', $b->id)->whereDate('date', '2026-08-03')->firstOrFail();
        $this->assertSame('absen', $row->status->value);
        $this->assertSame('Tanpa kabar', $row->note);
        $this->assertSame($clerk->id, (int) $row->recorded_by);
    }

    /**
     * The corrected sheet posted again fixes the day instead of doubling it —
     * the clerk's retry after a dropped connection must be idempotent.
     */
    public function test_reposting_the_sheet_updates_instead_of_duplicating(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $this->postJson('/api/hr/attendances/bulk', [
            'date' => '2026-08-03',
            'entries' => [['employee_id' => $employee->id, 'status' => 'absen']],
        ])->assertOk();

        $response = $this->postJson('/api/hr/attendances/bulk', [
            'date' => '2026-08-03',
            'entries' => [['employee_id' => $employee->id, 'status' => 'hadir']],
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('data.created'));
        $this->assertSame(1, $response->json('data.updated'));

        $rows = Attendance::query()->where('employee_id', $employee->id)->whereDate('date', '2026-08-03')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('hadir', $rows->first()->status->value);
    }

    public function test_a_future_date_is_refused_as_a_typo(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/attendances/bulk', [
            'date' => '2030-01-01',
            'entries' => [['employee_id' => $employee->id, 'status' => 'hadir']],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date']);
    }

    public function test_the_same_employee_twice_on_one_sheet_is_refused(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/attendances/bulk', [
            'date' => '2026-08-03',
            'entries' => [
                ['employee_id' => $employee->id, 'status' => 'hadir'],
                ['employee_id' => $employee->id, 'status' => 'absen'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['entries.0.employee_id']);
    }

    public function test_the_register_filters_by_date(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        foreach (['2026-08-03', '2026-08-04'] as $date) {
            $this->postJson('/api/hr/attendances/bulk', [
                'date' => $date,
                'entries' => [['employee_id' => $employee->id, 'status' => 'hadir']],
            ])->assertOk();
        }

        $response = $this->getJson('/api/hr/attendances?date=2026-08-04');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('2026-08-04', $response->json('data.0.date'));
    }
}
