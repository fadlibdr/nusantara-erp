<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Modules\Core\Services\DocumentPdfService;
use Modules\HrPayroll\Enums\OvertimeBasis;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\Payslip;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * SLIPNYA MENGATAKAN DENGAN DASAR APA IA DIBAYAR — di kertas, bukan di JSON.
 *
 * Seluruh pembenaran migrasi 001094 adalah satu kalimat, dan ia diulang di
 * empat tempat (migrasi, `OvertimeBasis`, `PayslipResource`, `PayrollService`)
 * serta di PANDUAN-PENGGUNA §21 yang memberi tahu KARYAWAN bahwa "slipnya
 * menyebutkan yang mana yang dipakai beserta sebabnya":
 *
 *     "sebuah slip yang tidak mengatakan jalur mana yang dipakainya membuat
 *      dua periode dibayar berbeda tanpa ada yang bisa melihat sebabnya"
 *
 * Sampai putaran verifikasi 14 Sep 2026 kalimat itu tidak benar. Kolomnya
 * memang diisi dan dibekukan dengan benar — lalu tidak pernah digambar di mana
 * pun: `grep -rn 'overtime_basis' public/ resources/` memulangkan NOL baris,
 * lembar PDF yang benar-benar diserahkan ke karyawan hanya mencetak
 * "Lembur (7,0 jam)", dan layar run gaji hanya mencetak rupiahnya. Cacat yang
 * paket ini nyatakan telah dicegah masih persis ada bagi setiap orang yang
 * membaca gaji.
 *
 * Yang membedakan kedua jalur bayar dalam praktik adalah hal paling sepele di
 * lapangan: satu hari LUPA ABSEN PULANG. Uji pertama di bawah membayar dua
 * orang dengan jam ILB yang sama persis dan upah yang sama persis, dan
 * selisihnya Rp 250.000.
 *
 * Berkas ini memaku JANJI terhadap GAMBAR, supaya keduanya tidak bisa berpisah
 * lagi tanpa ada uji yang merah.
 */
class PayslipSaysItsOvertimeBasisTest extends ErpTestCase
{
    use PayrollFixtures;

    private function html(int $payslipId): string
    {
        return app(DocumentPdfService::class)->html(
            'payslip',
            Payslip::query()->findOrFail($payslipId),
        );
    }

