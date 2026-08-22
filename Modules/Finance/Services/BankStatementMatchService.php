<?php

namespace Modules\Finance\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Support\Erp;
use Modules\Finance\Enums\BankStatementMatchStatus;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\BankStatement;
use Modules\Finance\Models\BankStatementLine;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\Payment;

/**
 * Matches statement lines to what the ERP already posted.
 *
 * A match is a CLAIM, not a posting: it says "this bank movement and this
 * document are the same economic event". Nothing here writes to the ledger, and
 * the server never chooses a counterpart on its own — suggest() ranks, the
 * operator confirms. A button that applies the system's picks would be
 * auto-matching with an extra click and the same blast radius.
 *
 * Two counterpart kinds:
 *
 *  - a posted PAYMENT (RCV/PAY), which is the normal case;
 *  - a posted JOURNAL LINE on the bank account, which is how a hand-booked bank
 *    charge stops being a reconciling item once somebody books it.
 *
 * A payment's OWN bank journal line is not admissible as the second kind. It is
 * the same movement reachable under two identities, and the unique index sees
 * ('payment', 7) and ('journal_line', 42) as different claims — so without that
 * guard, a duplicate bank debit could be "matched" against the journal line of
 * the single legitimate payment, and a real Rp 300 juta bank error would
 * disappear behind a balanced reconciliation. That is the exact failure this
 * feature exists to prevent, so the guard is repeated at the point of writing
 * and not only at the point of suggesting.
 */
class BankStatementMatchService
{
    public function __construct(private readonly BankReconciliationService $reconciliation) {}

    /**
     * How far apart a bank movement and its ERP document may be dated. Cheques
     * and inter-bank transfers clear over several days, so same-day-only would
     * suggest nothing useful; a wide window makes every payment a candidate and
     * destroys the value of the ranking.
     */
    public function matchDateWindowDays(): int
    {
        return max(1, Erp::int('reconciliation.match_date_window_days', 7));
    }

    /**
     * Suggestions for every open line of a statement, with the candidate pool
     * fetched ONCE for the whole statement and scored in PHP. Per-line queries
     * would be two round trips per line, on every repaint of a screen whose
     * whole job is repeated small mutations.
     *
     * @return array<int, list<array>> keyed by statement line id
     */
    public function suggestForStatement(BankStatement $statement): array
    {
        $lines = $statement->lines()
            ->where('match_status', '!=', BankStatementMatchStatus::Matched->value)
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $window = $this->matchDateWindowDays();
        $from = Carbon::parse($lines->min('entry_date'))->subDays($window);
        $to = Carbon::parse($lines->max('entry_date'))->addDays($window);

        $payments = $this->paymentPool($statement->bank_account_id, $from, $to);
        $journalLines = $this->journalLinePool($statement, $from, $to);

        $suggestions = [];

        foreach ($lines as $line) {
            $suggestions[$line->id] = $this->rank($line, $payments, $journalLines);
        }

        return $suggestions;
    }

    /**
     * @return list<array>
     */
    public function suggestForLine(BankStatementLine $line): array
    {
        $statement = $line->statement()->firstOrFail();
        $window = $this->matchDateWindowDays();
        $from = $line->entry_date->copy()->subDays($window);
        $to = $line->entry_date->copy()->addDays($window);

        return $this->rank(
            $line,
            $this->paymentPool($statement->bank_account_id, $from, $to),
            $this->journalLinePool($statement, $from, $to),
        );
    }

