<?php

namespace Tests\Feature\HrPayroll;

use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\OvertimeBasis;
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
 *
 * -----------------------------------------------------------------------
 * F-5 MENYEBERANGINYA, DAN ITU DICATAT DI SINI — BUKAN DILEWATI BEGITU SAJA
 * -----------------------------------------------------------------------
 * Pesan galat di bawah berbunyi: "Kalau tautan ini memang diputuskan pemilik,
 * hapus baris ini DAN tulis alasannya di LAPORAN-PAKET — jangan lewat begitu
 * saja." Pada 14 September 2026 tautan itu DIBUAT, dan pada putaran pertama ia
 * lewat begitu saja: F-5 menyeberang lewat `TimesheetService`, satu nama yang
 * tidak ada di jaring jarum di bawah, dan uji perilakunya memakai rekap
 * berlembur 0 jam sehingga `overtimeComputation()` pulang lebih awal tanpa
 * pernah menyentuh absensi. Berkas ini HIJAU sementara mengoreksi satu cap jam
 * menggeser upah lembur Rp 250.000 dan netto Rp 230.000.
 *
 * Yang dipaku sekarang BUKAN LAGI "tidak ada jalur", melainkan BATASNYA, dan
 * batas itu satu kalimat:
 *
 *     ABSENSI BOLEH MENGGESER TARIF. ABSENSI TIDAK PERNAH MENGGESER JUMLAH JAM.
 *
 * Berapa jam lembur yang dibayar tetap datang dari rekap bulanan — dokumen yang
 * diperiksa dan disimpan manusia, dan yang otoritatif atasnya tetap ILB. Yang
 * diputuskan bentuk harian absensi hanyalah 1,5x/2x-nya. Uji perilaku di bawah
 * karena itu memakai rekap yang BENAR-BENAR BERLEMBUR, supaya separuh yang
 * dijaganya sungguh dijalankan, dan menuntut kedua arahnya sekaligus: jamnya
 * diam, tarifnya bergerak.
 *
 * CONVENTIONS §29 dan §43 membawa kalimat yang sama, dan LAPORAN-PAKET-HM-F-5
 * §1 tidak lagi mengutip kehijauan berkas ini sebagai bukti tidak ada yang
 * berubah.
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
     * SATU-SATUNYA JEMBATAN, DAN IA DISEBUT NAMANYA.
     *
     * `PayrollService` boleh mengenal register absensi hanya lewat satu pintu
     * yang diaudit: `TimesheetService::measuredOvertimeShape()`, yang memulangkan
     * BENTUK harian lembur dan tidak pernah jumlahnya. Setiap pintu lain di
     * kelas yang sama (`forEmployee`, `day`, `policy`) membawa jam kerja,
     * keterlambatan dan keadaan hari — bahan yang tidak punya urusan dengan
     * satu rupiah pun, dan yang kalau sampai ke sini akan sampai tanpa ada yang
     * memutuskannya.
     */
    public function test_the_only_bridge_from_the_register_to_payroll_is_the_overtime_shape(): void
    {
        $source = (string) file_get_contents(base_path('Modules/HrPayroll/Services/PayrollService.php'));

        $this->assertSame(
            1,
            substr_count($source, 'TimesheetService'),
            'PayrollService menyebut TimesheetService lebih dari sekali. Jembatan absensi→payroll '
            .'harus punya SATU tempat yang bisa dibaca sekaligus, bukan beberapa panggilan yang '
            .'masing-masing tampak kecil.',
        );
        $this->assertStringContainsString('measuredOvertimeShape(', $source);

        foreach (['->forEmployee(', '->policy(', '->day(', '->breakMinutes(', '->overtimeMinutes('] as $otherDoor) {
            $this->assertStringNotContainsString($otherDoor, $source, sprintf(
                'PayrollService memanggil %s. Pintu itu membawa jam kerja dan keterlambatan — '
                .'bahan yang tidak boleh punya jalan ke slip gaji sama sekali.',
                $otherDoor,
            ));
        }
    }

    /**
     * BATASNYA, dipaku pada rekap yang BENAR-BENAR BERLEMBUR.
     *
     * Bentuk lama uji ini memakai rekap 0 jam, dan pada rekap 0 jam
     * `overtimeComputation()` pulang lebih awal: separuh perilaku yang paling
     * penting tidak pernah dijalankan, dan uji ini hijau untuk jalur yang
     * menggeser Rp 250.000. Di bawah, satu cap jam dikoreksi dan kedua arahnya
     * dituntut sekaligus — jumlah jamnya DIAM, tarifnya BERGERAK.
     */
    public function test_correcting_a_stamp_moves_the_overtime_rate_but_never_the_hours_that_are_paid(): void
    {
        $employee = $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
        $run = $this->makeRun(['period_year' => 2026, 'period_month' => 6]);

        // Rekap DITETAPKAN SEKALI dan tidak pernah disentuh lagi: 4 jam.
        $this->makeRecap($employee, $run, 4);

        // Dua hari terukur, 2 jam lembur masing-masing — totalnya sama dengan
        // rekap, jadi jalur rincian harian menyala.
        foreach (['2026-06-01', '2026-06-02'] as $date) {
            Attendance::query()->create([
                'employee_id' => $employee->id,
                'date' => $date,
                'status' => 'hadir',
                'check_in_at' => "{$date} 08:00:00",
                'check_out_at' => "{$date} 19:00:00",
            ]);
        }

        $this->payrollService()->calculate($run);
        $before = Payslip::query()->where('payroll_run_id', $run->id)->firstOrFail();
        $payBefore = (float) $before->overtime_pay;

        $this->assertSame(OvertimeBasis::RincianHarian, $before->overtime_basis, 'Prasyarat: jalur rincian harian menyala.');

        /*
         * SATU cap jam dikoreksi — persis pintu yang F-4 bangun dan panduan
         * anjurkan untuk "lupa absen pulang". Absensi sekarang mengukur LIMA
         * jam sementara rekap tetap membayar EMPAT, dan justru selisih itulah
         * yang membuat uji ini bisa merah: kalau jumlah jam diam-diam diambil
         * dari absensi, kolom `overtime_hours` akan berbunyi 5.
         */
        Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '2026-06-02')
            ->update(['check_out_at' => '2026-06-02 20:00:00']);

        $this->payrollService()->calculate($run->refresh());
        $after = Payslip::query()->where('payroll_run_id', $run->id)->firstOrFail();

        $this->assertSame(
            (string) $before->overtime_hours,
            (string) $after->overtime_hours,
            'JUMLAH JAM datang dari rekap bulanan dan tidak boleh bergerak karena satu cap jam '
            .'dikoreksi. Kalau baris ini merah, absensi sudah menjadi masukan payroll yang sebenarnya '
            .'— dan itu keputusan pemilik, bukan akibat sampingan sebuah suntingan.',
        );
        $this->assertSame((string) $before->basic_salary, (string) $after->basic_salary);
        $this->assertSame((string) $before->allowances_total, (string) $after->allowances_total);
        $this->assertSame((string) $before->bpjs_employee_total, (string) $after->bpjs_employee_total);

        // ...dan sisi sebaliknya: tarifnya memang bergerak, jadi uji ini
        // benar-benar sedang melihat jalur yang hidup.
        $this->assertNotSame(
            $payBefore,
            (float) $after->overtime_pay,
            'Kalau upah lemburnya TIDAK bergerak, jembatan absensi→tarif sudah mati dan uji ini '
            .'hijau untuk alasan yang salah — persis kegagalan yang berkas ini dibangun ulang untuk '
            .'menutupnya.',
        );
    }

    /**
     * Sisi perilaku pada periode yang TIDAK berlembur: absensi sedrastis apa pun
     * tidak boleh menggeser satu rupiah pun. Berpasangan dengan uji di atas —
     * yang ini menjaga bahwa tidak ada jalur KEDUA yang diam-diam terbuka.
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

    /**
     * Usulan rekap hanya membaca: tidak ada POST/PUT yang menerimanya.
     *
     * Disaring pada URI usulan rekap ITU SENDIRI, bukan pada kata "proposal" di
     * seluruh tabel rute. Bentuk lamanya menuntut daftar persis atas SETIAP rute
     * yang memuat kata itu di mana pun di aplikasi, jadi ia merah di gerbang
     * rilis F-6 — yang menambahkan `GET api/inventory/reorder/proposal`, sebuah
     * rute yang benar, di modul lain, yang tidak ada hubungannya dengan payroll.
     * Uji yang gagal karena modul lain menamai rutenya dengan wajar adalah uji
     * yang mengajari orang melemahkannya; yang dijaga di sini adalah pintu
     * usulan rekap, dan hanya itu.
     */
    public function test_the_recap_proposal_has_no_write_door(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'attendance-recaps/proposal'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(['GET|HEAD api/hr/attendance-recaps/proposal'], $routes);

        // ...dan tidak ada metode tulis yang mendarat di URI itu lewat rute lain
        // (mis. sebuah resource route yang kebetulan mencakupnya).
        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'attendance-recaps/proposal'))
            ->flatMap(fn ($route) => $route->methods())
            ->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])
            ->values()
            ->all();

        $this->assertSame([], $writes);
    }
}
