<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Finance\Enums\PeriodStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\RevenueRecognitionLine;
use Modules\Finance\Models\RevenueRecognitionRun;
use Modules\Finance\Support\PeriodCostInputs;

/**
 * Pengakuan pendapatan PSAK 115 — persentase penyelesaian.
 *
 * Full reasoning in docs/KEBIJAKAN-PENDAPATAN.md; the mechanics in one breath:
 * invoices keep crediting revenue when approved (billing basis — the tax regime
 * genuinely works that way), and once a period this service posts ONE adjusting
 * journal that moves the ledger to earned revenue:
 *
 *     % kemajuan          = biaya kumulatif / EAC          (cost-to-cost, B18)
 *     pendapatan kumulatif = % × harga transaksi
 *     saldo kontrak        = pendapatan kumulatif − tertagih kumulatif
 *         > 0  →  1-1360 Aset Kontrak
 *         < 0  →  2-1410 Liabilitas Kontrak
 *
 * The journal per contract is the DELTA against the last POSTED run, so the
 * first run is automatically the cumulative catch-up and every later run is
 * incremental. A CCO needs no restatement — cumulative progress × the new
 * price IS the catch-up PSAK 115 para 21(b) prescribes for a non-distinct
 * modification — but it does need a CUT-OFF, and so do the billings: every
 * basis this service measures a period on is read AS AT THE PERIOD END, never
 * as it stands at calculation time. See transactionPriceAt() and billingsAt().
 *
 * EAC discipline, in order of trust:
 *   override        management's number for this run (reviewed judgement)
 *   rap_approved    an approved RAP for EVERY project that carries cost
 *   rap_unapproved  as above, but one of them is still in workflow — flagged
 *   none            no estimate, or one covering only part of the cost base →
 *                   para 45: zero-margin (revenue = cost, capped at price)
 *                   until an estimate exists
 * and in every case EAC >= cost to date (progress is capped at 100%).
 *
 * Onerous contracts are PSAK 237, not 115: when EAC > price, the FULL expected
 * loss is provided at once. The provision balance is (EAC − price) × (1 − %):
 * the elapsed portion of the loss already sits in P&L as negative margin.
 */
class RevenueRecognitionService
{
    public const CONTRACT_ASSET_ACCOUNT = '1-1360';

    public const CONTRACT_LIABILITY_ACCOUNT = '2-1410';

    public const PROVISION_ACCOUNT = '2-1700';

    public const PROVISION_EXPENSE_ACCOUNT = '5-1600';

    /** Billing-basis maintenance already approximates straight-line — skipped. */
    private const RECOGNISED_SCOPES = ['construction', 'system_integration'];

    private const REVENUE_ACCOUNTS = [
        'construction' => '4-1100',
        'system_integration' => '4-1200',
    ];

    public function __construct(private readonly JournalService $journals) {}

