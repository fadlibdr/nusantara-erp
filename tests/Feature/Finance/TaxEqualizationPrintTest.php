<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Finance\Models\TaxObligation;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Formulir EKUALISASI PAJAK (Form F/EQ) — the working papers on paper.
 *
 * The sheet anchors on ONE fin_tax_obligations masa row exactly as the
 * kewajiban-pajak register does: the server reads the YEAR off that row and
 * prints all four worksheets of it, every cell resolved through
 * TaxEqualizationService — the registry entry does no arithmetic of its own.
 *
 * What is worth pinning here is the honesty of the PAPER, because a printed
 * ekualisasi is the copy a pemeriksa pajak takes away:
 *
 *   - the RESIDUAL VALUE, not just its label. A registry entry that printed
 *     the label over a ruled blank would invite the residual to be written in
 *     by hand — on the one line whose whole value is that the machine computed
 *     it. Zero prints as 0,00 (tested, not blank) and a nonzero prints with
 *     its sign, because a residual with a flipped sign tells the pemeriksa the
 *     books understate when they overstate.
 *
 *   - landscape via the body class, the one idiom that can actually fail: the
 *     orientation key feeds `<body class="landscape">` and nothing else, and a
 *     portrait fallback would wrap four money columns of long Indonesian
 *     labels into an unreadable sheet without any error.
 *
 *   - an EMPTY YEAR says "Tidak ada …" instead of rendering rows of zeros. A
 *     table of 0,00 over a year with no data reads as "nothing to reconcile",
 *     which is a claim; "tidak ada data" is a fact.
 */
class TaxEqualizationPrintTest extends ErpTestCase
{
    use FinanceFixtures;

    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->seedLedger(2026);
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

    /**
     * Any single masa row of the year is a valid anchor — the sheet prints the
     * whole YEAR regardless of which row the button was pressed on.
     */
    private function anchor(int $year): TaxObligation
    {
        return TaxObligation::query()->create([
            'tax_type' => 'ppn',
            'masa_year' => $year,
            'masa_month' => 6,
            'name' => "PPN Masa Juni {$year}",
            'due_date' => sprintf('%04d-07-31', $year),
        ]);
    }

