<?php

namespace Modules\Finance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Finance\Enums\BankStatementDirection;
use Modules\Finance\Enums\BankStatementMatchStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Models\BankStatementLine;

/**
 * Bank reconciliation: bridges the bank's closing balance to the general
 * ledger balance of the bank account, and names every difference.
 *
 * THE BRIDGE
 *
 *     G  =  S + O + B − C − D + E
 *
 *     G  general ledger balance of the bank COA at the cut-off
 *     S  closing balance of the last imported statement at the cut-off
 *     O  opening difference: the GL balance before the first imported
 *        statement, minus that statement's opening balance
 *     B  posted to the books, not yet on the statement — debits (setoran dalam perjalanan)
 *     C  posted to the books, not yet on the statement — credits (pengeluaran belum kliring)
 *     D  on the statement, not on the books — credits (penerimaan belum dicatat)
 *     E  on the statement, not on the books — debits (biaya bank / pengeluaran belum dicatat)
 *
 * The residual R = G − (S + O + B − C − D + E) is zero whenever the data is
 * consistent, and is reported rather than hidden when it is not.
 *
 * MEMBERSHIP IS AS AT A DATE, NOT A FLAG. This is where naive implementations
 * go wrong, so it is written down:
 *
 *  - a statement line counts as cleared only if its counterpart is posted
 *    WITHIN the reconciled window. A bank charge on 28 February that the clerk
 *    books on 3 March is matched, but at a 28 February cut-off it has not
 *    reached the books yet — it is category E, not a residual. That is the most
 *    common workflow there is, and reading match_status as a boolean puts it in
 *    "unexplained" every single month;
 *  - a GL line counts as cleared only if the statement line claiming it belongs
 *    to a statement inside the window. A payment booked on 30 March that the
 *    bank shows on 2 April is a textbook outstanding payment (C), even though
 *    the April statement is imported and the pair is matched.
 *
 * B and C are floored at the first imported statement's period_start. Without
 * that floor they sweep the account's entire GL history — none of which can
 * ever be matched, because no statement line exists for it — and the first
 * thing a new installation sees is its whole opening balance reported as
 * "deposits in transit". That history is already inside O.
 *
 * D and E are scoped to the statement SET, not to line dates, because S is a
 * statement's closing balance: it contains exactly the lines of the statements
 * in the window, including any the bank booked with a date outside the period.
 * Scoping one side by statement and the other by date makes the two sides
 * disagree about which movements exist.
 *
 * Nothing in this class writes to the ledger.
 */
class BankReconciliationService
{
    public function bankAccountFor(BankStatement $statement): BankAccount
    {
        return BankAccount::query()->findOrFail($statement->bank_account_id);
    }