    public function match(BankStatementLine $line, string $type, int $id, ?int $userId = null): BankStatementLine
    {
        return DB::transaction(function () use ($line, $type, $id, $userId): BankStatementLine {
            /** @var BankStatementLine $line */
            $line = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($line->isMatched()) {
                throw new LogicException("Baris ini sudah dicocokkan dengan {$this->counterpartLabel($line)}.");
            }

            $statement = $line->statement()->firstOrFail();

            match ($type) {
                BankStatementLine::MATCH_PAYMENT => $this->assertPaymentAdmissible($line, $statement, $id),
                BankStatementLine::MATCH_JOURNAL_LINE => $this->assertJournalLineAdmissible($line, $statement, $id),
                default => throw new LogicException("Jenis padanan \"{$type}\" tidak dikenal."),
            };

            try {
                $line->forceFill([
                    'match_status' => BankStatementMatchStatus::Matched->value,
                    'matched_type' => $type,
                    'matched_id' => $id,
                    'matched_at' => now(),
                    'matched_by' => $userId,
                    'note_reason' => null,
                    'note' => null,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw new LogicException(
                    'Dokumen itu baru saja dicocokkan dengan baris rekening koran lain. Muat ulang halaman ini.'
                );
            }

            return $line->refresh();
        });
    }

    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        return $this->transition($line, function (BankStatementLine $line): void {
            if (! $line->isMatched()) {
                throw new LogicException('Baris ini belum dicocokkan.');
            }

            $line->forceFill($this->unclaimed(BankStatementMatchStatus::Open))->save();
        });
    }

    /**
     * Every state change re-reads the row under the same lock match() takes.
     * Without it, two clicks that cross leave a line whose status says one thing
     * and whose matched_id says another — which frees nothing, permanently
     * orphans the payment behind the unique index, and injects a residual the
     * operator has no way to explain.
     */
    private function transition(BankStatementLine $line, callable $mutate): BankStatementLine
    {
        return DB::transaction(function () use ($line, $mutate): BankStatementLine {
            /** @var BankStatementLine $locked */
            $locked = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            $mutate($locked);

            return $locked->refresh();
        });
    }

    /**
     * Clearing the claim AND the status together. Leaving matched_id behind on a
     * line that is no longer matched keeps the counterpart locked out by the
     * unique index while the reconciliation treats it as unclaimed.
     */
    private function unclaimed(BankStatementMatchStatus $status): array
    {
        return [
            'match_status' => $status->value,
            'matched_type' => null,
            'matched_id' => null,
            'matched_at' => null,
            'matched_by' => null,
        ];
    }

    /**
     * "I have looked at this and the ERP has not recorded it." The line KEEPS
     * counting as a reconciling item — it is money the bank moved. All this
     * changes is that it stops being a to-do and carries a stated reason, so an
     * auditor reading the reconciliation sees a classified difference instead of
     * an unexamined one. Nothing an operator can press here makes a difference
     * go away; only a posting does that.
     */
    public function markNoMatch(BankStatementLine $line, string $reason, ?string $note = null): BankStatementLine
    {
        if (! array_key_exists($reason, BankStatementLine::REASONS)) {
            throw new LogicException("Alasan \"{$reason}\" tidak dikenal.");
        }

        return $this->transition($line, function (BankStatementLine $line) use ($reason, $note): void {
            if ($line->isMatched()) {
                throw new LogicException('Batalkan pencocokan lebih dulu sebelum menandai baris ini tanpa padanan.');
            }

            $line->forceFill($this->unclaimed(BankStatementMatchStatus::NoMatch) + [
                'note_reason' => $reason,
                'note' => $note,
            ])->save();
        });
    }

    public function reopen(BankStatementLine $line): BankStatementLine
    {
        return $this->transition($line, function (BankStatementLine $line): void {
            $line->forceFill($this->unclaimed(BankStatementMatchStatus::Open) + [
                'note_reason' => null,
                'note' => null,
            ])->save();
        });
    }

    // ------------------------------------------------------------- candidates

    private function paymentPool(int $bankAccountId, Carbon $from, Carbon $to): Collection
    {
        return Payment::query()
            ->where('bank_account_id', $bankAccountId)
            ->where('status', PaymentStatus::Posted->value)
            ->whereDate('payment_date', '>=', $from->toDateString())
            ->whereDate('payment_date', '<=', $to->toDateString())
            ->whereNotIn('id', $this->claimedIds(BankStatementLine::MATCH_PAYMENT))
            ->get();
    }

    /**
     * Manual journal lines on the bank account — bank charges, interest, and
     * corrections booked by hand.
     *
     * Payment journals are excluded: their bank leg is the payment, and offering
     * it separately would be offering the same movement twice.
     *
     * The explicit select is not decoration. fin_journal_lines and fin_journals
     * both have id, description, created_at and updated_at, so a joined
     * `select *` hands back the JOURNAL's id under the key `id` — the match
     * would then be written against an unrelated journal line.
     */
    private function journalLinePool(BankStatement $statement, Carbon $from, Carbon $to): Collection
    {
        $coaAccountId = $this->reconciliation->bankAccountFor($statement)->coa_account_id;

        return JournalLine::query()
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->where('fin_journal_lines.account_id', $coaAccountId)
            ->where('fin_journals.status', PostingStatus::Posted->value)
            ->whereNull('fin_journals.deleted_at')
            ->where(fn ($query) => $query
                ->whereNull('fin_journals.reference_type')
                ->orWhere('fin_journals.reference_type', '!=', 'payment'))
            ->whereDate('fin_journals.journal_date', '>=', $from->toDateString())
            ->whereDate('fin_journals.journal_date', '<=', $to->toDateString())
            ->whereNotIn('fin_journal_lines.id', $this->claimedIds(BankStatementLine::MATCH_JOURNAL_LINE))
            ->select([
                'fin_journal_lines.*',
                'fin_journals.code as journal_code',
                'fin_journals.journal_date as journal_date',
                'fin_journals.description as journal_description',
            ])
            ->get();
    }

    /**
     * @return Builder
     */
    private function claimedIds(string $type)
    {
        return DB::table('fin_bank_statement_lines')
            ->where('matched_type', $type)
            ->whereNotNull('matched_id')
            ->select('matched_id');
    }

    /**
     * @return list<array>
     */
    private function rank(BankStatementLine $line, Collection $payments, Collection $journalLines): array
    {
        $window = $this->matchDateWindowDays();
        $amountCents = (int) round((float) $line->amount * 100);
        $candidates = [];

        foreach ($payments as $payment) {
            if ($payment->direction !== $line->direction->paymentDirection()) {
                continue;
            }

            if ((int) round((float) $payment->amount * 100) !== $amountCents) {
                continue;
            }

            $days = $line->entry_date->diffInDays($payment->payment_date, true);

            if ($days > $window) {
                continue;
            }

            $candidates[] = [
                'matched_type' => BankStatementLine::MATCH_PAYMENT,
                'matched_id' => (int) $payment->id,
                'code' => $payment->code,
                'date' => $payment->payment_date->toDateString(),
                'amount' => (float) $payment->amount,
                'description' => $payment->notes ?: $payment->reference,
                'reference' => $payment->reference,
                'days_apart' => (int) $days,
                'reference_hit' => $this->referencesAgree($line, (string) $payment->reference),
            ];
        }

        $side = $line->direction->glSide();

        foreach ($journalLines as $journalLine) {
            $magnitude = (int) round(max((float) $journalLine->debit, (float) $journalLine->credit) * 100);
            $onSide = $side === 'debit' ? (float) $journalLine->debit > 0 : (float) $journalLine->credit > 0;

            if (! $onSide || $magnitude !== $amountCents) {
                continue;
            }

            $journalDate = Carbon::parse($journalLine->journal_date);
            $days = $line->entry_date->diffInDays($journalDate, true);

            if ($days > $window) {
                continue;
            }

            $candidates[] = [
                'matched_type' => BankStatementLine::MATCH_JOURNAL_LINE,
                'matched_id' => (int) $journalLine->id,
                'code' => $journalLine->journal_code,
                'date' => $journalDate->toDateString(),
                'amount' => $magnitude / 100,
                'description' => $journalLine->description ?: $journalLine->journal_description,
                'reference' => null,
                'days_apart' => (int) $days,
                'reference_hit' => false,
            ];
        }

        $unique = count($candidates) === 1;

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['score'] = $this->score($candidate, $window, $unique);
            $candidates[$index]['confidence'] = $this->confidence($candidates[$index]['score']);
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, 5);
    }

