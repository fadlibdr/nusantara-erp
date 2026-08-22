<?php

namespace Tests\Feature\HrPayroll;

use Illuminate\Support\Carbon;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Models\Payslip;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Formulir rumah untuk modul SDM — rekap gaji, pengajuan cuti, daftar hadir.
 *
 * THE POSTURE THIS MODULE ALREADY HAS AND THESE SHEETS INHERIT. hr_employees
 * carries NIK KTP, NPWP and a bank account number; the routes gate even the
 * GETs of the certificate and cuti registers on hr.view because those rows
 * pair a name with personal data. A printed sheet is the one artefact that
 * leaves the permission system entirely — it gets photocopied, left on a desk
 * and filed — so these three print the least that still makes them useful:
 * name, employee code, position, and the money or the days the sheet is about.
 * No KTP number, no NPWP, no bank account, on any of them. The per-employee
 * slip gaji that DOES carry those already exists as a dompdf document, is
 * printed one employee at a time, and is not duplicated here.
 *
 * THE CELLS WORTH TESTING:
 *
 *   SISA SALDO on a pengajuan cuti. It exists only for cuti tahunan —
 *   LeaveService computes it from join_date and the approved rows, and it is
 *   undefined for sakit, izin and cuti khusus. A "sisa 7 hari" printed on a
 *   sick note would be a balance nobody keeps.
 *
 *   TANDA TANGAN on a daftar hadir. Every one of those cells is ruled: the
 *   whole reason the sheet is printed is to collect wet signatures, and a
 *   column that filled itself would defeat the document.
 *
 *   TANGGAL PEMBAYARAN on a rekap gaji. hr_payroll_runs.payment_date is
 *   nullable until the run is scheduled, and a draft rekap must not carry a
 *   pay date somebody invented for it.
 */
class HrFormPrintTest extends ErpTestCase
{
    private const TODAY = '2026-08-09';

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------- fixtures