    /**
     * Create (or refresh) the draft run for a period and compute every line.
     *
     * @param  array<int, float>  $eacOverrides  contract_id => management's EAC
     */
    public function calculate(int $year, int $month, ?User $by = null, array $eacOverrides = []): RevenueRecognitionRun
    {
        if ($month < 1 || $month > 12) {
            throw new LogicException('Bulan periode harus 1–12.');
        }

        // Only COMPLETED periods are recognised. A typo'd December would sweep
        // the whole year into one journal and — because posting is append-only
        // and posted journals cannot be deleted — wedge every month in between
        // until a manual restatement.
        $periodEnd = sprintf('%04d-%02d-%02d', $year, $month,
            cal_days_in_month(CAL_GREGORIAN, $month, $year));

        if ($periodEnd > now()->toDateString()) {
            throw new LogicException("Periode {$year}-{$month} belum berakhir — pengakuan dihitung setelah periode selesai.");
        }

        return DB::transaction(function () use ($year, $month, $by, $eacOverrides): RevenueRecognitionRun {
            $existing = RevenueRecognitionRun::query()
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->first();

            if ($existing !== null && $existing->isPosted()) {
                throw new LogicException("Periode {$year}-{$month} sudah diposting ({$existing->code}). Pembatalan pengakuan adalah penyajian kembali — bukan hitung ulang.");
            }

            $run = $existing ?? new RevenueRecognitionRun([
                'period_year' => $year,
                'period_month' => $month,
            ]);
            $run->status = PostingStatus::Draft;
            $run->created_by = $by?->id ?? $run->created_by;
            $run->save();

            // A reviewed EAC survives recalculation unless explicitly replaced:
            // management's judgement should not evaporate because a cost arrived
            // and somebody pressed "hitung ulang".
            $kept = $run->lines()
                ->where('eac_source', 'override')
                ->pluck('estimated_total_cost', 'contract_id')
                ->map(fn ($value): float => (float) $value)
                ->all();
            $eacOverrides += $kept;

            // Recalculation replaces the draft's lines wholesale: the inputs
            // (costs, invoices, CCOs) may all have moved since last time.
            $run->lines()->delete();

            $periodEnd = $run->periodEnd();
            $previous = $this->previousBalances($run);
            $total = 0.0;

            foreach ($this->contractsInScope() as $contract) {
                $line = $this->computeLine($contract, $periodEnd, $eacOverrides[$contract->id] ?? null, $previous[$contract->id] ?? null);
                $run->lines()->save($line);
                $total += (float) $line->revenue_adjustment + (float) $line->provision_adjustment;
            }

            // A contract that appeared in the last posted run but has left the
            // scope (closed and settled) still needs its balances unwound.
            foreach ($previous as $contractId => $balances) {
                if ($run->lines()->where('contract_id', $contractId)->exists()) {
                    continue;
                }

                if (abs($balances['contract_balance']) < 0.01 && abs($balances['provision_balance']) < 0.01) {
                    continue;
                }

                $contract = Contract::withTrashed()->find($contractId);

                if ($contract === null) {
                    continue;
                }

                $line = $this->computeLine($contract, $periodEnd, null, $balances);
                $run->lines()->save($line);
                $total += (float) $line->revenue_adjustment + (float) $line->provision_adjustment;
            }

            $run->forceFill(['total_adjustment' => round($total, 2)])->save();

            return $run->refresh()->load('lines.contract');
        });
    }

