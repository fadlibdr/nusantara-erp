<?php

namespace Tests\Feature\HrPayroll;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\HrPayroll\Enums\PayrollRunType;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\AttendanceRecap;
use Modules\HrPayroll\Models\PayrollRun;
use Tests\ErpTestCase;

/**
 * `overtime_hours` KELUAR DARI `not_proposed` — DENGAN SYARAT (F-5, T5.5).
 *
 * Sampai 13 September 2026 usulan rekap memulangkan:
 *
 *     ['field' => 'overtime_hours', 'label' => 'Jam lembur',
 *      'why' => «Register mencatat kehadiran, bukan jam lembur yang disetujui.»]
 *
 * Kalimat itu BENAR hari itu. F-4 menambahkan cap jam masuk/pulang, F-5
 * menghitungnya, dan sejak itu register BISA menurunkan jam lembur — sehingga
 * kalimat lama menjadi sebuah penolakan yang sebabnya sudah tidak ada.
 *
 * Berkas ini memaku KEDUA arah dari perubahan itu:
 *
 *  - periode yang PUNYA hari terukur mengusulkan jam lembur, dan mengatakan di
 *    kalimat yang sama bahwa ILB tetap otoritatif dan HR yang menyimpan;
 *  - periode yang TIDAK punya satu pun hari bercap jam lengkap tetap MENOLAK
 *    mengusulkannya — dengan kalimat yang benar SEKARANG, bukan kalimat lama.
 *
 * Frasa lama ditulis di dalam «guillemet» pada komentar supaya bisa dibaca
 * manusia tanpa memerahkan uji yang melarangnya.
 */
class AttendanceRecapOvertimeProposalTest extends ErpTestCase
{
    use PayrollFixtures;

    private const OLD_SENTENCE = 'Register mencatat kehadiran, bukan jam lembur yang disetujui.';

    private function actAsAdmin(): User
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Hari kerja dengan dua cap jam; `$extraMinutes` menit di atas 8 jam normal.
     *
     * Pulang dihitung dari 17:00: hari kerja delapan jam berlangsung sembilan
     * jam di jam dinding karena istirahat 60 menit tidak termasuk jam kerja
     * (UU 13/2003 Pasal 79, TimesheetService::breakMinutes).
     */
    private function measuredDay(int $employeeId, string $date, int $extraMinutes = 0): void
    {
        $out = sprintf('%02d:%02d', 17 + intdiv($extraMinutes, 60), $extraMinutes % 60);

        Attendance::query()->create([
            'employee_id' => $employeeId,
            'date' => $date,
            'status' => 'hadir',
            'check_in_at' => "{$date} 08:00:00",
            'check_out_at' => "{$date} {$out}:00",
        ]);
    }

    private function proposal(int $year = 2026, int $month = 6): array
    {
        return $this->getJson("/api/hr/attendance-recaps/proposal?period_year={$year}&period_month={$month}")
            ->assertOk()
            ->json('data');
    }

    public function test_a_period_without_a_single_complete_pair_of_stamps_still_refuses_to_propose_overtime(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        // Kerani menulis kehadiran; tidak ada satu cap jam pun.
        Attendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-06-01', 'status' => 'hadir']);

        $payload = $this->proposal();
        $fields = collect($payload['not_proposed'])->pluck('field')->all();

        $this->assertContains('overtime_hours', $fields);
        $this->assertFalse($payload['overtime']['proposed']);
    }

    public function test_the_refusal_gives_the_reason_that_is_true_now_and_not_the_one_that_used_to_be(): void
    {
        $this->actAsAdmin();
        $this->makeEmployee();

        $entry = collect($this->proposal()['not_proposed'])->firstWhere('field', 'overtime_hours');

        $this->assertNotNull($entry);
        $this->assertStringContainsString(
            'Belum ada satu hari pun dengan cap jam masuk DAN pulang di periode ini',
            $entry['why'],
        );
        $this->assertStringNotContainsString(
            self::OLD_SENTENCE,
            $entry['why'],
            'Kalimat lama berhenti benar pada 14 Sep 2026: register KINI mencatat jam masuk dan '
            .'pulang. Sebuah penolakan yang sebabnya sudah tidak ada adalah penolakan yang tidak '
            .'bisa diperiksa siapa pun.',
        );
    }

