<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\AttendanceCorrection;
use Modules\HrPayroll\Models\Employee;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Modules\Projects\Models\Project;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Absen masuk/pulang dari ponsel (F-4).
 *
 * Yang diuji di sini bukan "apakah kolomnya terisi" melainkan tiga kalimat
 * yang boleh dan tidak boleh dikatakan sistem ini kepada pemakainya:
 * posisi tidak pernah menolak, tidak-tahu bukan nol, dan jam ponsel tidak
 * pernah menggantikan jam server.
 */
class AttendanceClockTest extends ErpTestCase
{
    use PayrollFixtures;

    /** Monas, Jakarta. Titik proyek pada semua uji di berkas ini. */
    private const SITE_LAT = -6.1753924;

    private const SITE_LNG = 106.8271528;

    private function project(array $attributes = []): Project
    {
        /** @var Project $project */
        $project = Project::query()->create(array_merge([
            'code' => 'PRJ-F4-'.str_pad((string) (Project::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => 'Proyek Uji F-4',
            'type' => 'construction',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ], $attributes));

        return $project;
    }

    /**
     * Seorang tukang: TANPA satu pun izin hr.*, ditautkan ke kartu karyawan.
     * Kalau absen masuk menuntut izin HR, absensi ponsel tidak dipakai siapa
     * pun yang benar-benar berdiri di lapangan.
     */
    private function fieldUser(Employee $employee): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('tukang-f4', 'web');
        $role->syncPermissions(['prj.view']);

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Tukang F4',
            'email' => 'tukang-f4-'.$employee->id.'@test.local',
            'password' => 'password',
            'is_active' => true,
            'employee_id' => $employee->id,
        ]);
        $user->assignRole('tukang-f4');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_field_worker_without_any_hr_permission_can_clock_in(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project();

        $response = $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $project->id,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ]);

        $response->assertOk();
        $this->assertSame('recorded', $response->json('data.outcome'));

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($row->check_in_at);
        $this->assertSame('hadir', $row->status->value);
        $this->assertSame(0, $row->check_in_distance_m);
        $this->assertFalse($row->outsideGeofence('check_in'));
    }

    /**
     * ATURAN 1. Di luar radius TIDAK ditolak — dicatat dan ditandai.
     *
     * Kalau ini pernah menjadi 422, orang yang tetap bekerja hari itu tidak
     * punya catatan sama sekali, dan yang paling sering kena adalah gudang
     * berdinding beton dan ponsel murah, bukan orang yang berbohong.
     */
    public function test_a_punch_far_outside_the_geofence_is_recorded_and_flagged_never_refused(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project();

        // ~1,1 km ke utara: 0,01 derajat lintang.
        $response = $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $project->id,
            'latitude' => self::SITE_LAT + 0.01,
            'longitude' => self::SITE_LNG,
        ]);

        $response->assertOk();
        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertNotNull($row->check_in_at, 'Absensi di luar radius WAJIB tersimpan.');
        $this->assertGreaterThan(1000, $row->check_in_distance_m);
        $this->assertTrue($row->outsideGeofence('check_in'));
        $this->assertSame(500, $row->check_in_geofence_m, 'Ambang yang berlaku ikut distempel di barisnya.');
        $this->assertSame('outside', $response->json('data.attendance.check_in.verdict'));
        $this->assertStringContainsString('DI LUAR', $response->json('message'));
    }

    /**
     * ATURAN 2, sisi ponsel. Izin lokasi ditolak → jarak NULL, bukan 0.
     *
     * 0 m berarti "berdiri tepat di titik proyek". Menuliskannya untuk "tidak
     * ada yang tahu" adalah satu perubahan kolom yang mengubah setiap layar di
     * atasnya menjadi bohong, dan tidak ada satu pun yang akan mengeluh.
     */
    public function test_a_punch_without_a_position_has_no_distance_at_all(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project();

        $response = $this->postJson('/api/hr/attendances/me/clock-in', ['project_id' => $project->id]);

        $response->assertOk();
        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertNotNull($row->check_in_at);
        $this->assertNull($row->check_in_distance_m);
        $this->assertNull($row->check_in_geofence_m);
        $this->assertNull($row->outsideGeofence('check_in'));
        $this->assertSame('unknown', $response->json('data.attendance.check_in.verdict'));
        $this->assertSame('—', $response->json('data.attendance.check_in.distance_text'));
        $this->assertSame('Lokasi tidak terukur', $response->json('data.attendance.check_in.verdict_text'));
    }

    /** ATURAN 2, sisi proyek: titik peta yang belum diisi menghasilkan jawaban yang sama. */
    public function test_a_project_without_coordinates_yields_no_distance_either(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project(['latitude' => null, 'longitude' => null]);

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $project->id,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($row->check_in_distance_m);
        $this->assertNull($row->outsideGeofence('check_in'));
    }

    /** Setengah pasangan koordinat bukan posisi: lintang tanpa bujur tidak disimpan sebagai titik. */
    public function test_half_a_coordinate_pair_is_not_a_position(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project();

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $project->id,
            'latitude' => self::SITE_LAT,
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($row->check_in_latitude);
        $this->assertNull($row->check_in_distance_m);
    }

    /**
     * ATURAN 3. Jam ponsel DICATAT di samping jam server, tidak pernah
     * menggantikannya — bahkan ketika ia dipalsukan tiga jam ke belakang.
     */
    public function test_a_falsified_device_clock_is_recorded_but_never_becomes_the_server_time(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:30:00'));
        $liar = '2026-09-08 05:30:00';

        $this->postJson('/api/hr/attendances/me/clock-in', ['device_at' => $liar])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-08 08:30:00', $row->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-08 05:30:00', $row->check_in_device_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    /**
     * Antrean luring yang menyeberangi tengah malam: butir dibuat 23:50,
     * terkirim 00:10 keesokan harinya. Barisnya milik hari KEMARIN — kalau
     * tidak, absen kemarin menabrak kunci unik hari ini dan menimpanya.
     */
    public function test_a_queued_punch_delivered_after_midnight_belongs_to_the_day_it_happened(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-09 00:10:00'));

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'device_at' => '2026-09-08 23:50:00',
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-08', $row->date->toDateString());

        Carbon::setTestNow();
    }

    /** Jam ponsel yang salah setel berbulan-bulan tidak boleh membuat absensi di tahun lain. */
    public function test_a_wildly_wrong_device_clock_does_not_move_the_working_day(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-08 08:00:00'));

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'device_at' => '2019-03-04 08:00:00',
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-08', $row->date->toDateString());
        $this->assertSame('2019-03-04 08:00:00', $row->check_in_device_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    /**
     * Jam ponsel yang mengirim sampah kehilangan JAMNYA, bukan absensinya.
     *
     * Orangnya berdiri di gerbang dan menekan tombol; yang rusak cuma jam
     * ponselnya. 422 di sini berarti tidak ada baris, tidak ada selfie, dan
     * tidak ada catatan bahwa ia datang.
     */
    public function test_a_garbage_device_clock_costs_the_clock_not_the_punch(): void
    {
        foreach (['banana', '0000-00-00 00:00:00', '', '   '] as $junk) {
            $employee = $this->makeEmployee();
            $this->fieldUser($employee);

            $response = $this->postJson('/api/hr/attendances/me/clock-in', ['device_at' => $junk]);

            $response->assertOk();
            $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
            $this->assertNotNull($row->check_in_at, "Absensi hilang untuk device_at [{$junk}].");
            $this->assertNull($row->check_in_device_at, "Jam perangkat yang tidak terbaca harus kosong, bukan ditebak [{$junk}].");
        }
    }

    /** Jam ponsel yang berjalan MAJU tidak boleh membuat absensi di masa depan. */
    public function test_a_device_clock_running_ahead_never_creates_a_future_day(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-08 23:55:00'));

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'device_at' => '2026-09-09 00:05:00',
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-08', $row->date->toDateString());

        Carbon::setTestNow();
    }

    /**
     * Ponsel mengirim ISO-8601 ber-offset. Absen pukul 06.30 WIB adalah 23.30
     * UTC HARI SEBELUMNYA — dipakai apa adanya, setiap absen pagi sebelum pukul
     * tujuh akan diarsipkan ke tanggal kemarin dan menabrak kunci unik hari
     * kemarin.
     */
    public function test_an_iso_offset_from_the_phone_is_read_in_jakarta_time(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-08 06:31:00'));

        // 2026-09-07T23:30:00Z == 2026-09-08 06:30 WIB.
        $this->postJson('/api/hr/attendances/me/clock-in', [
            'device_at' => '2026-09-07T23:30:00.000Z',
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-08', $row->date->toDateString(), 'Absen pagi WIB bukan absen kemarin.');
        $this->assertSame('06:30', $row->check_in_device_at->format('H:i'), 'Jam perangkat dibaca dalam zona aplikasi.');

        Carbon::setTestNow();
    }

    /**
     * Butir antrean yang sama dikirim ulang membawa jam ponsel yang sama —
     * satu-satunya tanda yang membedakan "kirim ulang" dari "ditekan dua kali".
     */
    public function test_the_same_queued_item_resent_changes_nothing(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $device = '2026-09-08 07:02:00';

        $first = $this->postJson('/api/hr/attendances/me/clock-out', ['device_at' => $device]);
        $first->assertOk();
        $this->assertSame('recorded', $first->json('data.outcome'));

        $stored = Attendance::query()->where('employee_id', $employee->id)->firstOrFail()->check_out_at;

        $again = $this->postJson('/api/hr/attendances/me/clock-out', ['device_at' => $device]);
        $again->assertOk();
        $this->assertSame('duplicate', $again->json('data.outcome'));

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertTrue($stored->equalTo($row->check_out_at));
        $this->assertSame(0, AttendanceCorrection::query()->count(), 'Kirim ulang bukan koreksi.');
    }

    /** Absen masuk kedua di hari yang sama: yang PERTAMA menang, dan dikatakan. */
    public function test_a_second_clock_in_keeps_the_earlier_one(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-08 07:00:00'));
        $this->postJson('/api/hr/attendances/me/clock-in', [])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-08 09:30:00'));
        $second = $this->postJson('/api/hr/attendances/me/clock-in', []);

        $second->assertOk();
        $this->assertSame('kept_earlier', $second->json('data.outcome'));
        $this->assertStringContainsString('07:00', $second->json('message'));

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-08 07:00:00', $row->check_in_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    /**
     * Absen pulang KEDUA menang (orang benar-benar pulang belakangan) — tapi
     * jam pulang yang lama tidak lenyap: ia menjadi baris jejak.
     */
    public function test_a_later_clock_out_replaces_the_earlier_one_and_leaves_a_trail(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-08 16:00:00'));
        $this->postJson('/api/hr/attendances/me/clock-out', ['device_at' => '2026-09-08 16:00:00'])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-08 18:20:00'));
        $second = $this->postJson('/api/hr/attendances/me/clock-out', ['device_at' => '2026-09-08 18:20:00']);

        $second->assertOk();
        $this->assertSame('replaced', $second->json('data.outcome'));

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-08 18:20:00', $row->check_out_at->format('Y-m-d H:i:s'));

        $trail = AttendanceCorrection::query()->where('field', 'check_out_at')->firstOrFail();
        $this->assertSame('clock', $trail->source);
        $this->assertSame('2026-09-08 16:00:00', $trail->old_value);
        $this->assertStringContainsString('16:00', $trail->reason);

        Carbon::setTestNow();
    }

    /** Absen pulang tanpa absen masuk tetap tercatat: orangnya tetap ada di sana. */
    public function test_clocking_out_without_clocking_in_is_recorded(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        $this->postJson('/api/hr/attendances/me/clock-out', [])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($row->check_in_at);
        $this->assertNotNull($row->check_out_at);
    }

    /**
     * Baris (karyawan, tanggal) yang sama lahir dari pintu LAIN antara SELECT
     * dan INSERT — lembar kerani yang dikirim bersamaan, tab kedua, ponsel
     * kedua. Pintu yang aturannya "MENCATAT, tidak pernah MENOLAK" tidak boleh
     * menjawab 500: itu penolakan paling keras yang tersedia, dan absennya
     * hilang bersamanya.
     *
     * Balapannya dipalsukan dengan menyisipkan baris pesaing di dalam kait
     * `creating` — satu-satunya cara menempatkan penulis kedua persis di celah
     * itu tanpa proses kedua.
     */
    public function test_a_racing_first_punch_of_the_day_is_recorded_not_a_five_hundred(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        Carbon::setTestNow(Carbon::parse('2026-09-08 07:00:00'));

        $fired = false;
        Attendance::creating(function (Attendance $attendance) use (&$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;

            /*
             * Penulis kedua menang balapannya: barisnya sudah ada saat INSERT
             * milik pintu absen mendarat.
             *
             * Tanggalnya ditulis '2026-09-08 00:00:00', bukan '2026-09-08'.
             * Cast `date` Eloquent menyimpan tengah malam, dan di SQLite kunci
             * unik membandingkan TEKS — dua ejaan hari yang sama tidak
             * bertabrakan di sana (di MySQL kolomnya DATE dan keduanya sama).
             * Uji yang memakai ejaan pendek tidak menguji balapan apa pun; ia
             * hanya membuat baris kedua.
             */
            DB::table('hr_attendances')->insert([
                'employee_id' => $attendance->employee_id,
                'date' => '2026-09-08 00:00:00',
                'status' => 'absen',
                'note' => 'Ditulis kerani sepersekian detik lebih dulu',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->postJson('/api/hr/attendances/me/clock-in', []);
        } finally {
            Attendance::flushEventListeners();
            Carbon::setTestNow();
        }

        $response->assertOk();
        $this->assertTrue($fired, 'Prasyarat: kait balapan harus benar-benar berjalan.');

        $rows = Attendance::query()->where('employee_id', $employee->id)->get();
        $this->assertCount(1, $rows, 'Satu baris per orang per hari, bukan dua.');
        $this->assertNotNull($rows->first()->check_in_at, 'Absennya tercatat, bukan hilang bersama 500.');

        /* Catatan kerani TIDAK diperiksa di sini, dan itu batas jujur dari
           simulasi ini: baris pesaing disisipkan DI DALAM transaksi percobaan
           pertama, jadi ia ikut tergulung balik saat kunci uniknya pecah. Yang
           dibuktikan uji ini adalah yang penting — pintunya menjawab 200,
           absennya tercatat, dan tidak ada baris kedua. Pada balapan sungguhan
           (dua proses) baris pesaingnya sudah ter-commit dan percobaan kedua
           menempel padanya. */
    }

    /**
     * Pintu ini tidak menerima employee_id. Kalau ia menerimanya, ia menjadi
     * pintu untuk mengabsenkan rekan yang belum datang.
     */
    public function test_the_clock_door_writes_only_the_callers_own_row(): void
    {
        $me = $this->makeEmployee();
        $someoneElse = $this->makeEmployee();
        $this->fieldUser($me);

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'employee_id' => $someoneElse->id,
        ])->assertOk();

        $this->assertSame(0, Attendance::query()->where('employee_id', $someoneElse->id)->count());
        $this->assertSame(1, Attendance::query()->where('employee_id', $me->id)->count());
    }

    /**
     * Kartu karyawan yang tertaut tetapi DIARSIPKAN mendapat kalimatnya
     * sendiri. Kalimat "belum ditautkan" akan menyuruh orangnya meminta HR
     * menautkan akun yang sudah tertaut; HR memeriksanya, menemukannya benar,
     * dan tidak punya apa pun untuk dikerjakan.
     */
    public function test_an_archived_employee_card_gets_its_own_sentence(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $employee->delete();

        $punch = $this->postJson('/api/hr/attendances/me/clock-in', []);

        $punch->assertStatus(422);
        $this->assertStringContainsString('sudah diarsipkan', $punch->json('message'));
        $this->assertStringNotContainsString('belum ditautkan', $punch->json('message'));
        $this->assertSame(0, Attendance::query()->count());
    }

    /**
     * Proyek yang diarsipkan tidak boleh lolos validasi lalu diam-diam gagal
     * diukur: orang yang berdiri tepat di titik proyek akan diberi tahu
     * "jarak tidak terukur" tanpa satu pun petunjuk kenapa.
     */
    public function test_an_archived_project_is_refused_at_the_door_not_silently_unmeasured(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project();
        $project->delete();

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $project->id,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);
    }

    /** Akun tanpa kartu karyawan mendapat kalimat, bukan layar rusak dan bukan absensi orang lain. */
    public function test_an_account_with_no_employee_card_is_told_so(): void
    {
        $this->seed(PermissionSeeder::class);
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Tanpa Kartu', 'email' => 'tanpa-kartu@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $punch = $this->postJson('/api/hr/attendances/me/clock-in', []);
        $punch->assertStatus(422);
        $this->assertStringContainsString('belum ditautkan ke data karyawan', $punch->json('message'));

        $list = $this->getJson('/api/hr/attendances/me');
        $list->assertOk();
        $this->assertFalse($list->json('data.linked'));
        $this->assertSame([], $list->json('data.data'));
        $this->assertSame(0, Attendance::query()->count());
    }

    /** "Absensi Saya" mengembalikan baris SAYA saja — penyaring bukan jaminan; kueri-nya yang harus tidak bisa. */
    public function test_my_attendance_never_returns_somebody_elses_row(): void
    {
        $me = $this->makeEmployee();
        $other = $this->makeEmployee();
        $this->fieldUser($me);

        Attendance::query()->create(['employee_id' => $other->id, 'date' => '2026-09-08', 'status' => 'hadir']);
        $this->postJson('/api/hr/attendances/me/clock-in', [])->assertOk();

        $response = $this->getJson('/api/hr/attendances/me');
        $response->assertOk();

        $this->assertTrue($response->json('data.linked'));
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame($me->id, $response->json('data.data.0.employee_id'));
    }

    /**
     * Register absensi membawa koordinat dan selfie sejak F-4: membacanya
     * menuntut hr.view, seperti register sertifikat dan pengajuan cuti.
     */
    public function test_the_register_is_no_longer_readable_without_hr_view(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);

        $this->getJson('/api/hr/attendances')->assertStatus(403);
    }

    /** Ambang geofence datang dari Pengaturan, bukan dari angka yang dipatri di kode. */
    public function test_the_geofence_threshold_comes_from_settings(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project();
        $this->setSetting('hr.attendance.geofence_metres', 2000);

        // ~1,1 km — di luar 500 m bawaan, di dalam 2.000 m yang disetel.
        $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $project->id,
            'latitude' => self::SITE_LAT + 0.01,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(2000, $row->check_in_geofence_m);
        $this->assertFalse($row->outsideGeofence('check_in'));
    }

    /**
     * Ambang yang distempel membuat penandaan TIDAK surut: menaikkan angka di
     * Pengaturan tidak boleh membersihkan tanda "di luar lokasi" kemarin.
     */
    public function test_raising_the_threshold_does_not_clear_yesterdays_flags(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $project = $this->project();

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $project->id,
            'latitude' => self::SITE_LAT + 0.01,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        $this->setSetting('hr.attendance.geofence_metres', 20000);

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertTrue($row->outsideGeofence('check_in'), 'Tanda kemarin dibaca dengan ambang kemarin.');
    }

    /** Jarak diukur ke proyek ACUAN yang tersimpan, bukan ke proyek baris yang bisa dipindah kerani. */
    public function test_the_measured_distance_keeps_its_own_reference_project(): void
    {
        $employee = $this->makeEmployee();
        $this->fieldUser($employee);
        $site = $this->project();

        $this->postJson('/api/hr/attendances/me/clock-in', [
            'project_id' => $site->id,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        $row = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $elsewhere = $this->project(['latitude' => -7.25, 'longitude' => 112.75]);
        $row->forceFill(['project_id' => $elsewhere->id])->save();

        $row->refresh();
        $this->assertSame($site->id, (int) $row->check_in_project_id);
        $this->assertSame(0, $row->check_in_distance_m);
    }
}
