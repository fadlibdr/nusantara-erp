<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Services\TaxEqualizationService;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Ekualisasi PPh 21 — beban gaji/upah menurut buku vs bruto SPT Masa.
 *
 * The book side is what PayrollPostingService actually debits: 6-1100 for
 * office gross AND 5-1200 for project gross — a sheet reading 6-1100 alone
 * would "lose" every site worker. 6-1200 (employer BPJS) is printed but kept
 * OUT of the arithmetic, because PayrollService computes the PPh 21 base on
 * the cash gross only (its own documented simplification) — so none of
 * 6-1200 is in bruto SPT, and pretending part of it is would manufacture a
 * difference the SPT cannot show. THR rides inside gross_income (verified by
 * the fixture), so it is disclosed as part of the bruto, never added on top.
 */
class TaxEqualizationPph21Test extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->seedLedger(2026);
    }

    private function sheet(int $year): array
    {
        return app(TaxEqualizationService::class)->pph21($year);
    }

    /** @return array<string, mixed> */
    private function row(array $sheet, string $labelPrefix): array
    {
        foreach ($sheet['rows'] as $row) {
            if (str_starts_with($row['label'], $labelPrefix)) {
                return $row;
            }
        }

        $this->fail("Worksheet has no row starting with [{$labelPrefix}].");
    }

    private function employeeId(string $code): int
    {
        return (int) DB::table('hr_employees')->insertGetId([
            'code' => $code,
            'name' => 'Karyawan '.$code,
            'nik_ktp' => str_pad((string) crc32($code), 16, '9', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2024-01-01',
            'employment_type' => 'tetap',
            'position' => 'Staf',
            'department' => 'proyek',
            'base_salary' => 10000000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payrollRunId(int $month, string $status, string $code, float $gross, string $runType = 'regular'): int
    {
        return (int) DB::table('hr_payroll_runs')->insertGetId([
            'code' => $code,
            'period_year' => 2026,
            'period_month' => $month,
            'run_type' => $runType,
            'payment_date' => sprintf('2026-%02d-25', $month),
            'total_gross' => $gross,
            'total_deductions' => 0,
            'total_net' => $gross,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payslip(int $runId, int $employeeId, float $gross, float $thr, float $companyBpjs, float $employeeBpjs, float $pph21, ?int $projectId = null): void
    {
        DB::table('hr_payslips')->insert([
            'payroll_run_id' => $runId,
            'employee_id' => $employeeId,
            'basic_salary' => $gross - $thr,
            'allowances_total' => 0,
            'overtime_hours' => 0,
            'overtime_pay' => 0,
            'thr_amount' => $thr,
            'gross_income' => $gross,
            'bpjs_employee_total' => $employeeBpjs,
            'bpjs_company_total' => $companyBpjs,
            'pph21_amount' => $pph21,
            'total_deductions' => $employeeBpjs + $pph21,
            'net_pay' => $gross - $employeeBpjs - $pph21,
            'project_id' => $projectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payroll_thr_and_manual_journals_reconcile_to_a_zero_residual(): void
    {
        $project = $this->makeProject();
        $office = $this->employeeId('EMP-T001');
        $site = $this->employeeId('EMP-T002');

        // Run Mei (regular, approved): kantor 100jt + proyek 60jt bruto.
        // Jurnal cermin PayrollPostingService — Dr 5-1200 proyek, Dr 6-1100
        // kantor, Dr 6-1200 iuran perusahaan, kredit kewajiban.
        $may = $this->payrollRunId(5, 'approved', 'PYR/2026/05/0001', 160000000);
        $this->payslip($may, $office, 100000000, 0, 8000000, 3000000, 5000000);
        $this->payslip($may, $site, 60000000, 0, 4000000, 2000000, 2500000, $project->id);
        $this->journals()->autoPost('payroll_run', $may, [
            ['account_code' => '5-1200', 'debit' => 60000000, 'credit' => 0, 'project_id' => $project->id],
            ['account_code' => '6-1100', 'debit' => 100000000, 'credit' => 0],
            ['account_code' => '6-1200', 'debit' => 12000000, 'credit' => 0],
            ['account_code' => '2-1210', 'debit' => 0, 'credit' => 7500000],
            ['account_code' => '2-1120', 'debit' => 0, 'credit' => 17000000],
            ['account_code' => '2-1110', 'debit' => 0, 'credit' => 147500000],
        ], '2026-05-31', 'Payroll 05/2026');

        // Run THR Juni (approved): 25jt, seluruhnya thr_amount DAN
        // gross_income — the very fact the sheet discloses.
        $thr = $this->payrollRunId(6, 'approved', 'PYR/2026/06/0001', 25000000, 'thr');
        $this->payslip($thr, $office, 25000000, 25000000, 0, 0, 1250000);
        $this->journals()->autoPost('payroll_run', $thr, [
            ['account_code' => '6-1100', 'debit' => 25000000, 'credit' => 0],
            ['account_code' => '2-1210', 'debit' => 0, 'credit' => 1250000],
            ['account_code' => '2-1110', 'debit' => 0, 'credit' => 23750000],
        ], '2026-06-30', 'Payroll THR 06/2026');

        // Honor lepas 7jt via JV manual (bukan payroll), lalu koreksi -4jt:
        // the non-payroll row must carry the NET 3jt, sign included.
        $this->postJournal([
            ['6-1100', 7000000, 0],
            ['2-1110', 0, 7000000],
        ], '2026-07-10', 'Honor narasumber (JV manual)');
        $this->postJournal([
            ['1-1210', 4000000, 0],
            ['6-1100', 0, 4000000],
        ], '2026-07-20', 'Koreksi honor (JV manual)');

        // Run Agustus masih draft: di buku belum, di SPT belum — warning row.
        $this->payrollRunId(8, 'draft', 'PYR/2026/08/0001', 30000000);

        $sheet = $this->sheet(2026);

        // 6-1100 = 100 + 25 + 7 - 4 = 128jt; 5-1200 = 60jt.
        $this->assertEqualsWithDelta(128000000.0, $this->row($sheet, 'Beban gaji & tunjangan menurut buku')['buku'], 0.01);
        $this->assertEqualsWithDelta(60000000.0, $this->row($sheet, 'Beban upah proyek menurut buku')['buku'], 0.01);

        // Bruto SPT = 100 + 60 + 25 = 185jt (run disetujui saja).
        $this->assertEqualsWithDelta(185000000.0, $this->row($sheet, 'Bruto SPT Masa PPh 21')['spt'], 0.01);

        // Di luar payroll: 7 - 4 = 3jt, netto dengan tandanya.
        $this->assertEqualsWithDelta(3000000.0, $this->row($sheet, 'Beban gaji/upah dibukukan di luar modul payroll')['selisih'], 0.01);

        // 128 + 60 - 185 - 3 = 0.
        $this->assertEqualsWithDelta(0.0, $sheet['residual']['amount'], 0.01);

        // THR is disclosed as part of the bruto, and BPJS as outside the base.
        $thrRow = $this->row($sheet, 'THR di dalam bruto SPT');
        $this->assertSame('info', $thrRow['kind']);
        $this->assertEqualsWithDelta(25000000.0, $thrRow['spt'], 0.01);

        $bpjsRow = $this->row($sheet, 'Iuran BPJS perusahaan');
        $this->assertSame('info', $bpjsRow['kind']);
        $this->assertEqualsWithDelta(12000000.0, $bpjsRow['buku'], 0.01);

        // The August draft is neither side yet — said out loud.
        $draftRow = $this->row($sheet, 'Run payroll belum disetujui');
        $this->assertSame('warning', $draftRow['kind']);
        $this->assertEqualsWithDelta(30000000.0, $draftRow['spt'], 0.01);
    }

    /**
     * The live demo's own defect, pinned so it can never be papered over: a
     * run approved BEFORE PayrollPostingService existed has bruto in the SPT
     * side and NOTHING in the books. That difference is REAL — the books
     * genuinely understate wages — so it must stay in the residual, loud,
     * with a warning naming the cause; a derived row that netted it away
     * would print "all reconciled" over broken books.
     */
    public function test_an_approved_run_without_a_journal_keeps_the_residual_loud(): void
    {
        $employee = $this->employeeId('EMP-T003');
        $run = $this->payrollRunId(4, 'approved', 'PYR/2026/04/0001', 90000000);
        $this->payslip($run, $employee, 90000000, 0, 7000000, 2500000, 4000000);
        // Deliberately NO journal.

        $sheet = $this->sheet(2026);

        $this->assertEqualsWithDelta(90000000.0, $this->row($sheet, 'Bruto SPT Masa PPh 21')['spt'], 0.01);
        $this->assertEqualsWithDelta(-90000000.0, $sheet['residual']['amount'], 0.01);
        $this->assertTrue(
            collect($sheet['warnings'])->contains(
                fn (string $warning): bool => str_contains($warning, 'PYR/2026/04/0001') && str_contains($warning, 'tanpa jurnal')
            ),
            'The sheet must name the approved run whose journal is missing.'
        );
    }

    public function test_an_empty_year_says_so(): void
    {
        $sheet = $this->sheet(2030);

        $this->assertSame([], $sheet['rows']);
        $this->assertNull($sheet['residual']);
        $this->assertTrue(
            collect($sheet['warnings'])->contains(fn (string $warning): bool => str_contains($warning, 'Tidak ada')),
        );
    }
}
