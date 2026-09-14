<?php

namespace Tests\Feature\HrPayroll;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\TimesheetDayState;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Services\TimesheetService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

/**
 * TURUNAN TIMESHEET DARI JAM TERCATAT (F-5, T5.2).
 *
 * Dua hal yang berdiri atau jatuh di berkas ini.
 *
 * SATU: tepi pembulatan. Pemilik menyebut aturannya pada 14 Sep 2026 — bulat ke
 * 15 menit terdekat, lembur minimum 30 menit — dan tepi-tepinya dipaku satu per
 * satu, karena aturan pembulatan yang benar "pada umumnya" adalah aturan yang
 * salah tepat pada menit yang diperdebatkan orang.
 *
 * DUA: keadaan yang BUKAN nol. Produksi memegang nol baris absensi hari ini,
 * jadi setiap layar paket ini pertama kali dilihat dalam keadaan kosong. Uji di
 * sini menuntut NULL — bukan 0 — untuk setiap angka yang tidak pernah diukur,
 * dan menuntut 0 yang SUNGGUHAN untuk hari yang diukur penuh dan memang tidak
 * berlembur. Kedua-duanya, karena menyamakannya adalah kebohongan yang paling
 * mudah dipercaya.
 *
 * Juni 2026 dipakai sepanjang berkas: 1 Juni 2026 jatuh hari Senin, jadi pekan
 * ISO dan hari Minggu-nya mudah dihitung di kepala (7, 14, 21, 28 Juni adalah
 * Minggu).
 */
class TimesheetDerivationTest extends ErpTestCase
{
    use PayrollFixtures;

    private function service(): TimesheetService
    {
        return app(TimesheetService::class);
    }