    /**
     * The bands are derived from the window rather than hard-coded, so raising
     * the setting cannot leave a legitimate candidate scoring zero on the date
     * and rendered to the operator as a weak guess.
     */
    private function score(array $candidate, int $window, bool $unique): int
    {
        $days = $candidate['days_apart'];

        $score = match (true) {
            $days === 0 => 50,
            $days <= max(1, (int) floor($window / 2)) => 35,
            default => 20,
        };

        if ($candidate['reference_hit']) {
            $score += 30;
        }

        if ($unique) {
            $score += 10;
        }

        return $score;
    }

    private function confidence(int $score): string
    {
        return match (true) {
            $score >= 80 => 'high',
            $score >= 50 => 'medium',
            default => 'low',
        };
    }

    /**
     * A reference agrees when the shorter of the two alphanumeric forms appears
     * inside the longer. Banks pad, truncate and re-punctuate references, so an
     * equality test finds nothing; a containment test on 4+ characters finds the
     * real ones without matching everything.
     */
    private function referencesAgree(BankStatementLine $line, string $reference): bool
    {
        $needle = $this->normaliseReference($reference);

        if (strlen($needle) < 4) {
            return false;
        }

        foreach ([$line->bank_reference, $line->customer_reference, $line->description] as $candidate) {
            $haystack = $this->normaliseReference((string) $candidate);

            if ($haystack === '' || strlen($haystack) < 4) {
                continue;
            }

            if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseReference(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    // ----------------------------------------------------------------- guards

    private function assertPaymentAdmissible(BankStatementLine $line, BankStatement $statement, int $id): void
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()->find($id);

        if ($payment === null) {
            throw new LogicException('Pembayaran yang dipilih tidak ditemukan.');
        }

        if ($payment->status !== PaymentStatus::Posted) {
            throw new LogicException("Pembayaran {$payment->code} belum diposting, jadi belum ada di buku besar.");
        }

        if ((int) $payment->bank_account_id !== (int) $statement->bank_account_id) {
            throw new LogicException("Pembayaran {$payment->code} memakai rekening bank yang berbeda.");
        }

        if ($payment->direction !== $line->direction->paymentDirection()) {
            throw new LogicException(
                "Baris ini {$line->direction->label()}, sedangkan {$payment->code} adalah {$payment->direction->label()}."
            );
        }

        $this->assertSameAmount((float) $payment->amount, $line, $payment->code);
    }

    private function assertJournalLineAdmissible(BankStatementLine $line, BankStatement $statement, int $id): void
    {
        /** @var JournalLine|null $journalLine */
        $journalLine = JournalLine::query()->with('journal')->find($id);

        if ($journalLine === null || $journalLine->journal === null) {
            throw new LogicException('Baris jurnal yang dipilih tidak ditemukan.');
        }

        $journal = $journalLine->journal;

        if ($journal->status !== PostingStatus::Posted) {
            throw new LogicException("Jurnal {$journal->code} belum diposting, jadi belum ada di buku besar.");
        }

        if ($journal->reference_type === 'payment') {
            throw new LogicException(
                "Baris jurnal ini milik pembayaran {$journal->code}. Cocokkan ke pembayarannya, bukan ke jurnalnya — "
                .'kalau tidak, satu mutasi bank yang sama bisa diklaim dua kali.'
            );
        }

        $bankAccount = $this->reconciliation->bankAccountFor($statement);

        if ((int) $journalLine->account_id !== (int) $bankAccount->coa_account_id) {
            throw new LogicException("Baris jurnal {$journal->code} tidak menyentuh akun bank rekening ini.");
        }

        $side = $line->direction->glSide();
        $onSide = $side === 'debit' ? (float) $journalLine->debit > 0 : (float) $journalLine->credit > 0;

        if (! $onSide) {
            throw new LogicException(
                "Baris ini {$line->direction->label()}, sehingga padanannya harus berada di sisi "
                .($side === 'debit' ? 'debit' : 'kredit')." akun bank — {$journal->code} tidak."
            );
        }

        $this->assertSameAmount(max((float) $journalLine->debit, (float) $journalLine->credit), $line, $journal->code);
    }

    /**
     * Exact, in whole cents. A partial match is a different feature (one bank
     * transfer settling several documents) and pretending otherwise would let a
     * Rp 50 juta booking error be reconciled away.
     */
    private function assertSameAmount(float $amount, BankStatementLine $line, string $code): void
    {
        $expected = (int) round((float) $line->amount * 100);
        $actual = (int) round($amount * 100);

        if ($expected !== $actual) {
            throw new LogicException(sprintf(
                'Nilai tidak sama: baris rekening koran %s, %s %s. Pencocokan sebagian belum didukung.',
                'Rp '.number_format($expected / 100, 2, ',', '.'),
                $code,
                'Rp '.number_format($actual / 100, 2, ',', '.'),
            ));
        }
    }

    private function counterpartLabel(BankStatementLine $line): string
    {
        if ($line->matched_type === BankStatementLine::MATCH_PAYMENT) {
            return Payment::query()->find($line->matched_id)?->code ?? 'pembayaran lain';
        }

        return JournalLine::query()->with('journal')->find($line->matched_id)?->journal?->code ?? 'jurnal lain';
    }
}
