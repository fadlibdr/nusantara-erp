<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\PaymentAllocation;
use Modules\Finance\Support\OutstandingAsOf;

/**
 * Read-only financial reporting over POSTED journals.
 *
 * NO JURNAL PENUTUP EXISTS IN THIS LEDGER. Nothing anywhere posts a year-end
 * closing entry to 3-2100 Laba Ditahan — grep finds the account only in the
 * chart of accounts and in comments telling a human to do it. So on
 * 2027-01-01 the P&L accounts still carry 2026: 4-1100 opens the January 2027
 * neraca saldo with a credit balance of Rp 9.700.000.000, and a balance sheet
 * that summed the P&L cumulatively would have reported the 2026 result of
 * Rp 9.471.760.000 as "Laba Tahun Berjalan" for a year in which the company
 * had earned nothing.
 *
 * balanceSheet() therefore performs the roll ARITHMETICALLY instead of waiting
 * for a journal: everything the P&L earned before 1 January is presented as
 * "Laba Ditahan (belum dijurnal tutup)" and only the current fiscal year's
 * result is "Laba Tahun Berjalan". Chosen over fabricating a closing journal
 * because it needs no posting, no permission and no migration; it is right on
 * the first of January without anybody remembering to do anything; and it
 * survives the accountant later keying the real jurnal penutup — that entry
 * zeroes the prior-year P&L movement and credits 3-2100, so the synthetic row
 * falls to nil exactly as the real equity row appears. A = L + E holds in
 * every one of those states because the two rows always sum to the same
 * cumulative figure the single row used to carry.
 *
 * trialBalance() is deliberately NOT given the same treatment: see its
 * docblock. The fiscal year is the calendar year — fin_fiscal_periods is keyed
 * (year, month 1-12) and nothing in the system offers another basis.
 */
class ReportService
{
    public function __construct(
        private readonly ProjectCostService $projectCosts,
    ) {}

    /**
     * Trial balance of one fiscal month: opening balance, period movement,
     * closing balance per postable account with activity.
     *
     * THE OPENING COLUMNS ARE ALL-TIME ON PURPOSE, including for the P&L
     * accounts in January. Opening a fiscal year with 4-1100 at zero was the
     * obvious-looking half of the year-boundary fix and it is the harmful
     * half: with no jurnal penutup posted, the Rp 9.471.760.000 those accounts
     * carry has no equity row to have moved to, so zeroing them would make
     * debit ≠ credit by exactly that amount — and `balanced` below is a
     * NON-OVERRIDABLE close blocker. The report would have manufactured the
     * very break it exists to detect. A neraca saldo lists what the ledger
     * holds; while the books are unclosed, the ledger holds last year's
     * revenue, and saying so is the honest answer. The balance sheet is where
     * the roll belongs, and that is where it is done.
     */
    public function trialBalance(int $year, int $month): array
    {
        if ($month < 1 || $month > 12) {
            throw new LogicException('Month must be 1-12.');
        }

        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $opening = $this->sumsPerAccount(null, $periodStart->copy()->subDay());
        $movement = $this->sumsPerAccount($periodStart, $periodEnd);

        $rows = [];
        $totals = ['opening_debit' => 0.0, 'opening_credit' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing_debit' => 0.0, 'closing_credit' => 0.0];

        $accounts = Account::query()
            ->where('is_postable', true)
            ->orderBy('code')
            ->get();

        foreach ($accounts as $account) {
            $openNet = round(
                (float) ($opening[$account->id]['debit'] ?? 0) - (float) ($opening[$account->id]['credit'] ?? 0),
                2
            );
            $moveDebit = round((float) ($movement[$account->id]['debit'] ?? 0), 2);
            $moveCredit = round((float) ($movement[$account->id]['credit'] ?? 0), 2);
            $closeNet = round($openNet + $moveDebit - $moveCredit, 2);

            if ($openNet === 0.0 && $moveDebit === 0.0 && $moveCredit === 0.0) {
                continue; // no balance, no movement — keep the report readable
            }

            $row = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->account_type->value,
                'opening_debit' => max($openNet, 0.0),
                'opening_credit' => max(-$openNet, 0.0),
                'debit' => $moveDebit,
                'credit' => $moveCredit,
                'closing_debit' => max($closeNet, 0.0),
                'closing_credit' => max(-$closeNet, 0.0),
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + $row[$key], 2);
            }

            $rows[] = $row;
        }