    /**
     * @param  string|null  $asOf  Y-m-d; defaults to the end of the newest imported statement
     */
    public function reconcile(BankAccount $bankAccount, ?string $asOf = null): array
    {
        $this->assertSoleOwnerOfCoaAccount($bankAccount);

        $cutOff = $asOf !== null
            ? Carbon::parse($asOf)->startOfDay()
            : $this->defaultCutOff($bankAccount);

        $chain = $this->chain($bankAccount, $cutOff);

        if ($chain->isEmpty()) {
            throw new LogicException(sprintf(
                'Belum ada rekening koran %s yang diimpor sampai %s.',
                $bankAccount->name,
                $cutOff->toDateString(),
            ));
        }

        $first = $chain->first();
        $last = $chain->last();
        $chainStart = $first->period_start->toDateString();

        $glCents = $this->glBalanceCents($bankAccount, null, $cutOff->toDateString());
        $preCents = $this->glBalanceCents($bankAccount, null, $first->period_start->copy()->subDay()->toDateString());
        $openingCents = $this->cents($first->opening_balance);
        $closingCents = $this->cents($last->closing_balance);

        $lines = $this->chainLines($chain);
        $clearedCounterparts = $this->clearedCounterparts($bankAccount, $lines, $chainStart, $cutOff->toDateString());

        [$onBankNotOnBooks, $unclearedLines] = $this->statementSide($lines, $clearedCounterparts);
        [$onBooksNotOnBank, $unclearedGl] = $this->ledgerSide($bankAccount, $chainStart, $cutOff->toDateString(), $clearedCounterparts);

        $openingDifferenceCents = $preCents - $openingCents - $onBankNotOnBooks['opening_cents'];

        $bridgeCents = $closingCents
            + $openingDifferenceCents
            + $onBooksNotOnBank['debit_cents']
            - $onBooksNotOnBank['credit_cents']
            - $onBankNotOnBooks['credit_cents']
            + $onBankNotOnBooks['debit_cents'];

        $residualCents = $glCents - $bridgeCents;
        $openItems = count($unclearedLines) + count($unclearedGl);

        return [
            'bank_account' => [
                'id' => $bankAccount->id,
                'code' => $bankAccount->code,
                'name' => $bankAccount->name,
                'bank_name' => $bankAccount->bank_name,
                'account_no' => $bankAccount->account_no,
                'coa_code' => $bankAccount->coaAccount?->code,
                'coa_name' => $bankAccount->coaAccount?->name,
            ],
            'as_of' => $cutOff->toDateString(),
            'period' => [
                'start' => $chainStart,
                'end' => $last->period_end->toDateString(),
                'statements' => $chain->map(fn (BankStatement $s): array => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'period_start' => $s->period_start->toDateString(),
                    'period_end' => $s->period_end->toDateString(),
                    'closing_balance' => (float) $s->closing_balance,
                ])->values()->all(),
            ],
            'bridge' => [
                'statement_closing' => $this->money($closingCents),
                'opening_difference' => $this->money($openingDifferenceCents),
                'on_books_not_on_bank_debit' => $this->money($onBooksNotOnBank['debit_cents']),
                'on_books_not_on_bank_credit' => $this->money($onBooksNotOnBank['credit_cents']),
                'on_bank_not_on_books_credit' => $this->money($onBankNotOnBooks['credit_cents']),
                'on_bank_not_on_books_debit' => $this->money($onBankNotOnBooks['debit_cents']),
                'residual' => $this->money($residualCents),
                'gl_balance' => $this->money($glCents),
            ],
            'categories' => [
                'on_books_not_on_bank' => $unclearedGl,
                'on_bank_not_on_books' => $unclearedLines,
            ],
            'possible_mismatches' => $this->possibleMismatches($unclearedLines, $unclearedGl),
            'summary' => [
                'open_items' => $openItems,
                'bridge_closes' => $residualCents === 0,
                // An opening difference is an unexplained gap with no backing
                // rows to inspect. An account carrying one is not reconciled,
                // however tidily the rest of the bridge closes.
                'fully_reconciled' => $residualCents === 0 && $openItems === 0 && $openingDifferenceCents === 0,
                'opening_difference_explained' => $openingDifferenceCents === 0,
                'matched_lines' => $lines->where('match_status', BankStatementMatchStatus::Matched)->count(),
                'total_lines' => $lines->count(),
            ],
        ];
    }

    /**
     * Every bank account and where its reconciliation currently stands — the
     * landing view, so an operator sees which account needs attention without
     * running each report by hand.
     */
    public function overview(?string $asOf = null): array
    {
        $rows = [];

        foreach (BankAccount::query()->with('coaAccount')->where('is_active', true)->orderBy('code')->get() as $account) {
            try {
                $report = $this->reconcile($account, $asOf);
                $rows[] = [
                    'bank_account' => $report['bank_account'],
                    'as_of' => $report['as_of'],
                    'statement_closing' => $report['bridge']['statement_closing'],
                    'gl_balance' => $report['bridge']['gl_balance'],
                    'opening_difference' => $report['bridge']['opening_difference'],
                    'open_items' => $report['summary']['open_items'],
                    'bridge_closes' => $report['summary']['bridge_closes'],
                    'fully_reconciled' => $report['summary']['fully_reconciled'],
                    'blocked' => null,
                ];
            } catch (LogicException $e) {
                $rows[] = [
                    'bank_account' => [
                        'id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'bank_name' => $account->bank_name,
                        'account_no' => $account->account_no,
                        'coa_code' => $account->coaAccount?->code,
                        'coa_name' => $account->coaAccount?->name,
                    ],
                    'as_of' => $asOf,
                    'statement_closing' => null,
                    'gl_balance' => $this->money($this->glBalanceCents($account, null, $asOf ?? now()->toDateString())),
                    'opening_difference' => null,
                    'open_items' => 0,
                    'bridge_closes' => false,
                    'fully_reconciled' => false,
                    'blocked' => $e->getMessage(),
                ];
            }
        }

        return ['as_of' => $asOf, 'rows' => $rows];
    }

    /**
     * The reconciliation reads the ledger by COA account, so two bank accounts
     * sharing one would each report the other's movements as their own timing
     * differences — both wrong, and neither obviously so. The migration adds a
     * unique index where it can; this catches installations where it could not.
     */
    private function assertSoleOwnerOfCoaAccount(BankAccount $bankAccount): void
    {
        $sharing = BankAccount::query()
            ->where('coa_account_id', $bankAccount->coa_account_id)
            ->where('id', '!=', $bankAccount->id)
            ->pluck('name');

        if ($sharing->isNotEmpty()) {
            throw new LogicException(sprintf(
                'Akun COA %s dipakai oleh lebih dari satu rekening bank (%s). Rekonsiliasi membaca buku besar '
                .'per akun COA, jadi angkanya akan tercampur. Pisahkan akun COA-nya lebih dulu.',
                $bankAccount->coaAccount?->code ?? $bankAccount->coa_account_id,
                $sharing->implode(', '),
            ));
        }
    }

    private function defaultCutOff(BankAccount $bankAccount): Carbon
    {
        $latest = BankStatement::query()
            ->where('bank_account_id', $bankAccount->id)
            ->orderByDesc('period_end')
            ->value('period_end');

        return $latest !== null ? Carbon::parse($latest)->startOfDay() : now()->startOfDay();
    }

    /**
     * @return Collection<int, BankStatement>
     */
    private function chain(BankAccount $bankAccount, Carbon $cutOff): Collection
    {
        return BankStatement::query()
            ->where('bank_account_id', $bankAccount->id)
            ->whereDate('period_end', '<=', $cutOff->toDateString())
            ->orderBy('period_start')
            ->get();
    }

    /**
     * @param  Collection<int, BankStatement>  $chain
     * @return Collection<int, BankStatementLine>
     */
    private function chainLines(Collection $chain): Collection
    {
        return BankStatementLine::query()
            ->whereIn('bank_statement_id', $chain->pluck('id'))
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Where every matched counterpart sits relative to the reconciled window.
     *
     * Three buckets, because "matched" is not the same as "cleared as at this
     * date" and both mistakes are expensive:
     *
     *   in_window  — the counterpart is posted inside [chainStart, cutOff]; the
     *                pair cancels and neither side is a reconciling item;
     *   opening    — the counterpart is posted BEFORE the first imported
     *                statement, so the ledger already carries it in the opening
     *                balance. Leaving these in D/E reports a receipt that
     *                cleared months ago as "on the bank, not booked" forever,
     *                and it can never be worked off. They are netted out of the
     *                opening difference instead, which is where they belong;
     *   (neither)  — posted after the cut-off: a genuine timing item.
     *
     * gl_lines carries the bank-account journal line ids claimed by this chain,
     * so the ledger side can test the actual LINE rather than the journal's
     * (reference_type, reference_id) tags — which are not unique enough to key
     * on, and would silently drop a bank leg belonging to some other journal
     * that happened to carry the same tags.
     *
     * @param  Collection<int, BankStatementLine>  $lines
     */
    private function clearedCounterparts(
        BankAccount $bankAccount,
        Collection $lines,
        string $chainStart,
        string $cutOff,
    ): array {
        $matched = $lines->where('match_status', BankStatementMatchStatus::Matched);

        $paymentIds = $matched
            ->where('matched_type', BankStatementLine::MATCH_PAYMENT)
            ->pluck('matched_id')->filter()->all();

        $journalLineIds = $matched
            ->where('matched_type', BankStatementLine::MATCH_JOURNAL_LINE)
            ->pluck('matched_id')->filter()->all();

        $cleared = [
            'payments' => [], 'journal_lines' => [],
            'opening_payments' => [], 'opening_journal_lines' => [],
            'gl_lines' => [], 'dates' => [],
        ];

        if ($paymentIds !== []) {
            // The payment's own bank leg — the row the ledger side will see.
            $rows = DB::table('fin_journal_lines')
                ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
                ->where('fin_journals.reference_type', 'payment')
                ->whereIn('fin_journals.reference_id', $paymentIds)
                ->where('fin_journal_lines.account_id', $bankAccount->coa_account_id)
                ->where('fin_journals.status', PostingStatus::Posted->value)
                ->whereNull('fin_journals.deleted_at')
                ->get(['fin_journal_lines.id', 'fin_journals.reference_id', 'fin_journals.journal_date']);

            foreach ($rows as $row) {
                $date = substr((string) $row->journal_date, 0, 10);
                $id = (int) $row->reference_id;
                $cleared['dates']['payment:'.$id] = $date;
                $cleared['gl_lines'][(int) $row->id] = true;

                if ($date < $chainStart) {
                    $cleared['opening_payments'][$id] = true;
                } elseif ($date <= $cutOff) {
                    $cleared['payments'][$id] = true;
                }
            }
        }

        if ($journalLineIds !== []) {
            $rows = DB::table('fin_journal_lines')
                ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
                ->whereIn('fin_journal_lines.id', $journalLineIds)
                ->where('fin_journals.status', PostingStatus::Posted->value)
                ->whereNull('fin_journals.deleted_at')
                ->get(['fin_journal_lines.id', 'fin_journals.journal_date']);

            foreach ($rows as $row) {
                $date = substr((string) $row->journal_date, 0, 10);
                $id = (int) $row->id;
                $cleared['dates']['journal_line:'.$id] = $date;
                $cleared['gl_lines'][$id] = true;

                if ($date < $chainStart) {
                    $cleared['opening_journal_lines'][$id] = true;
                } elseif ($date <= $cutOff) {
                    $cleared['journal_lines'][$id] = true;
                }
            }
        }

        return $cleared;
    }

    /**
     * @param  Collection<int, BankStatementLine>  $lines
     * @return array{0: array{credit_cents: int, debit_cents: int}, 1: list<array>}
     */
    private function statementSide(Collection $lines, array $cleared): array
    {
        $totals = ['credit_cents' => 0, 'debit_cents' => 0, 'opening_cents' => 0];
        $open = [];

        foreach ($lines as $line) {
            if ($this->lineIsCleared($line, $cleared)) {
                continue;
            }

            // Matched to something the ledger booked before this chain began:
            // already inside the opening balance, so it nets against the opening
            // difference rather than standing as a timing item that can never be
            // worked off.
            if ($this->lineClearedInOpening($line, $cleared)) {
                $totals['opening_cents'] += $line->signedCents();

                continue;
            }

            $cents = $this->cents($line->amount);
            $key = $line->direction === BankStatementDirection::Credit ? 'credit_cents' : 'debit_cents';
            $totals[$key] += $cents;

            $open[] = [
                'kind' => 'statement_line',
                'id' => $line->id,
                'statement_id' => $line->bank_statement_id,
                'date' => $line->entry_date->toDateString(),
                'direction' => $line->direction->value,
                'amount' => $this->money($cents),
                'description' => $line->description,
                'reference' => $line->bank_reference ?: $line->customer_reference,
                'match_status' => $line->match_status->value,
                'note_reason' => $line->note_reason,
                'note_reason_label' => $line->note_reason === null ? null : (BankStatementLine::REASONS[$line->note_reason] ?? $line->note_reason),
                'note' => $line->note,
                // Matched, but the counterpart is not in the books yet at this
                // cut-off — an honest timing item, not an unexamined one.
                'pending_counterpart_date' => $line->isMatched()
                    ? ($cleared['dates'][$line->matched_type.':'.$line->matched_id] ?? null)
                    : null,
            ];
        }

        return [$totals, $open];
    }

    private function lineIsCleared(BankStatementLine $line, array $cleared): bool
    {
        if (! $line->isMatched() || $line->matched_id === null) {
            return false;
        }

        return $line->matched_type === BankStatementLine::MATCH_PAYMENT
            ? isset($cleared['payments'][(int) $line->matched_id])
            : isset($cleared['journal_lines'][(int) $line->matched_id]);
    }

    private function lineClearedInOpening(BankStatementLine $line, array $cleared): bool
    {
        if (! $line->isMatched() || $line->matched_id === null) {
            return false;
        }

        return $line->matched_type === BankStatementLine::MATCH_PAYMENT
            ? isset($cleared['opening_payments'][(int) $line->matched_id])
            : isset($cleared['opening_journal_lines'][(int) $line->matched_id]);
    }

    /**
     * @return array{0: array{debit_cents: int, credit_cents: int}, 1: list<array>}
     */
    private function ledgerSide(BankAccount $bankAccount, string $from, string $to, array $cleared): array
    {
        $rows = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journal_lines.account_id', $bankAccount->coa_account_id)
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->whereDate('fin_journals.journal_date', '>=', $from)
            ->whereDate('fin_journals.journal_date', '<=', $to)
            ->orderBy('fin_journals.journal_date')
            ->orderBy('fin_journal_lines.id')
            ->get([
                'fin_journal_lines.id',
                'fin_journal_lines.debit',
                'fin_journal_lines.credit',
                'fin_journal_lines.description',
                'fin_journals.code as journal_code',
                'fin_journals.journal_date',
                'fin_journals.reference_type',
                'fin_journals.reference_id',
            ]);

        $totals = ['debit_cents' => 0, 'credit_cents' => 0];
        $open = [];

        foreach ($rows as $row) {
            // A GL line is cleared when a statement line in this chain claims
            // that exact line. Keying on the journal's (reference_type,
            // reference_id) tags instead would drop the bank leg of any other
            // journal carrying the same tags.
            if (isset($cleared['gl_lines'][(int) $row->id])) {
                continue;
            }

            $debit = $this->cents($row->debit);
            $credit = $this->cents($row->credit);
            $totals['debit_cents'] += $debit;
            $totals['credit_cents'] += $credit;

            $open[] = [
                'kind' => 'journal_line',
                'id' => (int) $row->id,
                'date' => substr((string) $row->journal_date, 0, 10),
                'code' => $row->journal_code,
                'direction' => $debit > 0 ? 'debit' : 'credit',
                'amount' => $this->money(max($debit, $credit)),
                'description' => $row->description,
                'source' => $row->reference_type,
            ];
        }

        return [$totals, $open];
    }

    /** How far two amounts may differ and still be worth pointing at. */
    private const MISMATCH_TOLERANCE = 0.20;

    /**
     * Two open items on opposite sides for nearly — but not exactly — the same
     * amount is the shape of a booking error: the bank moved Rp 300 juta, the
     * ERP recorded Rp 350 juta, and because neither can match the other the
     * bridge closes with both sitting in it. Arithmetically fine, Rp 50 juta
     * wrong, and nothing else on this screen would point at it.
     *
     * This is a HINT, not a finding. The band is deliberately wide — a mistyped
     * amount is often far more than a few percent out — and the closest pairs
     * are listed first, so a false suggestion costs a glance while a missed one
     * costs a month's reconciliation. It does not claim to find every booking
     * error; a transposition can land anywhere.
     *
     * @return list<array>
     */
    private function possibleMismatches(array $statementItems, array $ledgerItems): array
    {
        $window = 86400 * 7;
        $found = [];

        foreach ($statementItems as $line) {
            $wantedSide = $line['direction'] === 'credit' ? 'debit' : 'credit';

            foreach ($ledgerItems as $gl) {
                if ($gl['direction'] !== $wantedSide) {
                    continue;
                }

                $difference = abs($line['amount'] - $gl['amount']);
                $larger = max($line['amount'], $gl['amount']);

                if ($difference === 0.0 || $larger <= 0.0 || $difference > $larger * self::MISMATCH_TOLERANCE) {
                    continue;
                }

                if (abs(strtotime($line['date']) - strtotime($gl['date'])) > $window) {
                    continue;
                }

                $found[] = [
                    'statement_line_id' => $line['id'],
                    'statement_date' => $line['date'],
                    'statement_amount' => $line['amount'],
                    'journal_code' => $gl['code'],
                    'journal_date' => $gl['date'],
                    'journal_amount' => $gl['amount'],
                    'difference' => round($difference, 2),
                    'relative' => round($difference / $larger, 4),
                ];
            }
        }

        usort($found, static fn (array $a, array $b): int => $a['relative'] <=> $b['relative']);

        return array_slice($found, 0, 20);
    }

    /**
     * Debit-minus-credit over posted, undeleted journals. whereDate() is not
     * decoration: journal_date is stored as text with a time part, and a
     * lexicographic '<=' against a bare date silently drops the cut-off day.
     */
    private function glBalanceCents(BankAccount $bankAccount, ?string $from, ?string $to): int
    {
        $row = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journal_lines.account_id', $bankAccount->coa_account_id)
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->when($from !== null, fn ($q) => $q->whereDate('fin_journals.journal_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('fin_journals.journal_date', '<=', $to))
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) as debit, COALESCE(SUM(fin_journal_lines.credit), 0) as credit')
            ->first();

        return $this->cents($row->debit ?? 0) - $this->cents($row->credit ?? 0);
    }

    private function cents(int|float|string|null $value): int
    {
        return (int) round((float) $value * 100);
    }

    /**
     * Cents back to rupiah as a FLOAT, always. PHP's / yields an int when the
     * division is exact, so without this the same field is a number in one
     * response and a different JSON type in the next.
     */
    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
