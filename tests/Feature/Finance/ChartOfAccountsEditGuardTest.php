<?php

namespace Tests\Feature\Finance;

use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\NormalBalance;
use Modules\Finance\Models\Account;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * The chart of accounts is master data for as long as it is empty and part of
 * the ledger's meaning the moment it is not.
 *
 * Every report iterates ACCOUNTS and sums LINES: ReportService::trialBalance(),
 * profitLoss() and balanceSheet() all select
 * `Account::where('is_postable', true)` while sumsPerAccount() sums every
 * posted line, so an account_id present in the sums but absent from the loop
 * is dropped from the rows AND from the totals. PUT /api/finance/accounts/{id}
 * used to apply `$account->update($request->validated())` with no check at all,
 * on a model whose BaseModel sets $guarded = [].
 *
 * Two shapes of harm, one of them silent:
 *
 *   is_postable off  — Rp 10.767.000.000 of bank cash leaves the balance sheet
 *                      while the ledger stays balanced to the rupiah, and
 *                      PeriodCloseService's `trial_balance_balanced` BLOCK
 *                      fails with no acknowledge path, so that month and every
 *                      month after it cannot be closed. LOUD but undiagnosed —
 *                      no screen names the account that was toggled.
 *   account_type     — the same balance moves between the balance sheet and
 *                      the P&L with BOTH `balanced` flags still true and the
 *                      close checklist still clean. SILENT.
 *
 * The rule already existed at the three other points an account can move
 * (AccountController::destroy, UpdateSettingsRequest::
 * rejectRepointingAnAccountInUse, migration 2026_08_01_001109); this asserts
 * it at the fourth, and asserts just as hard that ordinary master-data upkeep
 * on the same account still works — the edit form submits every field on every
 * save, so a guard that refused unchanged values would make renaming an
 * account impossible for the rest of its life.
 */
class ChartOfAccountsEditGuardTest extends ErpTestCase
{
    use FinanceFixtures;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        // The demo dataset's own shape: 1-1220 Bank Mandiri Proyek carrying
        // Rp 10.767.000.000 of posted opening cash.
        $this->postJournal([
            ['1-1220', 10767000000, 0],
            ['3-3100', 0, 10767000000],
        ], '2026-02-05', 'Saldo awal bank proyek');