    public function test_the_sheet_is_landscape_and_prints_all_four_worksheets_from_one_anchor_row(): void
    {
        // Maintenance revenue: invoiced directly on 4-1300 (billing basis, no
        // POC run) so both sides of PPN keluaran carry the same 40jt and the
        // residual is a REAL computed zero.
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, [
            'scope_type' => 'maintenance',
            'value' => 480000000,
            'title' => 'Pemeliharaan CCTV',
        ]);
        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-07-01',
            'description' => 'Pemeliharaan triwulan III',
            'dpp' => 40000000,
            'ppn_rate' => 11.0,
        ]));

        $html = $this->forms->html('ekualisasi-pajak', ['id' => $this->anchor(2026)->id]);

        $this->assertStringContainsString('<body class="landscape">', $html);
        $this->assertStringContainsString('EKUALISASI PAJAK', $html);
        $this->assertStringContainsString('Form F/EQ', $html);

        // Four worksheets, four bordered tables, one anchor row.
        $this->assertStringContainsString('EKUALISASI PPN KELUARAN', $html);
        $this->assertStringContainsString('EKUALISASI PPN MASUKAN', $html);
        $this->assertStringContainsString('EKUALISASI PPH 21', $html);
        $this->assertStringContainsString('EKUALISASI PPH DIPOTONG', $html);

        // The 40jt appears exactly twice — buku side and SPT side — resolved
        // through the service, never summed a second time by the registry.
        $this->assertSame(2, substr_count($html, '40.000.000,00'));

        // A zero residual is a TESTED zero and still prints, as 0,00 — the
        // positive statement, never a blank and never a suppressed row.
        $this->assertStringContainsString('SELISIH BELUM TERJELASKAN', $html);
        $this->assertStringContainsString('0,00', $html);

        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * An approved payroll run with NO journal (the pre-PayrollPostingService
     * demo runs are exactly this) leaves bruto in the SPT and nothing in the
     * books. TaxEqualizationService deliberately keeps that gap IN the
     * residual, so the paper must print −44.500.000,00 — the value, with its
     * sign — and the warning row must name the run so the reader can chase it.
     */
    public function test_a_nonzero_residual_prints_its_value_with_its_sign_not_just_its_label(): void
    {
        $employeeId = (int) DB::table('hr_employees')->insertGetId([
            'code' => 'EMP-P001',
            'name' => 'Karyawan EMP-P001',
            'nik_ktp' => '3175012345678901',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => 'TK/0',
            'join_date' => '2024-01-01',
            'employment_type' => 'tetap',
            'position' => 'Staf',
            'department' => 'kantor',
            'base_salary' => 44500000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = (int) DB::table('hr_payroll_runs')->insertGetId([
            'code' => 'PYR/2026/07/0001',
            'period_year' => 2026,
            'period_month' => 7,
            'run_type' => 'regular',
            'payment_date' => '2026-07-25',
            'total_gross' => 44500000,
            'total_deductions' => 0,
            'total_net' => 44500000,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_payslips')->insert([
            'payroll_run_id' => $runId,
            'employee_id' => $employeeId,
            'basic_salary' => 44500000,
            'allowances_total' => 0,
            'overtime_hours' => 0,
            'overtime_pay' => 0,
            'thr_amount' => 0,
            'gross_income' => 44500000,
            'bpjs_employee_total' => 0,
            'bpjs_company_total' => 0,
            'pph21_amount' => 0,
            'total_deductions' => 0,
            'net_pay' => 44500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->forms->html('ekualisasi-pajak', ['id' => $this->anchor(2026)->id]);

        $this->assertStringContainsString('SELISIH BELUM TERJELASKAN', $html);
        // The residual's VALUE. books(0) − bruto SPT(44,5jt) = −44,5jt; a
        // flipped sign here would state the books OVERSTATE wages they are
        // missing entirely.
        $this->assertStringContainsString('-44.500.000,00', $html);
        // The warning that names the cause reaches the paper too.
        $this->assertStringContainsString('PYR/2026/07/0001', $html);
    }

    /**
     * pph_dipotong carries two panels on one table and only panel A owns the
     * residual. It must print where panel A's arithmetic ends — before the
     * "dipotong pelanggan" section — or the paper claims the soft panel-B
     * comparison was reconciled by it. Split by the payload's own panel key,
     * never by parsing labels.
     */
    public function test_the_pph_dipotong_residual_prints_inside_panel_a_not_after_panel_b(): void
    {
        // Panel A: 5-1300 moved by a manual JV, no vendor bill — the service
        // derives it as "sumber selain tagihan vendor" and closes to zero.
        $this->postJournal([
            ['5-1300', 75000000, 0],
            ['1-1210', 0, 75000000],
        ], '2026-05-20', 'Reklas beban subkon (JV manual)');

        // Panel B: construction revenue on 4-1100 with no bukti potong.
        $customer = $this->makeCustomer();
        $contract = $this->makeContract($customer, ['value' => 1000000000]);
        $this->makeProject(['contract_id' => $contract->id]);
        $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'invoice_date' => '2026-06-15',
            'description' => 'Termin 1',
            'dpp' => 200000000,
            'ppn_rate' => 11.0,
        ]));

        $html = $this->forms->html('ekualisasi-pajak', ['id' => $this->anchor(2026)->id]);

        $residualAt = strpos($html, 'SELISIH BELUM TERJELASKAN (PPH DIPOTONG PERUSAHAAN)');
        $panelBAt = strpos($html, 'PPH FINAL KONSTRUKSI DIPOTONG PELANGGAN ATAS KITA (PP 9/2022)');

        $this->assertNotFalse($residualAt, 'Panel A residual is missing from the printed sheet.');
        $this->assertNotFalse($panelBAt, 'Panel B section heading is missing from the printed sheet.');
        $this->assertLessThan($panelBAt, $residualAt, 'The panel A residual printed after panel B.');
    }

    /**
     * A year with no data SAYS SO. Rows of 0,00 over an empty year would read
     * as "nothing to reconcile" — a claim the database never made — and a
     * residual row over no data would claim a reconciliation that never ran.
     */
    public function test_an_empty_year_prints_tidak_ada_data_rather_than_zeros(): void
    {
        $html = $this->forms->html('ekualisasi-pajak', ['id' => $this->anchor(2031)->id]);

        $this->assertStringContainsString('Tidak ada data pendapatan maupun faktur pajak keluaran untuk tahun 2031.', $html);
        $this->assertStringContainsString('Tidak ada tagihan vendor untuk tahun 2031.', $html);
        $this->assertStringContainsString('Tidak ada data payroll maupun beban gaji untuk tahun 2031.', $html);
        $this->assertStringContainsString('Tidak ada pemotongan PPh vendor maupun beban subkontraktor untuk tahun 2031.', $html);
        $this->assertStringContainsString('Tidak ada bukti potong pelanggan maupun pendapatan konstruksi untuk tahun 2031.', $html);

        // No fake zeros anywhere on the sheet, and no residual line inviting
        // somebody to pen one over an empty year.
        $this->assertStringNotContainsString('0,00', $html);
        $this->assertStringNotContainsString('SELISIH BELUM TERJELASKAN', $html);
        $this->assertStringNotContainsString('null', $html);
    }
}
