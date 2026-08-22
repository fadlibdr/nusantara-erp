<?php

namespace Modules\Finance\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Support\SegregationOfDuties;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Support\MeasuredPeriods;

/**
 * Double-entry backbone. Every value-bearing document (AR invoice, AP bill,
 * payment) materialises in the GL exclusively through this service, so the
 * balance and open-period guards hold for the whole system.
 */
class JournalService
{
    /**
     * Create a draft journal with its lines.
     *
     * $data = [journal_date, description, reference_type?, reference_id?,
     *          created_by?,
     *          lines => [[account_id|account_code, debit, credit, description?, project_id?], ...]]
     *
     * created_by is the MAKER of a hand-keyed JV, passed by JournalController
     * and by nothing else. autoPost() deliberately never sets it: a journal a
     * document approval mints is already gated by that approval, and stamping
     * the approver here would turn them into "the submitter" of their own
     * posting and wedge every AP bill approval on the maker-checker guard.
     */
    public function create(array $data): Journal
    {
        return DB::transaction(function () use ($data): Journal {
            $lines = Arr::pull($data, 'lines', []);

            $journal = new Journal([
                'journal_date' => $data['journal_date'],
                'description' => $data['description'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);
            $journal->status = PostingStatus::Draft;
            $journal->save(); // HasDocumentNumber fills the JV code

            $this->syncLines($journal, $lines);

            if ($journal->created_by !== null) {
                // Keying a JV IS its submission — there is no separate Ajukan
                // step — so the same core_approvals row every approvable
                // document writes is recorded here. That row is what lets
                // SegregationOfDuties::submitterIdOf() answer for a journal
                // exactly as it answers for a payment.
                $journal->approvals()->create([
                    'action' => 'submitted',
                    'user_id' => $journal->created_by,
                ]);
            }

            return $journal->load('lines.account');
        });
    }

    /**
     * Replace the lines of a draft journal wholesale.
     *
     * THE DRAFT CHECK BELONGS INSIDE THE TRANSACTION, ON A RE-READ. It used to
     * run on the caller's in-memory instance before the transaction opened,
     * and a route-bound model is read three DB round-trips before the handler
     * gets to it (SubstituteBindings, then the permission lookup, then the
     * Rule::exists validation query). Dewi presses Simpan on draft
     * JV/2026/08/0009; inside that window Budi presses Posting in his own tab
     * and post() locks, balances, checks the period and commits status=posted;
     * Dewi's assertDraft then passed on the pre-post copy and syncLines()
     * DELETEd and re-INSERTed the lines of a POSTED ledger entry — the probe
     * rewrote Rp 5.000.000 into Rp 500.000.000 and re-dated it to 2026-01-15,
     * a CLOSED month whose trial balance had already been reported, on a path
     * that never reaches assertPeriodOpen() at all.
     *
     * lockForUpdate() is a silent no-op on SQLite, so the actual protection is
     * the RE-READ plus assertDraft on the re-read instance — every mutation
     * below then works from that instance, not from the caller's copy.
     */
    public function update(Journal $journal, array $data): Journal
    {
        return DB::transaction(function () use ($journal, $data): Journal {
            /** @var Journal $journal */
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            $this->assertDraft($journal);

            $lines = Arr::pull($data, 'lines');

            $journal->fill(Arr::only($data, [
                'journal_date', 'description', 'reference_type', 'reference_id',
            ]))->save();

            if (is_array($lines)) {
                $this->syncLines($journal, $lines);
            }

            return $journal->load('lines.account');
        });
    }

    /**
     * Post a draft journal: balanced (sum debit == sum credit, both > 0) and
     * dated inside an open fiscal period.
     */
    public function post(Journal $journal, ?int $userId = null): Journal
    {
        return DB::transaction(function () use ($journal, $userId): Journal {
            /** @var Journal $journal */
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            $this->assertDraft($journal);

            /*
             * A hand-keyed JV crediting the bank produces the identical ledger
             * entry PaymentService::post() writes — the review probe moved
             * Rp 111.000.000 out of 1-1210 in two requests with zero approval
             * rows — so it gets the identical second pair of eyes. Journals
             * autoPost() minted carry no created_by and wrote no `submitted`
             * row, so the guard stays silent on the internal path: their gate
             * is the approval of the document that called them.
             */
            if ($journal->created_by !== null && $userId !== null) {
                $approver = User::query()->find($userId);

                if ($approver !== null) {
                    SegregationOfDuties::assertNotSubmitter($journal, $approver);
                }
            }

            $this->assertBalanced($journal);
            $this->assertPeriodOpen($journal->journal_date);

            $journal->forceFill([
                'status' => PostingStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ])->save();

            return $journal->load('lines.account');
        });
    }

    /**
     * Create AND post a journal in one go — the hook used by the AR/AP/payment
     * services so their approval transaction either fully books or fully rolls
     * back.
     *
     * Lines use account CODES (the stable seed canon), resolved here.
     */
    public function autoPost(
        string $referenceType,
        int $referenceId,
        array $lines,
        string $date,
        string $description,
        ?int $userId = null,
    ): Journal {
        return DB::transaction(function () use ($referenceType, $referenceId, $lines, $date, $description, $userId): Journal {
            $resolved = [];

            foreach ($lines as $line) {
                // Zero-amount legs (e.g. no retention on this termin) are dropped.
                if (round((float) ($line['debit'] ?? 0), 2) === 0.0
                    && round((float) ($line['credit'] ?? 0), 2) === 0.0) {
                    continue;
                }

                $resolved[] = [
                    'account_id' => $line['account_id'] ?? $this->accountId($line['account_code']),
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'description' => $line['description'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                ];
            }

            $journal = $this->create([
                'journal_date' => $date,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'lines' => $resolved,
            ]);

            return $this->post($journal, $userId);
        });
    }

    /**
     * Post the mirror image of every journal a document booked.
     *
     * The reversal is read off the ORIGINAL LINES rather than re-derived from
     * the document: an AP bill alone has three posting shapes (advance,
     * three-way match, classic) picked from facts recorded at approval time,
     * and a cancellation that re-computed them would undo something subtly
     * different from what was booked — leaving a clearing account or a
     * prepayment carrying a residue nobody could explain. Mirroring the lines
     * cannot drift, whatever the shape was.
     *
     * A posted journal is never touched: autoPost() writes a NEW journal, so
     * both the mistake and its correction stay visible, which is what an audit
     * trail is for. By default it carries the original's DATE, so the two
     * cancel out inside one fiscal period instead of moving a balance between
     * months. Pass $on to override that — see reversalDate() for when the
     * original period is no longer somewhere a reversal may land.
     *
     * @param  string|null  $on  date for every reversal, or null to reuse each original's
     * @return array<int, Journal> one reversal per posted original
     */
    public function reverseFor(
        string $referenceType,
        int $referenceId,
        string $reversalReferenceType,
        string $description,
        ?int $userId = null,
        ?string $on = null,
    ): array {
        return DB::transaction(function () use ($referenceType, $referenceId, $reversalReferenceType, $description, $userId, $on): array {
            $originals = Journal::query()
                ->with('lines')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('status', PostingStatus::Posted)
                ->orderBy('id')
                ->get();

            if ($originals->isEmpty()) {
                throw new LogicException(
                    "Tidak ada jurnal terposting untuk {$referenceType} #{$referenceId}; tidak ada yang dapat dibalik."
                );
            }

            $reversals = [];

            foreach ($originals as $original) {
                $lines = $original->lines
                    ->map(fn (JournalLine $line): array => [
                        'account_id' => (int) $line->account_id,
                        'debit' => (float) $line->credit,
                        'credit' => (float) $line->debit,
                        'description' => $line->description,
                        'project_id' => $line->project_id,
                    ])
                    ->all();

                $reversals[] = $this->autoPost(
                    $reversalReferenceType,
                    $referenceId,
                    $lines,
                    $on ?? $original->journal_date->toDateString(),
                    "{$description} — pembalikan {$original->code}",
                    $userId,
                );
            }

            return $reversals;
        });
    }

    /**
     * Where a document's reversal belongs on the calendar.
     *
     * Reversing inside the original period is the cleanest outcome: the books
     * read as though the document never existed, and no balance moves between
     * months. That is only available while nobody has been told what that
     * period said. Two things end it — the fiscal period being closed, and a
     * PSAK 115 run having measured it.
     *
     * The second one is the expensive one, because a posted run can never be
     * recalculated. Say March billed Rp 9,7 M against Rp 6,0 M of earned
     * revenue, so the run parked Rp 3,7 M in 2-1410 Liabilitas Kontrak. Reverse
     * the invoice back into March and that month's revenue turns NEGATIVE by
     * Rp 3,7 M, while April's run — which recomputes billings live and finds
     * none — books a Rp 9,7 M catch-up with nothing to offset it. One
     * cancellation, two wrong income statements. Dated today instead, March
     * stands as reported and April nets to zero.
     *
     * A cancellation discovered today is an event of today.
     */
    public function reversalDate(\DateTimeInterface|string $documentDate): string
    {
        $date = $documentDate instanceof \DateTimeInterface
            ? CarbonImmutable::instance($documentDate)
            : CarbonImmutable::parse($documentDate);

        $period = FiscalPeriod::forDate($date);

        // The same test PeriodCloseService::reopenRefusal() applies, asked
        // through one implementation so the two can never disagree about which
        // month a posted run has put beyond reach.
        $measured = MeasuredPeriods::isMeasured((int) $date->format('Ym'));

        if ($period !== null && $period->isOpen() && ! $measured) {
            return $date->toDateString();
        }

        // Today has to be somewhere a journal can land at all; if it is not,
        // the operator needs to hear that rather than have the reversal
        // silently fall back into the period we just refused.
        $today = CarbonImmutable::today();
        $this->assertPeriodOpen($today);

        return $today->toDateString();
    }

    /**
     * Soft-delete a draft journal.
     *
     * Same stale-instance window update() describes, with a worse landing:
     * every GL reader filters whereNull('deleted_at'), so a journal posted
     * between the route binding and this call would simply cease to exist in
     * the trial balance, the balance sheet and the cash-flow statement while
     * its source document still read "posted". Hence the re-read and the
     * re-check inside the transaction — lockForUpdate() is a no-op on SQLite.
     */
    public function delete(Journal $journal): void
    {
        DB::transaction(function () use ($journal): void {
            /** @var Journal $journal */
            $journal = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();

            $this->assertDraft($journal);

            $journal->delete();
        });
    }

    /**
     * Resolve a COA code to its id; posting to a group or unknown account is a
     * setup error worth failing loudly on.
     */
    public function accountId(string $code): int
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null) {
            throw new LogicException("COA account {$code} does not exist; seed the chart of accounts first.");
        }

        if (! $account->is_postable) {
            throw new LogicException("COA account {$code} ({$account->name}) is a group and cannot be posted to.");
        }

        return (int) $account->id;
    }

    /**
     * THE control the whole posting layer runs through.
     *
     * Both messages reach the user verbatim through ApiController::error, so
     * both are Indonesian. The missing-period one used to read "No fiscal
     * period exists for the journal date; create it first." — which is the
     * symptom every posting in the company shows on 1 January, and which tells
     * the reader neither which year is missing nor where to fix it.
     */
    public function assertPeriodOpen(\DateTimeInterface|string $date): void
    {
        $on = $date instanceof \DateTimeInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date);

        $period = FiscalPeriod::forDate($on);

        if ($period === null) {
            throw new LogicException(
                "Belum ada periode fiskal untuk {$on->toDateString()}. Buat kalender fiskal {$on->year} "
                .'lebih dulu di Keuangan › Periode Fiskal.'
            );
        }

        if (! $period->isOpen()) {
            throw new LogicException(sprintf(
                'Periode fiskal %04d-%02d sudah ditutup; jurnal tidak dapat diposting ke dalamnya.',
                $period->year,
                $period->month,
            ));
        }
    }

