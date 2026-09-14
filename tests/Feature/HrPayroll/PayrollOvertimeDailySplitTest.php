<?php

namespace Tests\Feature\HrPayroll;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\OvertimeBasis;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Employee;
use Tests\ErpTestCase;

/**
 * UANG: 1,5x/2x PER HARI, DAN APA YANG SENGAJA TIDAK BERUBAH (F-5, T5.3).
 *
 * Sampai 13 September 2026 payroll membayar lembur dengan 1,5x RATA atas total
 * bulanan, dan komentar PayrollService mengakui sendiri itu kurang: Kepmenaker
 * 102/2004 memberi 1,5x untuk jam PERTAMA setiap hari lembur dan 2x untuk jam
 * berikutnya. Arah kesalahannya satu arah — tarif rata membayar KURANG.
 *
 * Berkas ini memaku ketiga hal yang membuat perbaikan itu boleh menyentuh uang:
 *
 *  1. ketika rincian harian ADA dan TOTALNYA SAMA dengan rekap yang dibayar,
 *     jam pertama tiap hari dibayar 1,5x dan sisanya 2x — dan angkanya ditulis
 *     sampai rupiah terakhir di sini;
 *  2. ketika rincian harian TIDAK ADA, atau totalnya BERBEDA dari rekap,
 *     payroll memakai jalur LAMA apa adanya dan slipnya MENGATAKAN itu beserta
 *     sebabnya;
 *  3. slip yang SUDAH DIPOSTING tidak berubah nilainya oleh paket ini, titik.
 *
 * Upah sebulan di seluruh berkas ini 11.000.000 (pokok 10 jt + transport 1 jt)
 * dengan pembagi 173, jadi upah sejam = 63.583,815028901736 — bilangan yang
 * sama yang dipakai PayrollOvertimeTest sejak P0, supaya angka baru di sini
 * bisa dibandingkan langsung dengan angka lama di sana.
 */
class PayrollOvertimeDailySplitTest extends ErpTestCase
{
    use PayrollFixtures;

    private function employeeOnElevenMillion(): Employee
    {
        return $this->makeEmployee([
            'base_salary' => 10_000_000,
            'fixed_allowances' => ['transport' => 1_000_000],
        ]);
    }

