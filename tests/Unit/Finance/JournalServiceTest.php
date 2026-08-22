<?php

namespace Tests\Unit\Finance;

use LogicException;
use Modules\Finance\Enums\PostingStatus;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Tests\ErpTestCase;

/**
 * The double-entry backbone. Every guard in JournalService::post() is a hard
 * accounting invariant: an unbalanced, empty, negative, mixed-sided or
 * out-of-period journal must never reach the ledger, and a rejected journal
 * must stay exactly as it was — draft, unposted, still editable.
 */
class JournalServiceTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    // ------------------------------------------------------------ balance guard

    public function test_post_refuses_an_unbalanced_journal_and_leaves_it_draft(): void
    {
        // Dr 1.000.000 vs Cr 900.000 => selisih 100.000, jauh di atas toleransi.
        $journal = $this->draftJournal([
            ['1-1100', 1000000, 0],
            ['4-1100', 0, 900000],
        ]);

        $this->expectException(LogicException::class);

        try {
            $this->journals()->post($journal);
        } finally {
            $fresh = $journal->fresh();
            $this->assertSame(PostingStatus::Draft, $fresh->status);
            $this->assertNull($fresh->posted_at);
            $this->assertNull($fresh->posted_by);
        }
    }

    public function test_post_refuses_a_journal_with_no_amounts_at_all(): void
    {
        // Lines built straight on the model so syncLines()' per-line guard is
        // bypassed and post()'s own "no amounts" guard is the one under test.
        $journal = Journal::create([
            'journal_date' => '2026-03-10',
            'description' => 'Jurnal tanpa nilai',
            'status' => PostingStatus::Draft,
        ]);
        $journal->lines()->create(['account_id' => $this->accountId('1-1100'), 'debit' => 0, 'credit' => 0]);
        $journal->lines()->create(['account_id' => $this->accountId('4-1100'), 'debit' => 0, 'credit' => 0]);

        $this->assertSame(0.0, $journal->totalDebit());
        $this->assertSame(0.0, $journal->totalCredit());

        $this->expectExceptionMessage('has no amounts to post');

        try {
            $this->journals()->post($journal);
        } finally {
            $this->assertSame(PostingStatus::Draft, $journal->fresh()->status);
        }
    }

    public function test_the_one_cent_balance_tolerance_is_honoured(): void
    {
        // Dr 123.456,79 vs Cr 123.456,78 => selisih 0,01 => diterima.
        $journal = $this->draftJournal([
            ['1-1100', 123456.79, 0],
            ['4-1100', 0, 123456.78],
        ]);

        $posted = $this->journals()->post($journal);

        $this->assertSame(PostingStatus::Posted, $posted->status);
        $this->assertSame(123456.79, $posted->totalDebit());
        $this->assertSame(123456.78, $posted->totalCredit());
    }

    public function test_the_one_cent_tolerance_holds_for_small_amounts_too(): void
    {
        // The tolerance is compared in whole cents, so it is the same 1 cent
        // here as on the six-figure journal above.
        $journal = $this->draftJournal([
            ['1-1100', 100.00, 0],
            ['4-1100', 0, 100.01],
        ]);

        $this->assertSame(PostingStatus::Posted, $this->journals()->post($journal)->status);
    }

    public function test_a_two_cent_imbalance_is_beyond_the_tolerance(): void
    {
        // Dr 123.456,80 vs Cr 123.456,78 => selisih 0,02 => ditolak.
        $journal = $this->draftJournal([
            ['1-1100', 123456.80, 0],
            ['4-1100', 0, 123456.78],
        ]);

        $this->expectExceptionMessage('is not balanced');

        try {
            $this->journals()->post($journal);
        } finally {
            $this->assertSame(PostingStatus::Draft, $journal->fresh()->status);
        }
    }

    // ------------------------------------------------------------ line guards

    public function test_a_line_cannot_be_both_debit_and_credit(): void
    {
        $this->expectExceptionMessage('either debit or credit, not both');

        try {
            $this->draftJournal([
                ['1-1100', 500000, 500000],
                ['4-1100', 0, 500000],
            ]);
        } finally {
            $this->assertDatabaseCount('fin_journals', 0);
        }
    }

    public function test_a_line_cannot_carry_a_negative_amount(): void
    {
        $this->expectExceptionMessage('cannot be negative');

        try {
            $this->draftJournal([
                ['1-1100', -1000000, 0],
                ['4-1100', 0, -1000000],
            ]);
        } finally {
            // create() runs inside a transaction: the header rolls back too.
            $this->assertDatabaseCount('fin_journals', 0);
            $this->assertDatabaseCount('fin_journal_lines', 0);
        }
    }

    public function test_a_line_cannot_point_at_a_group_account(): void
    {
        // 1-1000 "Aset Lancar" is a group (is_postable = false).
        $this->expectExceptionMessage('is a group and cannot be posted to');

        try {
            $this->draftJournal([
                ['1-1000', 1000000, 0],
                ['4-1100', 0, 1000000],
            ]);
        } finally {
            $this->assertDatabaseCount('fin_journals', 0);
        }
    }

    public function test_a_journal_needs_at_least_one_line(): void
    {
        $this->expectExceptionMessage('A journal needs at least two lines.');

        try {
            $this->journals()->create([
                'journal_date' => '2026-03-10',
                'description' => 'Jurnal kosong',
                'lines' => [],
            ]);
        } finally {
            $this->assertDatabaseCount('fin_journals', 0);
        }
    }

    public function test_auto_post_refuses_an_unknown_account_code(): void
    {
        $this->expectExceptionMessage('COA account 9-9999 does not exist');

        $this->journals()->accountId('9-9999');
    }

    public function test_auto_post_refuses_a_group_account_code(): void
    {
        $this->expectExceptionMessage('is a group and cannot be posted to');

        $this->journals()->accountId('1-0000');
    }

    // ------------------------------------------------------------ fiscal period guards

    public function test_post_refuses_a_journal_dated_in_a_closed_period(): void
    {
        FiscalPeriod::query()->where('year', 2026)->where('month', 4)->update(['status' => 'closed']);

        $journal = $this->draftJournal([
            ['1-1100', 1000000, 0],
            ['4-1100', 0, 1000000],
        ], '2026-04-15');

        $this->expectExceptionMessage('Periode fiskal 2026-04 sudah ditutup');

        try {
            $this->journals()->post($journal);
        } finally {
            $this->assertSame(PostingStatus::Draft, $journal->fresh()->status);
            $this->assertNull($journal->fresh()->posted_at);
        }
    }

    public function test_post_refuses_a_journal_dated_in_a_period_that_does_not_exist(): void
    {
        // seedLedger() only opened 2026; 2027 has no period row at all.
        $journal = $this->draftJournal([
            ['1-1100', 1000000, 0],
            ['4-1100', 0, 1000000],
        ], '2027-01-15');

        $this->expectExceptionMessage('Belum ada periode fiskal untuk');

        try {
            $this->journals()->post($journal);
        } finally {
            $this->assertSame(PostingStatus::Draft, $journal->fresh()->status);
        }
    }

    public function test_a_journal_on_the_last_day_of_an_open_period_posts(): void
    {
        $journal = $this->draftJournal([
            ['1-1100', 1000000, 0],
            ['4-1100', 0, 1000000],
        ], '2026-12-31');

        $this->assertSame(PostingStatus::Posted, $this->journals()->post($journal)->status);
    }

    // ------------------------------------------------------------ autoPost

    public function test_auto_post_drops_zero_amount_legs_and_still_balances(): void
    {
        // Termin tanpa retensi: DPP 200.000.000, PPN 11% = 22.000.000,
        // retensi 0 => total piutang 222.000.000. Leg 1-1350 bernilai nol.
        $journal = $this->journals()->autoPost('ar_invoice', 77, [
            ['account_code' => '1-1300', 'debit' => 222000000.0],
            ['account_code' => '1-1350', 'debit' => 0.0],
            ['account_code' => '4-1100', 'credit' => 200000000.0],
            ['account_code' => '2-1300', 'credit' => 22000000.0],
        ], '2026-03-10', 'Invoice tanpa retensi');

        $lines = $this->linesByAccount($journal);

        $this->assertCount(3, $lines);
        $this->assertArrayNotHasKey('1-1350', $lines);
        $this->assertSame(222000000.0, $journal->totalDebit());
        $this->assertSame(222000000.0, $journal->totalCredit());
        $this->assertSame(PostingStatus::Posted, $journal->status);
    }

    public function test_auto_post_stamps_the_reference_the_date_and_the_posting_user(): void
    {
        $user = $this->financeUser();

        $journal = $this->journals()->autoPost('ar_invoice', 42, [
            ['account_code' => '1-1300', 'debit' => 1110000.0],
            ['account_code' => '4-1100', 'credit' => 1000000.0],
            ['account_code' => '2-1300', 'credit' => 110000.0],
        ], '2026-05-20', 'Invoice INV/2026/V/0001', (int) $user->id);

        $this->assertPostedAndBalanced($journal, '2026-05-20');
        $this->assertSame('ar_invoice', $journal->reference_type);
        $this->assertSame(42, (int) $journal->reference_id);
        $this->assertSame((int) $user->id, (int) $journal->posted_by);
    }

    public function test_auto_post_rolls_the_whole_journal_back_when_the_period_is_closed(): void
    {
        FiscalPeriod::query()->where('year', 2026)->where('month', 6)->update(['status' => 'closed']);

        $this->expectExceptionMessage('Periode fiskal 2026-06 sudah ditutup');

        try {
            $this->journals()->autoPost('ar_invoice', 99, [
                ['account_code' => '1-1300', 'debit' => 1110000.0],
                ['account_code' => '4-1100', 'credit' => 1000000.0],
                ['account_code' => '2-1300', 'credit' => 110000.0],
            ], '2026-06-10', 'Invoice pada periode tertutup');
        } finally {
            // Neither the header nor the lines may survive a failed autoPost.
            $this->assertDatabaseCount('fin_journals', 0);
            $this->assertDatabaseCount('fin_journal_lines', 0);
        }
    }

    // ------------------------------------------------------------ immutability

    public function test_a_posted_journal_cannot_be_posted_again(): void
    {
        $journal = $this->postJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]], '2026-03-10');

        $this->expectExceptionMessage('is already posted');

        $this->journals()->post($journal);
    }

    public function test_a_posted_journal_cannot_be_updated(): void
    {
        $journal = $this->postJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]], '2026-03-10');
        $originalDescription = $journal->description;

        $this->expectExceptionMessage('is already posted');

        try {
            $this->journals()->update($journal, [
                'description' => 'Diubah setelah posting',
                'lines' => [
                    ['account_id' => $this->accountId('1-1100'), 'debit' => 5, 'credit' => 0],
                    ['account_id' => $this->accountId('4-1100'), 'debit' => 0, 'credit' => 5],
                ],
            ]);
        } finally {
            $fresh = $journal->fresh();
            $this->assertSame($originalDescription, $fresh->description);
            $this->assertSame(1000000.0, $fresh->totalDebit());
        }
    }

    public function test_a_posted_journal_cannot_be_deleted(): void
    {
        $journal = $this->postJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]], '2026-03-10');

        $this->expectExceptionMessage('is already posted');

        try {
            $this->journals()->delete($journal);
        } finally {
            $this->assertDatabaseHas('fin_journals', ['id' => $journal->id, 'deleted_at' => null]);
        }
    }

    public function test_a_draft_journal_can_still_be_updated_and_deleted(): void
    {
        $journal = $this->draftJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]]);

        $updated = $this->journals()->update($journal, [
            'description' => 'Koreksi sebelum posting',
            'lines' => [
                ['account_id' => $this->accountId('1-1100'), 'debit' => 2500000, 'credit' => 0],
                ['account_id' => $this->accountId('4-1100'), 'debit' => 0, 'credit' => 2500000],
            ],
        ]);

        $this->assertSame('Koreksi sebelum posting', $updated->description);
        $this->assertSame(2500000.0, $updated->totalDebit());
        $this->assertCount(2, $updated->lines()->get()); // old lines replaced wholesale

        $this->journals()->delete($updated);

        $this->assertSoftDeleted('fin_journals', ['id' => $updated->id]);
    }
}
