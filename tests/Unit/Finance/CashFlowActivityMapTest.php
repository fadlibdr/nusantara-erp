<?php

namespace Tests\Unit\Finance;

use Modules\Finance\Models\Account;
use Modules\Finance\Support\CashFlowActivityMap;
use Tests\ErpTestCase;

/**
 * The declarative counter-account map behind the PSAK 2 statement. The
 * completeness test is the important one: 'lainnya' exists for accounts
 * created AFTER this map, and this suite is what keeps it from quietly
 * becoming a dumping ground for accounts the seeder already ships.
 */
class CashFlowActivityMapTest extends ErpTestCase
{
    use FinanceFixtures;

    public function test_the_longest_prefix_wins_so_loan_interest_is_pendanaan(): void
    {
        // 7-2200 Beban Bunga Pinjaman is the PSAK 2 elective presented as
        // pendanaan; its siblings 7-2100 and 7-2300 stay operasi. A
        // shorter-prefix rule must never swallow the specific one.
        $this->assertSame('pendanaan', CashFlowActivityMap::activityFor('7-2200'));
        $this->assertSame('operasi', CashFlowActivityMap::activityFor('7-2100'));
        $this->assertSame('operasi', CashFlowActivityMap::activityFor('7-2300'));
        $this->assertSame('operasi', CashFlowActivityMap::activityFor('7-1100'));
    }

    public function test_each_activity_reads_its_own_family(): void
    {
        $this->assertSame('operasi', CashFlowActivityMap::activityFor('1-1300'));
        $this->assertSame('operasi', CashFlowActivityMap::activityFor('1-1370'));
        $this->assertSame('operasi', CashFlowActivityMap::activityFor('2-1110'));
        $this->assertSame('operasi', CashFlowActivityMap::activityFor('5-1300'));
        $this->assertSame('investasi', CashFlowActivityMap::activityFor('1-2400'));
        $this->assertSame('investasi', CashFlowActivityMap::activityFor('1-2210'));
        $this->assertSame('pendanaan', CashFlowActivityMap::activityFor('2-2100'));
        $this->assertSame('pendanaan', CashFlowActivityMap::activityFor('3-3100'));
    }

    public function test_an_unmapped_code_returns_null_instead_of_guessing(): void
    {
        $this->assertNull(CashFlowActivityMap::activityFor('9-9999'));
        $this->assertNull(CashFlowActivityMap::activityFor('8-1000'));
    }

    /**
     * COMPLETENESS against ChartOfAccountsSeeder: every postable account the
     * seeder ships either belongs to the cash pool or maps to an activity.
     * A new seeded account failing here must be placed in the map — 'lainnya'
     * is for future site-specific accounts, not for laziness today.
     */
    public function test_every_seeded_postable_account_outside_the_pool_is_mapped(): void
    {
        $this->seedLedger(2026);

        $pool = array_flip(CashFlowActivityMap::cashAccountIds());

        $accounts = Account::query()->where('is_postable', true)->get();
        $this->assertNotEmpty($accounts);

        foreach ($accounts as $account) {
            if (isset($pool[(int) $account->id])) {
                continue;
            }

            $this->assertNotNull(
                CashFlowActivityMap::activityFor((string) $account->code),
                "Account {$account->code} {$account->name} is seeded but unmapped — "
                .'place it in CashFlowActivityMap::PREFIXES.',
            );
        }
    }

    public function test_the_cash_pool_keeps_a_deleted_banks_coa_and_gains_new_drawer_leaves(): void
    {
        $this->seedLedger(2026);

        $pool = CashFlowActivityMap::cashAccountIds();
        // The seeded bank leaves are cash from day one, BEFORE any
        // fin_bank_accounts row claims them: a fresh install hand-keying
        // Dr 1-1220 / Cr 1-1210 is moving cash, not creating an activity.
        $this->assertContains($this->accountId('1-1210'), $pool);
        $this->assertContains($this->accountId('1-1220'), $pool);
        // Kas is a postable leaf as seeded, so it is cash.
        $this->assertContains($this->accountId('1-1100'), $pool);
        // Receivables are promises, not cash.
        $this->assertNotContains($this->accountId('1-1300'), $pool);

        // A bank account pointed at a CUSTOM code outside 1-11/1-12 joins the
        // pool only through its fin_bank_accounts claim…
        $valas = Account::query()->create([
            'code' => '1-1910',
            'name' => 'Bank Valas USD',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
        ]);
        $this->assertNotContains((int) $valas->id, CashFlowActivityMap::cashAccountIds());

        $bank = $this->makeBankAccount('1-1910', ['code' => 'BANK-VALAS', 'name' => 'Bank Valas USD']);
        $this->assertContains((int) $valas->id, CashFlowActivityMap::cashAccountIds());

        // …and closing that bank account must NOT reclassify its history.
        $bank->delete();
        $this->assertContains((int) $valas->id, CashFlowActivityMap::cashAccountIds());

        // A kas kecil drawer leaf joins the pool with no code change at all.
        $drawer = Account::query()->create([
            'code' => '1-1110',
            'name' => 'Kas Kecil Kantor Pusat',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'parent_id' => $this->accountId('1-1100'),
        ]);
        $this->assertContains((int) $drawer->id, CashFlowActivityMap::cashAccountIds());
    }
}
