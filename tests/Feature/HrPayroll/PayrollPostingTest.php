<?php

namespace Tests\Feature\HrPayroll;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\JournalLine;
use Modules\HrPayroll\Models\Employee;
use Modules\HrPayroll\Models\PayrollRun;
use Modules\HrPayroll\Services\PayrollPostingService;
use Modules\HrPayroll\Services\PayrollService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Payroll reaching the general ledger.
 *
 * Before this existed the module computed PPh 21 TER, capped BPJS, overtime and
 * THR correctly, and not one rupiah of it appeared in the books: the trial
 * balance, profit and loss and balance sheet all omitted the entire labour cost
 * of the business, and PPh 21 withheld from employees was never a liability.
 *
 * The properties below are the ones that make the difference real rather than
 * decorative: the entry balances, it lands on the accounts an accountant would
 * expect, wages worked on a site become that site's cost, and approving the same
 * run twice does not book the payroll twice.
 */
class PayrollPostingTest extends ErpTestCase
{
    use FinanceFixtures;

    private PayrollService $payroll;

    private PayrollPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->payroll = app(PayrollService::class);
        $this->posting = app(PayrollPostingService::class);
    }

    private function employee(array $overrides = []): Employee
    {
        return Employee::query()->create(array_merge([
            'code' => 'EMP-'.str()->random(5),
            'name' => 'Karyawan Uji',
            'nik_ktp' => (string) random_int(1_000_000_000_000_000, 9_999_999_999_999_999),
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'ptkp_status' => 'TK/0',
            'employment_type' => 'tetap',
            'join_date' => '2025-01-01',
            'position' => 'Staf',
            'department' => 'proyek',
            'base_salary' => 10_000_000,
            'status' => 'active',
        ], $overrides));
    }

    private function project(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-'.random_int(100, 999),
            'name' => 'Proyek Uji Payroll',
            'type' => 'construction',
            'status' => 'active',
        ]);
    }

    private function assign(Employee $employee, Project $project, string $from = '2026-03-01'): void
    {
        DB::table('prj_manpower_assignments')->insert([
            'project_id' => $project->id,
            'employee_id' => $employee->id,
            'role_on_project' => 'Pelaksana',
            'assigned_from' => $from,
            'assigned_until' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function calculatedRun(): PayrollRun
    {
        $run = $this->payroll->create([
            'period_year' => 2026,
            'period_month' => 3,
            'run_type' => 'regular',
            'payment_date' => '2026-04-05',
        ]);

        return $this->payroll->calculate($run);
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function ledgerByAccount(): array
    {
        $rows = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journals.reference_type', 'payroll_run')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->get(['fin_accounts.code', 'fin_journal_lines.debit', 'fin_journal_lines.credit']);

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->code]['debit'] = ($totals[$row->code]['debit'] ?? 0) + (float) $row->debit;
            $totals[$row->code]['credit'] = ($totals[$row->code]['credit'] ?? 0) + (float) $row->credit;
        }

        return $totals;
    }

    // ------------------------------------------------------------ the point

    public function test_approving_a_run_puts_the_wage_bill_in_the_ledger(): void
    {
        $this->employee();
        $run = $this->calculatedRun();

        $this->assertSame(0, JournalLine::query()->count(), 'nothing is posted before approval');

        $this->posting->post($run);

        $ledger = $this->ledgerByAccount();

        $this->assertArrayHasKey('6-1100', $ledger, 'salary expense must reach 6-1100');
        $this->assertArrayHasKey('6-1200', $ledger, 'employer BPJS must reach 6-1200');
        $this->assertArrayHasKey('2-1210', $ledger, 'PPh 21 withheld must become a liability');
        $this->assertArrayHasKey('2-1120', $ledger, 'BPJS payable must be recognised');
        $this->assertArrayHasKey('2-1110', $ledger, 'net pay must become a liability');
    }

    public function test_the_entry_balances(): void
    {
        $this->employee();
        $this->employee(['base_salary' => 25_000_000, 'ptkp_status' => 'K/2']);

        $this->posting->post($this->calculatedRun());

        $difference = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.reference_type', 'payroll_run')
            ->selectRaw('SUM(debit) - SUM(credit) as diff')
            ->value('diff');

        $this->assertSame(0, (int) round((float) $difference * 100), 'the payroll journal must balance to the cent');
    }

    /**
     * The amounts must be the payslips' amounts, not a plausible-looking total.
     */
    public function test_the_posted_amounts_equal_the_payslips(): void
    {
        $this->employee();
        $this->employee(['base_salary' => 18_000_000]);
        $run = $this->calculatedRun();

        $payslips = $run->payslips()->get();
        $this->posting->post($run);

        $ledger = $this->ledgerByAccount();

        $this->assertEqualsWithDelta($payslips->sum('gross_income'), $ledger['6-1100']['debit'], 0.01);
        $this->assertEqualsWithDelta($payslips->sum('bpjs_company_total'), $ledger['6-1200']['debit'], 0.01);
        $this->assertEqualsWithDelta($payslips->sum('pph21_amount'), $ledger['2-1210']['credit'], 0.01);
        $this->assertEqualsWithDelta($payslips->sum('net_pay'), $ledger['2-1110']['credit'], 0.01);
        $this->assertEqualsWithDelta(
            $payslips->sum('bpjs_employee_total') + $payslips->sum('bpjs_company_total'),
            $ledger['2-1120']['credit'],
            0.01,
        );
    }

    // ------------------------------------------------------- project wages

    public function test_wages_of_someone_assigned_to_a_site_are_that_sites_cost(): void
    {
        $site = $this->employee(['name' => 'Tukang']);
        $office = $this->employee(['name' => 'Staf Kantor']);
        $project = $this->project();
        $this->assign($site, $project);

        $run = $this->calculatedRun();
        $this->posting->post($run);

        $sitePayslip = $run->payslips()->where('employee_id', $site->id)->firstOrFail();
        $officePayslip = $run->payslips()->where('employee_id', $office->id)->firstOrFail();

        $this->assertSame($project->id, (int) $sitePayslip->project_id);
        $this->assertNull($officePayslip->project_id);

        $ledger = $this->ledgerByAccount();
        $this->assertEqualsWithDelta((float) $sitePayslip->gross_income, $ledger['5-1200']['debit'], 0.01);
        $this->assertEqualsWithDelta((float) $officePayslip->gross_income, $ledger['6-1100']['debit'], 0.01);
    }

    /**
     * The labour category on the project profitability report was permanently
     * zero because nothing ever wrote it. This is what fills it.
     *
     * What lands is gross PLUS the employer's BPJS for the same payslip: what
     * the project costs the company is what the company pays out for the people
     * on it, and the statutory contribution is not optional.
     */
    public function test_project_wages_reach_the_project_cost_ledger(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $this->assign($employee, $project);

        $run = $this->calculatedRun();
        $this->posting->post($run);

        $cost = DB::table('fin_project_costs')
            ->where('project_id', $project->id)
            ->where('cost_category', 'labor')
            ->first();

        $payslip = $run->payslips()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertNotNull($cost, 'labour cost must reach fin_project_costs');
        $this->assertEqualsWithDelta(
            (float) $payslip->gross_income + (float) $payslip->bpjs_company_total,
            (float) $cost->amount,
            0.01,
        );
    }

    // ------------------------------------------------- iuran BPJS perusahaan

    /**
     * Temuan T31. Only GROSS used to reach the project: the employer's BPJS for
     * a site worker went to 6-1200 as one company-wide lump with no project_id,
     * so the project's labour cost was understated by the entire employer share
     * — Rp 14.502.144 on the demo's June run — and no report could attribute it
     * back.
     */
    public function test_the_employer_bpjs_of_a_site_worker_is_tagged_with_the_project(): void
    {
        $site = $this->employee(['name' => 'Tukang']);
        $office = $this->employee(['name' => 'Staf Kantor']);
        $project = $this->project();
        $this->assign($site, $project);

        $run = $this->calculatedRun();
        $this->posting->post($run);

        $sitePayslip = $run->payslips()->where('employee_id', $site->id)->firstOrFail();
        $officePayslip = $run->payslips()->where('employee_id', $office->id)->firstOrFail();

        $lines = JournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', '6-1200')
            ->get(['fin_journal_lines.debit', 'fin_journal_lines.project_id']);

        $tagged = $lines->firstWhere('project_id', $project->id);
        $untagged = $lines->firstWhere('project_id', null);

        $this->assertNotNull($tagged, 'the site worker share must carry its project');
        $this->assertEqualsWithDelta((float) $sitePayslip->bpjs_company_total, (float) $tagged->debit, 0.01);

        $this->assertNotNull($untagged, 'the office share stays company overhead');
        $this->assertEqualsWithDelta((float) $officePayslip->bpjs_company_total, (float) $untagged->debit, 0.01);
    }

    /**
     * ATTRIBUTION, NOT RECLASSIFICATION: the account and the total are
     * unchanged, so the P&L split between COGS and overhead reads exactly what
     * it read before.
     */
    public function test_splitting_the_employer_bpjs_does_not_move_a_rupiah_off_6_1200(): void
    {
        $site = $this->employee();
        $office = $this->employee(['base_salary' => 18_000_000]);
        $project = $this->project();
        $this->assign($site, $project);

        $run = $this->calculatedRun();
        $payslips = $run->payslips()->get();
        $this->posting->post($run);

        $ledger = $this->ledgerByAccount();

        $this->assertEqualsWithDelta($payslips->sum('bpjs_company_total'), $ledger['6-1200']['debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $ledger['6-1200']['credit'], 0.01);
        // 5-1200 tetap hanya berisi upah bruto — iuran bukan upah.
        $this->assertEqualsWithDelta(
            (float) $run->payslips()->where('employee_id', $site->id)->value('gross_income'),
            $ledger['5-1200']['debit'],
            0.01,
        );
    }

    public function test_two_projects_each_carry_their_own_employer_bpjs(): void
    {
        $first = $this->employee();
        $second = $this->employee(['base_salary' => 20_000_000]);
        $projectA = $this->project();
        $projectB = $this->project();
        $this->assign($first, $projectA);
        $this->assign($second, $projectB);

        $run = $this->calculatedRun();
        $this->posting->post($run);

        $lines = JournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', '6-1200')
            ->get(['fin_journal_lines.debit', 'fin_journal_lines.project_id']);

        // Semua payslip berproyek, jadi tidak ada sisa kantor: dua baris saja.
        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(
            [$projectA->id, $projectB->id],
            $lines->pluck('project_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            0,
        );

        foreach ([[$first, $projectA], [$second, $projectB]] as [$employee, $project]) {
            $payslip = $run->payslips()->where('employee_id', $employee->id)->firstOrFail();
            $this->assertEqualsWithDelta(
                (float) $payslip->bpjs_company_total,
                (float) $lines->firstWhere('project_id', $project->id)->debit,
                0.01,
            );
        }
    }

    /**
     * The whole reason the split exists: the cost ledger, which is what the
     * profitability report, the RAP comparison and the PSAK 115 cost-to-cost
     * percentage all read.
     */
    public function test_the_project_cost_row_carries_gross_plus_the_employer_bpjs(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $this->assign($employee, $project);

        $run = $this->calculatedRun();
        $this->posting->post($run);

        $payslip = $run->payslips()->where('employee_id', $employee->id)->firstOrFail();

        $cost = DB::table('fin_project_costs')
            ->where('project_id', $project->id)
            ->where('cost_category', 'labor')
            ->first();

        // Satu baris saja — (reference, category) adalah kunci upsertnya, jadi
        // baris kedua akan menimpa yang pertama, bukan berdiri di sebelahnya.
        $this->assertSame(
            1,
            DB::table('fin_project_costs')->where('project_id', $project->id)->count(),
        );
        $this->assertEqualsWithDelta(
            (float) $payslip->gross_income + (float) $payslip->bpjs_company_total,
            (float) $cost->amount,
            0.01,
        );
        $this->assertGreaterThan((float) $payslip->gross_income, (float) $cost->amount);
    }

    /** The wage line has to carry its project, or no project report can find it. */
    public function test_the_project_wage_line_is_tagged_with_the_project(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $this->assign($employee, $project);

        $this->posting->post($this->calculatedRun());

        $line = JournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', '5-1200')
            ->first(['fin_journal_lines.project_id']);

        $this->assertSame($project->id, (int) $line->project_id);
    }

    public function test_two_projects_get_a_line_each_rather_than_one_lump(): void
    {
        $first = $this->employee();
        $second = $this->employee();
        $projectA = $this->project();
        $projectB = $this->project();
        $this->assign($first, $projectA);
        $this->assign($second, $projectB);

        $this->posting->post($this->calculatedRun());

        $lines = JournalLine::query()
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', '5-1200')
            ->get();

        $this->assertCount(2, $lines);
        $this->assertEqualsWithDelta(
            [$projectA->id, $projectB->id],
            $lines->pluck('project_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            0,
        );
    }

    /**
     * An assignment starting mid-month still makes those wages the site's cost.
     * Requiring it to span the whole period would send every mid-month
     * mobilisation to office overhead.
     */
    public function test_an_assignment_starting_mid_period_still_counts(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $this->assign($employee, $project, '2026-03-20');

        $run = $this->calculatedRun();

        $this->assertSame($project->id, (int) $run->payslips()->first()->project_id);
    }

    public function test_an_assignment_that_ended_before_the_period_does_not_count(): void
    {
        $employee = $this->employee();
        $project = $this->project();

        DB::table('prj_manpower_assignments')->insert([
            'project_id' => $project->id,
            'employee_id' => $employee->id,
            'role_on_project' => 'Pelaksana',
            'assigned_from' => '2026-01-05',
            'assigned_until' => '2026-02-10',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run = $this->calculatedRun();

        $this->assertNull($run->payslips()->first()->project_id);
    }

    // ------------------------------------------------------------ idempotency

    /**
     * A second posting would double the entire wage bill: autoPost() creates a
     * new journal each call rather than upserting, and a posted journal cannot
     * be deleted. Refusing is the only safe answer — correcting a posted run is
     * a reversing journal, which is an accountant's decision.
     */
    public function test_a_run_cannot_be_posted_twice(): void
    {
        $employee = $this->employee();
        $project = $this->project();
        $this->assign($employee, $project);

        $run = $this->calculatedRun();
        $this->posting->post($run);
        $afterFirst = $this->ledgerByAccount();

        try {
            $this->posting->post($run);
            $this->fail('a second posting must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('already posted', $e->getMessage());
        }

        $this->assertEquals($afterFirst, $this->ledgerByAccount(), 'the refused attempt must change nothing');
        $this->assertSame(
            1,
            DB::table('fin_project_costs')->where('project_id', $project->id)->where('cost_category', 'labor')->count(),
        );
    }

    /** Two projects must not overwrite each other in the project cost ledger. */
    public function test_each_project_gets_its_own_cost_row(): void
    {
        $first = $this->employee();
        $second = $this->employee();
        $projectA = $this->project();
        $projectB = $this->project();
        $this->assign($first, $projectA);
        $this->assign($second, $projectB);

        $this->posting->post($this->calculatedRun());

        $this->assertSame(2, DB::table('fin_project_costs')->where('cost_category', 'labor')->count());
    }

    public function test_a_run_with_no_payslips_is_refused(): void
    {
        $run = $this->payroll->create([
            'period_year' => 2026,
            'period_month' => 3,
            'run_type' => 'regular',
            'payment_date' => '2026-04-05',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/calculate the run/');

        $this->posting->post($run);
    }

    /**
     * Wages are earned in the month worked. Posting on the payment date would
     * move a December cost into January and make every year-end wrong.
     */
    public function test_the_entry_is_dated_to_the_period_worked_not_the_payment_date(): void
    {
        $this->employee();

        $this->posting->post($this->calculatedRun());

        $journalDate = DB::table('fin_journals')->where('reference_type', 'payroll_run')->value('journal_date');

        $this->assertStringStartsWith('2026-03-31', (string) $journalDate);
    }
}
