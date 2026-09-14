<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * Sesudah berapa menit kerja istirahat mulai berlaku (UU 13/2003 Pasal 79
     * ayat 2 huruf b: sekurang-kurangnya setengah jam SESUDAH bekerja 4 jam
     * terus-menerus). Bukan setelan: ia dasar hukum potongan di bawahnya, dan
     * sebuah kotak isian di sini hanya akan dipakai untuk menggeser lantai itu.
     */
    private const BREAK_AFTER_MINUTES = 240;

    /**
     * Sejauh mana sesudah jam mulai sebuah cap masuk masih bisa disebut
     * "terlambat" — setengah hari. Lihat outsideLateWindow().
     */
    private const LATE_WINDOW_MINUTES = 720;

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
            // Istirahat yang TIDAK dihitung jam kerja (UU 13/2003 Pasal 79).
            // Tanpa angka ini, rentang mentah masuk→pulang dibaca sebagai jam
            // kerja, dan hari kerja biasa 08:00–17:00 menghasilkan satu jam
            // lembur palsu setiap hari — lihat breakMinutes().
            'break_minutes' => max(0, Erp::int('hr.timesheet.break_minutes', 60)),
            'break_after_minutes' => self::BREAK_AFTER_MINUTES,
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

        // Jendela DILEBARKAN ke pekan ISO yang memuat tanggal 1 dan tanggal
        // terakhir — lihat weekOverflowDays(). Hari di luar bulan hanya dipakai
        // MENJUMLAHKAN pekan, tidak pernah ditampilkan sebagai baris.
        [$weekStart, $weekEnd] = $this->weekBounds($start, $end);

        $attendances = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
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
            'summary' => $this->summarise(
                $employee,
                $days,
                $policy,
                $year,
                $month,
                $permitHours,
                $recap,
                $this->weekOverflowDays($start, $end, $attendances, $policy),
            ),
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

        [$weekStart, $weekEnd] = $this->weekBounds($start, $end);

        $attendances = Attendance::query()
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
            ->get()
            ->groupBy('employee_id');

        $recaps = AttendanceRecap::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->get()
            ->keyBy('employee_id');

        $permitHours = $this->approvedPermitHoursByDay($start, $end);

        /*
         * HANYA pegawai yang punya sesuatu DI DALAM bulan ini.
         *
         * Kueri absensi di atas sengaja dilebarkan ke pekan ISO di kedua tepi
         * (weekOverflowDays), dan tanpa penyaringan ini seseorang yang
         * satu-satunya catatannya jatuh pada 29 Juni akan muncul sebagai baris
         * Juli berisi null semata — persis tabel nol yang seluruh layar ini
         * dibangun untuk tidak menggambarnya.
         */
        $inPeriod = $attendances
            ->filter(fn ($rows): bool => $rows->contains(
                fn (Attendance $row): bool => $row->date->toDateString() >= $start->toDateString()
                    && $row->date->toDateString() <= $end->toDateString(),
            ));

        $employeeIds = collect($inPeriod->keys())
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

            $rows[] = $this->summarise(
                $employee,
                $days,
                $policy,
                $year,
                $month,
                $permitHours[$employee->id] ?? [],
                $recaps->get($employee->id),
                $this->weekOverflowDays($start, $end, $rowsByDate, $policy),
            );
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

        /*
         * CAP JAM HARUS BERHUBUNGAN DENGAN TANGGAL BARISNYA.
         *
         * Pintu koreksi F-4 dianjurkan panduan justru untuk "lupa absen
         * pulang", jadi kerani mengetik tanggal DAN jam dengan tangan setiap
         * kali. Sampai putaran verifikasi, `day()` memakai kedua cap apa
         * adanya dan `$date` hanya dipakai untuk menghitung keterlambatan: satu
         * salah ketik bulan diterima 200 OK dan menjadi hari "terukur" dengan
         * 43.740 menit kerja dan 43.260 menit lembur, seluruhnya dibukukan ke
         * pekan ISO tanggal BARISNYA. Varian yang lebih halus — cap dua hari
         * geser — tidak melewati batas harian sama sekali dan lolos tanpa satu
         * tanda pun: 540 menit kerja, 60 menit lembur, 2.870 menit terlambat,
         * semuanya dicatat pada tanggal yang orangnya tidak ada di sana.
         *
         * Yang diterima: cap MASUK jatuh pada tanggal barisnya atau sehari
         * sebelumnya (shift malam yang dicatat kerani pada tanggal ia berakhir),
         * dan cap PULANG jatuh pada tanggal cap masuk atau keesokan harinya
         * (shift malam 22:00 → 06:00 tetap sah). Di luar itu, keduanya tidak
         * mengukur hari ini, dan hari ini tidak boleh diangkat menjadi Terukur:
         * angka yang tidak bisa dipercaya lebih baik BERGARIS daripada besar.
         */
        $stampsBelongHere = $this->stampsBelongToDay($date, $in, $out);

        $state = match (true) {
            $in !== null && $out !== null && $out->greaterThan($in) && $stampsBelongHere => TimesheetDayState::Terukur,
            $in !== null || $out !== null => TimesheetDayState::SetengahTerukur,
            $nonWorking => TimesheetDayState::NonKerja,
            default => TimesheetDayState::TidakTercatat,
        };

        $worked = $state === TimesheetDayState::Terukur ? $in->diffInMinutes($out) : null;
        $worked = $worked === null ? null : (int) $worked;

        /*
         * ISTIRAHAT, dan kenapa ia harus ada di sini.
         *
         * `$worked` adalah RENTANG masuk→pulang, dan rentang bukan jam kerja:
         * UU 13/2003 Pasal 79 menyatakan istirahat tidak termasuk jam kerja,
         * jadi hari kerja 8 jam di Indonesia berlangsung 9 jam di jam dinding.
         * Tanpa potongan ini, 08:00–17:00 — hari kerja yang paling biasa yang
         * ada — menghasilkan satu jam lembur setiap hari, untuk setiap orang,
         * tanpa satu bendera pun: batas 3 jam/hari tidak tersentuh dan
         * angkanya persis sebesar yang orang percaya masuk akal. Layar Usulan
         * Rekap lalu menyodorkan 26 jam lembur karangan ke formulir rekap, dan
         * dari sana ia menjadi uang.
         *
         * KEDUA angka dibawa keluar: rentang yang benar-benar terukur DAN jam
         * kerja sesudah istirahat. Mengganti yang satu dengan yang lain berarti
         * layar tidak bisa lagi menjawab "kenapa 9 jam di jam dinding menjadi
         * 8 jam kerja", dan pertanyaan itu akan diajukan pada hari pertama.
         */
        $break = $worked === null ? null : $this->breakMinutes($worked, $policy);
        $netWorked = $worked === null ? null : $worked - $break;

        /*
         * TERLAMBAT hanya menuntut jam MASUK, jadi ia terukur juga pada hari
         * setengah terukur: orang yang lupa absen pulang tetap datang pada jam
         * yang tercatat, dan menghapus keterlambatannya karena cap kedua hilang
         * berarti kehilangan separuh yang memang terukur.
         *
         * TIGA KEADAAN YANG TIDAK MENGUKURNYA, dan ketiganya dikatakan lewat
         * `late_withheld` + catatan harinya, bukan dipulangkan 0:
         *
         *  1. Hari non-kerja — tidak ada jam mulai yang berlaku.
         *  2. Cap jam TERBALIK. `TimesheetDayState` dan catatan harinya sama-
         *     sama berbunyi "dua stempel yang tidak membentuk rentang tidak
         *     mengukur apa pun", sementara kode tetap menghitung terlambat 530
         *     menit darinya dan membawanya ke total bulanan, ke `late_days`
         *     dan ke CSV — satu sel yang membantah keterangannya sendiri.
         *     Pembenaran "orang yang lupa absen pulang tetap datang pada jam
         *     yang tercatat" benar untuk hari yang cap pulangnya HILANG, dan
         *     tidak benar untuk hari yang capnya ADA tetapi tertukar: 17:00
         *     hampir pasti bukan jam datang orang itu.
         *  3. Jam masuk DI LUAR JENDELA hari kerja. Sistem ini hanya punya SATU
         *     jam mulai, jadi shift malam 22:00–06:00 dibaca "terlambat 13 jam
         *     50 menit", setiap hari, di layar yang dibuat supaya orangnya bisa
         *     membantah. Itu pengukuran yang tidak pernah terjadi.
         */
        $lateWithheld = match (true) {
            $in === null || $nonWorking => null,
            ! $stampsBelongHere => 'cap_bukan_hari_ini',
            $out !== null && ! $out->greaterThan($in) => 'cap_terbalik',
            $this->outsideLateWindow($date, $in, $policy) => 'di_luar_jendela_hari_kerja',
            default => null,
        };

        $late = $in !== null && ! $nonWorking && $lateWithheld === null
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
                $overtime = $this->overtimeMinutes($netWorked, $policy);
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
            'break_minutes' => $break,
            'net_worked_minutes' => $netWorked,
            'late_minutes' => $late,
            'late_withheld' => $lateWithheld,
            'overtime_minutes' => $overtime,
            'overtime_withheld' => $overtimeWithheld,
            'over_daily_cap' => $overtime !== null && $overtime > $capMinutes,
            'note' => $this->dayNote($state, $nonWorking, $overtimeWithheld, $lateWithheld, $attendance),
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
     * MENIT ADALAH SUMBERNYA, JAM HANYA TAMPILANNYA.
     *
     * Setiap hari membawa `minutes` (bilangan bulat, tepat) DAN `hours`
     * (dibulatkan ke 2 desimal, untuk layar dan untuk slip). Yang menghitung
     * uang WAJIB memakai `minutes`: sampai putaran verifikasi 14 Sep 2026,
     * pembelahan tarif menjumlahkan `hours` yang sudah dibulatkan satu-satu,
     * sementara gerbang kesamaan totalnya dibandingkan terhadap `total_hours`
     * yang dihitung dari menit — dua angka berbeda untuk satu jumlah jam, pada
     * slip yang sama. Pada pembulatan bawaan 15 menit keduanya kebetulan sama
     * persis (seperempat jam desimalnya tepat), jadi tidak satu uji pun bisa
     * melihatnya; pada pembulatan 10 atau 20 menit — nilai yang layar
     * Pengaturan terima dengan HTTP 200 — selisihnya nyata dan BOLAK-BALIK.
     *
     * @return array{total_minutes: int, total_hours: float, days: list<array{date: string, minutes: int, hours: float}>}|null
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
                $days[] = [
                    'date' => $day['date'],
                    'minutes' => (int) $day['overtime_minutes'],
                    'hours' => round($day['overtime_minutes'] / 60, 2),
                ];
            }
        }

        if ($measuredDays === 0) {
            return null;
        }

        return [
            'total_minutes' => $totalMinutes,
            'total_hours' => round($totalMinutes / 60, 2),
            'days' => $days,
        ];
    }

    /**
     * Menit istirahat yang dipotong dari rentang masuk→pulang satu hari.
     *
     * Dipotong SECARA BERTAHAP, bukan sekaligus, supaya bekerja lebih lama
     * tidak pernah menghasilkan jam kerja yang lebih pendek:
     *
     *     rentang 240 menit (4 jam) → potong 0   → 240
     *     rentang 250 menit         → potong 10  → 240
     *     rentang 300 menit         → potong 60  → 240
     *     rentang 540 menit (9 jam) → potong 60  → 480
     *
     * Memotong 60 menit penuh begitu rentang melewati 4 jam akan membuat orang
     * yang bekerja 4 jam 1 menit terbaca bekerja LEBIH SEDIKIT daripada orang
     * yang pulang satu menit lebih awal — tebing yang akan dipakai membantah
     * seluruh angka di layar ini.
     *
     * @param  array<string, mixed>  $policy
     */
    public function breakMinutes(int $workedMinutes, array $policy): int
    {
        $break = max(0, (int) $policy['break_minutes']);

        if ($break === 0) {
            return 0;
        }

        return (int) min($break, max(0, $workedMinutes - self::BREAK_AFTER_MINUTES));
    }

    /**
     * Menit lembur satu hari: sesudah jam normal, SESUDAH PEMBULATAN, sesudah
     * minimum — dalam urutan itu, dan pembulatannya terjadi DI SINI, sekali.
     *
     * Yang masuk ke sini adalah JAM KERJA — rentang masuk→pulang yang sudah
     * dikurangi istirahat (breakMinutes), bukan rentang mentahnya.
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
     * Apakah kedua cap jam benar-benar mengukur hari ini.
     *
     * Cap MASUK pada tanggal barisnya atau sehari sebelumnya (shift malam yang
     * dicatat kerani pada tanggal ia berakhir), dan cap PULANG pada tanggal cap
     * masuk atau keesokan harinya. Satu cap saja (lupa absen pulang) tidak
     * diuji pasangannya: harinya memang belum terukur, dan jam masuknya tetap
     * jam masuk yang tercatat.
     */
    private function stampsBelongToDay(Carbon $date, ?Carbon $in, ?Carbon $out): bool
    {
        if ($in === null || $out === null) {
            return true;
        }

        $inDate = $in->toDateString();

        $startsHere = $inDate === $date->toDateString()
            || $inDate === $date->copy()->subDay()->toDateString();

        $endsWithShift = $out->toDateString() === $inDate
            || $out->toDateString() === $in->copy()->addDay()->toDateString();

        return $startsHere && $endsWithShift;
    }

    /**
     * Jam masuk yang jatuh terlalu jauh dari jam mulai untuk bisa disebut
     * "terlambat".
     *
     * Sistem ini hanya punya SATU jam mulai untuk seluruh perusahaan (lihat
     * `day_start`), jadi shift malam tidak bisa dinilai keterlambatannya sama
     * sekali: 22:00 terhadap batas 08:10 adalah "terlambat 13 jam 50 menit",
     * yang bukan pengukuran melainkan salah baca. Setengah hari adalah garis
     * yang dipilih di sini: seseorang yang datang sembilan jam terlambat memang
     * terlambat, seseorang yang datang empat belas jam "terlambat" sedang
     * bekerja pada shift yang sistem ini tidak punya namanya.
     *
     * @param  array<string, mixed>  $policy
     */
    private function outsideLateWindow(Carbon $date, Carbon $checkIn, array $policy): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $policy['day_start']) + [1 => '0']);

        $start = $date->copy()->startOfDay()->setTime($hour, $minute);

        return $checkIn->lessThan($start) || $start->diffInMinutes($checkIn) > self::LATE_WINDOW_MINUTES;
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
        array $weekOverflowDays = [],
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
            // Rentang mentah, istirahat yang dipotong darinya, dan jam kerja
            // yang tersisa — ketiganya, karena yang dibandingkan orang dengan
            // cap jam di kolom sebelahnya adalah rentangnya, sementara yang
            // menjadi lembur adalah sisanya.
            'worked_minutes' => $measured === [] ? null : (int) array_sum(array_column($measured, 'worked_minutes')),
            'break_minutes' => $measured === [] ? null : (int) array_sum(array_column($measured, 'break_minutes')),
            'net_worked_minutes' => $measured === [] ? null : (int) array_sum(array_column($measured, 'net_worked_minutes')),
            'late_minutes' => $withCheckIn === [] ? null : (int) array_sum(array_column($withCheckIn, 'late_minutes')),
            'late_days' => count(array_filter($withCheckIn, fn (array $day): bool => $day['late_minutes'] > 0)),
            'overtime_minutes' => $overtimeMinutes,
            'overtime_hours' => $derivedHours,
            'overtime_days' => count(array_filter($measured, fn (array $day): bool => (int) $day['overtime_minutes'] > 0)),
            'days_over_daily_cap' => count(array_filter($measured, fn (array $day): bool => $day['over_daily_cap'])),
            'weeks_over_weekly_cap' => $this->weeksOverCap($measured, $weekOverflowDays, $policy),
            // Jam pada hari non-kerja: DIUKUR, DILAPORKAN, dan sengaja TIDAK
            // diusulkan sebagai lembur (tarif hari libur tidak dibangun).
            'non_working_measured_minutes' => $measuredNonWorking === [] ? null : (int) array_sum(array_column($measuredNonWorking, 'net_worked_minutes')),
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
     * PEKAN YANG TERBELAH ANTARA DUA BULAN DIHITUNG UTUH. Sampai putaran
     * verifikasi, pekan dikelompokkan atas hari-hari SATU BULAN saja, jadi
     * pekan ISO yang melintasi tanggal 1 diperiksa sebagai dua potongan dan
     * masing-masing potongan bisa berada di bawah batas walau pekannya di atas:
     * 29 Juni 7 jam + 30 Juni 7 jam + 1 Juli 3 jam adalah tujuh belas jam dalam
     * satu pekan — tiga jam di atas batas Kepmenaker — dan kedua bulan
     * melaporkan `[]`. Penanda kepatuhan yang diam justru pada pekan yang
     * paling berat adalah penanda yang mengatakan sesuatu yang tidak benar
     * kepada pengawas yang membacanya.
     *
     * Pekan seperti itu ditandai `spans_periods`, dan menyebut berapa menit
     * dari jumlahnya jatuh di bulan sebelah — supaya angkanya bisa dicocokkan
     * dengan tabel yang ada di layar, yang hanya memuat hari bulan ini.
     *
     * @param  list<array<string, mixed>>  $measured  hari terukur DI DALAM bulan
     * @param  list<array<string, mixed>>  $overflow  hari terukur di luar bulan, satu pekan dengan tepinya
     * @param  array<string, mixed>  $policy
     * @return list<array{week: string, minutes: int, minutes_outside_period: int, spans_periods: bool}>
     */
    private function weeksOverCap(array $measured, array $overflow, array $policy): array
    {
        $cap = (int) $policy['overtime_weekly_cap_hours'] * 60;
        $byWeek = [];
        $outsideByWeek = [];

        foreach ($measured as $day) {
            $week = Carbon::parse($day['date'])->format('o-\WW');
            $byWeek[$week] = ($byWeek[$week] ?? 0) + (int) $day['overtime_minutes'];
        }

        foreach ($overflow as $day) {
            $week = Carbon::parse($day['date'])->format('o-\WW');

            // HANYA pekan yang benar-benar punya hari di dalam bulan ini: sebuah
            // pekan yang seluruhnya di bulan sebelah adalah pekan bulan sebelah,
            // dan melaporkannya di sini berarti menandai orang dua kali.
            if (! array_key_exists($week, $byWeek)) {
                continue;
            }

            $byWeek[$week] += (int) $day['overtime_minutes'];
            $outsideByWeek[$week] = ($outsideByWeek[$week] ?? 0) + (int) $day['overtime_minutes'];
        }

        $over = [];

        foreach ($byWeek as $week => $minutes) {
            if ($minutes > $cap) {
                $outside = $outsideByWeek[$week] ?? 0;
                $over[] = [
                    'week' => $week,
                    'minutes' => $minutes,
                    'minutes_outside_period' => $outside,
                    'spans_periods' => $outside > 0,
                ];
            }
        }

        return $over;
    }

    /**
     * Hari terukur yang jatuh DI LUAR bulan tetapi masih satu pekan ISO dengan
     * tanggal 1 atau tanggal terakhirnya.
     *
     * Dipakai HANYA untuk menjumlahkan pekan (weeksOverCap); ia tidak pernah
     * menjadi baris di layar, tidak masuk jam kerja, tidak masuk total lembur
     * bulanan, dan tidak menyentuh rincian bayar. Paling banyak dua belas hari.
     *
     * @param  Collection<string, Attendance>  $rowsByDate
     * @param  array<string, mixed>  $policy
     * @return list<array<string, mixed>>
     */
    private function weekOverflowDays(Carbon $start, Carbon $end, $rowsByDate, array $policy): array
    {
        [$weekStart, $weekEnd] = $this->weekBounds($start, $end);
        $days = [];

        foreach ([[$weekStart, $start->copy()->subDay()], [$end->copy()->addDay(), $weekEnd]] as [$from, $to]) {
            for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
                $day = $this->day($cursor, $rowsByDate->get($cursor->toDateString()), $policy);

                if ($day['state'] === TimesheetDayState::Terukur->value && ! $day['non_working_day']) {
                    $days[] = $day;
                }
            }
        }

        return $days;
    }

    /** Pekan ISO (Senin–Minggu) yang memuat kedua tepi periode. */
    private function weekBounds(Carbon $start, Carbon $end): array
    {
        return [
            $start->copy()->startOfWeek(Carbon::MONDAY),
            $end->copy()->endOfWeek(Carbon::SUNDAY),
        ];
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
        ?string $lateWithheld,
        ?Attendance $attendance,
    ): ?string {
        // Cap yang tidak berhubungan dengan tanggal barisnya menjelaskan
        // SELURUH hari itu, jadi kalimatnya didahulukan.
        if ($lateWithheld === 'cap_bukan_hari_ini') {
            return 'Cap jam pada baris ini tidak jatuh pada tanggalnya (atau jam pulangnya lebih dari '
                .'sehari sesudah jam masuknya), jadi tidak ada yang diukur untuk hari ini. Kemungkinan '
                .'besar salah ketik tanggal saat koreksi — perbaiki lewat Rincian → Koreksi.';
        }

        if ($overtimeWithheld === 'hari_non_kerja') {
            return 'Jam pada hari non-kerja tercatat, tetapi tidak diusulkan sebagai lembur: '
                .'tarif hari libur Kepmenaker (2x/3x/4x) belum dibangun sistem ini.';
        }

        if ($lateWithheld === 'di_luar_jendela_hari_kerja') {
            return 'Jam masuk jatuh di luar jendela hari kerja yang berlaku, jadi keterlambatan TIDAK '
                .'diukur untuk hari ini. Sistem ini hanya punya satu jam mulai kerja, sehingga shift '
                .'malam tidak bisa dinilai keterlambatannya. Jam kerja dan lemburnya tetap terukur.';
        }

        return match ($state) {
            TimesheetDayState::Terukur => null,
            TimesheetDayState::SetengahTerukur => $attendance?->check_in_at !== null && $attendance?->check_out_at !== null
                ? 'Jam pulang tidak berada sesudah jam masuk, jadi tidak ada rentang kerja yang bisa diukur — '
                    .'termasuk keterlambatannya: dua cap yang tertukar hampir selalu berarti jam 17:00 itu '
                    .'bukan jam datang orangnya. Perbaiki lewat Rincian → Koreksi.'
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