    /** Pengguna yang ditautkan ke satu kartu karyawan, tanpa satu pun izin hr.*. */
    private function userFor(Employee $employee): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pengguna '.$employee->code,
            'email' => strtolower($employee->code).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'employee_id' => $employee->id,
        ]);

        return $user;
    }

    private function measuredDay(int $employeeId, string $date, string $out): void
    {
        Attendance::query()->create([
            'employee_id' => $employeeId,
            'date' => $date,
            'status' => 'hadir',
            'check_in_at' => "{$date} 08:00:00",
            'check_out_at' => "{$date} {$out}:00",
        ]);
    }

    /**
     * DUA ORANG, JAM ILB YANG SAMA, UPAH YANG SAMA, SELISIH Rp 95.375,72 —
     * dan sejak commit ini, slip keduanya mengatakan sebabnya.
     */
    public function test_two_slips_paid_differently_for_the_same_hours_each_name_their_basis(): void
    {
        $rapi = $this->makeEmployee(['base_salary' => 10_000_000, 'fixed_allowances' => ['transport' => 1_000_000]]);
        $lupa = $this->makeEmployee(['base_salary' => 10_000_000, 'fixed_allowances' => ['transport' => 1_000_000]]);
        $run = $this->makeRun();

        // Keduanya: rekap 4 jam lembur. Yang satu bercap jam lengkap dua hari
        // (2 + 2 = 4, sama dengan rekap); yang lain LUPA ABSEN PULANG sehari.
        foreach ([$rapi, $lupa] as $employee) {
            $this->makeRecap($employee, $run, 4);
            $this->measuredDay($employee->id, '2026-06-01', '19:00');
        }
        $this->measuredDay($rapi->id, '2026-06-02', '19:00');

        Attendance::query()->create([
            'employee_id' => $lupa->id,
            'date' => '2026-06-02',
            'status' => 'hadir',
            'check_in_at' => '2026-06-02 08:00:00',
            'check_out_at' => null,
        ]);

        $this->payrollService()->calculate($run);

        $rapiSlip = $this->payslipFor($run, $rapi);
        $lupaSlip = $this->payslipFor($run, $lupa);

        // Prasyarat: jam yang dibayar SAMA, uangnya tidak.
        $this->assertSame((string) $rapiSlip->overtime_hours, (string) $lupaSlip->overtime_hours);
        $this->assertNotSame((float) $rapiSlip->overtime_pay, (float) $lupaSlip->overtime_pay);
        $this->assertSame(OvertimeBasis::RincianHarian, $rapiSlip->overtime_basis);
        $this->assertSame(OvertimeBasis::RataJamPertama, $lupaSlip->overtime_basis);

        $rapiHtml = $this->html($rapiSlip->id);
        $lupaHtml = $this->html($lupaSlip->id);

        $this->assertStringContainsString(
            OvertimeBasis::RincianHarian->label(),
            $rapiHtml,
            'Slip yang dibayar dengan rincian harian harus mengatakannya di lembar yang orangnya '
            .'terima — bukan hanya di JSON API yang ia tidak punya jalan membukanya.',
        );
        $this->assertStringContainsString(OvertimeBasis::RataJamPertama->label(), $lupaHtml);
        $this->assertStringContainsString(
            'Rincian harian dari absensi berjumlah',
            $lupaHtml,
            'Kalimat `reason` yang menjelaskan KENAPA bulan ini dibayar rata adalah satu-satunya '
            .'jawaban atas pertanyaan yang orang itu akan ajukan. Ia sudah ditulis ke basis data; '
            .'yang kurang hanya menggambarnya.',
        );
    }

    /**
     * NULL berbunyi BERBEDA dari "Tanpa lembur" — persis yang PayslipResource
     * janjikan ("layar mengatakannya berbeda"). Sebuah slip yang lahir sebelum
     * kolomnya ada tidak boleh terbaca sebagai slip yang dasarnya diketahui.
     */
    public function test_a_slip_from_before_the_column_existed_says_its_basis_was_never_recorded(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 10_000_000]);
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 4);
        $this->payrollService()->calculate($run);

        $slip = $this->payslipFor($run, $employee);
        $slip->forceFill(['overtime_basis' => null, 'overtime_rate_detail' => null])->save();

        $html = $this->html($slip->id);

        $this->assertStringContainsString('tidak dicatat (slip dihitung sebelum 14 September 2026)', $html);
        $this->assertStringNotContainsString(
            OvertimeBasis::TanpaLembur->label(),
            $html,
            'Kolom KOSONG dan "tanpa lembur" adalah dua keadaan yang berbeda, dan slip ini punya '
            .'lembur. Menyamakannya menghapus satu-satunya tanda bahwa dasarnya memang tidak '
            .'pernah dicatat.',
        );
    }

    /**
     * KALENDER LEMBUR HARIAN ORANG LAIN TIDAK IKUT KELUAR.
     *
     * `overtime_rate_detail.days` adalah daftar tanggal dan jam lembur harian
     * seseorang — data turunan yang `GET hr/timesheet/{employee}` jaga dengan
     * 404 yang sengaja tidak bisa dibedakan dari "id tidak ada". F-5
     * menambahkannya ke `PayslipResource`, yang antara lain dilayani
     * `GET hr/employees/{employee}/payslips` — rute tanpa gerbang izin
     * (keadaan pra-F-5). Tanpa penyaringan, data yang satu pintu tolak keluar
     * bebas lewat pintu di sebelahnya.
     */
    public function test_someone_elses_daily_overtime_calendar_never_leaves_through_the_payslip_door(): void
    {
        $mine = $this->makeEmployee(['base_salary' => 10_000_000]);
        $theirs = $this->makeEmployee(['base_salary' => 10_000_000]);
        $run = $this->makeRun();

        $this->makeRecap($theirs, $run, 4);
        $this->measuredDay($theirs->id, '2026-06-01', '19:00');
        $this->measuredDay($theirs->id, '2026-06-02', '19:00');
        $this->payrollService()->calculate($run);

        $this->assertSame(
            OvertimeBasis::RincianHarian,
            $this->payslipFor($run, $theirs)->overtime_basis,
            'Prasyarat: slip orang lain BENAR-BENAR membawa rincian hariannya.',
        );

        // Pengguna yang ditautkan ke karyawan LAIN, tanpa satu pun izin hr.*.
        $outsider = $this->userFor($mine);

        $response = $this->actingAs($outsider, 'sanctum')
            ->getJson("api/hr/employees/{$theirs->id}/payslips")
            ->assertOk();

        $detail = $response->json('data.0.overtime_rate_detail');

        $this->assertIsArray($detail);
        $this->assertArrayNotHasKey(
            'days',
            $detail,
            'Tanggal dan jam lembur harian orang lain keluar lewat pintu slip. Pintu timesheet di '
            .'sebelahnya menjawab 404 untuk data turunan yang sama persis.',
        );
        $this->assertArrayHasKey('hours_at_first_rate', $detail, 'Yang disaring HANYA kalendernya.');

        // ...dan pemilik slipnya sendiri tetap melihat rinciannya utuh.
        $this->assertArrayHasKey(
            'days',
            $this->actingAs($this->userFor($theirs), 'sanctum')
                ->getJson("api/hr/employees/{$theirs->id}/payslips")
                ->json('data.0.overtime_rate_detail'),
        );
    }

    /**
     * Pemeriksa run melihat dasarnya SEBELUM menyetujui, bukan sesudah slipnya
     * dicetak. Penjaga teks, dan itu batasnya yang jujur — yang menjalankan
     * cabangnya sungguhan adalah harness.
     */
    public function test_the_payroll_run_screen_draws_the_basis_beside_the_rupiah(): void
    {
        $code = (string) file_get_contents(base_path('public/app/js/views/custom.js'));

        $this->assertStringContainsString('overtime_basis_label', $code);
        $this->assertStringContainsString(
            'dasar tidak dicatat (slip sebelum 14 Sep 2026)',
            $code,
            'Slip lama harus MENGATAKAN bahwa dasarnya tidak dicatat. Sel kosong di kolom itu akan '
            .'dibaca sebagai "tidak ada lembur".',
        );
    }
}