        $toleranceCents = $this->toleratedResidueCents($periodEnd);

        return [
            'year' => $year,
            'month' => $month,
            'rows' => $rows,
            'totals' => $totals,
            // Payload, not documentation: an operator reading `balanced` is
            // entitled to see what it forgave. Normally Rp 0,00.
            'rounding_tolerance' => round($toleranceCents / 100, 2),
            'balanced' => abs((int) round(($totals['closing_debit'] - $totals['closing_credit']) * 100)) <= $toleranceCents,
        ];
    }

    /**
     * P&L between two dates, optionally narrowed to one project's journal
     * lines (project P&L).
     */
    public function profitLoss(string $from, string $to, ?int $projectId = null): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();

        $sums = $this->sumsPerAccount($fromDate, $toDate, $projectId);

        $accounts = Account::query()
            ->where('is_postable', true)
            ->whereIn('account_type', [
                AccountType::Revenue->value,
                AccountType::Cogs->value,
                AccountType::Expense->value,
                AccountType::Other->value,
            ])
            ->orderBy('code')
            ->get();

        $sections = ['revenue' => [], 'cogs' => [], 'expense' => [], 'other' => []];
        $sectionTotals = ['revenue' => 0.0, 'cogs' => 0.0, 'expense' => 0.0, 'other' => 0.0];

        foreach ($accounts as $account) {
            $debit = (float) ($sums[$account->id]['debit'] ?? 0);
            $credit = (float) ($sums[$account->id]['credit'] ?? 0);

            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            // Income-natured sections read positive on the credit side,
            // cost-natured sections on the debit side. "Other" nets income
            // minus expense (credit - debit), so 7-2100 Beban Admin Bank
            // shows up negative inside other income.
            $amount = match ($account->account_type) {
                AccountType::Cogs, AccountType::Expense => round($debit - $credit, 2),
                default => round($credit - $debit, 2),
            };

            $section = match ($account->account_type) {
                AccountType::Revenue => 'revenue',
                AccountType::Cogs => 'cogs',
                AccountType::Expense => 'expense',
                default => 'other',
            };

            $sections[$section][] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'amount' => $amount,
            ];
            $sectionTotals[$section] = round($sectionTotals[$section] + $amount, 2);
        }

        $grossProfit = round($sectionTotals['revenue'] - $sectionTotals['cogs'], 2);
        $operatingProfit = round($grossProfit - $sectionTotals['expense'], 2);
        $netProfit = round($operatingProfit + $sectionTotals['other'], 2);

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'project_id' => $projectId,
            'revenue' => ['rows' => $sections['revenue'], 'total' => $sectionTotals['revenue']],
            'cogs' => ['rows' => $sections['cogs'], 'total' => $sectionTotals['cogs']],
            'gross_profit' => $grossProfit,
            'operating_expenses' => ['rows' => $sections['expense'], 'total' => $sectionTotals['expense']],
            'operating_profit' => $operatingProfit,
            'other' => ['rows' => $sections['other'], 'total' => $sectionTotals['other']],
            'net_profit' => $netProfit,
        ];
    }

    /**
     * Balance sheet as of a date. Assets = Liabilities + Equity holds because
     * the P&L result is presented inside equity — split at the fiscal year
     * boundary into what previous years earned and what this one has.
     *
     * The split is the whole fix for the year-boundary defect (see the class
     * docblock): summing the P&L cumulatively made the director opening
     * Keuangan › Laporan › Neraca on 2027-01-02 read "Laba Tahun Berjalan
     * Rp 9.471.760.000" for a year two days old, with no Laba Ditahan line
     * anywhere on the sheet. Both rows are synthetic (account_code null) and
     * always sum to that same cumulative figure, so nothing about the
     * balancing changes — only what each line claims to mean.
     */
    public function balanceSheet(string $asOf): array
    {
        $asOfDate = Carbon::parse($asOf)->endOfDay();
        // Calendar year: fin_fiscal_periods is keyed (year, month 1-12) and
        // there is no other fiscal-year basis in the system to honour.
        $priorYearEnd = $asOfDate->copy()->startOfYear()->subDay()->endOfDay();

        $sums = $this->sumsPerAccount(null, $asOfDate);
        $priorSums = $this->sumsPerAccount(null, $priorYearEnd);

        $accounts = Account::query()
            ->where('is_postable', true)
            ->orderBy('code')
            ->get()
            ->keyBy('id');

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
        $earnings = 0.0;  // this fiscal year's P&L result
        $retained = 0.0;  // every earlier year's, never closed to 3-2100

        foreach ($accounts as $account) {
            $debit = (float) ($sums[$account->id]['debit'] ?? 0);
            $credit = (float) ($sums[$account->id]['credit'] ?? 0);

            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            if (! $account->account_type->isBalanceSheet()) {
                // Every P&L account contributes credit - debit to earnings
                // (revenue positive, costs negative). Both sums run from the
                // beginning of the ledger, so the earlier one is always a
                // subset of the later and the two rows cannot double-count.
                $priorDebit = (float) ($priorSums[$account->id]['debit'] ?? 0);
                $priorCredit = (float) ($priorSums[$account->id]['credit'] ?? 0);

                $retained = round($retained + $priorCredit - $priorDebit, 2);
                $earnings = round($earnings + ($credit - $priorCredit) - ($debit - $priorDebit), 2);

                continue;
            }

            // Present by section nature, not the account's normal balance:
            // contra accounts (e.g. 1-2210 Akumulasi Penyusutan, credit-normal
            // but asset-typed) then show as negative rows inside their section,
            // keeping Assets = Liabilities + Equity.
            $balance = $account->account_type === AccountType::Asset
                ? round($debit - $credit, 2)
                : round($credit - $debit, 2);

            $section = match ($account->account_type) {
                AccountType::Asset => 'asset',
                AccountType::Liability => 'liability',
                default => 'equity',
            };

            $sections[$section][] = [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'balance' => $balance,
            ];
            $totals[$section] = round($totals[$section] + $balance, 2);
        }

        // Shown only when there IS a prior year in the ledger. A permanent
        // Rp 0,00 row in the company's first year invites the opposite
        // question — "where did our retained earnings go?" — and 3-2100 Laba
        // Ditahan is a real account that appears above under its own code the
        // day someone posts a real jurnal penutup. The label says which of the
        // two this is.
        if ($retained !== 0.0) {
            $sections['equity'][] = [
                'account_code' => null,
                'account_name' => 'Laba Ditahan (belum dijurnal tutup)',
                'balance' => $retained,
            ];
            $totals['equity'] = round($totals['equity'] + $retained, 2);
        }

        $sections['equity'][] = [
            'account_code' => null,
            'account_name' => 'Laba Tahun Berjalan',
            'balance' => $earnings,
        ];
        $totals['equity'] = round($totals['equity'] + $earnings, 2);

        $liabilitiesAndEquity = round($totals['liability'] + $totals['equity'], 2);
        $toleranceCents = $this->toleratedResidueCents($asOfDate);

        return [
            'as_of' => $asOfDate->toDateString(),
            'assets' => ['rows' => $sections['asset'], 'total' => $totals['asset']],
            'liabilities' => ['rows' => $sections['liability'], 'total' => $totals['liability']],
            'equity' => ['rows' => $sections['equity'], 'total' => $totals['equity']],
            'liabilities_and_equity' => $liabilitiesAndEquity,
            'rounding_tolerance' => round($toleranceCents / 100, 2),
            'balanced' => abs((int) round(($totals['asset'] - $liabilitiesAndEquity) * 100)) <= $toleranceCents,
        ];
    }

    /**
     * Project profitability: billed revenue (approved AR DPP) vs realisasi
     * per cost category, side-by-side with the RAP budget when Estimation has
     * an approved cost budget for the project.
     */
    public function projectProfitability(int $projectId): array
    {
        $revenue = round((float) ArInvoice::query()
            ->where('project_id', $projectId)
            ->where('status', DocumentStatus::Approved->value)
            ->sum('dpp'), 2);

        $actuals = $this->projectCosts->totalsByCategory($projectId);
        $budgets = $this->rapBudgetByCategory($projectId);

        $categories = [];
        $totalCost = 0.0;
        $totalBudget = 0.0;

        foreach (CostCategory::cases() as $category) {
            $actual = $actuals[$category->value] ?? 0.0;
            $budget = $budgets !== null ? ($budgets[$category->value] ?? 0.0) : null;

            $categories[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'actual' => $actual,
                'budget' => $budget,
                'variance' => $budget !== null ? round($budget - $actual, 2) : null,
            ];

            $totalCost = round($totalCost + $actual, 2);
            $totalBudget = $budget !== null ? round($totalBudget + $budget, 2) : $totalBudget;
        }

        $margin = round($revenue - $totalCost, 2);

        // Money promised but not yet billed. Reported BESIDE actual cost, never
        // added into it: nothing has been received, and an accrual for work not
        // yet done would be wrong. What it changes is the remaining budget a
        // decision can actually be made on — a PM who has signed Rp 5 miliar of
        // orders used to see the budget intact until the invoices arrived.
        $committed = app(CommitmentService::class)->forProject($projectId);

        return [
            'project_id' => $projectId,
            'revenue' => $revenue,
            'costs' => $categories,
            'total_cost' => $totalCost,
            'total_budget' => $budgets !== null ? $totalBudget : null,
            'committed' => $committed,
            // Budget less BOTH what has been spent and what has been promised.
            // This is the number that answers "can I still order anything?".
            'budget_remaining' => $budgets !== null
                ? round($totalBudget - $totalCost - $committed['total'], 2)
                : null,
            'margin' => $margin,
            'margin_pct' => $revenue > 0 ? round($margin / $revenue * 100, 2) : null,
        ];
    }

    /**
     * AR/AP aging by days overdue as at $asOf (default today).
     *
     * EVERYTHING HERE IS BOUNDED BY $asOf — the documents and the money
     * against them — because the aging is compared against a dated control
     * account. It used to select `amount_paid < total` and report
     * ArInvoice::outstanding(), both lifetime figures, while GL 1-1300 and
     * PeriodCloseService::subledgerOutstanding are date-bounded. A post-dated
     * giro of Rp 300.000.000 keyed as a receipt dated 2026-09-15 and posted on
     * 2026-08-03 therefore dropped the invoice out of the aging that same
     * afternoon: the aging said Rp 260.000.000 outstanding and the balance
     * sheet of the identical date said Piutang Usaha Rp 560.000.000, and
     * collections stopped chasing an invoice on which no money had arrived.
     * The close checklist compares those two numbers (itemSubledgerTied), so
     * the disagreement is not merely cosmetic — it is a warning the closer has
     * to investigate on a month where nothing is actually wrong.
     *
     * The document side is bounded too, not just the payments: invoice_date is
     * `['nullable', 'date']` in ArInvoiceStoreRequest with no upper bound, so
     * a future-dated invoice would break the same tie in the other direction.
     */
    public function agingReport(string $side, ?string $asOf = null): array
    {
        if (! in_array($side, ['ar', 'ap'], true)) {
            throw new LogicException("Aging side must be 'ar' or 'ap'.");
        }

        $asOfDate = $asOf !== null ? Carbon::parse($asOf)->startOfDay() : Carbon::today();
        $asOfString = $asOfDate->toDateString();
        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        $rows = [];

        // No `amount_paid < total` pre-filter: it is the lifetime figure, so it
        // would hide exactly the document this report has to show — the one a
        // future-dated receipt has already settled on paper. Rows that really
        // are settled as at $asOf are dropped below instead, so the payload is
        // the same size either way.
        $documents = $side === 'ar'
            ? ArInvoice::query()
                ->with('customer')
                ->where('status', DocumentStatus::Approved->value)
                ->whereDate('invoice_date', '<=', $asOfString)
                ->orderBy('due_date')
                ->get()
            : ApBill::query()
                ->with('vendor')
                ->where('status', DocumentStatus::Approved->value)
                ->whereDate('bill_date', '<=', $asOfString)
                ->orderBy('due_date')
                ->get();

        $settled = OutstandingAsOf::settled(
            $side === 'ar' ? PaymentAllocation::TYPE_AR_INVOICE : PaymentAllocation::TYPE_AP_BILL,
            $documents->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $asOfString,
        );

        foreach ($documents as $document) {
            $value = $side === 'ar' ? (float) $document->total : (float) $document->total_payable;
            $paid = $settled[(int) $document->id] ?? 0.0;
            $outstanding = round($value - $paid, 2);

            if ($outstanding <= 0) {
                continue;
            }

            $daysOverdue = $document->due_date->lessThan($asOfDate)
                ? (int) $document->due_date->diffInDays($asOfDate)
                : 0;

            $bucket = match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => '1_30',
                $daysOverdue <= 60 => '31_60',
                $daysOverdue <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$bucket] = round($buckets[$bucket] + $outstanding, 2);

            $rows[] = [
                'code' => $document->code,
                'partner' => $side === 'ar' ? $document->customer?->name : $document->vendor?->name,
                'document_date' => $side === 'ar'
                    ? $document->invoice_date->toDateString()
                    : $document->bill_date->toDateString(),
                'due_date' => $document->due_date->toDateString(),
                'total' => $side === 'ar' ? (float) $document->total : (float) $document->total_payable,
                // The as-at figure, NOT fin_ar_invoices.amount_paid: the SPA
                // prints this as "Dibayar" beside "Sisa" and the row has to
                // add up to the total on the same date basis.
                'amount_paid' => $paid,
                'outstanding' => $outstanding,
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
            ];
        }

        return [
            'side' => $side,
            'as_of' => $asOfDate->toDateString(),
            'rows' => $rows,
            'buckets' => $buckets,
            'total_outstanding' => round(array_sum($buckets), 2),
        ];
    }

    /**
     * Debit/credit sums per account over posted journals in a date window.
     *
     * @return Collection keyed by account_id => ['debit' => x, 'credit' => y]
     */
    private function sumsPerAccount(?Carbon $from, ?Carbon $to, ?int $projectId = null): Collection
    {
        return JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->when($from !== null, fn ($query) => $query->whereDate('fin_journals.journal_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($query) => $query->whereDate('fin_journals.journal_date', '<=', $to->toDateString()))
            ->when($projectId !== null, fn ($query) => $query->where('fin_journal_lines.project_id', $projectId))
            ->selectRaw('fin_journal_lines.account_id, SUM(fin_journal_lines.debit) as debit, SUM(fin_journal_lines.credit) as credit')
            ->groupBy('fin_journal_lines.account_id')
            ->get()
            ->keyBy('account_id')
            ->map(fn ($row) => ['debit' => (float) $row->debit, 'credit' => (float) $row->credit]);
    }

    /**
     * The rounding residue the POSTING GUARD already let into the ledger up to
     * $through, in whole cents. This is what `balanced` is allowed to forgive
     * — no more, and normally nothing at all.
     *
     * The two guards used to contradict each other. JournalService::
     * assertBalanced posts a journal whose debit and credit differ by up to
     * one cent, deliberately and test-locked (JournalServiceTest::
     * test_the_one_cent_balance_tolerance_is_honoured). The trial balance
     * hard-coded the SAME 0.01 against the sum of every account in the ledger,
     * and PeriodCloseService::itemTrialBalance turns that one boolean into a
     * self::BLOCK that close() throws on before it ever reaches the
     * acknowledge path — so no override can clear it. Two adjusting JVs of
     * Dr 6-4100 100,01 / Cr 1-1210 100,00, each of which the posting guard was
     * written to accept, put the ledger Rp 0,02 out and wedged that month's
     * close permanently, and because periods close oldest-first every later
     * month with it. The only exit was keying a THIRD deliberately unbalanced
     * journal in the opposite direction, which no screen suggests.
     *
     * WHICH GUARD IS RIGHT: the per-journal one. Rp 0,01 is sub-rupiah in a
     * currency with no circulating cent, and a report is not entitled to
     * refuse a residue its own posting layer manufactured. So the report stops
     * guessing at the number and derives it from the guard instead — ONE CENT
     * PER JOURNAL THAT CARRIES A GAP, capped at one cent each, which is
     * assertBalanced's `> 1` allowance restated per document.
     *
     * The cap is the load-bearing half, and it is why this is not simply the
     * sum of the gaps. itemTrialBalance's own docblock says the break it
     * defends against is "reachable in practice only through direct database
     * surgery"; a journal edited in the database to be Rp 1.000 out would, on
     * a sum-of-gaps basis, have raised the tolerance by exactly its own
     * corruption and forgiven itself. Capped, it contributes the one cent the
     * posting guard would have allowed and the other 99.999 still fail the
     * flag.
     *
     * When every journal balances exactly — the normal case, and the only case
     * any internal caller can produce — this returns 0 and `balanced` is
     * exact. The loud break stays loud: flipping is_postable on 1-1220 drops
     * Rp 10.767.000.000 of posted bank cash out of the report, which is
     * 1.076.700.000.000 cents past anything two decimal columns can excuse.
     *
     * Whole cents, for the reason assertBalanced's own comment gives: in
     * IEEE-754 abs(10999545100.00 - 10999545100.01) is 0.010000228881835938,
     * so the old `<= 0.01` did not actually grant even the single cent it was
     * written to grant at ledger magnitude.
     */
    private function toleratedResidueCents(?Carbon $through): int
    {
        // HAVING keeps the normal ledger — every journal balanced — at zero
        // returned rows; only journals that really are out come back.
        // A float-noise false positive costs nothing: it contributes 0 cents.
        $unbalanced = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->when($through !== null, fn ($query) => $query->whereDate('fin_journals.journal_date', '<=', $through->toDateString()))
            ->selectRaw('fin_journal_lines.journal_id, SUM(fin_journal_lines.debit) as debit, SUM(fin_journal_lines.credit) as credit')
            ->groupBy('fin_journal_lines.journal_id')
            ->havingRaw('SUM(fin_journal_lines.debit) <> SUM(fin_journal_lines.credit)')
            ->get();

        $cents = 0;

        foreach ($unbalanced as $journal) {
            $cents += min(abs((int) round(((float) $journal->debit - (float) $journal->credit) * 100)), 1);
        }

        return $cents;
    }

    /**
     * RAP budget totals per cost category for the project's newest approved
     * cost budget; null when Estimation is absent or has no approved RAP.
     */
    private function rapBudgetByCategory(int $projectId): ?array
    {
        if (! class_exists(CostBudget::class)) {
            return null;
        }

        $budget = CostBudget::query()
            ->where('project_id', $projectId)
            ->where('status', DocumentStatus::Approved->value)
            ->orderByDesc('id')
            ->first();

        if ($budget === null) {
            return null;
        }

        $totals = $budget->items()
            ->selectRaw('cost_category, SUM(amount) as total')
            ->groupBy('cost_category')
            ->pluck('total', 'cost_category');

        $result = [];

        foreach (CostCategory::cases() as $category) {
            $result[$category->value] = round((float) ($totals[$category->value] ?? 0), 2);
        }

        return $result;
    }
}
