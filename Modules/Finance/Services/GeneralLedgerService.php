<?php

namespace Modules\Finance\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\JournalLine;

/**
 * Buku besar — the journal lines behind ONE account's balance, in date order,
 * with a running balance.
 *
 * This is the drill-down the trial balance never had. Until now the only
 * answer the product could give to "why is 1-1400 Persediaan Material
 * Rp 332.510.000?" was the trial balance's own closing column; nobody could
 * see that the figure is Rp 351.250.000 received on GRN/2026/VII/0001 less
 * Rp 18.740.000 issued on ISS/2026/VII/0001. An accountant asked to explain a
 * balance had to read fin_journal_lines by hand.
 *
 * THE NUMBER THAT MAKES IT TRUSTWORTHY. Over a whole fiscal month this
 * report's closing balance is EXACTLY the figure ReportService::trialBalance()
 * prints for the same account and month — same predicates (posted only, not
 * soft-deleted, whereDate window), same rounding order (round the opening net,
 * round each movement side, then round their sum). A drill-down that lands on
 * a different total from the report it drills into is worse than no drill-down
 * at all: it makes the reader distrust both. GeneralLedgerTest pins the
 * agreement account by account.
 *
 * SIGN. Every balance here follows the account's OWN normal side
 * (Account::signedBalance): 5-1100 Beban Material reads +228.240.000 debit-
 * positive, 2-1300 PPN Keluaran reads +1.067.000.000 credit-positive. A single
 * debit-minus-credit convention would print the PPN payable as a negative
 * number and invite someone to "correct" it. The natural debit/credit split
 * the trial balance uses is returned alongside, so the two reports can be laid
 * side by side.
 *
 * BOUNDED. The rows are paginated and every total is a SQL aggregate; nothing
 * here ever materialises a whole account's history. 1-1400 has four lines in
 * the demo, but a bank account in a busy year has tens of thousands, and the
 * screen must open just as fast on that one.
 */
class GeneralLedgerService
{
    public const DEFAULT_PER_PAGE = 100;

    /**
     * A page ceiling, not a safety cap on the report: the whole point of the
     * pagination is that the browser never receives 50.000 rows at once. The
     * CSV button on the screen walks the pages instead of asking for one huge
     * page.
     */
    public const MAX_PER_PAGE = 500;

    /**
     * Indonesian names for the source documents journals are filed under.
     *
     * Deliberately a fallback map, never a gate: a reference_type absent from
     * here shows its raw string rather than an empty cell, so a document type
     * added by a later module is visible in the ledger on the day it is added
     * instead of silently reading as "no reference".
     */
    private const REFERENCE_LABELS = [
        'ar_invoice' => 'Invoice termin',
        'ap_bill' => 'Tagihan vendor',
        'ar_retention' => 'Retensi piutang',
        'payment' => 'Pembayaran',
        'petty_cash_voucher' => 'Bon kas kecil',
        'kasbon' => 'Kasbon',
        'kasbon_settlement' => 'Penyelesaian kasbon',
        'goods_receipt' => 'Penerimaan barang',
        'inventory_issue' => 'Pengeluaran barang',
        'stock_adjustment' => 'Penyesuaian stok (opname)',
        'payroll_run' => 'Payroll',
        'depreciation_run' => 'Penyusutan aset',
        'asset_disposal' => 'Pelepasan aset',
        'revenue_recognition' => 'Pengakuan pendapatan (PSAK 115)',
        'opening_stock_reclass' => 'Reklasifikasi saldo awal persediaan',
        'inventory_issue_cost_reclass' => 'Reklasifikasi biaya pemakaian',
    ];