    private function employee(array $attributes = []): Employee
    {
        return Employee::query()->create(array_merge([
            'code' => 'EMP-0007',
            'name' => 'Bambang Sutrisno',
            'nik_ktp' => '3175012509880003',
            'npwp' => '09.876.543.2-098.000',
            'gender' => 'male',
            'birth_date' => '1988-09-25',
            'ptkp_status' => 'K/2',
            'join_date' => '2021-03-01',
            'employment_type' => 'tetap',
            'position' => 'Pelaksana Lapangan',
            'department' => 'Operasional Proyek',
            'base_salary' => 9_500_000,
            'bank_name' => 'Bank Mandiri',
            'bank_account_no' => '1230004567890',
            'bank_account_name' => 'Bambang Sutrisno',
            'status' => 'active',
        ], $attributes));
    }

    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ/2026/0004',
            'name' => 'Pembangunan Gudang Logistik Cakung',
            'type' => 'construction',
            'location' => 'Kawasan Industri Pulogadung',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
            'start_date' => '2026-04-01',
            'end_date' => '2026-11-30',
            'contract_value' => 4_500_000_000,
            'status' => 'active',
        ]);
    }

    /**
     * A regular August run with two payslips whose columns add up by hand:
     * 9.500.000 + 1.750.000 + 620.000 = 11.870.000 bruto, less 1.104.000 =
     * 10.766.000 netto.
     */
    private function payrollRun(array $attributes = []): PayrollRun
    {
        $run = PayrollRun::query()->create(array_merge([
            'code' => 'PYR/2026/VIII/0001',
            'period_year' => 2026,
            'period_month' => 7,
            'run_type' => 'regular',
            'payment_date' => '2026-08-01',
            'total_gross' => 20_120_000,
            'total_deductions' => 1_856_000,
            'total_net' => 18_264_000,
            'status' => 'approved',
        ], $attributes));

        Payslip::query()->create([
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee()->id,
            'basic_salary' => 9_500_000,
            'allowances_total' => 1_750_000,
            'overtime_hours' => 8,
            'overtime_pay' => 620_000,
            'gross_income' => 11_870_000,
            'bpjs_employee_total' => 474_800,
            'bpjs_company_total' => 712_200,
            'pph21_amount' => 629_200,
            'total_deductions' => 1_104_000,
            'net_pay' => 10_766_000,
        ]);

        Payslip::query()->create([
            'payroll_run_id' => $run->id,
            'employee_id' => $this->employee([
                'code' => 'EMP-0011',
                'name' => 'Siti Nurhaliza',
                'nik_ktp' => '3175014403910007',
                'npwp' => null,
                'gender' => 'female',
                'birth_date' => '1991-03-04',
                'position' => 'Staf Administrasi Proyek',
                'base_salary' => 6_800_000,
                'bank_account_no' => '1230009876543',
                'bank_account_name' => 'Siti Nurhaliza',
            ])->id,
            'basic_salary' => 6_800_000,
            'allowances_total' => 1_200_000,
            'overtime_hours' => 0,
            'overtime_pay' => 250_000,
            'gross_income' => 8_250_000,
            'bpjs_employee_total' => 330_000,
            'bpjs_company_total' => 495_000,
            'pph21_amount' => 422_000,
            'total_deductions' => 752_000,
            'net_pay' => 7_498_000,
        ]);

        return $run->fresh();
    }

    private function leaveRequest(array $attributes = []): LeaveRequest
    {
        return LeaveRequest::query()->create(array_merge([
            'code' => 'CTI/2026/VIII/0003',
            'employee_id' => $this->employee()->id,
            'leave_type' => 'tahunan',
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-21',
            'day_count' => 5,
            'reason' => 'Mengantar orang tua berobat ke Surabaya',
            'status' => 'approved',
        ], $attributes));
    }

    /** One site sheet: two workers on the same project on the same day. */
    private function attendance(): Attendance
    {
        $project = $this->project();

        $first = Attendance::query()->create([
            'employee_id' => $this->employee()->id,
            'date' => '2026-08-06',
            'status' => 'hadir',
            'project_id' => $project->id,
        ]);

        Attendance::query()->create([
            'employee_id' => $this->employee([
                'code' => 'EMP-0011',
                'name' => 'Siti Nurhaliza',
                'nik_ktp' => '3175014403910007',
                'position' => 'Staf Administrasi Proyek',
            ])->id,
            'date' => '2026-08-06',
            'status' => 'absen',
            'project_id' => $project->id,
            'note' => 'Surat dokter diserahkan ke HR',
        ]);

        // Another day on the same project: the sheet is one DATE, not a month.
        Attendance::query()->create([
            'employee_id' => $this->employee([
                'code' => 'EMP-0019',
                'name' => 'Joko Prasetyo',
                'nik_ktp' => '3175010101900001',
                'position' => 'Tukang Besi',
            ])->id,
            'date' => '2026-08-07',
            'status' => 'hadir',
            'project_id' => $project->id,
        ]);

        return $first->fresh();
    }

    // ---------------------------------------------------------- rekap gaji

    public function test_the_payroll_recap_prints_a_row_per_employee_and_the_run_totals(): void
    {
        $html = $this->forms->html('rekap-payroll', ['id' => $this->payrollRun()->id]);

        $this->assertStringContainsString('REKAPITULASI GAJI', $html);
        $this->assertStringContainsString('Form F/RG', $html);
        $this->assertStringContainsString('PYR/2026/VIII/0001', $html);
        $this->assertStringContainsString('Juli 2026', $html);
        $this->assertStringContainsString('Bambang Sutrisno', $html);
        $this->assertStringContainsString('Pelaksana Lapangan', $html);
        $this->assertStringContainsString('11.870.000,00', $html);
        $this->assertStringContainsString('10.766.000,00', $html);
        $this->assertStringContainsString('Siti Nurhaliza', $html);
        // The run's own stored totals, not a second sum taken at print time.
        $this->assertStringContainsString('18.264.000,00', $html);
        $this->assertStringContainsString('Delapan belas juta dua ratus enam puluh empat ribu rupiah', $html);
    }

    /**
     * The sheet leaves the permission system the moment it is printed. What it
     * may carry is name, code, position and money — never the identity numbers
     * that turn a rekap into a data leak on a photocopier.
     */
    public function test_the_payroll_recap_never_prints_identity_or_bank_numbers(): void
    {
        $html = $this->forms->html('rekap-payroll', ['id' => $this->payrollRun()->id]);

        $this->assertStringNotContainsString('3175012509880003', $html);
        $this->assertStringNotContainsString('09.876.543.2-098.000', $html);
        $this->assertStringNotContainsString('1230004567890', $html);
    }

    /**
     * A worker who has LEFT keeps his name beside the money the company paid
     * him.
     *
     * This is the worst case of the whole registry: an employee is soft-deleted
     * the day he leaves, and the run that paid him stays in the database for
     * ever — payroll records outlive employment by law. Loaded plainly the
     * relation came back null and the rekap ruled NIK, NAMA and JABATAN beside
     * a real 10.766.000,00, on the sheet a director signs to release the
     * transfer. Nothing was fabricated; a payment was made to a name the sheet
     * would not print.
     */
    public function test_a_departed_employee_keeps_his_name_beside_his_netto(): void
    {
        $run = $this->payrollRun();

        Employee::query()->where('code', 'EMP-0007')->firstOrFail()->delete();

        $html = $this->forms->html('rekap-payroll', ['id' => $run->id]);

        $this->assertStringContainsString('Bambang Sutrisno', $html);
        // The two columns that come off the relation, beside the netto that
        // does not. NAMA KARYAWAN and JABATAN are all this sheet carries of a
        // person — the employee code is not on the rekap at all.
        $this->assertStringContainsString('Pelaksana Lapangan', $html);
        $this->assertStringContainsString('10.766.000,00', $html);
    }

    /**
     * A draft run has no pay date, and the sheet says so with a rule.
     *
     * On the CELL. This sheet carries a rule wherever the run has nothing —
     * the notes block alone rules two lines when hr_payroll_runs.notes is null
     * — so assertStringContainsString('fill-line') was true of every rekap
     * ever printed and stayed true with the line defaulted to now(): a draft
     * rekap stating it was paid today, over the director's signature rule.
     */
    public function test_a_run_without_a_payment_date_rules_that_line(): void
    {
        $html = $this->forms->html('rekap-payroll', [
            'id' => $this->payrollRun(['payment_date' => null, 'status' => 'draft'])->id,
        ]);

        $this->assertMatchesRegularExpression($this->ruledIdentityCell('TANGGAL PEMBAYARAN'), $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /** And a scheduled run states the day it is paid, on that same line. */
    public function test_a_scheduled_run_prints_its_payment_date(): void
    {
        $html = $this->forms->html('rekap-payroll', ['id' => $this->payrollRun()->id]);

        $this->assertMatchesRegularExpression(
            $this->identityCell('TANGGAL PEMBAYARAN', '01 Agustus 2026'),
            $html,
        );
    }

    /**
     * total_net is printed THREE times on this sheet and no two of those cells
     * may claim to be the same check of it.
     *
     * Two of them carried the word-for-word caption "JUMLAH NETTO DIBAYARKAN":
     * one footing the column of employee nettos, one in the REKAPITULASI
     * block. Two identical captions over one figure read as two independent
     * verifications — a reader who finds them agreeing has learnt nothing, and
     * had they ever disagreed nobody could say which was wrong. Each caption
     * now states what its OWN cell counted.
     *
     * Counted, not merely found: a test asserting the presence of
     * 'JUMLAH NETTO DIBAYARKAN' passes in both worlds, which is the trap.
     */
    public function test_no_two_cells_on_the_recap_carry_the_same_caption_for_one_total(): void
    {
        $html = $this->forms->html('rekap-payroll', ['id' => $this->payrollRun()->id]);

        $this->assertSame(1, substr_count($html, 'JUMLAH NETTO SELURUH KARYAWAN'));
        $this->assertSame(1, substr_count($html, 'JUMLAH NETTO DIBAYARKAN (bruto − potongan)'));
        // The bare caption, i.e. one that ends where its cell does.
        $this->assertSame(0, substr_count($html, 'JUMLAH NETTO DIBAYARKAN<'));
        // All three cells still state the run's own stored total.
        $this->assertSame(3, substr_count($html, '18.264.000,00'));
    }

    // -------------------------------------------------------- pengajuan cuti

    public function test_the_leave_form_prints_the_request_and_the_annual_balance(): void
    {
        $html = $this->forms->html('pengajuan-cuti', ['id' => $this->leaveRequest()->id]);

        $this->assertStringContainsString('PENGAJUAN CUTI / IZIN', $html);
        $this->assertStringContainsString('CTI/2026/VIII/0003', $html);
        $this->assertStringContainsString('Bambang Sutrisno', $html);
        $this->assertStringContainsString('EMP-0007', $html);
        $this->assertStringContainsString('Cuti Tahunan', $html);
        $this->assertStringContainsString('17 Agustus 2026', $html);
        $this->assertStringContainsString('21 Agustus 2026', $html);
        $this->assertStringContainsString('Mengantar orang tua berobat ke Surabaya', $html);
        // The saldo block: entitlement, taken, remaining — computed by
        // LeaveService, never a second arithmetic written for the sheet.
        $this->assertStringContainsString('Hak cuti tahunan periode berjalan', $html);
        $this->assertStringContainsString('Sisa saldo', $html);
        // Pemohon is a rule with a name because employee_id really records who
        // is asking; the other two are left for the pen.
        $this->assertStringContainsString('Pemohon', $html);
        $this->assertStringContainsString('Atasan Langsung', $html);
    }

    /**
     * The balance exists for cuti tahunan and nowhere else. Printing "sisa 7
     * hari" on a sick note asserts a quota that neither the statute nor this
     * ERP keeps.
     */
    public function test_a_sick_note_carries_no_annual_balance(): void
    {
        $html = $this->forms->html('pengajuan-cuti', [
            'id' => $this->leaveRequest(['leave_type' => 'sakit', 'code' => 'CTI/2026/VIII/0004'])->id,
        ]);

        $this->assertStringContainsString('Sakit', $html);
        $this->assertStringContainsString(
            'Saldo cuti tahunan tidak berlaku untuk jenis pengajuan ini.',
            $html,
        );
        $this->assertStringNotContainsString('Sisa saldo', $html);
    }

    // --------------------------------------------------------- daftar hadir

    public function test_the_attendance_sheet_is_one_project_on_one_day(): void
    {
        $html = $this->forms->html('daftar-hadir', ['id' => $this->attendance()->id]);

        $this->assertStringContainsString('DAFTAR HADIR HARIAN', $html);
        $this->assertStringContainsString('Form F/DH', $html);
        $this->assertStringContainsString('Pembangunan Gudang Logistik Cakung', $html);
        $this->assertStringContainsString('06 Agustus 2026', $html);
        $this->assertStringContainsString('Bambang Sutrisno', $html);
        $this->assertStringContainsString('Siti Nurhaliza', $html);
        $this->assertStringContainsString('Surat dokter diserahkan ke HR', $html);
        // The 7th is a different sheet — one date, one register.
        $this->assertStringNotContainsString('Joko Prasetyo', $html);
    }

    /**
     * The columns the sheet is printed FOR. Jam masuk, jam keluar and the
     * signature are ruled on every row, including the rows the ERP filled in:
     * nothing anywhere records a clock time, and the signature is the wet ink
     * this whole document exists to collect.
     *
     * On the CELLS of a row the ERP DID fill in. This register pads itself to
     * twenty rows, so seventeen rows of nothing but rules sit under the real
     * ones and `str_contains($html, 'class="fill"')` cannot fail — it stayed
     * green with the TANDA TANGAN column given a value, which is a daftar
     * hadir that signs itself.
     */
    public function test_the_attendance_sheet_rules_the_signature_and_clock_columns(): void
    {
        $html = $this->forms->html('daftar-hadir', ['id' => $this->attendance()->id]);

        $this->assertStringContainsString('TANDA TANGAN', $html);
        $this->assertStringContainsString('JAM MASUK', $html);
        $this->assertStringContainsString('JAM KELUAR', $html);

        $cells = $this->bodyRowCells($html, 'Bambang Sutrisno');

        // What the ERP knows about this worker's day, beside the four cells it
        // does not: jam masuk, jam keluar and the signature.
        $this->assertSame('Hadir', $cells[4]);
        $this->assertSame('<div class="fill"></div>', $cells[5]);
        $this->assertSame('<div class="fill"></div>', $cells[6]);
        $this->assertSame('<div class="fill"></div>', $cells[8]);
        $this->assertStringNotContainsString('00:00', $html);
    }

    /**
     * The column heading is NIK PEGAWAI, never a bare NIK.
     *
     * This column carries hr_employees.code (EMP-0007) and the sheet leaves
     * the permission system the moment it is printed. A signature list that
     * circulates by photocopy under a column headed "NIK" invites its reader
     * to treat the payroll code as the identity number on the worker's KTP —
     * the one thing every HR sheet in this registry keeps off the paper.
     */
    public function test_the_attendance_sheet_heads_the_code_column_unambiguously(): void
    {
        $html = $this->forms->html('daftar-hadir', ['id' => $this->attendance()->id]);

        $this->assertStringContainsString('NIK PEGAWAI', $html);
        $this->assertStringNotContainsString('>NIK<', $html);
        // And the number it must never be, which this fixture does store.
        $this->assertStringNotContainsString('3175012509880003', $html);
    }

    /**
     * A worker who has since left keeps his name on the day he was on site.
     *
     * hr_employees soft-deletes the day somebody leaves; the attendance row
     * stays for ever. Loaded plainly, HrFormService::attendanceRegister came
     * back with a null relation and the register printed "Hadir" against
     * ruled NIK, NAMA and JABATAN — a signed day's attendance for a person
     * the sheet cannot name, on the paper the site pays daily wages from.
     */
    public function test_a_departed_worker_still_has_a_named_row_on_the_register(): void
    {
        $anchor = $this->attendance();

        Employee::query()->where('code', 'EMP-0007')->firstOrFail()->delete();

        $cells = $this->bodyRowCells(
            $this->forms->html('daftar-hadir', ['id' => $anchor->id]),
            'Bambang Sutrisno',
        );

        $this->assertSame('EMP-0007', $cells[1]);
        $this->assertSame('Bambang Sutrisno', $cells[2]);
        $this->assertSame('Pelaksana Lapangan', $cells[3]);
        $this->assertSame('Hadir', $cells[4]);
    }

    /**
     * The department is spelled the way the application spells it.
     *
     * hr_employees.department is a plain string column with no cast, so the
     * form printed the stored slug: "DEPARTEMEN : hrga" on a sheet the
     * employee, the supervisor and HR all sign, while every screen the request
     * was raised on says "HR & GA". Department::labelFor closes it.
     */
    public function test_the_leave_form_spells_the_department_the_way_the_screens_do(): void
    {
        $request = $this->leaveRequest();
        $request->employee->forceFill(['department' => 'hrga'])->save();

        $html = $this->forms->html('pengajuan-cuti', ['id' => $request->id]);

        $this->assertStringContainsString('HR &amp; GA', $html);
        $this->assertStringNotContainsString('>hrga<', $html);
    }

    /**
     * And the cuti form of a worker who has since left still names him.
     *
     * The band of this sheet IS the employee — header kind 'employee' — so
     * losing the relation empties the letterhead as well as the identity
     * block: a leave request signed by three people, about nobody.
     */
    public function test_a_departed_employees_leave_form_still_names_him(): void
    {
        $request = $this->leaveRequest();

        $request->employee->delete();

        $html = $this->forms->html('pengajuan-cuti', ['id' => $request->id]);

        $this->assertStringContainsString('Bambang Sutrisno', $html);
        $this->assertStringContainsString('EMP-0007', $html);
    }

    /**
     * A value outside the six prints AS ITSELF.
     *
     * Not blank, which would hide something the database holds, and never
     * mapped onto the nearest known department — a signed form carrying
     * another department's name is worse than one carrying an unfamiliar
     * spelling. Rows predating the Rule::in are exactly this case.
     *
     * THE SECOND HALF IS WHAT MAKES THE FIRST MEAN ANYTHING. Printed straight
     * off the column an unknown slug comes out verbatim too, so the verbatim
     * assertion alone held with Department::labelFor removed — it could not
     * tell the enum from the raw column, which is the whole question. The same
     * sheet is therefore rendered for a slug the enum DOES know, where the two
     * differ, and both are anchored on the DEPARTEMEN cell rather than on the
     * sheet: a name appearing somewhere on the paper is not the same claim as
     * a name appearing on that line.
     */
    public function test_an_unrecognised_department_prints_verbatim(): void
    {
        $request = $this->leaveRequest();
        $request->employee->forceFill(['department' => 'Divisi Lama'])->save();

        $html = $this->forms->html('pengajuan-cuti', ['id' => $request->id]);

        $this->assertMatchesRegularExpression($this->identityCell('DEPARTEMEN', 'Divisi Lama'), $html);

        $request->employee->forceFill(['department' => 'servis'])->save();

        $mapped = $this->forms->html('pengajuan-cuti', ['id' => $request->id]);

        $this->assertMatchesRegularExpression($this->identityCell('DEPARTEMEN', 'Servis'), $mapped);
    }

    /**
     * One identity ROW — the label and the value together, in the markup that
     * puts them in the same row of the block. Either half on its own is on
     * every copy of the sheet.
     */
    private function identityCell(string $label, string $value): string
    {
        return '~>'.preg_quote($label, '~').'</td>\s*<td class="s">:</td>\s*<td class="v">\s*'
            .preg_quote($value, '~').'\s*</td>~';
    }

    /** The same row with a RULED BLANK where the value would be. */
    private function ruledIdentityCell(string $label): string
    {
        return $this->identityCell($label, '<span class="fill-line"></span>');
    }

    /**
     * The cells of the single body row that names $needle, in column order.
     *
     * Every assertion in this file about a RULED body cell goes through here.
     * The daftar hadir pads itself to twenty rows and the rekap rules whatever
     * the run has not answered, so "a rule appears on the sheet" is true of
     * every copy of both — which is how a signature column that filled itself
     * went unnoticed.
     *
     * @return list<string>
     */
    private function bodyRowCells(string $html, string $needle): array
    {
        $rows = array_values(array_filter(
            preg_split('~(?=<tr\b)~', $html) ?: [],
            fn (string $row): bool => str_contains($row, $needle) && str_contains($row, '</tr>'),
        ));

        $this->assertCount(1, $rows, "expected exactly one printed row naming {$needle}");

        preg_match_all('~<td\b[^>]*>(.*?)</td>~s', $rows[0], $matches);

        return array_map(trim(...), $matches[1]);
    }
}
