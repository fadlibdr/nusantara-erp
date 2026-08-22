<?php

namespace Modules\HrPayroll\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\JournalService;
use Modules\Finance\Services\ProjectCostService;
use Modules\HrPayroll\Models\PayrollRun;

/**
 * Payroll into the general ledger.
 *
 * Until this existed, HrPayroll computed PPh 21 TER, capped BPJS, overtime and
 * THR correctly and none of it reached the books: 6-1100, 6-1200, 5-1200 and
 * 2-1210 were all zero on the payroll side, so every trial balance, profit and
 * loss and balance sheet omitted the whole labour cost of the business, and
 * withheld PPh 21 never appeared as a liability or in e-Bupot.
 *
 * The entry, posted when a run is approved:
 *
 *   Dr 5-1200 Beban Upah Proyek          gross of payslips carrying a project
 *   Dr 6-1100 Beban Gaji & Tunjangan     gross of everyone else
 *   Dr 6-1200 Beban BPJS & Kesejahteraan employer's BPJS contribution
 *       Cr 2-1210 Hutang PPh 21              PPh 21 withheld
 *       Cr 2-1120 Hutang BPJS                employee + employer contributions
 *       Cr 2-1110 Hutang Gaji & Upah         net take-home
 *
 * It balances by construction: net_pay is gross − PPh 21 − employee BPJS, so the
 * debits (gross + employer BPJS) equal the credits (PPh 21 + both BPJS shares +
 * net). The test suite asserts it rather than trusting the algebra.
 *
 * IURAN BPJS PERUSAHAAN IKUT KE PROYEK. Until this package only GROSS reached
 * the project: the employer's BPJS for a site worker went to 6-1200 as one
 * company-wide lump with no project_id and no fin_project_costs row, so the
 * project's labour cost was understated by the entire employer share — for the
 * June run that is Rp 14.502.144 of contributions the company must pay for
 * exactly those eight workers (3.649.398 + 2.336.922 + 1.762.347 + 1.982.397 +
 * 1.339.362 + 1.285.572 + 943.074 + 1.203.072). On an assigned run that is
 * roughly 7,4% of the wage bill missing from realisasi, from the cost-to-cost
 * percentage PSAK 115 measures, and from every RAP comparison — with no report
 * able to attribute it back.
 *
 * The ACCOUNT does not move: employer BPJS stays on 6-1200, because it is a
 * statutory contribution and not wages, and re-classifying it into 5-1200
 * would change the COGS/overhead split of the P&L on top of fixing the
 * attribution. What changes is that the debit is now split one line PER
 * PROJECT (each carrying project_id) plus one for the office, and the project
 * share is added to the labour row in fin_project_costs. The project cost
 * ledger therefore exceeds 5-1200 by exactly the employer BPJS — the same
 * legitimate gap DeploymentService's internal plant charge already creates,
 * and for the same reason: the project ledger is a costing view, not a copy of
 * the trial balance.
 *
 * Paying the wages is a separate step — an ordinary PAY payment against
 * 2-1110 — so payroll appears in bank reconciliation like every other
 * disbursement instead of vanishing into a single combined entry.
 */
class PayrollPostingService
{
    /** Where each half of the wage bill lands. */
    private const PROJECT_WAGE_ACCOUNT = '5-1200';

    private const OFFICE_SALARY_ACCOUNT = '6-1100';

    private const BPJS_EXPENSE_ACCOUNT = '6-1200';

    private const PPH21_PAYABLE_ACCOUNT = '2-1210';

    private const BPJS_PAYABLE_ACCOUNT = '2-1120';

    private const WAGES_PAYABLE_ACCOUNT = '2-1110';

    public function __construct(
        private readonly JournalService $journals,
        private readonly ProjectCostService $projectCosts,
    ) {}

