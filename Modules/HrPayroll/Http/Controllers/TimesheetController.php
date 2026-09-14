<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Services\TimesheetService;

/**
 * Timesheet & lembur dari absensi (F-5) — BACA-SAJA, seluruhnya.
 *
 * Tidak ada satu pun POST/PUT/DELETE di sini, dan itu keputusan yang sama
 * dengan usulan rekap F-4: yang menulis rekap bulanan tetap manusia lewat
 * formulir yang sudah ada, dengan izin yang sudah ada. Register absensi boleh
 * dikoreksi kapan saja; payroll yang sudah disetujui sudah membukukan jurnal
 * dan membayar orang. Jalur otomatis dari yang pertama ke yang kedua berarti
 * mengetik ulang absen bulan lalu menggerakkan uang yang sudah keluar.
 *
 * TIGA PINTU, DAN KENAPA YANG KETIGA MENJAWAB 404
 * -----------------------------------------------
 *  - `timesheet/me`        milik pemanggil sendiri, TANPA izin hr.*. Tukang,
 *                          teknisi dan pengemudi tidak memegang satu pun izin
 *                          HR, dan timesheet yang hanya bisa dilihat HR adalah
 *                          timesheet yang orangnya tidak pernah bisa membantah.
 *                          Tidak ada satu parameter pun di pintu ini yang bisa
 *                          menyebut orang lain.
 *  - `timesheet`           satu periode untuk SETIAP orang, di balik hr.view —
 *                          gerbang yang sama dengan register absensi sejak F-4,
 *                          karena isinya jam datang dan jam pulang orang.
 *  - `timesheet/{id}`      satu orang dengan rincian hariannya. TANPA middleware
 *                          izin, karena izinnya bersyarat: milik sendiri selalu
 *                          boleh, milik orang lain menuntut hr.view.
 *
 * Pintu ketiga menjawab **404 YANG SAMA PERSIS** untuk "karyawan ini bukan
 * Anda dan Anda tidak memegang hr.view" dan untuk "karyawan dengan id ini tidak
 * ada" — pola PushSubscriptionEndpointTest. 403 akan memberi tahu pemanggil
 * bahwa id itu ADA, dan dengan menyapu id 1..N siapa pun bisa menghitung jumlah
 * karyawan perusahaan tanpa memegang izin apa pun. Karena itu juga pengikat
 * model rute TIDAK dipakai di sini: 404 bawaannya berbentuk lain, dan dua
 * bentuk penolakan yang berbeda adalah cara membedakan kedua keadaan itu.
 */
class TimesheetController extends ApiController
{
    /** Satu kalimat untuk dua keadaan — lihat kepala kelas. */
    private const NOT_FOUND_MESSAGE = 'Timesheet yang diminta tidak ditemukan.';

    private const NO_EMPLOYEE_MESSAGE = 'Akun ini belum ditautkan ke data karyawan, jadi tidak ada '
        .'timesheet yang bisa ditampilkan atas namanya. Minta HR menautkan akun Anda ke kartu '
        .'karyawan (Karyawan → akun pengguna).';

    public function __construct(private readonly TimesheetService $service) {}

    /**
     * Satu periode, setiap orang yang punya sesuatu di dalamnya.
     *
     * Periode tanpa satu pun absensi, ILB dan rekap memulangkan `rows: []` —
     * bukan satu baris nol per karyawan. Keadaan itulah keadaan produksi hari
     * ini, dan layar mengatakannya dengan kalimat, bukan dengan tabel nol.
     */
    public function index(Request $request): JsonResponse
    {
        [$year, $month] = $this->period($request);

        return $this->ok($this->service->forPeriod($year, $month));
    }

    /** Timesheet SAYA — tanpa izin hr.*, dan tanpa parameter yang menyebut orang lain. */
    public function mine(Request $request): JsonResponse
    {
        $employeeId = $request->user()?->employee_id;
        $employee = $employeeId === null ? null : Employee::query()->find($employeeId);

        if ($employee === null) {
            // Kalimatnya ikut DI DALAM data, bukan hanya di amplop: api.get()
            // milik SPA memulangkan `data` saja, dan sebuah layar yang menyusun
            // kalimatnya sendiri adalah kalimat kedua yang akan menyimpang dari
            // yang pertama. Amplopnya tetap membawanya untuk pemanggil API.
            return $this->ok(
                ['linked' => false, 'employee' => null, 'days' => [], 'notice' => self::NO_EMPLOYEE_MESSAGE],
                self::NO_EMPLOYEE_MESSAGE,
            );
        }

        [$year, $month] = $this->period($request);

        return $this->ok($this->service->forEmployee($employee, $year, $month) + ['linked' => true]);
    }

    /** Satu orang. Milik sendiri selalu boleh; milik orang lain menuntut hr.view. */
    public function show(Request $request, string $employee): JsonResponse
    {
        $user = $request->user();
        $row = ctype_digit($employee) ? Employee::query()->find((int) $employee) : null;

        $isMine = $row !== null && $user?->employee_id !== null && (int) $row->id === (int) $user->employee_id;

        if ($row === null || (! $isMine && ! (bool) $user?->can('hr.view'))) {
            // Satu kalimat untuk dua keadaan — lihat kepala kelas.
            return $this->error(self::NOT_FOUND_MESSAGE, 404);
        }

        [$year, $month] = $this->period($request);

        return $this->ok($this->service->forEmployee($row, $year, $month) + ['linked' => true]);
    }

    /**
     * Periode yang diminta, atau bulan berjalan.
     *
     * Bulan di luar 1..12 dijepit, bukan ditolak: layar mengirim angka dari
     * kotak pilihannya sendiri, dan sebuah 422 di sini hanya akan muncul pada
     * tautan yang diketik tangan.
     *
     * @return array{0: int, 1: int}
     */
    private function period(Request $request): array
    {
        $now = now();
        $year = $request->integer('period_year') ?: (int) $now->year;
        $month = $request->integer('period_month') ?: (int) $now->month;

        return [max(2000, min(2100, $year)), max(1, min(12, $month))];
    }
}
