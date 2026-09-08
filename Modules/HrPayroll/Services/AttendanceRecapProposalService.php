<?php

namespace Modules\HrPayroll\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HrPayroll\Models\AttendanceRecap;

/**
 * USULAN rekap bulanan dari register absensi (F-4) — dan tidak lebih dari itu.
 *
 * Kelas ini MEMBACA. Ia tidak menulis hr_attendance_recaps, tidak menyentuh
 * payroll run mana pun, dan tidak dipanggil dari satu pun jalur yang
 * menghasilkan slip gaji. Rekap bulanan tetap dokumen yang diketik dan
 * disimpan manusia lewat endpoint yang sudah ada, dengan izin yang sudah ada;
 * layar hanya menawarkan angka register sebagai ISIAN AWAL yang masih harus
 * ditekan Simpan oleh orang.
 *
 * Alasannya bukan kehati-hatian yang samar. Register absensi bisa dikoreksi
 * kapan saja — itu memang gunanya — sedangkan payroll yang sudah disetujui
 * sudah membukukan jurnal dan membayar orang. Jalur otomatis dari yang pertama
 * ke yang kedua berarti mengetik ulang absen bulan lalu menggerakkan uang yang
 * sudah keluar, tanpa satu pun persetujuan di antaranya.
 * AttendanceIsNotPayrollInputTest memaku ketiadaan jalur itu.
 *
 * Yang TIDAK diusulkan sama pentingnya dengan yang diusulkan: sakit, cuti dan
 * lembur TIDAK punya angka di sini, karena register tidak tahu apa-apa tentang
 * ketiganya (sakit dan cuti ada di hr_leave_requests dengan persetujuannya
 * sendiri; lembur di rekap lembur). Mengisinya dengan 0 akan terlihat seperti
 * jawaban dan terbawa ke slip gaji sebagai hak yang hilang.
 */
class AttendanceRecapProposalService
{
    /**
     * @return array{
     *     period: array{year: int, month: int, label: string},
     *     not_proposed: list<array{field: string, why: string}>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function propose(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $rows = DB::table('hr_attendances as a')
            ->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->whereNull('e.deleted_at')
            /*
             * whereDate, BUKAN whereBetween — dan bukan kehalusan gaya.
             *
             * Cast `date` MENYIMPAN tengah malam, jadi kolomnya berisi
             * '2026-06-30 00:00:00'. SQLite membandingkan STRING, sehingga
             * '2026-06-30 00:00:00' > '2026-06-30' dan tanggal terakhir setiap
             * bulan jatuh keluar dari rentangnya. MySQL punya kolom DATE
             * sungguhan dan memaksa nilainya, jadi ia benar — dan produksi
             * hari ini masih SQLite, jadi yang salah justru yang dipakai.
             *
             * Akibatnya lebih berat daripada selisih satu hari: karyawan yang
             * SATU-SATUNYA catatannya bulan itu jatuh di tanggal terakhir
             * lenyap sama sekali dari usulan, dan layarnya lalu mencetak
             * "Register bulan ini kosong" tentang orang yang ada di dalam
             * register. Idiom yang sama sudah dipakai AttendanceService dan
             * AttendanceController dengan alasan yang sama.
             */
            ->whereDate('a.date', '>=', $start->toDateString())
            ->whereDate('a.date', '<=', $end->toDateString())
            ->groupBy('a.employee_id', 'e.code', 'e.name')
            ->orderBy('e.code')
            ->get([
                'a.employee_id',
                'e.code as employee_code',
                'e.name as employee_name',
                DB::raw('COUNT(*) as recorded_days'),
                DB::raw("SUM(CASE WHEN a.status = 'hadir' THEN 1 ELSE 0 END) as present_days"),
                DB::raw("SUM(CASE WHEN a.status = 'setengah_hari' THEN 1 ELSE 0 END) as half_days"),
                DB::raw("SUM(CASE WHEN a.status = 'absen' THEN 1 ELSE 0 END) as absent_days"),
                /*
                 * check_in_DEVICE_at, bukan check_in_at. Pengawas BOLEH
                 * mengetik jam masuk lewat pintu koreksi (§28 justru
                 * menganjurkannya untuk "lupa absen pulang"), dan menghitung
                 * kolom itu membuat kolom layar yang berjudul "Absen ponsel"
                 * melaporkan hari yang tidak pernah disentuh ponsel siapa pun —
                 * lalu sub-barisnya menambahkan "1 tanpa jarak terukur" tentang
                 * hari itu. Hanya pintu absen ponsel yang menulis jam
                 * perangkat.
                 */
                DB::raw('SUM(CASE WHEN a.check_in_device_at IS NOT NULL THEN 1 ELSE 0 END) as clocked_days'),
                // "Di luar lokasi" = jaraknya terukur DAN melewati ambang yang
                // distempel saat itu. Baris tanpa jarak tidak masuk ke sini dan
                // tidak masuk ke "di dalam" — ia dihitung terpisah di bawah.
                DB::raw('SUM(CASE WHEN a.check_in_distance_m IS NOT NULL AND a.check_in_geofence_m IS NOT NULL '
                    .'AND a.check_in_distance_m > a.check_in_geofence_m THEN 1 ELSE 0 END) as outside_days'),
                DB::raw('SUM(CASE WHEN a.check_in_device_at IS NOT NULL AND a.check_in_distance_m IS NULL '
                    .'THEN 1 ELSE 0 END) as unmeasured_days'),
            ]);

        $existing = AttendanceRecap::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->pluck('id', 'employee_id');

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $start->translatedFormat('F Y'),
            ],
            // `label` ikut dikirim, bukan disusun layar: nama kolom bukan bahasa
            // manusia, dan "sick_days" di layar HR adalah kebocoran istilah
            // basis data ke orang yang sedang memutuskan gaji seseorang.
            'not_proposed' => [
                ['field' => 'sick_days', 'label' => 'Hari sakit', 'why' => 'Sakit tercatat di pengajuan cuti/izin, bukan di register absensi.'],
                ['field' => 'leave_days', 'label' => 'Hari cuti', 'why' => 'Cuti tercatat di pengajuan cuti/izin yang punya persetujuannya sendiri.'],
                ['field' => 'work_days', 'label' => 'Hari kerja sebulan', 'why' => 'Register tidak tahu kalender kerja bulan ini — hari libur dan hari kerja pengganti tidak ada di dalamnya.'],
                ['field' => 'overtime_hours', 'label' => 'Jam lembur', 'why' => 'Register mencatat kehadiran, bukan jam lembur yang disetujui.'],
                ['field' => 'half_days', 'label' => 'Hari setengah', 'why' => 'Rekap bulanan tidak punya kolom setengah hari. Hari setengah '
                    .'terhitung di layar ini supaya terlihat, tetapi ke mana ia dibukukan adalah keputusan HR — '
                    .'membaginya sendiri menjadi setengah hadir dan setengah alpa akan menggeser gaji tanpa '
                    .'seorang pun memutuskannya.'],
            ],
            'rows' => $rows->map(fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'employee_code' => $row->employee_code,
                'employee_name' => $row->employee_name,
                'recorded_days' => (int) $row->recorded_days,
                'present_days' => (int) $row->present_days,
                'half_days' => (int) $row->half_days,
                'absent_days' => (int) $row->absent_days,
                'clocked_days' => (int) $row->clocked_days,
                'outside_days' => (int) $row->outside_days,
                'unmeasured_days' => (int) $row->unmeasured_days,
                'has_recap' => $existing->has((int) $row->employee_id),
            ])->all(),
        ];
    }
}
