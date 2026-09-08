<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\AttendanceCorrection;
use Tests\ErpTestCase;

/**
 * Koreksi absensi (F-4): baris boleh ditimpa, nilai lama TIDAK boleh hilang.
 *
 * Sebelum absensi bisa diisi orangnya sendiri, mengubah satu baris adalah
 * kerani membetulkan ketikannya. Sesudahnya, ia menimpa apa yang seorang
 * karyawan catatkan tentang dirinya — termasuk jam datang dan jam pulang. Uji
 * di berkas ini menjaga bahwa pertanyaan "siapa mengubah jam pulang saya,
 * kapan, kenapa" punya jawaban di setiap pintu yang bisa mengubahnya.
 */
class AttendanceCorrectionTest extends ErpTestCase
{
    use PayrollFixtures;

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user);

        return $user;
    }

    private function row(array $attributes = []): Attendance
    {
        $employee = $this->makeEmployee();

        /** @var Attendance $attendance */
        $attendance = Attendance::query()->create(array_merge([
            'employee_id' => $employee->id,
            'date' => '2026-09-07',
            'status' => 'hadir',
        ], $attributes));

        return $attendance;
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $this->actAsAdmin();
        $row = $this->row();

        $response = $this->putJson("/api/hr/attendances/{$row->id}", ['status' => 'absen']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
        $this->assertSame('hadir', $row->fresh()->status->value, 'Baris tidak boleh berubah saat alasannya ditolak.');
    }

    /**
     * "." memenuhi 'required' tetapi tidak menjelaskan apa pun tiga bulan
     * kemudian. Alasan yang terlalu pendek untuk dibaca sama saja dengan
     * tidak ada alasan.
     */
    public function test_a_one_character_reason_is_refused(): void
    {
        $this->actAsAdmin();
        $row = $this->row();

        $this->putJson("/api/hr/attendances/{$row->id}", ['status' => 'absen', 'reason' => '.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_a_correction_stores_the_old_value_and_the_reason(): void
    {
        $clerk = $this->actAsAdmin();
        $row = $this->row(['note' => 'Masuk pagi']);

        $this->putJson("/api/hr/attendances/{$row->id}", [
            'status' => 'absen',
            'note' => 'Tidak hadir tanpa kabar',
            'reason' => 'Salah tandai; yang bersangkutan tidak datang menurut mandor.',
        ])->assertOk();

        $trail = AttendanceCorrection::query()->where('attendance_id', $row->id)->orderBy('field')->get();

        $this->assertCount(2, $trail);
        $this->assertSame(['note', 'status'], $trail->pluck('field')->all());
        $this->assertSame('Masuk pagi', $trail->firstWhere('field', 'note')->old_value);
        $this->assertSame('hadir', $trail->firstWhere('field', 'status')->old_value);
        $this->assertSame('absen', $trail->firstWhere('field', 'status')->new_value);
        $this->assertSame('update', $trail->first()->source);
        $this->assertSame($clerk->id, (int) $trail->first()->corrected_by);
        $this->assertStringContainsString('mandor', $trail->first()->reason);
    }

    /** Jam pulang yang lupa ditekan bisa dikoreksi — itu kasus paling sering di lapangan. */
    public function test_a_supervisor_can_fill_in_a_forgotten_clock_out(): void
    {
        $this->actAsAdmin();
        $row = $this->row();

        $this->putJson("/api/hr/attendances/{$row->id}", [
            'status' => 'hadir',
            'check_out_at' => '2026-09-07 17:00:00',
            'reason' => 'Lupa absen pulang; jam keluar gerbang dari buku satpam.',
        ])->assertOk();

        $this->assertSame('2026-09-07 17:00:00', $row->fresh()->check_out_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            'check_out_at',
            AttendanceCorrection::query()->where('attendance_id', $row->id)->firstOrFail()->field,
        );
    }

    /**
     * Kolom yang TIDAK hadir di badan permintaan tidak disentuh, dan null
     * EKSPLISIT mengosongkan. Perbedaan itu penting: yang pertama adalah
     * formulir yang tidak menawarkan kolomnya, yang kedua adalah pengawas yang
     * sengaja membatalkan jam pulang yang salah.
     */
    public function test_an_absent_key_leaves_the_clock_alone_and_an_explicit_null_clears_it(): void
    {
        $this->actAsAdmin();
        $row = $this->row(['check_out_at' => '2026-09-07 17:00:00']);

        $this->putJson("/api/hr/attendances/{$row->id}", [
            'status' => 'hadir',
            'reason' => 'Hanya mengubah catatan, jam pulang jangan disentuh.',
            'note' => 'Shift sore',
        ])->assertOk();
        $this->assertNotNull($row->fresh()->check_out_at);

        $this->putJson("/api/hr/attendances/{$row->id}", [
            'status' => 'hadir',
            'check_out_at' => null,
            'reason' => 'Jam pulang milik orang lain, salah baris; dikosongkan.',
        ])->assertOk();
        $this->assertNull($row->fresh()->check_out_at);
    }

    /**
     * Koordinat, jarak dan ambang TIDAK bisa dikoreksi lewat pintu ini. Itu
     * hasil pengukuran, bukan pendapat — dan orang yang paling berkepentingan
     * menghapus tanda "di luar lokasi" adalah orang yang ditandai.
     */
    public function test_the_measurement_columns_cannot_be_edited_away(): void
    {
        $this->actAsAdmin();
        $row = $this->row([
            'check_in_at' => '2026-09-07 08:00:00',
            'check_in_latitude' => -6.9,
            'check_in_longitude' => 107.6,
            'check_in_distance_m' => 4200,
            'check_in_geofence_m' => 500,
        ]);

        $this->putJson("/api/hr/attendances/{$row->id}", [
            'status' => 'hadir',
            'reason' => 'Mencoba menghapus tanda di luar lokasi.',
            'check_in_distance_m' => 0,
            'check_in_geofence_m' => 999999,
            'check_in_latitude' => null,
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertSame(4200, $fresh->check_in_distance_m);
        $this->assertSame(500, $fresh->check_in_geofence_m);
        $this->assertTrue($fresh->outsideGeofence('check_in'));
    }

    /** Menyimpan tanpa mengubah apa pun bukan koreksi, dan tidak boleh mengarang jejak. */
    public function test_saving_without_changing_anything_records_no_trail(): void
    {
        $this->actAsAdmin();
        $row = $this->row(['note' => 'Masuk pagi']);

        $response = $this->putJson("/api/hr/attendances/{$row->id}", [
            'status' => 'hadir',
            'note' => 'Masuk pagi',
            'reason' => 'Menekan simpan tanpa mengubah apa pun.',
        ]);

        $response->assertOk();
        $this->assertSame(0, AttendanceCorrection::query()->count());
        $this->assertStringContainsString('Tidak ada nilai yang berubah', $response->json('message'));
    }

    /**
     * Lembar kerani yang dikirim ulang juga berjejak — pintu kedua yang
     * menimpa baris yang sama. Alasannya ditulis sistem (memaksa 40 alasan per
     * lembar berarti kerani kembali ke kertas), dan `source` yang membedakan
     * keduanya di layar.
     */
    public function test_the_reposted_sheet_leaves_a_trail_of_its_own_kind(): void
    {
        $this->actAsAdmin();
        $row = $this->row();

        $this->postJson('/api/hr/attendances/bulk', [
            'date' => '2026-09-07',
            'entries' => [['employee_id' => $row->employee_id, 'status' => 'absen']],
        ])->assertOk();

        $trail = AttendanceCorrection::query()->where('field', 'status')->firstOrFail();
        $this->assertSame('bulk', $trail->source);
        $this->assertSame('hadir', $trail->old_value);
        $this->assertSame('absen', $trail->new_value);
        $this->assertStringContainsString('dikirim ulang', $trail->reason);
    }

    /**
     * Lembar kertas tidak tahu jam berapa orangnya datang. Mengirimnya ulang
     * TIDAK BOLEH menghapus bukti GPS hari itu.
     */
    public function test_the_reposted_sheet_never_erases_the_gps_evidence(): void
    {
        $this->actAsAdmin();
        $row = $this->row([
            'check_in_at' => '2026-09-07 07:12:00',
            'check_in_distance_m' => 42,
            'check_in_geofence_m' => 500,
        ]);

        $this->postJson('/api/hr/attendances/bulk', [
            'date' => '2026-09-07',
            'entries' => [['employee_id' => $row->employee_id, 'status' => 'setengah_hari']],
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertSame('2026-09-07 07:12:00', $fresh->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(42, $fresh->check_in_distance_m);
    }

    /** Jejak yang bisa diedit tidak membuktikan apa pun: tidak ada rute update maupun delete. */
    public function test_the_trail_has_no_update_or_delete_door(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->filter(fn (string $line) => str_contains($line, 'corrections'))
            ->values()
            ->all();

        $this->assertSame(['GET|HEAD api/hr/attendances/{attendance}/corrections'], $routes);
    }

    /** Membaca jejak tidak boleh lebih mudah daripada membaca barisnya sendiri. */
    public function test_reading_the_trail_needs_hr_view(): void
    {
        $this->actAsAdmin();
        $row = $this->row();

        /** @var User $outsider */
        $outsider = User::query()->create([
            'name' => 'Tanpa HR', 'email' => 'tanpa-hr-f4@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        Sanctum::actingAs($outsider);

        $this->getJson("/api/hr/attendances/{$row->id}/corrections")->assertStatus(403);
    }
}