    /**
     * Post the period's single adjusting journal and lock the run.
     */
    public function post(RevenueRecognitionRun $run, User $by): RevenueRecognitionRun
    {
        return DB::transaction(function () use ($run, $by): RevenueRecognitionRun {
            $run = RevenueRecognitionRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->isPosted()) {
                throw new LogicException("Run {$run->code} sudah diposting.");
            }

            /*
             * COST FIRST, THEN PROGRESS. Cost-to-cost divides cumulative cost
             * by EAC, so posting this run while June's payroll (Rp 196.270.346
             * of wages) or June's depreciation (Rp 25.125.000) is still a draft
             * measures progress on a cost base that is short by both — and the
             * revenue recognised is understated by the same proportion. The run
             * cannot be recalculated afterwards, so that understatement is
             * permanent in June and lands as a catch-up in July.
             *
             * Only runs that EXIST but are unposted block. A month with no
             * payroll run at all still posts: a control the business cannot
             * satisfy is as bad as no control. The close checklist reports that
             * absence as an overridable warning instead.
             */
            $pending = PeriodCostInputs::pending($run->period_year, $run->period_month);

            if ($pending !== []) {
                $named = implode(', ', array_map(
                    fn (array $item): string => $item['label'].' '.$item['code'],
                    array_slice($pending, 0, 3),
                ));

                throw new LogicException(sprintf(
                    '%s untuk periode %04d-%02d belum diposting. Biaya bulan ini belum lengkap, sehingga '
                        .'persentase penyelesaian akan understated — posting payroll dan penyusutan lebih dulu.',
                    $named, $run->period_year, $run->period_month,
                ));
            }

            // History is append-only: posting June after July is already booked
            // would silently rewrite July's opening position.
            $later = RevenueRecognitionRun::query()
                ->where('status', PostingStatus::Posted->value)
                ->where(function ($query) use ($run): void {
                    $query->where('period_year', '>', $run->period_year)
                        ->orWhere(function ($sub) use ($run): void {
                            $sub->where('period_year', $run->period_year)
                                ->where('period_month', '>', $run->period_month);
                        });
                })
                ->orderBy('period_year')->orderBy('period_month')
                ->first();

            if ($later !== null) {
                throw new LogicException("Periode {$later->period_year}-{$later->period_month} sudah diposting; periode yang lebih lama tidak dapat diposting setelahnya.");
            }

            // Even a no-movement run may not close a period the ledger has shut
            // — autoPost would catch it, but only when there are legs.
            $open = FiscalPeriod::query()
                ->where('year', $run->period_year)
                ->where('month', $run->period_month)
                ->where('status', PeriodStatus::Open->value)
                ->exists();

            if (! $open) {
                throw new LogicException("Periode fiskal {$run->period_year}-{$run->period_month} tidak terbuka.");
            }

            /*
             * THE DRAFT ON SCREEN IS NEVER WHAT GETS POSTED — the period is
             * recomputed from the database inside this transaction, keeping
             * only management's EAC overrides. A draft is a preview: its
             * stored deltas were measured against whichever run was last
             * posted WHEN IT WAS CALCULATED, and between then and now another
             * period may have posted, a cost may have landed, a CCO may have
             * been approved. Posting stored numbers double-counted the
             * cumulative catch-up in exactly that sequence; recomputing makes
             * the race unrepresentable instead of merely unlikely.
             */
            $run = $this->calculate($run->period_year, $run->period_month, $by);
            $run = RevenueRecognitionRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            $legs = $this->journalLegs($run);

            if ($legs === []) {
                // Nothing moved — still lock the run so the period is answered.
                $run->forceFill([
                    'status' => PostingStatus::Posted,
                    'posted_by' => $by->id,
                    'posted_at' => now(),
                ])->save();

                return $run->refresh();
            }

            $this->journals->autoPost(
                'revenue_recognition',
                (int) $run->id,
                $legs,
                $run->periodEnd(),
                "Pengakuan pendapatan PSAK 115 {$run->code} — periode {$run->period_year}-".str_pad((string) $run->period_month, 2, '0', STR_PAD_LEFT),
                (int) $by->id,
            );

            $run->forceFill([
                'status' => PostingStatus::Posted,
                'posted_by' => $by->id,
                'posted_at' => now(),
            ])->save();

            return $run->refresh();
        });
    }

    public function delete(RevenueRecognitionRun $run): void
    {
        DB::transaction(function () use ($run): void {
            // Re-fetched under the same lock discipline as post(): a stale
            // instance from before a concurrent posting must not delete the
            // run whose journal is now in the ledger.
            $run = RevenueRecognitionRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->isPosted()) {
                throw new LogicException('Run yang sudah diposting tidak dapat dihapus — jurnalnya ada di buku besar.');
            }

            $run->lines()->delete();
            $run->delete();
        });
    }

    // ------------------------------------------------------------ calculation

    private function contractsInScope()
    {
        return Contract::query()
            ->whereIn('scope_type', self::RECOGNISED_SCOPES)
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Closed->value])
            ->orderBy('code')
            ->get();
    }

    private function computeLine(Contract $contract, string $periodEnd, ?float $eacOverride, ?array $previous): RevenueRecognitionLine
    {
        $scope = $contract->scope_type instanceof \BackedEnum
            ? $contract->scope_type->value
            : (string) $contract->scope_type;

        /*
         * ALL of the contract's projects, and their types. Costs are booked per
         * project while billing is per contract; a contract split over two site
         * projects that only counted one project's costs would understate
         * progress every month. Soft-deleted projects still carry real costs,
         * so they stay in the cost base (falling out of it would "un-earn"
         * recognised revenue the moment somebody archives a finished project).
         */
        $projects = DB::table('prj_projects')
            ->where('contract_id', $contract->id)
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get(['id', 'type', 'deleted_at']);

        $projectIds = $projects->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $primaryProject = $projects->first();

        $price = $this->transactionPriceAt($contract, $periodEnd);

        /*
         * PER PROJECT, not one lump sum. estimateTotalCost() has to know WHICH
         * projects the numerator was built from before it can tell whether the
         * denominator covers them — see the coverage guard there.
         */
        $costPerProject = $this->costsPerProject($projectIds, $periodEnd);
        $cost = round(array_sum($costPerProject), 2);

        [$eac, $eacSource] = $this->estimateTotalCost($costPerProject, $cost, $eacOverride);

        if ($eac !== null) {
            $pct = $eac > 0 ? min(1.0, $cost / $eac) : 0.0;
            $earned = round($pct * $price, 2);
        } else {
            // Para 45: outcome not reliably measurable — zero-margin. Revenue
            // equals recoverable cost, never more than the price. The stored
            // pct is the revenue ratio, and the UI labels the line "margin
            // nol" rather than presenting it as measured progress.
            $pct = $price > 0 ? min(1.0, $cost / $price) : 0.0;
            $earned = round(min($cost, $price), 2);
        }

        $billings = $this->billingsAt($contract, $scope, $periodEnd);
        $billed = $billings['total'];

        $balance = round($earned - $billed, 2);

        // PSAK 237: full expected loss, immediately, net of the part already
        // recognised through negative margin. (Without a reliable EAC there is
        // no quantifiable loss to provide — the zero-margin basis already
        // recognises no profit.)
        $provision = 0.0;

        if ($eac !== null && $eac > $price) {
            $provision = round(($eac - $price) * (1 - $pct), 2);
        }

        $prevBalance = $previous['contract_balance'] ?? 0.0;
        $prevProvision = $previous['provision_balance'] ?? 0.0;

        return new RevenueRecognitionLine([
            'contract_id' => $contract->id,
            'project_id' => $primaryProject?->id,
            'scope_type' => $scope,
            'revenue_account' => $this->revenueAccountFor($billings['by_account'], $primaryProject?->type, $scope),
            'transaction_price' => $price,
            'estimated_total_cost' => $eac ?? 0.0,
            'eac_source' => $eacSource,
            'cost_to_date' => $cost,
            'progress_pct' => round($pct * 100, 4),
            'revenue_cumulative' => $earned,
            'billed_cumulative' => $billed,
            'contract_balance' => $balance,
            'provision_balance' => $provision,
            'revenue_adjustment' => round($balance - $prevBalance, 2),
            'provision_adjustment' => round($provision - $prevProvision, 2),
        ]);
    }

    /**
     * The transaction price AS AT PERIOD END.
     *
     * crm_contracts.value already carries every approved change order, whenever
     * it was approved, because ContractChangeOrderService::approve() writes the
     * new value straight onto the contract. Month-end close runs here in the
     * first week of the following month, so a CCO of Rp 400.000.000 signed on
     * 2 August was already on the contract when the July run was calculated on
     * 3 August: July gained Rp 200.000.000 of revenue (50% progress × the CCO)
     * for scope that did not exist on 31 July, on a journal dated 2026-07-31.
     * Posting that run measures July, so the period can never be reopened and
     * the run can never be recalculated (calculate() refuses a posted period) —
     * two income statements permanently wrong, July over and August under.
     *
     * PSAK 115 para 18-21 accounts for a modification when it is APPROVED, so
     * change orders dated after the period ended are peeled back off the
     * current value. The para 21(b) cumulative catch-up still happens — one
     * period later, in the month the scope was actually agreed. change_date is
     * the same basis cost_date and invoice_date are cut off on two methods
     * below, and whereDate for the same reason.
     */
    private function transactionPriceAt(Contract $contract, string $periodEnd): float
    {
        $laterChanges = round((float) DB::table('crm_contract_change_orders')
            ->where('contract_id', $contract->id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('deleted_at')
            ->whereDate('change_date', '>', $periodEnd)
            ->sum('value_change'), 2);

        return round((float) $contract->value - $laterChanges, 2);
    }

    /**
     * Cost to date PER PROJECT, so coverage can be judged against it.
     *
     * whereDate, NOT a raw string compare. cost_date and invoice_date are cast
     * `date` on their models, so Eloquent stores them as "2026-06-30 00:00:00"
     * — which sorts AFTER the bare "2026-06-30" this method receives as
     * $periodEnd. A raw <= therefore dropped every row dated on the LAST day of
     * the period: June's payroll (Rp 196.270.346) and depreciation
     * (Rp 25.125.000) are dated at month end BY DESIGN, and a month-end termin
     * invoice of Rp 9.700.000.000 missing from billed_cumulative overstated
     * June revenue by exactly that amount. Same bug, same fix as
     * DanglingDocuments::scan and the whereDate cutoffs in
     * BankStatementMatchService.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, float> every project of the contract, 0.0 included
     */
    private function costsPerProject(array $projectIds, string $periodEnd): array
    {
        $costs = array_fill_keys($projectIds, 0.0);

        if ($projectIds === []) {
            return $costs;
        }

        $rows = DB::table('fin_project_costs')
            ->whereIn('project_id', $projectIds)
            ->whereDate('cost_date', '<=', $periodEnd)
            ->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount) as total')
            ->get();

        foreach ($rows as $row) {
            $costs[(int) $row->project_id] = round((float) $row->total, 2);
        }

        return $costs;
    }

    /**
     * The contract's billings AS AT PERIOD END — on BOTH axes.
     *
     * This query used to filter status as of NOW while filtering invoice_date
     * as of period end, and the mismatch cost two income statements. A wrong
     * termin invoice of Rp 400.000.000 dated 15 March, measured by a posted
     * March run, is cancelled in August: JournalService::reversalDate() moves
     * the reversal to TODAY precisely because March is measured, but the next
     * POC run recomputed billings live, found none, and re-credited the whole
     * Rp 400.000.000 on a journal dated 30 April — four months before the
     * cancellation that caused it, and understating August by the same amount.
     *
     * So an invoice counts as billed for a period when its billing revenue was
     * still in the ledger at period end, which is exactly the date its own
     * reversal carries. Reading that date rather than cancelled_at matters:
     * when the invoice's month was NOT measured, reversalDate() puts the
     * reversal back on the invoice's own date and the billing was never live
     * at any period end at all — counting it to the cancellation date would
     * have understated that month by the same Rp 400.000.000 in the opposite
     * direction.
     *
     * @return array{total: float, by_account: array<string, float>}
     */
    private function billingsAt(Contract $contract, string $scope, string $periodEnd): array
    {
        $invoices = DB::table('fin_ar_invoices')
            ->where('contract_id', $contract->id)
            ->whereNull('deleted_at')
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Cancelled->value])
            ->whereDate('invoice_date', '<=', $periodEnd)
            ->orderBy('id')
            ->get(['id', 'project_id', 'dpp', 'status', 'cancelled_at']);

        if ($invoices->isEmpty()) {
            return ['total' => 0.0, 'by_account' => []];
        }

        $reversedOn = $this->cancellationDates($invoices
            ->where('status', DocumentStatus::Cancelled->value)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $types = $this->projectTypes($invoices
            ->pluck('project_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all());

        $total = 0.0;
        $byAccount = [];

        foreach ($invoices as $invoice) {
            if ($invoice->status === DocumentStatus::Cancelled->value) {
                // '' when a cancelled invoice carries no trail at all, which
                // sorts below every period end — never billed, the answer the
                // old status filter gave for every cancellation.
                $removedOn = substr((string) ($reversedOn[(int) $invoice->id] ?? $invoice->cancelled_at ?? ''), 0, 10);

                if ($removedOn <= $periodEnd) {
                    continue;
                }
            }

            $dpp = round((float) $invoice->dpp, 2);
            $account = $this->revenueAccountCodeFor($types[(int) $invoice->project_id] ?? null, $scope);

            $total += $dpp;
            $byAccount[$account] = round(($byAccount[$account] ?? 0.0) + $dpp, 2);
        }

        return ['total' => round($total, 2), 'by_account' => $byAccount];
    }

    /**
     * When each cancelled invoice's billing left the ledger — the date of the
     * reversing journal ArInvoiceService::cancel() raised through
     * JournalService::reverseFor('ar_invoice_cancellation', ...).
     *
     * @param  array<int, int>  $invoiceIds
     * @return array<int, string>
     */
    private function cancellationDates(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $dates = [];

        $rows = DB::table('fin_journals')
            ->where('reference_type', 'ar_invoice_cancellation')
            ->whereIn('reference_id', $invoiceIds)
            ->whereNull('deleted_at')
            ->groupBy('reference_id')
            ->selectRaw('reference_id, MIN(journal_date) as reversed_on')
            ->get();

        foreach ($rows as $row) {
            $dates[(int) $row->reference_id] = (string) $row->reversed_on;
        }

        return $dates;
    }

    /**
     * Types for any project an invoice names — which is not necessarily one of
     * the contract's own: ArInvoiceStoreRequest validates project_id as a bare
     * integer. Read through DB::table on purpose, so an archived project still
     * answers for the invoices it carries.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, mixed>
     */
    private function projectTypes(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $types = [];

        foreach (DB::table('prj_projects')->whereIn('id', $projectIds)->get(['id', 'type']) as $row) {
            $types[(int) $row->id] = $row->type;
        }

        return $types;
    }

    /**
     * THE SAME RULE AS ArInvoiceService::revenueAccountCode, ON THE SAME INPUT.
     *
     * The rule alone was not enough, and that was the whole defect: the invoice
     * resolves from ITS OWN project_id while this line resolved from the
     * contract's FIRST project, and the two inputs part company the moment a
     * contract carries more than one project — the shape the suite already
     * supports (test_a_contract_split_over_two_projects_counts_both_cost_bases)
     * and the demo already models with CTR/2026/I/0001 + CTR/2026/II/0002. One
     * manual invoice raised against the ELV site credited 4-1200 Rp 400.000.000
     * while the adjustment debited 4-1100 Rp 150.000.000: total revenue right
     * at Rp 250.000.000, 4-1100 left holding a Rp 150.000.000 DEBIT balance,
     * and the disaggregation disclosure (docs/KEBIJAKAN-PENDAPATAN.md §8) wrong
     * on both streams — exactly what this invariant exists to prevent.
     *
     * So the account is read back off the billings themselves: whichever
     * account the contract's live invoices actually credited. The primary
     * project answers only while nothing has been billed, where there is no
     * invoice to disagree with.
     *
     * KNOWN LIMIT, stated rather than papered over: a contract whose billings
     * span TWO revenue streams cannot be honoured by one leg, because a line
     * carries ONE contract balance, not one per performance obligation. The
     * adjustment follows the stream carrying the most billing, which bounds the
     * residue at the smaller stream instead of leaving it wherever the first
     * project happened to sort. Splitting it properly needs a per-obligation
     * balance, which is a schema change, not a resolution change.
     *
     * @param  array<string, float>  $billedByAccount
     */
    private function revenueAccountFor(array $billedByAccount, mixed $primaryProjectType, string $contractScope): string
    {
        if ($billedByAccount !== []) {
            arsort($billedByAccount);

            return (string) array_key_first($billedByAccount);
        }

        return $this->revenueAccountCodeFor($primaryProjectType, $contractScope);
    }

    /** Konstruksi 4-1100, integrasi 4-1200, pemeliharaan 4-1300. */
    private function revenueAccountCodeFor(mixed $projectType, string $contractScope): string
    {
        $type = $projectType instanceof \BackedEnum ? $projectType->value : $projectType;

        return match ($type ?? $contractScope) {
            'system_integration' => '4-1200',
            'maintenance' => '4-1300',
            default => '4-1100',
        };
    }

    /**
     * @param  array<int, float>  $costPerProject  project_id => cost to date
     * @return array{0: float|null, 1: string}
     */
    private function estimateTotalCost(array $costPerProject, float $costToDate, ?float $override): array
    {
        if ($override !== null) {
            if ($override <= 0) {
                throw new LogicException('EAC harus lebih besar dari nol.');
            }

            return [round(max($override, $costToDate), 2), 'override'];
        }

        if ($costPerProject === []) {
            return [null, 'none'];
        }

        // One RAP per project — approved preferred, otherwise the newest still
        // in workflow. A REJECTED budget is nobody's estimate of anything and
        // must not set the denominator of revenue.
        $total = 0.0;
        $anyUnapproved = false;
        $found = false;
        $uncovered = 0.0;

        foreach ($costPerProject as $projectId => $projectCost) {
            $rap = DB::table('est_cost_budgets')
                ->where('project_id', $projectId)
                ->whereNull('deleted_at')
                ->whereIn('status', ['approved', 'submitted', 'draft'])
                ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
                ->orderByDesc('id')
                ->first();

            if ($rap === null || (float) $rap->total_budget <= 0) {
                $uncovered += $projectCost;

                continue;
            }

            $found = true;
            $total += (float) $rap->total_budget;
            $anyUnapproved = $anyUnapproved || $rap->status !== 'approved';
        }

        /*
         * COVERAGE, not mere presence. computeLine() divides the cost of ALL
         * the contract's projects by this EAC, so a denominator built from only
         * SOME of them measures a numerator it does not cover. On a Rp 1 miliar
         * contract split over two sites — site A with an approved RAP of
         * Rp 400.000.000, site B mobilised later with none, Rp 100.000.000 spent
         * on each — the run read 50% complete and recognised Rp 500.000.000
         * instead of Rp 250.000.000, and stamped the line `rap_approved`, the
         * highest-trust label there is. A posted run can never be recalculated,
         * so that Rp 250.000.000 of premature revenue is permanent in its month.
         *
         * A partial estimate is therefore no estimate: the contract falls to the
         * para 45 zero-margin basis until either the missing RAP is approved or
         * management keys an EAC override for the whole contract, which is the
         * one input that legitimately spans projects. abs(), because a negative
         * uncovered cost (a purchase-price variance credit) breaks the same
         * agreement between numerator and denominator in the other direction.
         */
        if (! $found || abs($uncovered) >= 0.01) {
            return [null, 'none'];
        }

        return [
            round(max($total, $costToDate), 2),
            $anyUnapproved ? 'rap_unapproved' : 'rap_approved',
        ];
    }

    /**
     * Contract balances as at the last POSTED run — the baseline every
     * adjustment is a delta against. No posted run yet means baseline zero,
     * which is exactly what makes the first run the cumulative catch-up.
     *
     * @return array<int, array{contract_balance: float, provision_balance: float}>
     */
    private function previousBalances(RevenueRecognitionRun $current): array
    {
        $lastPosted = RevenueRecognitionRun::query()
            ->where('status', PostingStatus::Posted->value)
            ->where('id', '!=', $current->id)
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->first();

        if ($lastPosted === null) {
            return [];
        }

        // Posting order is enforced, so the last posted run is by construction
        // the latest period; a draft for an EARLIER period is refused here
        // rather than allowed to double-count the baseline.
        $lastKey = $lastPosted->period_year * 100 + $lastPosted->period_month;
        $currentKey = $current->period_year * 100 + $current->period_month;

        if ($lastKey >= $currentKey) {
            throw new LogicException("Periode {$lastPosted->period_year}-{$lastPosted->period_month} sudah diposting; run baru harus periode sesudahnya.");
        }

        return $lastPosted->lines()
            ->get()
            ->keyBy('contract_id')
            ->map(fn (RevenueRecognitionLine $line): array => [
                'contract_balance' => (float) $line->contract_balance,
                'provision_balance' => (float) $line->provision_balance,
            ])
            ->all();
    }

    // ---------------------------------------------------------------- journal

    /**
     * The period's adjusting legs, one set per contract with movement.
     *
     * The asset and liability sides are handled per contract because a balance
     * can cross zero between periods: earned catches up with a down payment and
     * the position walks out of 2-1410 into 1-1360. Splitting the movement per
     * account keeps each account's balance faithful, not just the net.
     */
    private function journalLegs(RevenueRecognitionRun $run): array
    {
        $legs = [];

        foreach ($run->lines as $line) {
            $prevBalance = round((float) $line->contract_balance - (float) $line->revenue_adjustment, 2);
            $newBalance = (float) $line->contract_balance;

            $prevAsset = max(0.0, $prevBalance);
            $newAsset = max(0.0, $newBalance);
            $prevLiability = max(0.0, -$prevBalance);
            $newLiability = max(0.0, -$newBalance);

            $assetDelta = round($newAsset - $prevAsset, 2);
            $liabilityDelta = round($newLiability - $prevLiability, 2);
            $revenueDelta = (float) $line->revenue_adjustment;

            $label = $line->contract?->code ?? "kontrak #{$line->contract_id}";

            if (abs($assetDelta) >= 0.01) {
                $legs[] = [
                    'account_code' => self::CONTRACT_ASSET_ACCOUNT,
                    ($assetDelta > 0 ? 'debit' : 'credit') => abs($assetDelta),
                    'description' => "Aset kontrak {$label}",
                    'project_id' => $line->project_id,
                ];
            }

            if (abs($liabilityDelta) >= 0.01) {
                $legs[] = [
                    'account_code' => self::CONTRACT_LIABILITY_ACCOUNT,
                    ($liabilityDelta > 0 ? 'credit' : 'debit') => abs($liabilityDelta),
                    'description' => "Liabilitas kontrak {$label}",
                    'project_id' => $line->project_id,
                ];
            }

            if (abs($revenueDelta) >= 0.01) {
                $legs[] = [
                    'account_code' => $line->revenue_account,
                    ($revenueDelta > 0 ? 'credit' : 'debit') => abs($revenueDelta),
                    'description' => "Penyesuaian pendapatan PSAK 115 {$label}",
                    'project_id' => $line->project_id,
                ];
            }

            $provisionDelta = (float) $line->provision_adjustment;

            if (abs($provisionDelta) >= 0.01) {
                $legs[] = [
                    'account_code' => self::PROVISION_EXPENSE_ACCOUNT,
                    ($provisionDelta > 0 ? 'debit' : 'credit') => abs($provisionDelta),
                    'description' => "Provisi kerugian kontrak {$label}",
                    'project_id' => $line->project_id,
                ];
                $legs[] = [
                    'account_code' => self::PROVISION_ACCOUNT,
                    ($provisionDelta > 0 ? 'credit' : 'debit') => abs($provisionDelta),
                    'description' => "Provisi kerugian kontrak {$label}",
                    'project_id' => $line->project_id,
                ];
            }
        }

        return $legs;
    }
}