    /**
     * Satu hari kerja dengan lembur sekian jam penuh di atas 8 jam normal.
     *
     * Pulang dihitung dari 17:00, bukan 16:00: hari kerja delapan jam
     * berlangsung sembilan jam di jam dinding karena istirahat 60 menit tidak
     * termasuk jam kerja (UU 13/2003 Pasal 79, TimesheetService::breakMinutes).
     * 08:00–16:00 adalah TUJUH jam kerja, bukan delapan.
     */
    private function overtimeDay(Employee $employee, string $date, float $hours): void
    {
        $out = sprintf('%02d:%02d', 17 + (int) floor($hours), (int) round(fmod($hours, 1) * 60));

        Attendance::query()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => 'hadir',
            'check_in_at' => "{$date} 08:00:00",
            'check_out_at' => "{$date} {$out}:00",
        ]);
    }

    // ------------------------------------------- jalur baru: rincian harian

    public function test_six_overtime_hours_spread_over_three_days_pay_one_and_a_half_then_double(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun(); // Juni 2026

        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->overtimeDay($employee, $date, 2);
        }

        $this->makeRecap($employee, $run, 6);
        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertSame(OvertimeBasis::RincianHarian, $slip->overtime_basis);
        // 3 jam pertama x 1,5 + 3 jam berikutnya x 2 = 10,5 x 63.583,815028901736
        $this->assertMoney(667_630.06, $slip->overtime_pay);
        $this->assertMoney(6.0, $slip->overtime_hours, 'Total jamnya TETAP dari rekap bulanan.');

        $detail = $slip->overtime_rate_detail;
        $this->assertSame(3.0, (float) $detail['hours_at_first_rate']);
        $this->assertSame(3.0, (float) $detail['hours_at_next_rate']);
        $this->assertCount(3, $detail['days']);
        $this->assertNull($detail['reason'], 'Jalur utama tidak perlu menjelaskan diri.');
    }

    public function test_the_same_six_hours_in_one_day_pay_more_because_five_of_them_are_second_hours(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        $this->overtimeDay($employee, '2026-06-01', 6);
        $this->makeRecap($employee, $run, 6);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        // 1 jam pertama x 1,5 + 5 jam berikutnya x 2 = 11,5 x upah sejam
        $this->assertMoney(731_213.87, $slip->overtime_pay);
        $this->assertSame(1.0, (float) $slip->overtime_rate_detail['hours_at_first_rate']);
        $this->assertSame(5.0, (float) $slip->overtime_rate_detail['hours_at_next_rate']);
    }

    public function test_the_daily_split_pays_more_than_the_flat_rate_it_replaces(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->overtimeDay($employee, $date, 2);
        }
        $this->makeRecap($employee, $run, 6);

        $this->payrollService()->calculate($run);

        $this->assertGreaterThan(
            572_254.34, // 6 jam x 1,5 x upah sejam — jalur lama untuk jam yang sama
            (float) $this->payslipFor($run, $employee)->overtime_pay,
            'Arah kesalahan jalur lama satu arah: tarif rata MEMBAYAR KURANG untuk jam kedua dan '
            .'seterusnya. Perbaikan yang membayar kurang atau sama bukan perbaikan.',
        );
    }

    public function test_half_an_hour_of_overtime_on_one_day_never_reaches_the_second_hour_rate(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        $this->overtimeDay($employee, '2026-06-01', 0.5);
        $this->makeRecap($employee, $run, 0.5);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertSame(0.5, (float) $slip->overtime_rate_detail['hours_at_first_rate']);
        $this->assertSame(0.0, (float) $slip->overtime_rate_detail['hours_at_next_rate']);
        $this->assertMoney(47_687.86, $slip->overtime_pay); // 0,5 x 1,5 x upah sejam
    }

    public function test_hours_on_a_non_working_day_never_enter_the_split(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        // Minggu 7 Juni 2026, sepuluh jam. Terukur, dilaporkan — dan sengaja
        // TIDAK diusulkan sebagai lembur hari kerja.
        $this->overtimeDay($employee, '2026-06-07', 2);
        // Satu hari kerja yang terukur, supaya periodenya punya bentuk.
        $this->overtimeDay($employee, '2026-06-01', 2);
        $this->makeRecap($employee, $run, 2);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertSame(OvertimeBasis::RincianHarian, $slip->overtime_basis);
        $this->assertCount(
            1,
            $slip->overtime_rate_detail['days'],
            'Hari Minggu tidak boleh muncul di rincian bayar: tarif hari libur Kepmenaker (2x/3x/4x) '
            .'tidak dibangun paket ini, dan memasukkannya ke rumus hari kerja berarti membayar kurang.',
        );
    }

    // ------------------------------------- satu sumber jam, bukan dua

    /**
     * SLIP TIDAK BOLEH MEMBANTAH DIRINYA SENDIRI.
     *
     * Sampai putaran verifikasi 14 Sep 2026, bentuk harian membulatkan menit
     * menjadi jam DUA KALI dengan cara berbeda: `total_hours` dari jumlah
     * menit, tetapi tiap `days[].hours` dari menit HARI ITU. Gerbang kesamaan
     * total membandingkan rekap terhadap yang pertama, sementara uang dihitung
     * dari jumlah yang kedua — jadi jumlah jam yang DIBAYAR slip berbeda dari
     * jumlah jam yang TERTULIS pada slip yang sama.
     *
     * Pada pembulatan bawaan 15 menit ini tidak pernah menggigit: seperempat
     * jam desimalnya tepat, jadi seluruh uji paket ini hijau. `rounding_minutes`
     * adalah setelan operator dengan rentang 1..60 yang `help`-nya justru
     * mengundang orang mengubahnya, dan pada 10 menit ia membayar LEBIH,
     * pada 20 menit ia membayar KURANG. Kedua arah dipaku di bawah.
     */
    public function test_ten_minute_rounding_pays_exactly_the_hours_the_slip_says_it_pays(): void
    {
        $this->setSetting('hr.timesheet.rounding_minutes', 10);
        $this->setSetting('hr.timesheet.overtime_minimum_minutes', 0);

        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        // Tiga hari x 10 menit = 30 menit = 0,5 jam tepat. Dibulatkan per hari
        // menjadi jam, tiap hari berbunyi 0,17 dan jumlahnya 0,51.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->overtimeDay($employee, $date, 10 / 60);
        }
        $this->makeRecap($employee, $run, 0.5);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);
        $detail = $slip->overtime_rate_detail;

        $this->assertSame(OvertimeBasis::RincianHarian, $slip->overtime_basis);
        $this->assertMoney(
            47_687.86, // 0,5 jam x 1,5 x 63.583,815028901736
            $slip->overtime_pay,
            'Setengah jam lembur dibayar sebagai setengah jam. Menjumlahkan jam yang sudah '
            .'dibulatkan per hari membayar 0,51 jam di sini — Rp 953,76 untuk satu menit yang '
            .'tidak pernah ada.',
        );
        $this->assertSame(
            0.5,
            round((float) $detail['hours_at_first_rate'] + (float) $detail['hours_at_next_rate'], 2),
            'Rincian tarif HARUS berjumlah persis kolom overtime_hours slip yang sama.',
        );
        $this->assertSame(0.5, round((float) $slip->overtime_hours, 2));
        $this->assertSame(30, (int) $detail['minutes_at_first_rate']);
    }

    public function test_twenty_minute_rounding_does_not_pay_less_than_the_hours_the_slip_says(): void
    {
        $this->setSetting('hr.timesheet.rounding_minutes', 20);
        $this->setSetting('hr.timesheet.overtime_minimum_minutes', 0);

        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        // Tiga hari x 20 menit = 60 menit = 1 jam tepat; per hari 0,33 dan
        // jumlahnya 0,99 — arah yang berlawanan dengan uji di atas.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->overtimeDay($employee, $date, 20 / 60);
        }
        $this->makeRecap($employee, $run, 1);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);
        $detail = $slip->overtime_rate_detail;

        $this->assertMoney(
            95_375.72, // 1 jam x 1,5 x upah sejam
            $slip->overtime_pay,
            'Satu jam lembur dibayar sebagai satu jam. Menjumlahkan jam yang sudah dibulatkan per '
            .'hari membayar 0,99 jam di sini — Rp 953,75 KURANG, pada slip yang kolomnya berbunyi '
            .'1,00 jam.',
        );
        $this->assertSame(
            1.0,
            round((float) $detail['hours_at_first_rate'] + (float) $detail['hours_at_next_rate'], 2),
        );
    }

    /**
     * Tepi tampilan: 110 + 50 menit membulat sendiri-sendiri menjadi 1,83 dan
     * 0,83 = 2,66, sementara totalnya 2,67. Jam berikutnya karena itu adalah
     * SISA, bukan pembulatannya sendiri — dan menit yang tepat ikut dibawa.
     */
    public function test_the_two_rate_buckets_always_add_up_to_the_hours_column(): void
    {
        $this->setSetting('hr.timesheet.rounding_minutes', 10);
        $this->setSetting('hr.timesheet.overtime_minimum_minutes', 0);

        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        $this->overtimeDay($employee, '2026-06-01', 110 / 60);
        $this->overtimeDay($employee, '2026-06-02', 50 / 60);
        $this->makeRecap($employee, $run, 2.67);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);
        $detail = $slip->overtime_rate_detail;

        $this->assertSame(OvertimeBasis::RincianHarian, $slip->overtime_basis);
        $this->assertSame(
            round((float) $slip->overtime_hours, 2),
            round((float) $detail['hours_at_first_rate'] + (float) $detail['hours_at_next_rate'], 2),
        );
        $this->assertSame([110, 50], [(int) $detail['minutes_at_first_rate'], (int) $detail['minutes_at_next_rate']]);
    }

    // ------------------------------------------------ jalur lama tetap hidup

    public function test_a_period_without_any_daily_detail_still_uses_the_old_flat_path_and_says_so(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 10); // ILB, tanpa satu pun cap jam

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertSame(OvertimeBasis::RataJamPertama, $slip->overtime_basis);
        $this->assertMoney(
            953_757.23,
            $slip->overtime_pay,
            'Angka yang sama persis dengan PayrollOvertimeTest sejak P0: periode lama harus dibayar '
            .'dengan cara yang sama seperti sebelum paket ini.',
        );
        $this->assertStringContainsString(
            'Tidak ada satu hari pun dengan cap jam masuk DAN pulang',
            $slip->overtime_rate_detail['reason'],
            'Dua periode yang dibayar dengan tarif berbeda tanpa ada yang bisa melihat sebabnya '
            .'adalah persis cacat yang kolom ini dibuat untuk mencegahnya.',
        );
    }

    public function test_a_recap_that_disagrees_with_the_measured_detail_falls_back_and_names_both_numbers(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        // Absensi mengukur 2 jam; ILB menyetujui 10 dan menulisnya ke rekap.
        $this->overtimeDay($employee, '2026-06-01', 2);
        $this->makeRecap($employee, $run, 10);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertSame(OvertimeBasis::RataJamPertama, $slip->overtime_basis);
        $this->assertMoney(953_757.23, $slip->overtime_pay, 'ILB tetap otoritatif atas JUMLAH jamnya.');

        $reason = $slip->overtime_rate_detail['reason'];
        $this->assertStringContainsString('2 jam', $reason);
        $this->assertStringContainsString('10 jam', $reason);
    }

    public function test_a_month_measured_but_with_no_overtime_still_disagrees_with_a_recap_that_has_hours(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();

        // Hari kerja penuh, tanpa lembur: bentuk bulan itu DIKETAHUI dan
        // jumlahnya nol — sementara rekap membayar 4 jam.
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-01',
            'status' => 'hadir',
            'check_in_at' => '2026-06-01 08:00:00',
            'check_out_at' => '2026-06-01 16:00:00',
        ]);
        $this->makeRecap($employee, $run, 4);

        $this->payrollService()->calculate($run);

        $this->assertSame(OvertimeBasis::RataJamPertama, $this->payslipFor($run, $employee)->overtime_basis);
    }

    public function test_a_recap_without_overtime_hours_is_labelled_as_such_not_left_blank(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 0);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);

        $this->assertSame(
            OvertimeBasis::TanpaLembur,
            $slip->overtime_basis,
            'Kolom KOSONG berarti "slip dihitung sebelum 14 Sep 2026". Memakainya juga untuk '
            .'"tidak ada lembur" menggabungkan dua keadaan yang berbeda.',
        );
        $this->assertNull($slip->overtime_rate_detail);
    }

    public function test_a_thr_run_says_it_pays_no_overtime(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun(['run_type' => PayrollRunType::Thr]);

        $this->payrollService()->calculate($run);

        $this->assertSame(OvertimeBasis::TanpaLembur, $this->payslipFor($run, $employee)->overtime_basis);
    }

    // ------------------------------------------------------------- maju-saja

    public function test_a_posted_payroll_run_can_never_be_recalculated_by_this_package(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 10);

        $this->payrollService()->calculate($run);
        $before = (float) $this->payslipFor($run, $employee)->overtime_pay;

        $run->forceFill(['status' => DocumentStatus::Approved])->save();

        // Absensi bulan itu dilengkapi SESUDAH payroll disetujui — persis
        // kejadian yang membuat jalur otomatis berbahaya.
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
            $this->overtimeDay($employee, $date, 2);
        }

        try {
            $this->payrollService()->calculate($run->refresh());
            $this->fail('Payroll yang sudah disetujui dihitung ulang. Rincian harian tidak boleh '
                .'pernah menggerakkan uang yang sudah dibukukan dan dibayarkan.');
        } catch (LogicException) {
            // benar — assertEditable menolak
        }

        $this->assertMoney(
            $before,
            $this->payslipFor($run, $employee)->overtime_pay,
            'Slip yang sudah diposting tidak berubah nilainya oleh paket ini.',
        );
        $this->assertSame(OvertimeBasis::RataJamPertama, $this->payslipFor($run, $employee)->overtime_basis);
    }

    public function test_the_frozen_detail_does_not_move_when_the_policy_changes_afterwards(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();
        $this->overtimeDay($employee, '2026-06-01', 2);
        $this->makeRecap($employee, $run, 2);

        $this->payrollService()->calculate($run);
        $slip = $this->payslipFor($run, $employee);
        $paid = (float) $slip->overtime_pay;

        $this->setSetting('hr.timesheet.overtime_next_hours_pct', 300);

        $this->assertMoney($paid, $this->payslipFor($run, $employee)->overtime_pay);
        $this->assertSame(
            200.0,
            (float) $this->payslipFor($run, $employee)->overtime_rate_detail['next_hours_pct'],
            'Tarif yang tersimpan adalah tarif yang BERLAKU SAAT SLIP DIHITUNG — pola stempel yang '
            .'sama dengan geofence F-4 dan ter_rate P0.',
        );
    }

    public function test_the_rates_come_from_the_settings_and_not_from_the_code(): void
    {
        $this->setSetting('hr.timesheet.overtime_first_hour_pct', 200);
        $this->setSetting('hr.timesheet.overtime_next_hours_pct', 300);

        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();
        $this->overtimeDay($employee, '2026-06-01', 2);
        $this->makeRecap($employee, $run, 2);

        $this->payrollService()->calculate($run);

        // (1 x 2,0 + 1 x 3,0) x 63.583,815028901736 = 317.919,08
        $this->assertMoney(317_919.08, $this->payslipFor($run, $employee)->overtime_pay);
    }

    public function test_older_payslips_keep_a_blank_basis_because_nothing_is_backfilled(): void
    {
        $employee = $this->employeeOnElevenMillion();
        $run = $this->makeRun();
        $this->makeRecap($employee, $run, 10);
        $this->payrollService()->calculate($run);

        // Sebuah slip yang lahir sebelum 14 Sep 2026 tidak punya kolom ini.
        $slip = $this->payslipFor($run, $employee);
        $slip->forceFill(['overtime_basis' => null, 'overtime_rate_detail' => null])->save();

        $this->assertNull(
            $slip->refresh()->overtime_basis,
            'Tidak ada backfill dan tidak akan ada: menebak dasar sebuah slip yang sudah diposting '
            .'adalah mengarang bukti tentang uang yang sudah keluar.',
        );
    }
}
