<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Enums\TimesheetDayState;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\PayrollRun;

/**
 * TIMESHEET DARI JAM TERCATAT (F-5) — sebuah PEMBACA, bukan penulis.
 *
 * Kelas ini membaca `hr_attendances.check_in_at` / `check_out_at` yang ditulis
 * F-4 dan memulangkan, per pegawai per hari: menit kerja, menit terlambat,
 * menit lembur. Ia tidak menulis satu baris pun — tidak ke rekap bulanan,
 * tidak ke slip, tidak ke izin lembur. Yang menulis rekap tetap manusia lewat
 * formulir yang sudah ada, dan yang otoritatif untuk jam lembur tetap ILB
 * (`prj_overtime_permits` → OvertimePermitService::approve →
 * OvertimeRecapService). Yang dihitung di sini adalah USULAN dan PEMBANDING.
 *
 * TIDAK ADA TABEL TURUNAN, DAN ITU KEPUTUSAN
 * -----------------------------------------
 * Angka-angka ini dihitung ulang setiap kali ditanya, dan tidak disimpan di
 * mana pun. Tiga sebab, dan ketiganya sudah jadi pelajaran rumah ini:
 *
 *  1. Cap jam BOLEH BERUBAH. F-4 justru membangun pintu koreksinya, dan
 *     sebuah tabel turunan mulai berbohong pada koreksi pertama. Migrasi
 *     001091 sudah menolak menyimpan `outside_geofence` dengan kalimat yang
 *     sama: "kolom turunan yang disimpan adalah kolom yang suatu hari
 *     melenceng dari sumbernya".
 *  2. Aturannya SETELAN. Baris yang dihitung di bawah pembulatan 15 menit lalu
 *     ditinggalkan di tabel akan tetap terbaca sebagai hasil aturan hari ini
 *     ketika aturannya sudah 30. Dihitung di tempat, layar selalu menampilkan
 *     kebijakan yang berlaku — dan ia mencetak kebijakan itu di sebelah
 *     angkanya.
 *  3. Yang BENAR-BENAR harus dibekukan adalah UANG, dan uang dibekukan di
 *     tempat payroll sudah membekukan segalanya: pada slip (`hr_payslips`,
 *     kolom `overtime_basis` + `overtime_rate_detail`, migrasi 001094). Satu
 *     kolom di sana menjawab "dengan dasar apa slip ini dibayar" selamanya,
 *     tanpa satu pun tabel yang harus dijaga tetap sinkron.
 *
 * ILB DIBACA, TIDAK PERNAH DITULIS
 * --------------------------------
 * Jam ILB yang disetujui dibaca lewat query builder atas nama tabelnya
 * (`prj_overtime_permits`), bukan lewat model Modules\Projects — preseden
 * PayrollService::projectAssignments, yang membaca `prj_manpower_assignments`
 * dengan cara yang sama persis. Arah ketergantungan tetap satu arah
 * (Projects → HrPayroll) dan modul ini tetap nol impor dari Projects.
 */
class TimesheetService
{
    /**
     * Kebijakan yang BERLAKU HARI INI, dibaca sekali per pemanggilan.
     *
     * Dikembalikan bersama setiap muatan, dan layar mencetaknya di sebelah
     * angkanya: sebuah jam lembur tanpa aturan yang menghasilkannya adalah
     * angka yang tidak bisa diperiksa siapa pun.
     *
     * @return array<string, mixed>
     */
    public function policy(): array
    {
        return [
            'day_start' => Erp::string('hr.timesheet.day_start', '08:00'),
            'late_tolerance_minutes' => Erp::int('hr.timesheet.late_tolerance_minutes', 10),
            'normal_hours_per_day' => Erp::int('hr.timesheet.normal_hours_per_day', 8),
            'rounding_minutes' => max(1, Erp::int('hr.timesheet.rounding_minutes', 15)),
            'overtime_minimum_minutes' => Erp::int('hr.timesheet.overtime_minimum_minutes', 30),
            'overtime_daily_cap_hours' => Erp::int('hr.timesheet.overtime_daily_cap_hours', 3),
            'overtime_weekly_cap_hours' => Erp::int('hr.timesheet.overtime_weekly_cap_hours', 14),
            'overtime_first_hour_pct' => Erp::float('hr.timesheet.overtime_first_hour_pct', 150),
            'overtime_next_hours_pct' => Erp::float('hr.timesheet.overtime_next_hours_pct', 200),
            // Dipinjam dari kebijakan cuti yang sudah ada, bukan dikarang
            // kedua kalinya: 6 = hanya Minggu libur (rezim proyek), 5 = Sabtu
            // ikut libur. Register tidak tahu hari libur nasional dan paket ini
            // tidak berpura-pura tahu — lihat holidays_known di bawah.
            'workweek_days' => Erp::int('hr.leave.workweek_days', 6),
            // Dikirim apa adanya supaya layar tidak perlu mengarang kalimatnya:
            // kalender hari libur nasional TIDAK ada di sistem ini.
            'holidays_known' => false,
        ];
    }