    /**
     * One account's ledger over a date window.
     *
     * @param  int  $accountId  the COA account being explained
     * @param  string  $from  inclusive first date
     * @param  string  $to  inclusive last date
     * @param  ?int  $projectId  narrow to one project's lines (see below)
     * @return array<string, mixed>
     */
    public function ledger(
        int $accountId,
        string $from,
        string $to,
        ?int $projectId = null,
        int $page = 1,
        ?int $perPage = null,
    ): array {
        $account = Account::query()->find($accountId);

        if ($account === null) {
            throw new LogicException("Akun #{$accountId} tidak ditemukan di bagan akun.");
        }

        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->startOfDay();

        if ($toDate->lessThan($fromDate)) {
            throw new LogicException('Tanggal akhir tidak boleh mendahului tanggal awal.');
        }

        $perPage = max(1, min($perPage ?? self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // Everything before the window, in one aggregate. The opening balance
        // carries the SAME project filter as the rows: a ledger narrowed to
        // PRJ-2026-001 that opened at the company-wide balance would show a
        // running balance no line in it explains, and its closing figure would
        // tie to nothing at all.
        $opening = $this->sums($account->id, null, $fromDate->copy()->subDay(), $projectId);
        $movement = $this->sums($account->id, $fromDate, $toDate, $projectId);

        // Rounded in the same order trialBalance() rounds: the opening net
        // first, each movement side next, their sum last. Same order, same
        // cent — this is what makes the two reports agree exactly rather than
        // to within a rounding step.
        $openNet = round($opening['debit'] - $opening['credit'], 2);
        $moveDebit = round($movement['debit'], 2);
        $moveCredit = round($movement['credit'], 2);
        $closeNet = round($openNet + $moveDebit - $moveCredit, 2);

        $rows = $this->page($account->id, $fromDate, $toDate, $projectId, $offset, $perPage);
        $total = $this->countLines($account->id, $fromDate, $toDate, $projectId);

        // The running balance must CONTINUE on page 2, not restart. A ledger
        // whose second page opens again at the period's opening balance is a
        // lie in the one column people read it for — so the rows skipped by
        // the offset are summed in SQL and carried in.
        $carried = $this->carriedForward($account->id, $fromDate, $toDate, $projectId, $offset);
        $running = round($openNet + $carried['debit'] - $carried['credit'], 2);

        $lines = [];

        foreach ($rows as $row) {
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);
            $running = round($running + $debit - $credit, 2);

            $lines[] = [
                'journal_id' => (int) $row->journal_id,
                'journal_code' => $row->journal_code,
                'journal_date' => Carbon::parse($row->journal_date)->toDateString(),
                // The line's own narration when it has one, else the journal's:
                // autoPost() writes a per-line description on most documents,
                // but a hand-keyed JV may carry it only on the header, and a
                // ledger row with an empty "Keterangan" is unreadable.
                'description' => $row->line_description ?: $row->journal_description,
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id !== null ? (int) $row->reference_id : null,
                'reference_label' => $this->referenceLabel($row->reference_type),
                'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
                'project_code' => $row->project_code,
                'project_name' => $row->project_name,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $this->signed($account, $running),
            ];
        }

        $lastPage = (int) max(1, (int) ceil($total / $perPage));

        return [
            'account' => [
                'id' => (int) $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type?->value,
                'account_type_label' => $account->account_type?->label(),
                'normal_balance' => $account->normal_balance?->value,
                // Carried so the screen can say why a balance the ledger can
                // prove is missing from the trial balance: every report
                // iterates postable accounts only, so an account flipped to
                // "tidak dapat diposting" keeps its lines here and disappears
                // from the neraca saldo — the one state nothing else in the
                // product points at.
                'is_postable' => (bool) $account->is_postable,
            ],
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'project_id' => $projectId,
            'opening' => $this->signed($account, $openNet),
            'opening_debit' => $this->debitOf($openNet),
            'opening_credit' => $this->creditOf($openNet),
            // Where THIS page's running balance starts — the period's opening
            // balance on page 1, the carried-forward balance after that. The
            // screen prints it as the "Saldo pindahan" row, so a reader who
            // lands on page 4 can see the running column is a continuation
            // rather than a fresh count from zero.
            'page_opening' => $this->signed($account, round($openNet + $carried['debit'] - $carried['credit'], 2)),
            'movement' => ['debit' => $moveDebit, 'credit' => $moveCredit],
            // Computed from the aggregate, NOT from the last row's running
            // balance: on page 3 of 7 the last row is nowhere near the end of
            // the period, and the header must still show the closing balance
            // of the whole window.
            'closing' => $this->signed($account, $closeNet),
            'closing_debit' => $this->debitOf($closeNet),
            'closing_credit' => $this->creditOf($closeNet),
            'rows' => $lines,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * The account's normal side applied to a debit-positive net.
     *
     * 6-4100 Beban Umum & Administrasi and 2-1300 PPN Keluaran read opposite
     * ways: the expense is debit-normal, the tax payable credit-normal, and
     * printing both as debit-minus-credit would show a perfectly ordinary
     * Rp 1.067.000.000 PPN liability as minus a miliar.
     */
    private function signed(Account $account, float $net): float
    {
        return $account->signedBalance($this->debitOf($net), $this->creditOf($net));
    }

    /**
     * The debit/credit split of a net figure — the same two columns
     * trialBalance() prints, so the reader can lay the ledger's footer next to
     * the neraca saldo row and compare cell by cell.
     *
     * The `+ 0` is the one format.js already applies for the screen: PHP's
     * max(-0.0, 0.0) is -0.0 and json_encode writes it as "-0", so an account
     * whose opening balance is exactly nothing would answer the API with a
     * negative zero.
     */
    private function debitOf(float $net): float
    {
        return max($net, 0.0) + 0;
    }

    private function creditOf(float $net): float
    {
        return max(-$net, 0.0) + 0;
    }

    private function referenceLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        if (isset(self::REFERENCE_LABELS[$type])) {
            return self::REFERENCE_LABELS[$type];
        }

        // Cancellations are minted as "<original>_cancellation" by
        // JournalService::reverseFor(), so they are named from their original
        // rather than listed one by one — a reversal must never read as an
        // unlabelled stranger next to the entry it reverses.
        if (str_ends_with($type, '_cancellation')) {
            $origin = substr($type, 0, -strlen('_cancellation'));

            return (self::REFERENCE_LABELS[$origin] ?? $origin).' (pembatalan)';
        }

        return $type;
    }

    /**
     * Debit/credit sums over POSTED, non-deleted journal lines of one account.
     *
     * Both bounds are INCLUSIVE, so the opening sum is taken with $from null
     * and $to = the day before the window — literally what trialBalance() does
     * (`sumsPerAccount(null, $periodStart->copy()->subDay())`).
     *
     * The predicates are ReportService::sumsPerAccount()'s, line for line —
     * status posted, deleted_at null, inclusive whereDate bounds. They are
     * repeated rather than shared because the two reports must be provably
     * identical, and a helper either report could quietly change is the shape
     * that lets them drift apart.
     *
     * @return array{debit: float, credit: float}
     */
    private function sums(int $accountId, ?Carbon $from, Carbon $to, ?int $projectId): array
    {
        $row = JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journal_lines.account_id', $accountId)
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->when($from !== null, fn ($query) => $query->whereDate('fin_journals.journal_date', '>=', $from->toDateString()))
            ->whereDate('fin_journals.journal_date', '<=', $to->toDateString())
            ->when($projectId !== null, fn ($query) => $query->where('fin_journal_lines.project_id', $projectId))
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) as debit, COALESCE(SUM(fin_journal_lines.credit), 0) as credit')
            ->first();

        return ['debit' => (float) ($row->debit ?? 0), 'credit' => (float) ($row->credit ?? 0)];
    }

    /**
     * The window's lines, one page of them, oldest first.
     *
     * Ordered by date then journal id then line id: two journals dated the
     * same day must always come back in the same order, or the row that closes
     * page 1 could reappear at the top of page 2 and the running balance would
     * count it twice.
     *
     * @return Collection<int, object>
     */
    private function page(int $accountId, Carbon $from, Carbon $to, ?int $projectId, int $offset, int $perPage): Collection
    {
        return $this->window($accountId, $from, $to, $projectId)
            ->leftJoin('prj_projects', 'prj_projects.id', '=', 'fin_journal_lines.project_id')
            ->select([
                'fin_journal_lines.id',
                'fin_journal_lines.debit',
                'fin_journal_lines.credit',
                'fin_journal_lines.description as line_description',
                'fin_journal_lines.project_id',
                'fin_journals.id as journal_id',
                'fin_journals.code as journal_code',
                'fin_journals.journal_date',
                'fin_journals.description as journal_description',
                'fin_journals.reference_type',
                'fin_journals.reference_id',
                'prj_projects.code as project_code',
                'prj_projects.name as project_name',
            ])
            ->orderBy('fin_journals.journal_date')
            ->orderBy('fin_journals.id')
            ->orderBy('fin_journal_lines.id')
            ->offset($offset)
            ->limit($perPage)
            ->get();
    }

    private function countLines(int $accountId, Carbon $from, Carbon $to, ?int $projectId): int
    {
        return (int) $this->window($accountId, $from, $to, $projectId)->count('fin_journal_lines.id');
    }

    /**
     * Debit/credit of the rows the current page SKIPS.
     *
     * Summed inside the database over an ordered, LIMITed subquery — reading
     * the skipped rows into PHP to add them up would put 49.900 rows in memory
     * to render page 500, which is the very thing the pagination exists to
     * prevent.
     *
     * @return array{debit: float, credit: float}
     */
    private function carriedForward(int $accountId, Carbon $from, Carbon $to, ?int $projectId, int $offset): array
    {
        if ($offset === 0) {
            return ['debit' => 0.0, 'credit' => 0.0];
        }

        $skipped = $this->window($accountId, $from, $to, $projectId)
            ->select(['fin_journal_lines.debit as debit', 'fin_journal_lines.credit as credit'])
            ->orderBy('fin_journals.journal_date')
            ->orderBy('fin_journals.id')
            ->orderBy('fin_journal_lines.id')
            ->limit($offset);

        $row = DB::query()
            ->fromSub($skipped, 'terlewati')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return ['debit' => (float) ($row->debit ?? 0), 'credit' => (float) ($row->credit ?? 0)];
    }

    /**
     * The posted lines of one account inside the window — the one query shape
     * the page, the count and the carried-forward sum all build on, so they
     * cannot disagree about which rows the ledger contains.
     */
    private function window(int $accountId, Carbon $from, Carbon $to, ?int $projectId): Builder
    {
        return JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journal_lines.account_id', $accountId)
            // Drafts are not the ledger. A Rp 999.000.000 draft JV is visible
            // in the journal list and must stay invisible here, exactly as it
            // is to the trial balance.
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->whereDate('fin_journals.journal_date', '>=', $from->toDateString())
            ->whereDate('fin_journals.journal_date', '<=', $to->toDateString())
            ->when($projectId !== null, fn ($query) => $query->where('fin_journal_lines.project_id', $projectId));
    }
}