    /**
     * Satu hari kerja dengan dua cap jam. Jam masuk 08:00 pas kecuali diminta
     * lain, jadi setiap uji lembur di bawah tidak juga mengukur keterlambatan.
     */
    private function clockedDay(Employee $employee, string $date, ?string $in = '08:00', ?string $out = null): Attendance
    {
        return Attendance::query()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => 'hadir',
            'check_in_at' => $in === null ? null : "{$date} {$in}:00",
            'check_out_at' => $out === null ? null : "{$date} {$out}:00",
        ]);
    }

    /**
     * Hari kerja dengan lembur sekian menit di atas 8 jam NORMAL.
     *
     * Pulang dihitung dari 17:00, bukan 16:00: hari kerja delapan jam
     * berlangsung sembilan jam di jam dinding, karena istirahat 60 menit tidak
     * termasuk jam kerja (UU 13/2003 Pasal 79, TimesheetService::breakMinutes).
     * 08:00–16:00 adalah TUJUH jam kerja.
     */
    private function dayWithExtraMinutes(Employee $employee, string $date, int $extra): void
    {
        $out = sprintf('%02d:%02d', 17 + intdiv($extra, 60), $extra % 60);
        $this->clockedDay($employee, $date, '08:00', $out);
    }

    private function dayFor(Employee $employee, string $date): array
    {
        $days = $this->service()->forEmployee($employee, (int) substr($date, 0, 4), (int) substr($date, 5, 2))['days'];

        foreach ($days as $day) {
            if ($day['date'] === $date) {
                return $day;
            }
        }

        $this->fail("Tanggal {$date} tidak ada di rincian harian.");
    }

    // ------------------------------------------------------ empat keadaan

    public function test_a_day_with_two_stamps_is_measured_and_carries_its_hours(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '17:00');

        $day = $this->dayFor($employee, '2026-06-01');

        $this->assertSame(TimesheetDayState::Terukur->value, $day['state']);
        $this->assertSame(540, $day['worked_minutes'], '08:00–17:00 adalah 540 menit DI LOKASI.');
        $this->assertSame(480, $day['net_worked_minutes'], '...dan 480 menit KERJA sesudah istirahat.');
        $this->assertSame(0, $day['late_minutes']);
        $this->assertNull($day['note'], 'Hari yang terukur penuh tidak perlu menjelaskan apa pun.');
    }

    // ------------------------------------------------------------ istirahat

    /**
     * SATU JAM LEMBUR SETIAP HARI, UNTUK SETIAP ORANG, TANPA SATU BENDERA PUN.
     *
     * Sampai putaran verifikasi 14 Sep 2026, kelas ini memperlakukan rentang
     * masuk→pulang sebagai jam kerja dan menyebut kelebihannya di atas 8 jam
     * sebagai lembur. Hari kerja 08:00–17:00 — bentuk hari kerja yang paling
     * biasa yang ada di Indonesia — karena itu menghasilkan satu jam lembur
     * setiap hari: di bawah batas 3 jam/hari (jadi tidak ditandai), sebesar
     * angka yang orang percaya masuk akal (jadi tidak dicurigai), dan langsung
     * masuk ke kolom yang Usulan Rekap sodorkan ke formulir rekap.
     *
     * Dua puluh enam hari kerja menjadi 26 jam lembur karangan, yaitu 22,5%
     * upah sebulan — dan tidak satu pun uji, layar atau kalimat panduan yang
     * bisa melihatnya. Berkas ini memakunya dari kedua arah: hari normal TIDAK
     * berlembur, dan lembur yang sungguhan tetap terukur penuh.
     */
    public function test_a_plain_eight_hour_day_produces_no_overtime_because_the_break_is_not_working_time(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '17:00');

        $day = $this->dayFor($employee, '2026-06-01');

        $this->assertSame(60, $day['break_minutes']);
        $this->assertSame(
            0,
            $day['overtime_minutes'],
            'UU 13/2003 Pasal 79: istirahat tidak termasuk jam kerja, jadi hari kerja delapan jam '
            .'berlangsung sembilan jam di jam dinding. Membaca rentangnya sebagai jam kerja berarti '
            .'mengarang satu jam lembur setiap hari untuk setiap orang.',
        );
    }

    public function test_a_month_of_plain_working_days_derives_no_overtime_at_all(): void
    {
        $employee = $this->makeEmployee();

        // Seluruh hari kerja Juni 2026 (Minggu dilewati), 08:00–17:00.
        foreach (range(1, 30) as $dayOfMonth) {
            $date = sprintf('2026-06-%02d', $dayOfMonth);

            if (in_array($dayOfMonth, [7, 14, 21, 28], true)) {
                continue;
            }

            $this->clockedDay($employee, $date, '08:00', '17:00');
        }

        $this->assertSame(
            0.0,
            $this->service()->forEmployee($employee, 2026, 6)['summary']['overtime_hours'],
            'Sebulan penuh hari kerja biasa adalah nol jam lembur. Angka lain di sini adalah angka '
            .'yang akan disodorkan Usulan Rekap ke formulir rekap, dan dari sana menjadi uang.',
        );
    }

    public function test_real_overtime_is_still_measured_in_full_after_the_break_is_taken_out(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '19:00'); // 11 jam di lokasi

        $this->assertSame(
            120,
            $this->dayFor($employee, '2026-06-01')['overtime_minutes'],
            'Sebelas jam di lokasi dikurangi satu jam istirahat adalah sepuluh jam kerja: dua jam '
            .'lembur, utuh. Potongan istirahat tidak boleh ikut memakan lembur yang sungguhan.',
        );
    }

    /**
     * Bekerja LEBIH LAMA tidak boleh pernah menghasilkan jam kerja yang lebih
     * pendek. Memotong 60 menit penuh begitu rentang melewati 4 jam membuat
     * orang yang pulang pukul 12:01 terbaca bekerja 181 menit sementara orang
     * yang pulang 12:00 terbaca 240 — tebing yang akan dipakai membantah
     * seluruh angka di layar ini.
     */
    public function test_the_break_is_taken_out_gradually_so_working_longer_never_measures_shorter(): void
    {
        $employee = $this->makeEmployee();

        $this->clockedDay($employee, '2026-06-01', '08:00', '12:00'); // 4 jam pas
        $this->clockedDay($employee, '2026-06-02', '08:00', '12:10'); // 4 jam 10 menit
        $this->clockedDay($employee, '2026-06-03', '08:00', '13:00'); // 5 jam

        $this->assertSame([240, 0], [$this->dayFor($employee, '2026-06-01')['net_worked_minutes'], $this->dayFor($employee, '2026-06-01')['break_minutes']]);
        $this->assertSame([240, 10], [$this->dayFor($employee, '2026-06-02')['net_worked_minutes'], $this->dayFor($employee, '2026-06-02')['break_minutes']]);
        $this->assertSame([240, 60], [$this->dayFor($employee, '2026-06-03')['net_worked_minutes'], $this->dayFor($employee, '2026-06-03')['break_minutes']]);
    }

    public function test_the_break_comes_from_the_settings_and_not_from_the_code(): void
    {
        $this->setSetting('hr.timesheet.break_minutes', 0);

        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '17:00');

        $day = $this->dayFor($employee, '2026-06-01');

        $this->assertSame(0, $day['break_minutes']);
        $this->assertSame(
            540,
            $day['net_worked_minutes'],
            'Istirahat 0 adalah pilihan yang sah untuk regu yang memang tidak beristirahat — dan '
            .'layar mengatakan mana yang sedang berlaku. Angka 60 yang tertanam di kode akan '
            .'menjawab 480 di sini.',
        );
        $this->assertSame(60, $day['overtime_minutes']);
    }

    public function test_a_day_with_only_a_check_in_is_half_measured_and_its_hours_are_null_not_zero(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-02', '08:00', null);

        $day = $this->dayFor($employee, '2026-06-02');

        $this->assertSame(TimesheetDayState::SetengahTerukur->value, $day['state']);
        $this->assertNull(
            $day['worked_minutes'],
            'Orang yang lupa absen pulang TIDAK bekerja nol jam. 0 di sini akan terbaca sebagai '
            .'pengukuran, dan bulan berikutnya dipakai untuk memotong upahnya.',
        );
        $this->assertNull($day['overtime_minutes'], 'Lembur hari yang belum terukur juga belum terukur.');
        $this->assertStringContainsString('BELUM TERUKUR', (string) $day['note']);
    }

    public function test_a_half_measured_day_still_reports_the_lateness_it_did_measure(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-02', '08:25', null);

        $day = $this->dayFor($employee, '2026-06-02');

        $this->assertSame(TimesheetDayState::SetengahTerukur->value, $day['state']);
        $this->assertSame(
            15,
            $day['late_minutes'],
            'Jam datang TERUKUR walau jam pulang hilang: 08:25 dengan mulai 08:00 dan toleransi 10 '
            .'menit adalah terlambat 15 menit. Membuangnya berarti kehilangan separuh yang memang ada.',
        );
    }

    public function test_a_working_day_with_no_stamps_at_all_is_unrecorded(): void
    {
        $employee = $this->makeEmployee();

        $day = $this->dayFor($employee, '2026-06-03');

        $this->assertSame(TimesheetDayState::TidakTercatat->value, $day['state']);
        $this->assertNull($day['worked_minutes']);
        $this->assertNull($day['attendance_status']);
        $this->assertSame('Tidak ada catatan apa pun untuk hari ini.', $day['note']);
    }

    public function test_a_clerk_row_without_stamps_is_unrecorded_but_keeps_the_clerks_word(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-03', null, null);

        $day = $this->dayFor($employee, '2026-06-03');

        $this->assertSame(TimesheetDayState::TidakTercatat->value, $day['state']);
        $this->assertSame(
            'hadir',
            $day['attendance_status'],
            'Kehadiran menurut kerani dan pengukuran jam adalah dua hal; layar harus bisa mengatakan '
            .'keduanya sekaligus, bukan memilih salah satu.',
        );
        $this->assertStringContainsString('tanpa cap jam', (string) $day['note']);
    }

    public function test_sunday_is_a_non_working_day_under_the_six_day_workweek(): void
    {
        $employee = $this->makeEmployee();

        $day = $this->dayFor($employee, '2026-06-07'); // Minggu

        $this->assertSame(TimesheetDayState::NonKerja->value, $day['state']);
        $this->assertTrue($day['non_working_day']);
        $this->assertNull($day['worked_minutes']);
    }

    public function test_saturday_becomes_a_non_working_day_when_the_workweek_is_five_days(): void
    {
        $this->setSetting('hr.leave.workweek_days', 5);
        $employee = $this->makeEmployee();

        $this->assertSame(TimesheetDayState::NonKerja->value, $this->dayFor($employee, '2026-06-06')['state']);
    }

    public function test_a_check_out_that_is_not_after_the_check_in_measures_nothing_instead_of_negative_hours(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-04', '17:00', '08:00');

        $day = $this->dayFor($employee, '2026-06-04');

        $this->assertSame(TimesheetDayState::SetengahTerukur->value, $day['state']);
        $this->assertNull($day['worked_minutes'], 'Dua stempel yang tidak membentuk rentang tidak mengukur apa pun.');
        $this->assertNull($day['overtime_minutes']);
    }

    // ------------------------------------------------- tepi pembulatan

    /**
     * Tepi-tepi yang perintah paket ini sebut satu per satu.
     *
     * Bulat ke 15 menit TERDEKAT, lalu minimum 30 menit — dalam urutan itu:
     *   +7  → 0   (bulat ke bawah, habis)
     *   +8  → 15  → di bawah minimum → 0
     *   +22 → 15  → di bawah minimum → 0
     *   +29 → 30  → lolos minimum → 30
     *   +30 → 30  → 30
     */
    public static function overtimeEdges(): array
    {
        return [
            '7 menit lewat' => [7, 0],
            '8 menit lewat' => [8, 0],
            '22 menit lewat' => [22, 0],
            '29 menit lewat' => [29, 30],
            '30 menit lewat' => [30, 30],
            '37 menit lewat' => [37, 30],
            '38 menit lewat' => [38, 45],
            '90 menit lewat' => [90, 90],
        ];
    }

    #[DataProvider('overtimeEdges')]
    public function test_the_overtime_rounding_edges_are_where_the_owner_put_them(int $extra, int $expected): void
    {
        $employee = $this->makeEmployee();
        $this->dayWithExtraMinutes($employee, '2026-06-01', $extra);

        $this->assertSame(
            $expected,
            $this->dayFor($employee, '2026-06-01')['overtime_minutes'],
            "Kerja {$extra} menit di atas jam normal harus menghasilkan {$expected} menit lembur "
            .'pada kebijakan bawaan (bulat 15, minimum 30).',
        );
    }

    public function test_a_day_worked_exactly_to_the_normal_hours_has_a_measured_zero_not_an_unknown(): void
    {
        $employee = $this->makeEmployee();
        // Sembilan jam di lokasi, satu jam istirahat: delapan jam kerja pas.
        $this->clockedDay($employee, '2026-06-01', '08:00', '17:00');

        $day = $this->dayFor($employee, '2026-06-01');

        $this->assertSame(TimesheetDayState::Terukur->value, $day['state']);
        $this->assertSame(
            0,
            $day['overtime_minutes'],
            'Nol yang TERUKUR: harinya diukur penuh dan lemburnya memang tidak ada. Yang tidak boleh '
            .'nol adalah hari yang tidak pernah diukur — dan itu dijaga oleh keadaan harinya.',
        );
    }

    public function test_rounding_happens_once_and_only_on_the_minutes_above_the_normal_hours(): void
    {
        $employee = $this->makeEmployee();
        // 08:07 sampai 17:07 = 540 menit di lokasi, 480 menit kerja sesudah
        // istirahat: masuknya tidak dibulatkan, pulangnya tidak dibulatkan,
        // jadi tidak ada lembur yang dikarang oleh dua pembulatan yang saling
        // menambah.
        $this->clockedDay($employee, '2026-06-01', '08:07', '17:07');

        $day = $this->dayFor($employee, '2026-06-01');

        $this->assertSame(540, $day['worked_minutes'], 'Rentang masuk→pulang dilaporkan MENTAH.');
        $this->assertSame(480, $day['net_worked_minutes'], 'Jam kerja = rentang dikurangi istirahat, tanpa pembulatan.');
        $this->assertSame(0, $day['overtime_minutes']);
    }

    public function test_the_rounding_step_comes_from_the_settings_and_not_from_the_code(): void
    {
        $this->setSetting('hr.timesheet.rounding_minutes', 30);
        $this->setSetting('hr.timesheet.overtime_minimum_minutes', 0);

        $employee = $this->makeEmployee();
        $this->dayWithExtraMinutes($employee, '2026-06-01', 20);

        $this->assertSame(
            30,
            $this->dayFor($employee, '2026-06-01')['overtime_minutes'],
            'Pada pembulatan 30 menit, 20 menit lewat membulat NAIK ke 30. Angka 15 yang tertanam di '
            .'kode akan menjawab 15 di sini.',
        );
    }

    public function test_the_minimum_comes_from_the_settings_too(): void
    {
        $this->setSetting('hr.timesheet.overtime_minimum_minutes', 0);

        $employee = $this->makeEmployee();
        $this->dayWithExtraMinutes($employee, '2026-06-01', 8);

        $this->assertSame(15, $this->dayFor($employee, '2026-06-01')['overtime_minutes']);
    }

    public function test_the_normal_hours_setting_moves_where_overtime_starts(): void
    {
        $this->setSetting('hr.timesheet.normal_hours_per_day', 7);

        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '17:00'); // 9 jam di lokasi = 8 jam kerja

        $this->assertSame(
            60,
            $this->dayFor($employee, '2026-06-01')['overtime_minutes'],
            'Jam kerja normal 7 membuat jam kedelapan menjadi lembur.',
        );
    }

    // ------------------------------------------------------- terlambat

    public function test_arriving_inside_the_tolerance_is_not_late_at_all(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:10', '17:00');

        $this->assertSame(0, $this->dayFor($employee, '2026-06-01')['late_minutes']);
    }

    public function test_lateness_is_counted_from_the_tolerance_boundary_not_from_the_start(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:12', '17:00');

        $this->assertSame(
            2,
            $this->dayFor($employee, '2026-06-01')['late_minutes'],
            'Toleransi 10 menit yang hanya menjadi saklar akan melaporkan 12 menit di sini, dan '
            .'membuat 08:11 dan 08:59 terbaca sama beratnya.',
        );
    }

    public function test_the_day_start_setting_moves_what_counts_as_late(): void
    {
        $this->setSetting('hr.timesheet.day_start', '07:00');

        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', '17:00');

        $this->assertSame(50, $this->dayFor($employee, '2026-06-01')['late_minutes']);
    }

    public function test_lateness_is_not_measured_on_a_non_working_day(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-07', '10:00', '14:00'); // Minggu

        $this->assertNull(
            $this->dayFor($employee, '2026-06-07')['late_minutes'],
            'Tidak ada jam mulai yang berlaku pada hari non-kerja, jadi tidak ada yang bisa dilanggar.',
        );
    }

    // ------------------------------------------- batas Kepmenaker & libur

    public function test_overtime_beyond_the_daily_cap_is_recorded_in_full_and_flagged(): void
    {
        $employee = $this->makeEmployee();
        $this->dayWithExtraMinutes($employee, '2026-06-01', 240); // 4 jam, batas 3

        $day = $this->dayFor($employee, '2026-06-01');

        $this->assertSame(
            240,
            $day['overtime_minutes'],
            'Batas Kepmenaker DITANDAI, tidak dipotong: memotongnya diam-diam membuat layar '
            .'mengatakan angka yang berbeda dari yang benar-benar dikerjakan orangnya.',
        );
        $this->assertTrue($day['over_daily_cap']);
    }

    public function test_a_week_beyond_the_weekly_cap_is_reported_with_its_real_total(): void
    {
        $employee = $this->makeEmployee();

        // Senin–Jumat 1–5 Juni 2026, masing-masing 3 jam lembur = 15 jam > 14.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
            $this->dayWithExtraMinutes($employee, $date, 180);
        }

        $summary = $this->service()->forEmployee($employee, 2026, 6)['summary'];

        $this->assertSame([['week' => '2026-W23', 'minutes' => 900]], $summary['weeks_over_weekly_cap']);
        $this->assertSame(900, $summary['overtime_minutes'], 'Totalnya tetap 15 jam penuh, bukan 14.');
        $this->assertSame(0, $summary['days_over_daily_cap'], '3 jam sehari tepat di batas, belum melewatinya.');
    }

    public function test_hours_on_a_non_working_day_are_measured_but_never_proposed_as_weekday_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-07', '08:00', '18:00'); // Minggu, 10 jam

        $day = $this->dayFor($employee, '2026-06-07');

        $this->assertSame(TimesheetDayState::Terukur->value, $day['state']);
        $this->assertTrue($day['non_working_day']);
        $this->assertSame(600, $day['worked_minutes'], 'Jamnya TERUKUR dan harus terlihat.');
        $this->assertNull(
            $day['overtime_minutes'],
            'Kepmenaker memberi hari istirahat skala 2x/3x/4x sejak jam pertama. Menghitungnya dengan '
            .'rumus hari kerja berarti membayar kurang, diam-diam, pada hari yang paling mahal.',
        );
        $this->assertSame('hari_non_kerja', $day['overtime_withheld']);
        $this->assertStringContainsString('tarif hari libur', (string) $day['note']);

        $summary = $this->service()->forEmployee($employee, 2026, 6)['summary'];
        $this->assertSame(600, $summary['non_working_measured_minutes']);
        $this->assertNull($summary['overtime_minutes'], 'Tidak ada satu pun hari KERJA yang terukur bulan itu.');
    }

    // -------------------------------------------------- keadaan kosong

    public function test_a_period_with_nothing_in_it_returns_no_rows_at_all(): void
    {
        $this->makeEmployee();
        $this->makeEmployee();

        $payload = $this->service()->forPeriod(2026, 6);

        $this->assertSame(
            [],
            $payload['rows'],
            'Dua karyawan yang tidak pernah diukur TIDAK boleh menjadi dua baris "0 jam". Tabel nol '
            .'membaca seperti hasil pengukuran; yang benar adalah tidak ada barisnya.',
        );
    }

    public function test_an_employee_with_only_unmeasured_days_reports_nulls_not_zeroes(): void
    {
        $employee = $this->makeEmployee();
        $this->clockedDay($employee, '2026-06-01', '08:00', null);

        $summary = $this->service()->forEmployee($employee, 2026, 6)['summary'];

        $this->assertNull($summary['worked_minutes']);
        $this->assertNull($summary['overtime_minutes']);
        $this->assertNull($summary['overtime_hours']);
        $this->assertSame(1, $summary['half_measured_days'], 'Harinya harus BISA DILIHAT HR untuk dikoreksi.');
    }

    public function test_the_period_rows_include_someone_who_only_has_an_approved_permit(): void
    {
        $employee = $this->makeEmployee();
        $this->approvedPermit($employee, '2026-06-10', 3.0);

        $rows = $this->service()->forPeriod(2026, 6)['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame(3.0, $rows[0]['permit_hours']);
        $this->assertNull($rows[0]['overtime_hours'], 'Tidak ada satu pun cap jam, jadi tidak ada turunan.');
        $this->assertNull($rows[0]['delta_hours'], 'Selisih antara sesuatu dan ketiadaan bukan nol.');
    }

    // ------------------------------------------------- pembanding ILB

    public function test_the_permit_hours_stand_beside_the_derived_hours_without_overwriting_them(): void
    {
        $employee = $this->makeEmployee();
        $this->dayWithExtraMinutes($employee, '2026-06-10', 120); // turunan 2 jam
        $this->approvedPermit($employee, '2026-06-10', 3.0);      // ILB 3 jam

        $payload = $this->service()->forEmployee($employee, 2026, 6);
        $day = $this->dayFor($employee, '2026-06-10');

        $this->assertSame(120, $day['overtime_minutes'], 'Turunan absensi tetap apa adanya.');
        $this->assertSame(3.0, $day['permit_hours'], 'Jam ILB tetap apa adanya.');
        $this->assertSame(2.0, $payload['summary']['overtime_hours']);
        $this->assertSame(3.0, $payload['summary']['permit_hours']);
        $this->assertSame(
            -1.0,
            $payload['summary']['delta_hours'],
            'Selisihnya dikatakan berapa dan ke arah mana; siapa yang menang TIDAK diputuskan di sini.',
        );
    }

    public function test_an_unapproved_permit_is_not_a_comparator(): void
    {
        $employee = $this->makeEmployee();
        $this->approvedPermit($employee, '2026-06-10', 3.0, DocumentStatus::Submitted);

        $this->assertNull($this->service()->forEmployee($employee, 2026, 6)['summary']['permit_hours']);
    }

    /**
     * Satu ILB yang disetujui, ditulis langsung ke tabelnya.
     *
     * Lewat query builder dan bukan lewat OvertimePermitService: uji ini
     * mengukur PEMBACAAN-nya, dan memakai layanan Projects akan menyeret
     * proyek, nomor dokumen, dan gerbang persetujuannya ke dalam uji tentang
     * pembulatan menit.
     */
    private function approvedPermit(Employee $employee, string $date, float $hours, DocumentStatus $status = DocumentStatus::Approved): void
    {
        $id = DB::table('prj_overtime_permits')->insertGetId([
            'code' => 'ILB/TEST/'.$date.'/'.$employee->id,
            'project_id' => $this->projectId(),
            'overtime_date' => $date,
            'start_time' => '17:00',
            'end_time' => '20:00',
            'reason' => 'Pengecoran lanjutan',
            'status' => $status->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('prj_overtime_permit_workers')->insert([
            'overtime_permit_id' => $id,
            'employee_id' => $employee->id,
            'hours' => $hours,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private ?int $projectId = null;

    private function projectId(): int
    {
        return $this->projectId ??= (int) DB::table('prj_projects')->insertGetId([
            'code' => 'PRJ-TS-001',
            'name' => 'Proyek uji timesheet',
            'type' => 'konstruksi',
            'status' => 'execution',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
