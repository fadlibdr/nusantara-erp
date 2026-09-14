<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * TIMESHEET SAYA, DAN TIDAK PERNAH MILIK ORANG LAIN (F-5, T5.4 + T5.6).
 *
 * Tiga pintu, dan yang ketiga adalah yang perlu penjelasan.
 *
 * `timesheet/me` tidak punya gerbang izin — pola `attendances/me` F-4 — karena
 * tukang, teknisi dan pengemudi tidak memegang satu pun izin hr.*, dan sebuah
 * timesheet yang hanya bisa dilihat HR adalah timesheet yang orangnya tidak
 * pernah bisa membantah. Yang menggantikan gerbangnya adalah bentuk kodenya:
 * tidak ada satu parameter pun di pintu itu yang menyebut orang lain.
 *
 * `timesheet/{id}` PUNYA parameter yang menyebut orang lain, jadi ia menegakkan
 * izinnya sendiri — dan menolak dengan **404 yang sama persis** dengan id yang
 * tidak ada sama sekali (pola PushSubscriptionEndpointTest). 403 akan memberi
 * tahu pemanggil bahwa id itu ADA; dengan menyapu id 1..N siapa pun lalu bisa
 * menghitung jumlah karyawan perusahaan tanpa memegang izin apa pun. Dua
 * kalimat penolakan yang berbeda adalah cara menghitung orang.
 *
 * Karena itu uji di berkas ini menjalankan KEDUA arah, dan membandingkan kedua
 * badan jawabannya sebagai satu asersi.
 */
class TimesheetApiTest extends ErpTestCase
{
    use PayrollFixtures;