        $this->bank = Account::query()->where('code', '1-1220')->firstOrFail();
    }

    // ------------------------------------------------------------ refused

    public function test_an_account_with_posted_lines_cannot_be_turned_into_a_group(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$this->bank->id}", $this->form(['is_postable' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_postable');

        $this->assertStringContainsString('Rp 10.767.000.000,00', $response->json('errors.is_postable.0'));
        $this->assertStringContainsString('1-1220', $response->json('errors.is_postable.0'));

        $this->assertTrue((bool) $this->bank->fresh()->is_postable);

        // The consequence the guard exists for: the trial balance still sees
        // the money and the balance sheet still balances.
        $trialBalance = $this->reports()->trialBalance(2026, 2);
        $balanceSheet = $this->reports()->balanceSheet('2026-02-28');

        $this->assertTrue($trialBalance['balanced']);
        $this->assertSame(10767000000.0, $trialBalance['totals']['closing_debit']);
        $this->assertTrue($balanceSheet['balanced']);
        $this->assertSame(10767000000.0, $balanceSheet['assets']['total']);
    }

    public function test_an_account_with_posted_lines_cannot_change_its_type(): void
    {
        // The silent half: asset -> expense moves the balance from the neraca
        // to the laba rugi with every `balanced` flag still true.
        $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$this->bank->id}", $this->form(['account_type' => 'expense']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_type');

        $this->assertSame(AccountType::Asset, $this->bank->fresh()->account_type);
        $this->assertSame(10767000000.0, $this->reports()->balanceSheet('2026-02-28')['assets']['total']);
    }

    public function test_an_account_with_posted_lines_cannot_be_renumbered(): void
    {
        // Services resolve accounts by code (JournalService::accountId), so a
        // rename breaks the next posting rather than this one.
        $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$this->bank->id}", $this->form(['code' => '1-1221']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame('1-1220', $this->bank->fresh()->code);
    }

    public function test_an_account_with_posted_lines_cannot_flip_its_normal_balance(): void
    {
        $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$this->bank->id}", $this->form(['normal_balance' => 'credit']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('normal_balance');

        $this->assertSame(NormalBalance::Debit, $this->bank->fresh()->normal_balance);
    }

    public function test_a_draft_journal_line_seals_the_account_just_as_a_posted_one_does(): void
    {
        // Same test destroy() applies. post() re-checks the balance and the
        // period but never re-checks that the account is still postable, so a
        // draft line is a posted line the moment somebody presses Posting.
        $cash = Account::query()->where('code', '1-1210')->firstOrFail();

        $this->journals()->create([
            'journal_date' => '2026-03-10',
            'description' => 'Jurnal draf atas 1-1210',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 1000000, 'credit' => 0],
                ['account_id' => $this->accountId('4-1100'), 'debit' => 0, 'credit' => 1000000],
            ],
        ]);

        $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$cash->id}", [
                'code' => '1-1210',
                'name' => 'Bank BCA Operasional',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'is_postable' => false,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_postable');

        $this->assertTrue((bool) $cash->fresh()->is_postable);
    }

    // ------------------------------------------------------------ still works

    public function test_an_account_with_posted_lines_can_still_be_renamed_and_deactivated(): void
    {
        // The whole form comes back on every save, sealed fields included —
        // unchanged, so only the name and the flag move.
        $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$this->bank->id}", $this->form([
                'name' => 'Bank Mandiri Proyek (ditutup bertahap)',
                'is_active' => false,
            ]))
            ->assertOk();

        $fresh = $this->bank->fresh();

        $this->assertSame('Bank Mandiri Proyek (ditutup bertahap)', $fresh->name);
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertTrue((bool) $fresh->is_postable);
    }

    public function test_an_account_that_has_never_been_posted_to_stays_fully_editable(): void
    {
        // Install-time mapping onto a company's own chart: everything moves
        // while the account is empty, which is the window that work belongs in.
        $spare = Account::query()->create([
            'code' => '6-4900',
            'name' => 'Beban Lain-lain',
            'account_type' => AccountType::Expense,
            'normal_balance' => NormalBalance::Debit,
            'is_postable' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$spare->id}", [
                'code' => '6-4910',
                'name' => 'Beban Lain-lain (grup)',
                'account_type' => 'other',
                'normal_balance' => 'credit',
                'is_postable' => false,
                'is_active' => true,
            ])
            ->assertOk();

        $fresh = $spare->fresh();

        $this->assertSame('6-4910', $fresh->code);
        $this->assertSame(AccountType::Other, $fresh->account_type);
        $this->assertFalse((bool) $fresh->is_postable);
    }

    public function test_an_account_wrongly_flipped_to_a_group_can_be_ticked_back_on(): void
    {
        // The repair path for a flip made before this guard existed. Only the
        // direction that HIDES the balance is refused: an account carrying
        // lines while not postable is already the broken state, and refusing
        // the way back would need database surgery to undo.
        $this->bank->forceFill(['is_postable' => false])->save();

        $this->assertFalse($this->reports()->balanceSheet('2026-02-28')['balanced']);

        $this->actingAs($this->adminUser())
            ->putJson("/api/finance/accounts/{$this->bank->id}", $this->form(['is_postable' => true]))
            ->assertOk();

        $this->assertTrue((bool) $this->bank->fresh()->is_postable);
        $this->assertTrue($this->reports()->balanceSheet('2026-02-28')['balanced']);
    }

    /**
     * The payload the edit screen sends: every field of the record, with only
     * the overrides changed.
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'code' => '1-1220',
            'name' => 'Bank Mandiri Proyek',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
        ], $overrides);
    }
}