    /**
     * Post an approved run.
     *
     * Refuses a second posting rather than replacing the first.
     * JournalService::autoPost() creates a NEW journal on every call — it does
     * not upsert on (reference_type, reference_id) — and a posted journal cannot
     * be deleted, so "post again" would silently double the entire wage bill.
     * Correcting a posted run is a reversing journal, which is an accountant's
     * decision and not something this method may take on its own.
     */
    public function post(PayrollRun $run, ?int $userId = null): Journal
    {
        return DB::transaction(function () use ($run, $userId): Journal {
            $existing = Journal::query()
                ->where('reference_type', 'payroll_run')
                ->where('reference_id', $run->id)
                ->where('status', PostingStatus::Posted->value)
                ->whereNull('deleted_at')
                ->first();

            if ($existing !== null) {
                throw new LogicException(
                    "Payroll {$run->code} is already posted as {$existing->code}. "
                    .'Correcting it needs a reversing journal, not a second posting.'
                );
            }

            $payslips = $run->payslips()->get();

            if ($payslips->isEmpty()) {
                throw new LogicException(
                    "Payroll {$run->code} has no payslips; calculate the run before approving it."
                );
            }

            $projectWages = [];      // project_id => gross
            $projectBpjs = [];       // project_id => employer BPJS
            $officeWages = 0.0;
            $bpjsCompany = 0.0;
            $bpjsEmployee = 0.0;
            $pph21 = 0.0;
            $netPay = 0.0;

            foreach ($payslips as $payslip) {
                $gross = (float) $payslip->gross_income;
                $employerBpjs = (float) $payslip->bpjs_company_total;

                // Split the SAME way as gross, off the same payslip: the
                // employer contribution is incurred for one worker on one
                // site, so anything else would be an allocation key nobody
                // could defend.
                if ($payslip->project_id !== null) {
                    $projectWages[(int) $payslip->project_id] =
                        ($projectWages[(int) $payslip->project_id] ?? 0.0) + $gross;
                    $projectBpjs[(int) $payslip->project_id] =
                        ($projectBpjs[(int) $payslip->project_id] ?? 0.0) + $employerBpjs;
                } else {
                    $officeWages += $gross;
                }

                $bpjsCompany += $employerBpjs;
                $bpjsEmployee += (float) $payslip->bpjs_employee_total;
                $pph21 += (float) $payslip->pph21_amount;
                $netPay += (float) $payslip->net_pay;
            }

            $lines = [];
            $period = $this->periodLabel($run);

            // One debit line per project, so the ledger says which site the
            // wages were worked on rather than aggregating them into one number
            // that no project report can take apart.
            foreach ($projectWages as $projectId => $amount) {
                $lines[] = [
                    'account_code' => self::PROJECT_WAGE_ACCOUNT,
                    'debit' => round($amount, 2),
                    'description' => "Upah proyek {$period}",
                    'project_id' => $projectId,
                ];
            }

            $lines[] = [
                'account_code' => self::OFFICE_SALARY_ACCOUNT,
                'debit' => round($officeWages, 2),
                'description' => "Gaji & tunjangan {$period}",
            ];

            // One 6-1200 line per project, tagged, plus one for the office.
            // The account is unchanged and the TOTAL is unchanged — this is
            // attribution, not reclassification — so anything reading 6-1200
            // in aggregate reads exactly what it read before.
            foreach ($projectBpjs as $projectId => $amount) {
                $lines[] = [
                    'account_code' => self::BPJS_EXPENSE_ACCOUNT,
                    'debit' => round($amount, 2),
                    'description' => "Iuran BPJS perusahaan proyek {$period}",
                    'project_id' => $projectId,
                ];
            }

            // Balancing leg, computed as the RESIDUAL rather than as its own
            // accumulation: rounding each project share to the cent and then
            // rounding an independent office total can leave the debits a cent
            // away from the credit on 2-1120, and a payroll journal that fails
            // assertBalanced() is a run that cannot be approved at all.
            $officeBpjsLine = round(
                round($bpjsCompany, 2) - array_sum(array_map(
                    static fn (float $amount): float => round($amount, 2),
                    $projectBpjs,
                )),
                2,
            );

            $lines[] = [
                'account_code' => self::BPJS_EXPENSE_ACCOUNT,
                'debit' => $officeBpjsLine,
                'description' => "Iuran BPJS perusahaan {$period}",
            ];

            $lines[] = [
                'account_code' => self::PPH21_PAYABLE_ACCOUNT,
                'credit' => round($pph21, 2),
                'description' => "PPh 21 dipotong {$period}",
            ];

            $lines[] = [
                'account_code' => self::BPJS_PAYABLE_ACCOUNT,
                'credit' => round($bpjsEmployee + $bpjsCompany, 2),
                'description' => "Iuran BPJS terutang {$period}",
            ];

            $lines[] = [
                'account_code' => self::WAGES_PAYABLE_ACCOUNT,
                'credit' => round($netPay, 2),
                'description' => "Gaji bersih terutang {$period}",
            ];

            $journal = $this->journals->autoPost(
                'payroll_run',
                (int) $run->id,
                $lines,
                $this->postingDate($run),
                "Payroll {$run->code} — {$period}",
                $userId,
            );

            $this->recordProjectCosts($run, $projectWages, $projectBpjs, $period);

            return $journal;
        });
    }

    /**
     * Wages worked on a project — AND the employer BPJS paid for the same
     * payslips — also land in the project cost ledger, which is what makes the
     * `labor` category on the profitability report stop being permanently zero
     * and stop being understated by the employer share once it is not.
     *
     * Each project gets its own reference id because
     * ProjectCostService::record() upserts on
     * (reference_type, reference_id, cost_category) — one id per run would make
     * the second project overwrite the first's cost rather than sit beside it.
     */
    private function recordProjectCosts(PayrollRun $run, array $projectWages, array $projectBpjs, string $period): void
    {
        foreach ($projectWages as $projectId => $amount) {
            // Gross PLUS the employer's BPJS for the same payslips: what the
            // project costs the company is what the company pays out for the
            // people on it, and the contribution is not optional. One row, not
            // two, because the reference id is what keeps the upsert
            // idempotent across re-approval and (reference, category) is its
            // key — a second row in the same category would overwrite the
            // first.
            $total = round($amount + ($projectBpjs[$projectId] ?? 0.0), 2);

            $this->projectCosts->record(
                $projectId,
                $this->postingDate($run),
                CostCategory::Labor,
                'payroll_run',
                // Distinct per project, and stable across re-approval.
                (int) ($run->id * 100_000 + $projectId),
                "Upah & iuran BPJS {$period} — {$run->code}",
                $total,
            );
        }
    }

    /**
     * The last day of the payroll period, not the payment date.
     *
     * Wages are earned in the month they are worked; posting them on the day
     * they happen to be paid would move a December cost into January and make
     * every year-end wrong.
     */
    private function postingDate(PayrollRun $run): string
    {
        return Carbon::create($run->period_year, $run->period_month, 1)
            ->endOfMonth()
            ->toDateString();
    }

    private function periodLabel(PayrollRun $run): string
    {
        return sprintf('%02d/%04d', $run->period_month, $run->period_year);
    }
}
