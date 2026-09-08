<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\HrPayroll\Models\Attendance;
use Tests\ErpTestCase;

/**
 * Usulan rekap bulanan dari register (F-4).
 *
 * Yang diuji bukan hanya angkanya, melainkan apa yang usulan ini menolak
 * mengatakan: sakit, cuti, hari kerja dan lembur TIDAK punya angka di sini,
 * karena register tidak tahu apa-apa tentang keempatnya. Mengisinya dengan 0
 * akan terlihat seperti jawaban dan terbawa ke slip gaji sebagai hak yang
 * hilang.
 */
class AttendanceRecapProposalTest extends ErpTestCase
{
    use PayrollFixtures;

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user);

        return $user;
    }

    private function mark(int $employeeId, string $date, string $status, array $extra = []): void
    {
        Attendance::query()->create(array_merge([
            'employee_id' => $employeeId,
            'date' => $date,
            'status' => $status,
        ], $extra));
    }

    public function test_the_proposal_counts_each_status_separately(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $this->mark($employee->id, '2026-06-01', 'hadir');
        $this->mark($employee->id, '2026-06-02', 'hadir');
        $this->mark($employee->id, '2026-06-03', 'setengah_hari');
        $this->mark($employee->id, '2026-06-04', 'absen');
        // Di luar bulan yang diminta — tidak boleh ikut terhitung.
        $this->mark($employee->id, '2026-05-30', 'hadir');
        $this->mark($employee->id, '2026-07-01', 'hadir');

        $response = $this->getJson('/api/hr/attendance-recaps/proposal?period_year=2026&period_month=6');

        $response->assertOk();
        $row = $response->json('data.rows.0');

        $this->assertSame(4, $row['recorded_days']);
        $this->assertSame(2, $row['present_days']);
        $this->assertSame(1, $row['half_days']);
        $this->assertSame(1, $row['absent_days']);
    }

    /**
     * Bulan tanpa satu pun catatan TIDAK boleh muncul sebagai "0 hadir": itu
     * membaca seperti semua orang absen sebulan penuh. Barisnya tidak ada, dan
     * layar yang membacanya tahu bedanya.
     */
    public function test_an_employee_with_no_records_is_absent_from_the_proposal_not_zeroed(): void
    {
        $this->actAsAdmin();
        $recorded = $this->makeEmployee();
        $this->makeEmployee(); // tidak pernah dicatat bulan itu

        $this->mark($recorded->id, '2026-06-01', 'hadir');

        $response = $this->getJson('/api/hr/attendance-recaps/proposal?period_year=2026&period_month=6');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.rows'));
        $this->assertSame($recorded->id, $response->json('data.rows.0.employee_id'));
    }

    public function test_the_proposal_says_out_loud_what_it_does_not_know(): void
    {
        $this->actAsAdmin();

        $response = $this->getJson('/api/hr/attendance-recaps/proposal?period_year=2026&period_month=6');

        $response->assertOk();
        $fields = collect($response->json('data.not_proposed'))->pluck('field')->all();

        $this->assertSame(['sick_days', 'leave_days', 'work_days', 'overtime_hours'], $fields);

        foreach ($response->json('data.not_proposed') as $entry) {
            $this->assertNotSame('', trim((string) $entry['why']), 'Setiap kolom yang tidak diusulkan menyebut ALASANNYA.');
        }
    }

    /**
     * "Di luar lokasi" hanya dihitung bila jaraknya BENAR-BENAR terukur.
     * Hari tanpa jarak masuk ke kolomnya sendiri, bukan ke "di dalam".
     */
    public function test_unmeasured_days_are_counted_apart_from_outside_days(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $this->mark($employee->id, '2026-06-01', 'hadir', [
            'check_in_at' => '2026-06-01 07:00:00', 'check_in_distance_m' => 4200, 'check_in_geofence_m' => 500,
        ]);
        $this->mark($employee->id, '2026-06-02', 'hadir', [
            'check_in_at' => '2026-06-02 07:00:00', 'check_in_distance_m' => 12, 'check_in_geofence_m' => 500,
        ]);
        $this->mark($employee->id, '2026-06-03', 'hadir', [
            'check_in_at' => '2026-06-03 07:00:00',
        ]);
        // Diisi kerani dari kertas: tidak pernah ada absen ponsel sama sekali.
        $this->mark($employee->id, '2026-06-04', 'hadir');

        $response = $this->getJson('/api/hr/attendance-recaps/proposal?period_year=2026&period_month=6');

        $response->assertOk();
        $row = $response->json('data.rows.0');

        $this->assertSame(4, $row['recorded_days']);
        $this->assertSame(3, $row['clocked_days']);
        $this->assertSame(1, $row['outside_days']);
        $this->assertSame(1, $row['unmeasured_days'], 'Hari ber-absen tanpa jarak: satu (03), bukan dua.');
    }

    public function test_the_proposal_needs_hr_view(): void
    {
        /** @var User $outsider */
        $outsider = User::query()->create([
            'name' => 'Tanpa HR', 'email' => 'tanpa-hr-proposal@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        Sanctum::actingAs($outsider);

        $this->getJson('/api/hr/attendance-recaps/proposal?period_year=2026&period_month=6')->assertStatus(403);
    }

    public function test_a_missing_period_is_refused_rather_than_guessed(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/hr/attendance-recaps/proposal')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_year', 'period_month']);
    }
}