    public function test_the_old_sentence_is_gone_from_every_line_that_can_reach_a_screen(): void
    {
        $source = (string) file_get_contents(base_path('Modules/HrPayroll/Services/AttendanceRecapProposalService.php'));

        // Komentar dibuang lebih dulu: kepala kelas MENCERITAKAN pergantian ini
        // dan menyebut kalimat lamanya, dan itu justru yang seharusnya ada di
        // sana. Yang dilarang adalah kalimat itu masih dikirim ke layar.
        $code = (string) preg_replace('#/\\*.*?\\*/#s', '', $source);
        $code = (string) preg_replace('#^\\s*//.*$#m', '', $code);

        $this->assertStringNotContainsString(
            self::OLD_SENTENCE,
            $code,
            'Kalimat lama masih dikirim ke layar. Sejak 14 Sep 2026 ia adalah penolakan yang '
            .'sebabnya sudah tidak ada, dan tidak bisa diperiksa siapa pun.',
        );
    }

    public function test_a_period_with_measured_days_proposes_overtime_and_takes_it_out_of_not_proposed(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();

        $this->measuredDay($employee->id, '2026-06-01', 120); // 2 jam lembur
        $this->measuredDay($employee->id, '2026-06-02', 0);

        $payload = $this->proposal();

        $this->assertTrue($payload['overtime']['proposed']);
        $this->assertNotContains(
            'overtime_hours',
            collect($payload['not_proposed'])->pluck('field')->all(),
            'Sebuah kolom yang BENAR-BENAR diusulkan tidak boleh tetap berdiri di daftar "yang tidak '
            .'diusulkan": layar akan mencetak alasan penolakan di atas angkanya sendiri.',
        );
        $this->assertSame(2.0, (float) $payload['rows'][0]['overtime_hours']);
    }

    public function test_the_proposal_sentence_says_ilb_stays_authoritative_and_hr_saves_it(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();
        $this->measuredDay($employee->id, '2026-06-01', 120);

        $why = $this->proposal()['overtime']['why'];

        $this->assertStringContainsString('USULAN', $why);
        $this->assertStringContainsString('otoritatif', $why);
        $this->assertStringContainsString('rekap tetap Anda', $why);
    }

    public function test_an_employee_with_no_measured_day_of_their_own_gets_null_not_zero(): void
    {
        $this->actAsAdmin();
        $measured = $this->makeEmployee();
        $forgetful = $this->makeEmployee();

        $this->measuredDay($measured->id, '2026-06-01', 120);
        // Lupa absen pulang: setengah terukur, bukan nol jam lembur.
        Attendance::query()->create([
            'employee_id' => $forgetful->id,
            'date' => '2026-06-01',
            'status' => 'hadir',
            'check_in_at' => '2026-06-01 08:00:00',
        ]);

        $rows = collect($this->proposal()['rows'])->keyBy('employee_code');

        $this->assertSame(2.0, (float) $rows[$measured->code]['overtime_hours']);
        $this->assertNull(
            $rows[$forgetful->code]['overtime_hours'],
            'Nol di sini akan tersodor ke formulir rekap sebagai "nol jam lembur yang diputuskan", '
            .'dan orang akan menekan Simpan. Yang benar adalah kosong: harinya belum terukur.',
        );
        $this->assertSame(1, $rows[$forgetful->code]['half_measured_days'], 'Hari yang belum terukur harus bisa dilihat HR.');
    }

    public function test_the_approved_permit_hours_stand_beside_the_derived_ones(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();
        $this->measuredDay($employee->id, '2026-06-01', 120);

        $row = $this->proposal()['rows'][0];

        $this->assertSame(2.0, (float) $row['overtime_hours']);
        $this->assertNull($row['permit_hours'], 'Tidak ada ILB: kosong, bukan 0 — nol jam ILB adalah klaim.');
    }

    /**
     * Layar usulan mengambil keputusannya DARI SERVER, tidak menyusun syaratnya
     * sendiri — dan menyodorkan angka lembur ke formulir hanya ketika ia benar
     * benar ada.
     */
    public function test_the_proposal_screen_reads_the_condition_from_the_server_and_prefills_only_what_exists(): void
    {
        $code = (string) file_get_contents(base_path('public/app/js/views/usulanrekap.js'));
        $code = (string) preg_replace('#/\\*.*?\\*/#s', '', $code);
        $code = (string) preg_replace('#^\\s*//.*$#m', '', $code);

        $this->assertStringContainsString(
            'payload.overtime.why',
            $code,
            'Kalimat syarat lembur harus datang dari server: sebuah kalimat kedua yang disusun layar '
            .'akan menyimpang dari yang pertama pada perubahan berikutnya.',
        );
        $this->assertStringContainsString(
            '...(row.overtime_hours === null ? {} : { overtime_hours: row.overtime_hours })',
            $code,
            'Formulir rekap hanya boleh disodori jam lembur yang BENAR-BENAR diturunkan. Sebuah 0 '
            .'yang disodorkan akan disimpan sebagai nol yang diputuskan.',
        );
    }