    /**
     * Rincian per hari untuk SATU pegawai pada satu periode bulanan.
     *
     * Memulangkan SETIAP hari kalender periode itu, termasuk hari yang tidak
     * tercatat dan hari non-kerja — di sebuah kalender, keadaan "tidak ada
     * data" justru yang paling perlu terlihat.
     *
     * @return array{policy: array<string, mixed>, period: array<string, mixed>, summary: array<string, mixed>, days: list<array<string, mixed>>}
     */
    public function forEmployee(Employee $employee, int $year, int $month): array
    {
        $policy = $this->policy();
        [$start, $end] = $this->bounds($year, $month);

        $attendances = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (Attendance $row): string => $row->date->toDateString());

        $permitHours = $this->approvedPermitHoursByDay($start, $end, [$employee->id])[$employee->id] ?? [];

        $recap = AttendanceRecap::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        $days = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $days[] = $this->day($cursor, $attendances->get($date), $policy) + [
                // Pembanding ILB per HARI, bukan hanya per bulan: selisih yang
                // hanya terlihat sebagai satu angka bulanan tidak memberi tahu
                // siapa pun hari mana yang perlu dilihat.
                'permit_hours' => isset($permitHours[$date]) ? round((float) $permitHours[$date], 2) : null,
            ];
        }

        return [
            'policy' => $policy,
            'period' => $this->periodMeta($year, $month, $start),
            'summary' => $this->summarise($employee, $days, $policy, $year, $month, $permitHours, $recap),
            'days' => $days,
        ];
    }

    /**
     * Ringkasan satu periode untuk SETIAP pegawai yang punya sesuatu di
     * dalamnya — dan untuk tidak seorang pun kalau periodenya memang kosong.
     *
     * Karyawan tanpa satu pun absensi, tanpa ILB dan tanpa rekap TIDAK muncul
     * sebagai baris berangka nol. Delapan baris "0 jam" tentang delapan orang
     * yang tidak pernah diukur adalah tabel yang membaca seperti hasil
     * pengukuran; yang benar adalah tidak ada barisnya dan satu kalimat yang
     * mengatakan registernya belum berisi.
     *
     * @return array{policy: array<string, mixed>, period: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function forPeriod(int $year, int $month): array
    {
        $policy = $this->policy();
        [$start, $end] = $this->bounds($year, $month);

        $attendances = Attendance::query()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->groupBy('employee_id');

        $recaps = AttendanceRecap::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->get()
            ->keyBy('employee_id');

        $permitHours = $this->approvedPermitHoursByDay($start, $end);

        $employeeIds = collect($attendances->keys())
            ->merge($recaps->keys())
            ->merge(array_keys($permitHours))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($employeeIds->isEmpty()) {
            return ['policy' => $policy, 'period' => $this->periodMeta($year, $month, $start), 'rows' => []];
        }

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('code')
            ->get();

        $rows = [];

        foreach ($employees as $employee) {
            $rowsByDate = ($attendances->get($employee->id) ?? collect())
                ->keyBy(fn (Attendance $row): string => $row->date->toDateString());
            $days = [];

            for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
                $days[] = $this->day($cursor, $rowsByDate->get($cursor->toDateString()), $policy);
            }

            $rows[] = $this->summarise($employee, $days, $policy, $year, $month, $permitHours[$employee->id] ?? [], $recaps->get($employee->id));
        }

        return ['policy' => $policy, 'period' => $this->periodMeta($year, $month, $start), 'rows' => $rows];
    }

    /**
     * Satu hari: keadaannya, lalu angka-angkanya HANYA bila keadaannya
     * membawanya.
     *
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public function day(Carbon $date, ?Attendance $attendance, array $policy): array
    {
        $nonWorking = $this->isNonWorkingDay($date, (int) $policy['workweek_days']);
        $in = $attendance?->check_in_at;
        $out = $attendance?->check_out_at;

        $state = match (true) {
            $in !== null && $out !== null && $out->greaterThan($in) => TimesheetDayState::Terukur,
            $in !== null || $out !== null => TimesheetDayState::SetengahTerukur,
            $nonWorking => TimesheetDayState::NonKerja,
            default => TimesheetDayState::TidakTercatat,
        };

        $worked = $state === TimesheetDayState::Terukur ? $in->diffInMinutes($out) : null;
        $worked = $worked === null ? null : (int) $worked;

        // Terlambat hanya menuntut jam MASUK, jadi ia terukur juga pada hari
        // setengah terukur: orang yang lupa absen pulang tetap datang pada jam
        // yang tercatat, dan menghapus keterlambatannya karena cap kedua hilang
        // berarti kehilangan separuh yang memang terukur.
        $late = $in !== null && ! $nonWorking
            ? $this->lateMinutes($date, $in, $policy)
            : null;

        $overtime = null;
        $overtimeWithheld = null;

        if ($state === TimesheetDayState::Terukur) {
            if ($nonWorking) {
                // Hari non-kerja TIDAK diusulkan sebagai lembur hari kerja.
                // Kepmenaker 102/2004 Pasal 11 ayat 2 memberi hari istirahat
                // skala 2x/3x/4x sejak jam PERTAMA, dan paket ini tidak
                // membangunnya. Menghitungnya dengan rumus hari kerja berarti
                // membayar kurang, diam-diam, pada hari yang justru paling
                // mahal.
                $overtimeWithheld = 'hari_non_kerja';
            } else {
                $overtime = $this->overtimeMinutes($worked, $policy);
            }
        }

        $capMinutes = (int) $policy['overtime_daily_cap_hours'] * 60;

        return [
            'date' => $date->toDateString(),
            'weekday' => $date->dayOfWeekIso,
            'non_working_day' => $nonWorking,
            'state' => $state->value,
            'state_label' => $state->label(),
            // Keterangan kerani, dibawa terpisah dari keadaan pengukuran: sebuah
            // hari boleh "hadir" menurut lembar kerani dan tetap tanpa cap jam.
            'attendance_status' => $attendance?->status?->value,
            'check_in_at' => $in?->toDateTimeString(),
            'check_out_at' => $out?->toDateTimeString(),
            'worked_minutes' => $worked,
            'late_minutes' => $late,
            'overtime_minutes' => $overtime,
            'overtime_withheld' => $overtimeWithheld,
            'over_daily_cap' => $overtime !== null && $overtime > $capMinutes,
            'note' => $this->dayNote($state, $nonWorking, $overtimeWithheld, $attendance),
        ];
    }

    /**
     * BENTUK HARIAN lembur satu pegawai pada satu periode — bahan yang payroll
     * butuhkan untuk membayar 1,5x jam pertama dan 2x jam berikutnya.
     *
     * Memulangkan NULL ketika periode itu tidak punya SATU PUN hari kerja yang
     * terukur penuh. Null bukan "nol jam lembur": null berarti tidak ada
     * pengetahuan tentang bentuk bulan itu sama sekali, dan payroll harus
     * memakai jalur lamanya. Sebuah bulan yang PUNYA hari terukur tetapi
     * lemburnya nihil memulangkan total 0.0 dengan daftar hari kosong — itu
     * pengetahuan, bukan ketiadaannya.
     *
     * Hari non-kerja tidak pernah masuk: lemburnya sengaja tidak diusulkan
     * (tarif hari libur tidak dibangun paket ini), jadi ia juga bukan bentuk
     * yang boleh dipakai membelah jam yang dibayar.
     *
     * @return array{total_hours: float, days: list<array{date: string, hours: float}>}|null
     */
    public function measuredOvertimeShape(int $employeeId, int $year, int $month): ?array
    {
        $policy = $this->policy();
        [$start, $end] = $this->bounds($year, $month);

        $attendances = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (Attendance $row): string => $row->date->toDateString());

        $measuredDays = 0;
        $days = [];
        $totalMinutes = 0;

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $day = $this->day($cursor, $attendances->get($cursor->toDateString()), $policy);

            if ($day['state'] !== TimesheetDayState::Terukur->value || $day['non_working_day']) {
                continue;
            }

            $measuredDays++;

            if ((int) $day['overtime_minutes'] > 0) {
                $totalMinutes += (int) $day['overtime_minutes'];
                $days[] = ['date' => $day['date'], 'hours' => round($day['overtime_minutes'] / 60, 2)];
            }
        }

        if ($measuredDays === 0) {
            return null;
        }

        return ['total_hours' => round($totalMinutes / 60, 2), 'days' => $days];
    }

    /**
     * Menit lembur satu hari: sesudah jam normal, SESUDAH PEMBULATAN, sesudah
     * minimum — dalam urutan itu, dan pembulatannya terjadi DI SINI, sekali.
     *
     * Contoh pada kebijakan bawaan (normal 8 jam, bulat 15, minimum 30), yang
     * dipaku tepi demi tepi di TimesheetDerivationTest:
     *
     *     +7 menit  → bulat 0   → 0            (tidak ada lembur)
     *     +8 menit  → bulat 15  → < 30 → 0     (dibulatkan, lalu gugur minimum)
     *     +22 menit → bulat 15  → < 30 → 0
     *     +29 menit → bulat 30  → 30           (naik melewati minimum)
     *     +30 menit → bulat 30  → 30
     *
     * Membulatkan ke kelipatan TERDEKAT, bukan ke bawah: pembulatan yang selalu
     * ke bawah adalah potongan upah yang menyamar sebagai aritmetika.
     *
     * @param  array<string, mixed>  $policy
     */
    public function overtimeMinutes(int $workedMinutes, array $policy): int
    {
        $excess = $workedMinutes - ((int) $policy['normal_hours_per_day'] * 60);

        if ($excess <= 0) {
            // TERUKUR NOL, bukan "tidak ada data": harinya diukur penuh dan
            // lemburnya memang tidak ada. Bedanya dijaga oleh keadaan harinya,
            // bukan oleh angka ini.
            return 0;
        }

        $step = max(1, (int) $policy['rounding_minutes']);
        $rounded = (int) (round($excess / $step) * $step);

        return $rounded < (int) $policy['overtime_minimum_minutes'] ? 0 : $rounded;
    }

    /**
     * Menit terlambat, dihitung dari BATAS toleransi — bukan dari jam mulai.
     *
     * Datang 08:12 dengan mulai 08:00 dan toleransi 10 menit adalah terlambat
     * 2 menit, bukan 12. Toleransi yang hanya menjadi saklar "terlambat/tidak"
     * membuat 08:11 dan 08:59 terbaca sama.
     *
     * @param  array<string, mixed>  $policy
     */
    private function lateMinutes(Carbon $date, Carbon $checkIn, array $policy): int
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $policy['day_start']) + [1 => '0']);

        $deadline = $date->copy()->startOfDay()
            ->setTime($hour, $minute)
            ->addMinutes((int) $policy['late_tolerance_minutes']);

        return $checkIn->greaterThan($deadline) ? (int) $deadline->diffInMinutes($checkIn) : 0;
    }

    /**
     * Ringkasan satu pegawai atas hari-hari yang sudah dihitung.
     *
     * Setiap total yang tidak punya satu pun hari untuk dijumlahkan
     * dikembalikan NULL, bukan 0 — itulah seluruh pokok paket ini pada
     * pemasangan yang registernya masih kosong.
     *
     * @param  list<array<string, mixed>>  $days
     * @param  array<string, mixed>  $policy
     * @param  array<string, float>  $permitHoursByDate
     * @return array<string, mixed>
     */
    private function summarise(
        Employee $employee,
        array $days,
        array $policy,
        int $year,
        int $month,
        array $permitHoursByDate = [],
        ?AttendanceRecap $recap = null,
    ): array {
        $measured = array_values(array_filter($days, fn (array $day): bool => $day['state'] === TimesheetDayState::Terukur->value && ! $day['non_working_day']));
        $measuredNonWorking = array_values(array_filter($days, fn (array $day): bool => $day['state'] === TimesheetDayState::Terukur->value && $day['non_working_day']));
        $half = array_values(array_filter($days, fn (array $day): bool => $day['state'] === TimesheetDayState::SetengahTerukur->value));
        $unrecorded = array_values(array_filter($days, fn (array $day): bool => $day['state'] === TimesheetDayState::TidakTercatat->value));
        $withCheckIn = array_values(array_filter($days, fn (array $day): bool => $day['late_minutes'] !== null));

        $overtimeMinutes = $measured === [] ? null : (int) array_sum(array_column($measured, 'overtime_minutes'));

        $permitHours = $permitHoursByDate === [] ? null : round((float) array_sum($permitHoursByDate), 2);
        $derivedHours = $overtimeMinutes === null ? null : round($overtimeMinutes / 60, 2);

        return [
            'employee_id' => (int) $employee->id,
            'employee_code' => $employee->code,
            'employee_name' => $employee->name,
            'measured_days' => count($measured),
            'half_measured_days' => count($half),
            'unrecorded_days' => count($unrecorded),
            'non_working_measured_days' => count($measuredNonWorking),
            'worked_minutes' => $measured === [] ? null : (int) array_sum(array_column($measured, 'worked_minutes')),
            'late_minutes' => $withCheckIn === [] ? null : (int) array_sum(array_column($withCheckIn, 'late_minutes')),
            'late_days' => count(array_filter($withCheckIn, fn (array $day): bool => $day['late_minutes'] > 0)),
            'overtime_minutes' => $overtimeMinutes,
            'overtime_hours' => $derivedHours,
            'overtime_days' => count(array_filter($measured, fn (array $day): bool => (int) $day['overtime_minutes'] > 0)),
            'days_over_daily_cap' => count(array_filter($measured, fn (array $day): bool => $day['over_daily_cap'])),
            'weeks_over_weekly_cap' => $this->weeksOverCap($measured, $policy),
            // Jam pada hari non-kerja: DIUKUR, DILAPORKAN, dan sengaja TIDAK
            // diusulkan sebagai lembur (tarif hari libur tidak dibangun).
            'non_working_measured_minutes' => $measuredNonWorking === [] ? null : (int) array_sum(array_column($measuredNonWorking, 'worked_minutes')),
            // Pembanding, bukan hakim: ILB tetap otoritatif.
            'permit_hours' => $permitHours,
            'recap_overtime_hours' => $recap === null ? null : round((float) $recap->overtime_hours, 2),
            'delta_hours' => $permitHours === null || $derivedHours === null ? null : round($derivedHours - $permitHours, 2),
            'payroll_posted' => $this->periodPayrollPosted($year, $month),
        ];
    }

    /**
     * Pekan (Senin–Minggu, ISO) yang jumlah lemburnya melewati batas pekanan.
     *
     * DICATAT DAN DITANDAI, tidak dipotong: yang dipulangkan adalah jumlah
     * sebenarnya, bukan jumlah yang sudah dipangkas ke batas.
     *
     * @param  list<array<string, mixed>>  $measured
     * @param  array<string, mixed>  $policy
     * @return list<array{week: string, minutes: int}>
     */
    private function weeksOverCap(array $measured, array $policy): array
    {
        $cap = (int) $policy['overtime_weekly_cap_hours'] * 60;
        $byWeek = [];

        foreach ($measured as $day) {
            $week = Carbon::parse($day['date'])->format('o-\WW');
            $byWeek[$week] = ($byWeek[$week] ?? 0) + (int) $day['overtime_minutes'];
        }

        $over = [];

        foreach ($byWeek as $week => $minutes) {
            if ($minutes > $cap) {
                $over[] = ['week' => $week, 'minutes' => $minutes];
            }
        }

        return $over;
    }

    /**
     * Jam ILB yang DISETUJUI, per pegawai per tanggal.
     *
     * Dibaca dari tabelnya lewat query builder — bukan lewat model
     * Modules\Projects — supaya HrPayroll tetap nol impor dari Projects
     * (preseden PayrollService::projectAssignments). Hanya izin berstatus
     * approved: lembar yang belum disetujui bukan jam yang boleh dibandingkan
     * dengan apa pun.
     *
     * @param  list<int>  $employeeIds  kosong = semua pegawai
     * @return array<int, array<string, float>> employee_id => [tanggal => jam]
     */
    private function approvedPermitHoursByDay(Carbon $start, Carbon $end, array $employeeIds = []): array
    {
        $rows = DB::table('prj_overtime_permit_workers as w')
            ->join('prj_overtime_permits as p', 'p.id', '=', 'w.overtime_permit_id')
            ->where('p.status', DocumentStatus::Approved->value)
            ->whereNull('p.deleted_at')
            ->whereNotNull('w.employee_id')
            ->whereDate('p.overtime_date', '>=', $start->toDateString())
            ->whereDate('p.overtime_date', '<=', $end->toDateString())
            ->when($employeeIds !== [], fn ($query) => $query->whereIn('w.employee_id', $employeeIds))
            ->groupBy('w.employee_id', 'p.overtime_date')
            ->get(['w.employee_id', 'p.overtime_date', DB::raw('SUM(w.hours) as total_hours')]);

        $map = [];

        foreach ($rows as $row) {
            // MySQL memulangkan DATE, SQLite memulangkan string tanggal apa
            // adanya; keduanya dinormalkan ke 'Y-m-d' supaya kuncinya cocok
            // dengan kunci hari di atas.
            $date = Carbon::parse((string) $row->overtime_date)->toDateString();
            $map[(int) $row->employee_id][$date] = (float) $row->total_hours;
        }

        return $map;
    }

    /**
     * Sama pertanyaannya, sama jawabannya dengan
     * OvertimeRecapService::periodPayrollPosted — dan dipakai untuk MELAPORKAN,
     * bukan untuk menolak: layar harus mengatakan bahwa periode ini sudah
     * dibayar sebelum seseorang menerapkan usulan ke dalamnya.
     */
    public function periodPayrollPosted(int $year, int $month): bool
    {
        return PayrollRun::query()
            ->where('run_type', PayrollRunType::Regular->value)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->exists();
    }

    /**
     * Hari non-kerja menurut pola pekan — dan TIDAK menurut kalender libur
     * nasional, karena sistem ini tidak punya satu pun.
     */
    private function isNonWorkingDay(Carbon $date, int $workweekDays): bool
    {
        return $workweekDays >= 6
            ? $date->isSunday()
            : $date->isSunday() || $date->isSaturday();
    }

    /**
     * Kalimat yang menjelaskan kenapa sebuah angka kosong — supaya layar tidak
     * perlu menyusunnya sendiri dari nama keadaan, dan supaya kalimat yang sama
     * muncul di layar, di ekspor CSV dan di cetakan.
     */
    private function dayNote(
        TimesheetDayState $state,
        bool $nonWorking,
        ?string $overtimeWithheld,
        ?Attendance $attendance,
    ): ?string {
        if ($overtimeWithheld === 'hari_non_kerja') {
            return 'Jam pada hari non-kerja tercatat, tetapi tidak diusulkan sebagai lembur: '
                .'tarif hari libur Kepmenaker (2x/3x/4x) belum dibangun sistem ini.';
        }

        return match ($state) {
            TimesheetDayState::Terukur => null,
            TimesheetDayState::SetengahTerukur => $attendance?->check_in_at !== null && $attendance?->check_out_at !== null
                ? 'Jam pulang tidak berada sesudah jam masuk, jadi tidak ada rentang kerja yang bisa diukur. '
                    .'Perbaiki lewat Rincian → Koreksi.'
                : ($attendance?->check_in_at !== null
                    ? 'Absen masuk ada, absen pulang tidak. Jam kerja dan lembur hari ini BELUM TERUKUR — '
                        .'bukan nol. Lengkapi lewat Rincian → Koreksi.'
                    : 'Absen pulang ada, absen masuk tidak. Jam kerja dan lembur hari ini BELUM TERUKUR — '
                        .'bukan nol. Lengkapi lewat Rincian → Koreksi.'),
            TimesheetDayState::TidakTercatat => $attendance === null
                ? 'Tidak ada catatan apa pun untuk hari ini.'
                : 'Kehadiran dicatat kerani tanpa cap jam, jadi tidak ada jam yang bisa diukur.',
            TimesheetDayState::NonKerja => $nonWorking ? 'Hari non-kerja menurut pola pekan yang berlaku.' : null,
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function bounds(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /** @return array<string, mixed> */
    private function periodMeta(int $year, int $month, Carbon $start): array
    {
        return [
            'year' => $year,
            'month' => $month,
            'label' => $start->translatedFormat('F Y'),
            'payroll_posted' => $this->periodPayrollPosted($year, $month),
        ];
    }
}
