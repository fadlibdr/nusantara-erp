<?php

namespace Tests\Feature\Inventory;

use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Journal;

/**
 * Small assertion helpers for the inventory -> general ledger tests. They only
 * fetch and reshape journal rows; the expected amounts always live in the test.
 */
trait AssertsJournals
{
    /**
     * The single journal raised for a source document. Fails when the document
     * produced none or more than one.
     */
    protected function singleJournalFor(string $referenceType, int $referenceId): Journal
    {
        $journals = Journal::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->get();

        $this->assertCount(1, $journals, "Expected exactly one {$referenceType} journal.");

        return $journals->first();
    }

    protected function assertNoJournalFor(string $referenceType, int $referenceId): void
    {
        $this->assertDatabaseMissing('fin_journals', [
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * Journal lines keyed by COA code: ['1-1400' => ['debit' => …, 'credit' => …, 'project_id' => …]].
     *
     * @return array<string, array{debit: float, credit: float, project_id: ?int}>
     */
    protected function linesByAccount(Journal $journal): array
    {
        $lines = [];

        foreach ($journal->lines()->with('account')->get() as $line) {
            $lines[$line->account->code] = [
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'project_id' => $line->project_id !== null ? (int) $line->project_id : null,
            ];
        }

        return $lines;
    }

    /**
     * Posted, dated as expected and debit == credit.
     */
    protected function assertPostedAndBalanced(Journal $journal, string $expectedDate): void
    {
        $this->assertSame(PostingStatus::Posted, $journal->status);
        $this->assertNotNull($journal->posted_at);
        $this->assertSame($expectedDate, $journal->journal_date->toDateString());
        $this->assertSame($journal->totalDebit(), $journal->totalCredit());
        $this->assertGreaterThan(0.0, $journal->totalDebit());
    }

    /**
     * A postable COA leaf that is not part of the shipped canon, for testing
     * that the account settings are honoured.
     */
    protected function makePostableAccount(string $code, string $name, string $type, string $normal): Account
    {
        return Account::create([
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_postable' => true,
            'is_active' => true,
        ]);
    }
}
