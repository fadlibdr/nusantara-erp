<?php

namespace Tests\Feature\HrPayroll;

use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Payslip;
use Tests\ErpTestCase;

/**
 * Register absensi BUKAN masukan payroll — dipaku, bukan dijanjikan.
 *
 * Alasannya bukan kehati-hatian yang samar. Register absensi bisa dikoreksi
 * kapan saja (itu memang gunanya, dan F-4 justru menambah pintu koreksinya),
 * sedangkan payroll run yang disetujui SUDAH membukukan jurnal dan membayar
 * orang. Jalur otomatis dari yang pertama ke yang kedua berarti mengetik ulang
 * absen bulan lalu menggerakkan uang yang sudah keluar, tanpa satu pun
 * persetujuan di antaranya.
 *
 * Dua lapis, karena satu tidak cukup: perilaku (menghitung ulang payroll
 * sesudah mengubah absensi tidak menggeser satu angka pun) DAN sumber (tidak
 * ada berkas penghasil payroll yang menyebut register absensi sama sekali).
 * Uji perilaku sendirian hijau untuk jalur yang belum ada datanya; uji sumber
 * sendirian hijau untuk kode yang menyebutnya lewat nama tabel mentah.
 */
class AttendanceIsNotPayrollInputTest extends ErpTestCase
{
    use PayrollFixtures;

    /**
     * Berkas yang benar-benar menghasilkan angka slip gaji, dan tidak boleh
     * mengenal register absensi.
     *
     * @var list<string>
     */
    private const PAYROLL_SOURCES = [
        'Modules/HrPayroll/Services/PayrollService.php',
        'Modules/HrPayroll/Services/PayrollPostingService.php',
        'Modules/HrPayroll/Services/Pph21TerService.php',
        'Modules/HrPayroll/Services/OvertimeRecapService.php',
        'Modules/HrPayroll/Http/Controllers/PayrollRunController.php',
        'Modules/HrPayroll/Models/PayrollRun.php',
        'Modules/HrPayroll/Models/Payslip.php',
    ];

    /**
     * ...dan sebaliknya: berkas absensi tidak boleh menyentuh payroll.
     *
     * @var list<string>
     */
    private const ATTENDANCE_SOURCES = [
        'Modules/HrPayroll/Services/AttendanceService.php',
        'Modules/HrPayroll/Services/AttendanceClockService.php',
        'Modules/HrPayroll/Services/AttendanceCorrectionService.php',
        'Modules/HrPayroll/Services/AttendanceRecapProposalService.php',
        'Modules/HrPayroll/Http/Controllers/AttendanceController.php',
    ];

    public function test_no_payroll_source_reads_the_attendance_register(): void
    {
        foreach (self::PAYROLL_SOURCES as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            /*
             * Jaring ini pernah bocor (verifikasi F-4). Tiga jarum pertama
             * tidak melihat `$employee->attendances()` — relasi hasMany di
             * Employee — maupun service absensi yang di-inject lewat
             * konstruktor, dan sebuah mutasi yang memotong gaji pokok dari
             * register GPS lolos HIJAU di kedua lensa. `AttendanceRecap`
             * sengaja TIDAK ikut terjaring: rekap bulanan memang masukan
             * payroll yang sah, dan itulah seluruh pemisahannya.
             */
            $needles = [
                'hr_attendances',
                'Models\\Attendance;',
                'Attendance::',
                'attendances(',
                '->attendances',
                'AttendanceService',
                'AttendanceClockService',
                'AttendanceCorrection',
                'AttendanceRecapProposal',
            ];

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $source, sprintf(
                    '%s menyebut register absensi (%s). Register bisa dikoreksi kapan saja; payroll yang '
                    .'disetujui sudah membukukan jurnal. Kalau tautan ini memang diputuskan pemilik, '
                    .'hapus baris ini DAN tulis alasannya di LAPORAN-PAKET — jangan lewat begitu saja.',
                    $relative,
                    $needle,
                ));
            }
        }
    }

    public function test_no_attendance_source_touches_payroll(): void
    {
        foreach (self::ATTENDANCE_SOURCES as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            foreach (['PayrollRun', 'Payslip', 'hr_payroll_runs', 'hr_payslips'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, sprintf(
                    '%s menyentuh payroll (%s).',
                    $relative,
                    $needle,
                ));
            }
        }
    }

    /**
     * Sisi perilaku: ubah absensi sedrastis mungkin, hitung ulang payroll,
     * dan angka bruto/netto tidak boleh bergeser satu rupiah pun.
     */
    public function test_rewriting_the_register_does_not_move_a_calculated_payslip(): void
    {
        $this->seedLedger(2026);

        $employee = $this->makeEmployee(['base_salary' => 8_000_000]);
        $run = $this->makeRun(['period_year' => 2026, 'period_month' => 6]);
        $this->makeRecap($employee, $run, 0);

        $this->payrollService()->calculate($run);
        $before = Payslip::query()->where('payroll_run_id', $run->id)->firstOrFail();
        $grossBefore = (string) $before->gross_pay;
        $netBefore = (string) $before->net_pay;

        foreach (range(1, 30) as $day) {
            Attendance::query()->create([
                'employee_id' => $employee->id,
                'date' => sprintf('2026-06-%02d', $day),
                'status' => 'absen',
            ]);
        }

        $run->refresh();
        $this->assertSame(DocumentStatus::Draft, $run->status, 'Prasyarat: run masih draft, jadi boleh dihitung ulang.');
        $this->payrollService()->calculate($run);

        $after = Payslip::query()->where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertSame($grossBefore, (string) $after->gross_pay);
        $this->assertSame($netBefore, (string) $after->net_pay);
    }

    /** Usulan rekap hanya membaca: tidak ada POST/PUT yang menerimanya. */
    public function test_the_recap_proposal_has_no_write_door(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->filter(fn (string $line) => str_contains($line, 'proposal'))
            ->values()
            ->all();

        $this->assertSame(['GET|HEAD api/hr/attendance-recaps/proposal'], $routes);
    }
}
