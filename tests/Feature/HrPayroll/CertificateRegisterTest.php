<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Modules\HrPayroll\Enums\EmploymentType;
use Modules\HrPayroll\Models\Certificate;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Register sertifikat & PKWT.
 *
 * The stakes are both written elsewhere in the system. config/erp.php
 * (PP 9/2022): PPh final pelaksanaan konstruksi is 2,65% bersertifikat versus
 * 4,00% tanpa sertifikat — 1,35 points of every construction billing hangs on
 * certificates nobody tracked. And PP 35/2021: a PKWT worked past its end date
 * becomes PKWTT demi hukum — both kontrak employees in the live demo
 * (EMP-0007 Joko Susilo, EMP-0008 Made Wirawan) had no end date on file
 * anywhere before hr_employees.pkwt_end_date existed.
 */
class CertificateRegisterTest extends ErpTestCase
{
    use PayrollFixtures;

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** A user holding hr.view only — may read the register, never write it. */
    private function actAsViewer(): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('staf-hr-baca', 'web');
        $role->syncPermissions(['hr.view']);

        $user = User::query()->create([
            'name' => 'Staf HR Baca',
            'email' => 'staf-hr@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function certificatePayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
            'certificate_type' => 'skk',
            'name' => 'SKK Ahli Madya Teknik Bangunan Gedung',
            'number' => 'SKK-2024-001234',
            'issuer' => 'LPJK',
            'issued_date' => '2024-06-01',
            'expiry_date' => '2027-06-01',
        ], $overrides);
    }

    /** Everything EmployeeStoreRequest requires, PKWT fields via overrides. */
    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Karyawan Baru',
            'nik_ktp' => '3171012345678901',
            'gender' => 'male',
            'birth_date' => '1992-05-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2026-01-05',
            'employment_type' => 'kontrak',
            'position' => 'Teknisi',
            'department' => 'servis',
            'base_salary' => 6_000_000,
        ], $overrides);
    }

    // ------------------------------------------------------- certificate CRUD

    public function test_a_certificate_can_be_recorded_for_an_employee(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/certificates', $this->certificatePayload($employee));

        $response->assertStatus(201);
        $this->assertSame('skk', $response->json('data.certificate_type'));
        $this->assertSame('SKK Konstruksi', $response->json('data.certificate_type_label'));
        $this->assertSame($employee->code, $response->json('data.employee.code'));
        $this->assertSame('2027-06-01', $response->json('data.expiry_date'));
        $this->assertIsInt($response->json('data.days_to_expiry'));
    }

    public function test_the_register_lists_soonest_expiry_first_and_never_expiring_last(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();
        Certificate::query()->create($this->certificatePayload($employee, [
            'name' => 'Tidak kedaluwarsa', 'number' => null, 'expiry_date' => null,
        ]));
        Certificate::query()->create($this->certificatePayload($employee, [
            'name' => 'Jatuh tempo jauh', 'number' => null, 'expiry_date' => '2028-01-01',
        ]));
        Certificate::query()->create($this->certificatePayload($employee, [
            'name' => 'Jatuh tempo dekat', 'number' => null, 'expiry_date' => '2026-09-01',
        ]));

        $names = collect($this->getJson('/api/hr/certificates')->assertOk()->json('data'))
            ->pluck('name')->all();

        $this->assertSame(['Jatuh tempo dekat', 'Jatuh tempo jauh', 'Tidak kedaluwarsa'], $names);
    }

    /** Renewal is an update of expiry_date — no supersede chain, no new row. */
    public function test_a_renewal_updates_the_expiry_date_alone(): void
    {
        $this->actAsAdmin();
        $certificate = Certificate::query()->create($this->certificatePayload($this->makeEmployee()));

        $response = $this->putJson("/api/hr/certificates/{$certificate->id}", [
            'expiry_date' => '2030-06-01',
        ]);

        $response->assertOk();
        $this->assertSame('2030-06-01', $certificate->refresh()->expiry_date->toDateString());
        $this->assertSame(1, Certificate::query()->count());
    }

    public function test_a_renewal_dated_before_the_stored_issue_date_is_refused(): void
    {
        $this->actAsAdmin();
        $certificate = Certificate::query()->create($this->certificatePayload($this->makeEmployee()));

        // issued_date is not in the payload; the anchor must come from the row.
        $this->putJson("/api/hr/certificates/{$certificate->id}", ['expiry_date' => '2023-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_date');

        $this->assertSame('2027-06-01', $certificate->refresh()->expiry_date->toDateString());
    }

    /**
     * The reverse direction of the same window: a PUT carrying issued_date
     * ALONE must be checked against the stored expiry (2027-06-01), or HR can
     * "correct" the issue date to after the certificate already expired and
     * the watcher starts notifying off self-contradictory data.
     */
    public function test_moving_the_issue_date_past_the_stored_expiry_is_refused(): void
    {
        $this->actAsAdmin();
        $certificate = Certificate::query()->create($this->certificatePayload($this->makeEmployee()));

        $this->putJson("/api/hr/certificates/{$certificate->id}", ['issued_date' => '2028-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('issued_date');

        $this->assertSame('2024-06-01', $certificate->refresh()->issued_date->toDateString());
    }

    public function test_correcting_the_issue_date_within_the_window_is_accepted(): void
    {
        $this->actAsAdmin();
        $certificate = Certificate::query()->create($this->certificatePayload($this->makeEmployee()));

        $this->putJson("/api/hr/certificates/{$certificate->id}", ['issued_date' => '2025-01-01'])
            ->assertOk();

        $this->assertSame('2025-01-01', $certificate->refresh()->issued_date->toDateString());
    }

    public function test_an_expiry_on_or_before_the_issued_date_is_refused(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $this->postJson('/api/hr/certificates', $this->certificatePayload($employee, [
            'expiry_date' => '2024-06-01', // same day it was issued
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_date');

        $this->assertDatabaseCount('hr_certificates', 0);
    }

    /** Some principal certificates never lapse; NULL expiry is a valid state. */
    public function test_a_certificate_without_an_expiry_date_is_accepted(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/certificates', $this->certificatePayload($employee, [
            'certificate_type' => 'principal',
            'name' => 'Sertifikasi Partner CCTV',
            'issued_date' => null,
            'expiry_date' => null,
        ]));

        $response->assertStatus(201);
        $this->assertNull($response->json('data.expiry_date'));
        $this->assertNull($response->json('data.days_to_expiry'));
    }

    public function test_a_certificate_for_a_deleted_employee_is_refused(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();
        $employee->delete();

        $this->postJson('/api/hr/certificates', $this->certificatePayload($employee))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_a_dropped_certificate_is_soft_deleted_and_leaves_the_register(): void
    {
        $this->actAsAdmin();
        $certificate = Certificate::query()->create($this->certificatePayload($this->makeEmployee()));

        $this->deleteJson("/api/hr/certificates/{$certificate->id}")->assertOk();

        $this->assertSoftDeleted('hr_certificates', ['id' => $certificate->id]);
        $this->assertSame([], $this->getJson('/api/hr/certificates')->assertOk()->json('data'));
        // Implicit binding excludes trashed rows: the dropped cert is gone from
        // the API surface, not just filtered from one list.
        $this->getJson("/api/hr/certificates/{$certificate->id}")->assertStatus(404);
    }

    public function test_a_user_without_hr_create_cannot_record_a_certificate(): void
    {
        $this->actAsViewer();
        $employee = Employee::query()->create($this->employeePayload([
            'code' => 'EMP-9001', 'status' => 'active',
        ]));

        $this->getJson('/api/hr/certificates')->assertOk();
        $this->postJson('/api/hr/certificates', $this->certificatePayload($employee))->assertStatus(403);
        $this->assertDatabaseCount('hr_certificates', 0);
    }

    public function test_a_user_without_hr_update_cannot_renew_or_drop_a_certificate(): void
    {
        $this->actAsViewer();
        $employee = Employee::query()->create($this->employeePayload([
            'code' => 'EMP-9002', 'nik_ktp' => '3171012345678902', 'status' => 'active',
        ]));
        $certificate = Certificate::query()->create($this->certificatePayload($employee));

        $this->getJson("/api/hr/certificates/{$certificate->id}")->assertOk(); // hr.view may read
        $this->putJson("/api/hr/certificates/{$certificate->id}", ['expiry_date' => '2030-06-01'])
            ->assertStatus(403);
        $this->deleteJson("/api/hr/certificates/{$certificate->id}")->assertStatus(403);

        $this->assertSame('2027-06-01', $certificate->refresh()->expiry_date->toDateString());
        $this->assertNull($certificate->deleted_at);
    }

    /**
     * The register pairs employee names with certificate numbers and expiry
     * dates — personal data. hr.view gates the READS too, like crm.view does
     * for the guarantee register one module over.
     */
    public function test_a_user_without_hr_view_cannot_read_the_register(): void
    {
        $certificate = Certificate::query()->create($this->certificatePayload($this->makeEmployee()));

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('staf-non-hr', 'web');
        $role->syncPermissions(['crm.view']); // authenticated, but no hr.* at all

        $user = User::query()->create([
            'name' => 'Staf Non HR',
            'email' => 'staf-non-hr@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/hr/certificates')->assertStatus(403);
        $this->getJson("/api/hr/certificates/{$certificate->id}")->assertStatus(403);
    }

    // ------------------------------------------------------------------ PKWT

    public function test_a_kontrak_employee_can_carry_a_pkwt_end_date(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/hr/employees', $this->employeePayload([
            'pkwt_end_date' => '2027-01-04',
        ]));

        $response->assertStatus(201);
        $this->assertSame('2027-01-04', $response->json('data.pkwt_end_date'));
    }

    public function test_a_pkwt_end_date_on_a_tetap_employee_is_refused(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/hr/employees', $this->employeePayload([
            'employment_type' => 'tetap',
            'pkwt_end_date' => '2027-01-04',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pkwt_end_date');

        $this->assertDatabaseCount('hr_employees', 0);
    }

    /**
     * The renewal edit this column exists for: PUT carrying only the new end
     * date. A rule reading the request payload alone would refuse it because
     * employment_type and join_date are absent — the anchors must fall back to
     * the stored row.
     */
    public function test_a_partial_update_can_set_the_pkwt_end_date_alone(): void
    {
        $this->actAsAdmin();
        // join_date overridden: the 5-year Pasal 8 cap counts from the STORED
        // join date (fixture default 2020-01-01 would put 2027-03-31 past it).
        $employee = $this->makeEmployee(['employment_type' => EmploymentType::Kontrak, 'join_date' => '2026-01-05']);

        $response = $this->putJson("/api/hr/employees/{$employee->id}", [
            'pkwt_end_date' => '2027-03-31',
        ]);

        $response->assertOk();
        $this->assertSame('2027-03-31', $employee->refresh()->pkwt_end_date->toDateString());
    }

    public function test_a_partial_update_on_a_tetap_employee_still_refuses_the_pkwt_date(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee(); // fixture default: tetap

        $this->putJson("/api/hr/employees/{$employee->id}", ['pkwt_end_date' => '2027-03-31'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pkwt_end_date');

        $this->assertNull($employee->refresh()->pkwt_end_date);
    }

    public function test_a_pkwt_end_date_on_or_before_the_join_date_is_refused(): void
    {
        $this->actAsAdmin();
        // Fixture join_date is 2020-01-01; a PKWT "ending" that same day is a
        // data-entry slip, not a contract.
        $employee = $this->makeEmployee(['employment_type' => EmploymentType::Kontrak]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['pkwt_end_date' => '2020-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pkwt_end_date');
    }

    public function test_an_update_that_stays_kontrak_keeps_the_pkwt_date(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee([
            'employment_type' => EmploymentType::Kontrak,
            'join_date' => '2026-01-05',
            'pkwt_end_date' => '2027-03-31',
        ]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['position' => 'Teknisi Senior'])
            ->assertOk();

        $this->assertSame('2027-03-31', $employee->refresh()->pkwt_end_date->toDateString());
    }

    /**
     * PKWT → PKWTT is exactly the conversion PP 35/2021 forces when the date is
     * missed; when it happens the old clock must not survive on the row.
     */
    public function test_converting_a_kontrak_employee_to_tetap_clears_the_pkwt_date_and_basis(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee([
            'employment_type' => EmploymentType::Kontrak,
            'join_date' => '2026-01-05',
            'pkwt_basis' => 'jangka_waktu',
            'pkwt_end_date' => '2027-03-31',
        ]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['employment_type' => 'tetap'])
            ->assertOk();

        $employee->refresh();
        $this->assertNull($employee->pkwt_end_date);
        $this->assertNull($employee->pkwt_basis);
    }

    // ------------------------------------------------- PKWT: the 5-year cap

    /**
     * PP 35/2021 Pasal 8: a jangka-waktu PKWT, including perpanjangan, tops
     * out at 5 tahun. join 2026-01-05 with end 2035-01-01 is already PKWTT
     * demi hukum — recording it would have the watcher count down 8+ years to
     * a legally meaningless date.
     */
    public function test_a_pkwt_term_beyond_five_years_is_refused(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/hr/employees', $this->employeePayload([
            'pkwt_end_date' => '2035-01-01',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pkwt_end_date');

        $this->assertDatabaseCount('hr_employees', 0);
    }

    /** before_or_equal boundary: exactly join + 5 tahun is still lawful. */
    public function test_a_pkwt_term_of_exactly_five_years_is_accepted(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/hr/employees', $this->employeePayload([
            'pkwt_end_date' => '2031-01-05', // join 2026-01-05 + 5 tahun
        ]))->assertStatus(201);
    }

    public function test_a_partial_update_cannot_stretch_the_pkwt_past_five_years(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee(['employment_type' => EmploymentType::Kontrak, 'join_date' => '2026-01-05']);

        $this->putJson("/api/hr/employees/{$employee->id}", ['pkwt_end_date' => '2031-01-06'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pkwt_end_date');

        $this->assertNull($employee->refresh()->pkwt_end_date);
    }

    // --------------------------------------- PKWT: join_date moves alone

    /**
     * The reverse anchor. A PUT carrying join_date ALONE never triggers the
     * pkwt_end_date field rules, so without the after() check the row could
     * claim a PKWT (end 2026-12-31) that ends before it starts — and the
     * watcher would keep counting down to the bogus date.
     */
    public function test_moving_the_join_date_past_the_stored_pkwt_end_is_refused(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee([
            'employment_type' => EmploymentType::Kontrak,
            'join_date' => '2026-01-05',
            'pkwt_end_date' => '2026-12-31',
        ]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['join_date' => '2027-03-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('join_date');

        $this->assertSame('2026-01-05', $employee->refresh()->join_date->toDateString());
    }

    /** Moving join_date backwards can silently stretch the term past Pasal 8 too. */
    public function test_moving_the_join_date_cannot_stretch_the_pkwt_past_five_years(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee([
            'employment_type' => EmploymentType::Kontrak,
            'join_date' => '2026-01-05',
            'pkwt_end_date' => '2026-12-31',
        ]);

        // 2020-01-01 + 5 tahun = 2025-01-01, before the stored end 2026-12-31.
        $this->putJson("/api/hr/employees/{$employee->id}", ['join_date' => '2020-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('join_date');
    }

    public function test_moving_the_join_date_within_the_pkwt_window_is_accepted(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee([
            'employment_type' => EmploymentType::Kontrak,
            'join_date' => '2026-01-05',
            'pkwt_end_date' => '2026-12-31',
        ]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['join_date' => '2026-03-01'])
            ->assertOk();

        $this->assertSame('2026-03-01', $employee->refresh()->join_date->toDateString());
    }

    // --------------------------------------------------- PKWT: the two bases

    /**
     * PP 35/2021 Pasal 5 & 9 permit a PKWT with NO calendar end date —
     * berdasarkan selesainya suatu pekerjaan tertentu, the normal shape for
     * per-project construction crews. Recording that lawful fact must not
     * require inventing a fake date to silence the deadline watcher.
     */
    public function test_a_completion_based_pkwt_is_recorded_without_an_end_date(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/hr/employees', $this->employeePayload([
            'pkwt_basis' => 'selesainya_pekerjaan',
        ]));

        $response->assertStatus(201);
        $this->assertSame('selesainya_pekerjaan', $response->json('data.pkwt_basis'));
        $this->assertSame('Selesainya pekerjaan tertentu', $response->json('data.pkwt_basis_label'));
        $this->assertNull($response->json('data.pkwt_end_date'));
    }

    /**
     * A completion-based row may still record the Pasal 9 completion ESTIMATE
     * in pkwt_end_date, and that estimate is exempt from the Pasal 8 cap —
     * only a jangka-waktu term is bounded at 5 tahun.
     */
    public function test_a_completion_estimate_beyond_five_years_is_accepted(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/hr/employees', $this->employeePayload([
            'pkwt_basis' => 'selesainya_pekerjaan',
            'pkwt_end_date' => '2033-01-01', // join 2026-01-05: > 5 tahun as a term, fine as an estimate
        ]))->assertStatus(201);
    }

    public function test_a_pkwt_basis_on_a_tetap_employee_is_refused(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/hr/employees', $this->employeePayload([
            'employment_type' => 'tetap',
            'pkwt_basis' => 'selesainya_pekerjaan',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pkwt_basis');

        $this->assertDatabaseCount('hr_employees', 0);
    }
}