    private function syncLines(Journal $journal, array $lines): void
    {
        if ($lines === []) {
            throw new LogicException('A journal needs at least two lines.');
        }

        $journal->lines()->delete();

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0) {
                throw new LogicException('Journal line amounts cannot be negative.');
            }

            if ($debit > 0 && $credit > 0) {
                throw new LogicException('A journal line is either debit or credit, not both.');
            }

            if ($debit === 0.0 && $credit === 0.0) {
                throw new LogicException('A journal line needs a debit or credit amount.');
            }

            // Manual JVs pass account ids straight through, so the postable
            // guard from accountId() must be repeated here.
            $account = Account::query()->find($line['account_id']);

            if ($account === null) {
                throw new LogicException('Journal line account does not exist; seed the chart of accounts first.');
            }

            if (! $account->is_postable) {
                throw new LogicException("COA account {$account->code} ({$account->name}) is a group and cannot be posted to.");
            }

            $journal->lines()->create([
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'project_id' => $line['project_id'] ?? null,
            ]);
        }
    }

    private function assertBalanced(Journal $journal): void
    {
        $debit = $journal->totalDebit();
        $credit = $journal->totalCredit();

        if ($debit <= 0 || $credit <= 0) {
            throw new LogicException("Journal {$journal->code} has no amounts to post.");
        }

        // 1-cent tolerance absorbs decimal column rounding. Compared in whole
        // cents: subtracting IEEE-754 doubles and testing against 0.01 makes
        // the tolerance magnitude-dependent (a 1-cent gap on 100,00 evaluates
        // to 0.010000000000005 and would be refused, while the same gap on
        // 123.456,79 evaluates to 0.00999999999 and would pass).
        if (abs((int) round(($debit - $credit) * 100)) > 1) {
            throw new LogicException(
                "Journal {$journal->code} is not balanced: debit {$debit} vs credit {$credit}."
            );
        }
    }

    private function assertDraft(Journal $journal): void
    {
        if ($journal->status !== PostingStatus::Draft) {
            throw new LogicException("Journal {$journal->code} is already {$journal->status->value}.");
        }
    }
}