    private function userFor(?Employee $employee, bool $withHrView = false): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengguna '.($employee?->code ?? 'tanpa kartu'),
            'email' => strtolower(str_replace(' ', '', ($employee?->code ?? 'nobody'))).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'employee_id' => $employee?->id,
        ]);

        if ($withHrView) {
            $role = Role::findOrCreate('hr-viewer', 'web');
            $role->syncPermissions(Permission::query()->where('guard_name', 'web')->where('name', 'hr.view')->get());
            $user->assignRole('hr-viewer');
        }

        return $user;
    }

    private function clockedDay(Employee $employee, string $date, string $in, ?string $out): void
    {
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => 'hadir',
            'check_in_at' => "{$date} {$in}:00",
            'check_out_at' => $out === null ? null : "{$date} {$out}:00",
        ]);
    }

    public function test_every_door_needs_a_session(): void
    {
        $this->getJson('api/hr/timesheet?period_year=2026&period_month=6')->assertUnauthorized();
        $this->getJson('api/hr/timesheet/me')->assertUnauthorized();
        $this->getJson('api/hr/timesheet/1')->assertUnauthorized();
    }

    // ------------------------------------------------------ milik sendiri

    public function test_a_worker_without_any_hr_permission_can_read_their_own_timesheet(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '19:00');

        $this->actingAs($this->userFor($employee), 'sanctum')
            ->getJson('api/hr/timesheet/me?period_year=2026&period_month=6')
            ->assertOk()
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.summary.overtime_minutes', 120)
            ->assertJsonPath('data.policy.rounding_minutes', 15);
    }

    public function test_a_worker_can_read_their_own_timesheet_through_the_id_door_too(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '19:00');

        $this->actingAs($this->userFor($employee), 'sanctum')
            ->getJson("api/hr/timesheet/{$employee->id}?period_year=2026&period_month=6")
            ->assertOk()
            ->assertJsonPath('data.summary.employee_id', $employee->id);
    }

    public function test_an_account_without_an_employee_card_gets_a_sentence_not_an_empty_screen(): void
    {
        $response = $this->actingAs($this->userFor(null), 'sanctum')
            ->getJson('api/hr/timesheet/me')
            ->assertOk()
            ->assertJsonPath('data.linked', false);

        $this->assertStringContainsString('belum ditautkan ke data karyawan', (string) $response->json('message'));
    }

    // ----------------------------------------------------- milik orang lain

    public function test_reading_someone_elses_timesheet_answers_exactly_the_same_404_as_an_id_that_does_not_exist(): void
    {
        $me = $this->makeEmployee();
        $someoneElse = $this->makeEmployee();
        $this->clockedDay($someoneElse, '2026-06-01', '08:00', '19:00');

        $actor = $this->userFor($me);

        $theirs = $this->actingAs($actor, 'sanctum')->getJson("api/hr/timesheet/{$someoneElse->id}")->assertStatus(404);
        $nothing = $this->actingAs($actor, 'sanctum')->getJson('api/hr/timesheet/999999')->assertStatus(404);

        $this->assertSame(
            $nothing->json(),
            $theirs->json(),
            'Dua kalimat penolakan yang berbeda adalah cara menghitung karyawan perusahaan dengan '
            .'menyapu id 1..N tanpa memegang satu pun izin.',
        );
    }

    public function test_hr_view_opens_someone_elses_timesheet(): void
    {
        $me = $this->makeEmployee();
        $someoneElse = $this->makeEmployee();
        $this->clockedDay($someoneElse, '2026-06-01', '08:00', '19:00');

        $this->actingAs($this->userFor($me, withHrView: true), 'sanctum')
            ->getJson("api/hr/timesheet/{$someoneElse->id}?period_year=2026&period_month=6")
            ->assertOk()
            ->assertJsonPath('data.summary.overtime_minutes', 120);
    }

    public function test_the_period_wide_list_is_closed_to_anyone_without_hr_view(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '19:00');

        $this->actingAs($this->userFor($employee), 'sanctum')
            ->getJson('api/hr/timesheet?period_year=2026&period_month=6')
            ->assertForbidden();
    }

    public function test_the_period_wide_list_opens_with_hr_view(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '19:00');

        $this->actingAs($this->userFor($employee, withHrView: true), 'sanctum')
            ->getJson('api/hr/timesheet?period_year=2026&period_month=6')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.overtime_hours', 2);
    }

    // ------------------------------------------------------ keadaan kosong

    public function test_an_empty_period_answers_with_no_rows_rather_than_a_row_of_zeroes_per_employee(): void
    {
        $this->makeEmployee();
        $this->makeEmployee();
        $this->makeEmployee();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->getJson('api/hr/timesheet?period_year=2026&period_month=6')
            ->assertOk()
            ->assertJsonCount(0, 'data.rows')
            ->assertJsonPath('data.period.label', 'Juni 2026');
    }

    public function test_the_me_door_of_an_employee_with_nothing_recorded_still_draws_a_calendar_of_unknowns(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->actingAs($this->userFor($employee), 'sanctum')
            ->getJson('api/hr/timesheet/me?period_year=2026&period_month=6')
            ->assertOk();

        $this->assertCount(30, $response->json('data.days'), 'Juni punya 30 hari, dan semuanya harus terlihat.');
        $this->assertNull(
            $response->json('data.summary.worked_minutes'),
            'Nol jam kerja sebulan adalah tuduhan; yang benar adalah tidak ada yang pernah diukur.',
        );
        $this->assertSame('tidak_tercatat', $response->json('data.days.0.state'));
    }

    // ----------------------------------------------- rute tidak saling telan

    public function test_the_me_route_is_not_swallowed_by_the_parameterised_one(): void
    {
        $employee = $this->makeEmployee();

        // 'me' bukan angka: kalau rute ini ditelan pola {employee}, jawabannya
        // 404 — cacat yang sudah pernah terjadi dua kali di modul ini.
        $this->actingAs($this->userFor($employee), 'sanctum')
            ->getJson('api/hr/timesheet/me')
            ->assertOk();
    }

    public function test_the_payload_carries_the_policy_it_used_so_the_numbers_can_be_checked(): void
    {
        $employee = $this->makeEmployee();

        $policy = $this->actingAs($this->userFor($employee), 'sanctum')
            ->getJson('api/hr/timesheet/me?period_year=2026&period_month=6')
            ->assertOk()
            ->json('data.policy');

        foreach (['day_start', 'late_tolerance_minutes', 'normal_hours_per_day', 'rounding_minutes',
            'overtime_minimum_minutes', 'overtime_daily_cap_hours', 'overtime_weekly_cap_hours',
            'overtime_first_hour_pct', 'overtime_next_hours_pct', 'holidays_known'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $policy,
                "Kebijakan '{$key}' tidak ikut dikirim. Sebuah jam lembur tanpa aturan yang "
                .'menghasilkannya adalah angka yang tidak bisa diperiksa siapa pun.',
            );
        }

        $this->assertFalse(
            $policy['holidays_known'],
            'Sistem ini tidak punya kalender hari libur nasional, dan layar harus bisa mengatakannya.',
        );
    }
}