    /**
     * LAYAR YANG MENULIS HARUS TAHU PERIODENYA SUDAH DIBAYAR.
     *
     * Rekap bulanan adalah catatan tentang dari apa run yang sudah diposting
     * dihitung — `OvertimeRecapService` dan `LeaveService` MEMBEKUKANNYA bila
     * payroll periodenya sudah diposting, dengan kalimat itu ditulis di
     * keduanya. Layar Timesheet F-5 menanyakannya dan memasang spanduk; layar
     * ini, yang justru menaruh jam lembur ke dalam dokumen itu, tidak
     * menanyakannya sama sekali sampai putaran verifikasi.
     */
    public function test_the_proposal_says_when_the_payroll_of_that_period_is_already_posted(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();
        $this->measuredDay($employee->id, '2026-06-01', 120);

        $this->assertFalse(
            $this->proposal()['period']['payroll_posted'],
            'Prasyarat: belum ada run yang diposting untuk periode ini.',
        );

        PayrollRun::query()->create([
            'code' => 'PYR/TEST/POSTED',
            'period_year' => 2026,
            'period_month' => 6,
            'run_type' => PayrollRunType::Regular,
            'payment_date' => '2026-06-25',
            'status' => DocumentStatus::Closed,
        ]);

        $this->assertTrue(
            $this->proposal()['period']['payroll_posted'],
            'Periode yang payroll-nya sudah ditutup harus MENGATAKANNYA di muatan usulan. Tanpa itu, '
            .'HR membuat dokumen bukti sesudah uangnya keluar tanpa satu peringatan pun — dan '
            .'kalimat `overtime.why` di layar yang sama justru mengundangnya menyimpan.',
        );
    }

    /**
     * Peringatan hari setengah terukur DI LUAR cabang null, dan ketiadaan ILB
     * DIGAMBAR — dua kalimat yang layar ini sembunyikan tepat ketika ada angka
     * untuk disimpan.
     */
    public function test_the_proposal_screen_warns_about_half_measured_days_and_missing_permits_beside_the_number(): void
    {
        $code = (string) file_get_contents(base_path('public/app/js/views/usulanrekap.js'));

        $this->assertStringContainsString('usulan-posted', $code, 'Spanduk payroll terposting tidak ada.');
        $this->assertStringContainsString('usulan-half', $code);
        $this->assertStringContainsString('tanpa ILB disetujui', $code);
        $this->assertStringContainsString(
            'disetujui siapa pun',
            $code,
            'Baris tanpa ILB harus mengatakan apa artinya menyimpannya. Pita di atas tabel berjanji '
            .'"ILB tetap otoritatif"; sebuah sel yang diam tentang ketiadaan ILB membuat janji itu '
            .'tidak bisa diperiksa di baris mana pun.',
        );

        /*
         * ...dan peringatan setengah terukur tidak boleh kembali masuk ke
         * cabang `overtime_hours === null`. Di sana ia hanya muncul ketika
         * TIDAK ADA angka — yaitu tepat ketika tidak ada yang bisa disimpan.
         */
        $cell = (int) strpos($code, "el('td.right.usulan-ot'");
        $this->assertGreaterThan(0, $cell, 'Sel lembur tidak ditemukan — penjaga di bawah tidak menjaga apa pun.');
        $this->assertStringNotContainsString(
            'usulan-half',
            substr($code, $cell, 900),
            'Peringatan hari setengah terukur kembali masuk ke dalam sel lembur, tempat ia hanya '
            .'muncul ketika TIDAK ADA angka — yaitu tepat ketika tidak ada yang bisa disimpan.',
        );
    }

    public function test_asking_for_the_proposal_writes_absolutely_nothing(): void
    {
        $this->actAsAdmin();
        $employee = $this->makeEmployee();
        $this->measuredDay($employee->id, '2026-06-01', 120);

        $this->proposal();

        $this->assertSame(
            0,
            AttendanceRecap::query()->count(),
            'Usulan tidak pernah menulis sendiri ke rekap — HR yang menerapkan, seperti F-4.',
        );
    }
}
