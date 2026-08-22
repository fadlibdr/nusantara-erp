<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\PettyCashFund;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Kas kecil master data: the COA fence around a drawer, and the balance math
 * everything else (ceilings, imprest amounts, deactivation) reads from.
 */
class PettyCashFundTest extends ErpTestCase
{
    use FinanceFixtures;
    use PettyCashFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
    }

    // ------------------------------------------------------------ chart shape

    public function test_the_seeded_chart_adds_the_kasbon_receivable_in_the_receivable_family(): void
    {
        $receivable = Account::query()->where('code', '1-1370')->firstOrFail();

        $this->assertSame('Piutang Karyawan (Kasbon)', $receivable->name);
        $this->assertTrue($receivable->is_postable);
        $this->assertSame('asset', $receivable->account_type->value);
        $this->assertSame('debit', $receivable->normal_balance->value);
        $this->assertSame($this->accountId('1-1000'), (int) $receivable->parent_id);
    }

    /**
     * The 1-1100 flip lives ONLY in migration 2026_08_01_001109 — the seeder
     * ships it postable (Core's suites pin that) and preserves whatever the
     * migration decided. Both halves of the guard are exercised by running
     * the migration's up() against a seeded chart.
     */
    public function test_the_migration_flips_kas_to_a_group_only_while_it_has_no_postings(): void
    {
        $migration = require base_path(
            'Modules/Finance/Database/Migrations/2026_08_01_001109_add_petty_cash_accounts.php'
        );

        // Fresh chart, zero lines on 1-1100: the flip happens.
        $this->assertTrue(Account::query()->where('code', '1-1100')->firstOrFail()->is_postable);
        $migration->up();
        $this->assertFalse(Account::query()->where('code', '1-1100')->firstOrFail()->is_postable);

        // And re-seeding does NOT undo it — the installation owns the state.
        $this->seed(ChartOfAccountsSeeder::class);
        $this->assertFalse(Account::query()->where('code', '1-1100')->firstOrFail()->is_postable);

        // An installation that HAS posted to 1-1100 keeps its postable leaf:
        // posting-time is_postable checks must never invalidate history.
        Account::query()->where('code', '1-1100')->update(['is_postable' => true]);
        $this->postJournal([['1-1100', 1000000, 0], ['4-1100', 0, 1000000]], '2026-03-10', 'Kas berjalan');
        $migration->up();
        $this->assertTrue(Account::query()->where('code', '1-1100')->firstOrFail()->is_postable);
    }

    // ------------------------------------------------------------- COA guards

    public function test_a_fund_on_its_own_postable_kas_child_works(): void
    {
        $fund = $this->makeFund();

        $this->assertSame('KK-01', $fund->code);
        $this->assertSame($this->custodianUser()->id, (int) $fund->custodian_id);
        $this->assertSame(0.0, $this->funds()->balance($fund));
    }

    public function test_a_fund_on_a_group_account_is_refused(): void
    {
        $group = Account::query()->where('code', '1-1200')->firstOrFail(); // Bank, a group

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/akun kelompok/');

        $this->makeFund(['coa_account_id' => (int) $group->id]);
    }

    public function test_a_fund_on_a_liability_account_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/bukan akun aset/');

        $this->makeFund(['coa_account_id' => $this->accountId('2-1110')]);
    }

    public function test_a_fund_on_a_bank_accounts_coa_leaf_is_refused(): void
    {
        $this->makeBankAccount('1-1210');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah dipakai rekening bank/');

        $this->makeFund(['coa_account_id' => $this->accountId('1-1210')]);
    }

    public function test_two_funds_sharing_one_account_are_refused(): void
    {
        $first = $this->makeFund();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah dipakai kas kecil KK-01/');

        $this->makeFund(['code' => 'KK-XX', 'coa_account_id' => (int) $first->coa_account_id]);
    }

    /**
     * Temuan #5: the guard's own messages promised 1-11xx but nothing
     * enforced it. 1-1500 Uang Muka Proyek is postable, active, asset,
     * debit-normal, unclaimed by any bank or fund — it passed every check,
     * and then CashFlowActivityMap (pooling 1-11%/1-12% only) read a
     * Rp 5.000.000 bank→drawer top-up as an OPERATING OUTFLOW, closing cash
     * excluded the drawer, and bankBalances() omitted it entirely.
     */
    public function test_a_fund_outside_the_kas_family_is_refused_even_on_a_postable_asset_leaf(): void
    {
        // Straight through the service (makeFund would mint an unused 1-11xx
        // leaf per attempt): the refused account is the whole point here.
        $outside = fn (string $code): PettyCashFund => $this->funds()->create([
            'code' => 'KK-'.substr($code, 2),
            'name' => 'Kas Kecil Salah Akun',
            'coa_account_id' => $this->accountId($code),
            'custodian_id' => $this->custodianUser()->id,
            'float_amount' => 5000000,
        ]);

        try {
            $outside('1-1500');
            $this->fail('A drawer outside the 1-11xx Kas family must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('1-1500', $e->getMessage());
            $this->assertStringContainsString('bukan akun kas 1-11xx', $e->getMessage());
        }

        // 1-1370 is worse still: a kasbon issue would book Dr 1-1370 /
        // Cr 1-1370 and the drawer balance would never move.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/bukan akun kas 1-11xx/');

        $outside('1-1370');
    }

    // ------------------------------------------------------------ balance math

    public function test_the_drawer_balance_is_the_posted_gl_balance_of_the_fund_account(): void
    {
        $fund = $this->makeFund();

        // Rp 5.000.000 in, Rp 750.000 out: the drawer holds 4.250.000 and the
        // imprest gap back to the float is exactly the 750.000 that left.
        $this->fundDrawer($fund, 5000000, '2026-06-01');
        $this->postJournal([['6-4100', 750000, 0], [$fund->coaAccount->code, 0, 750000]], '2026-06-08', 'ATK kantor');

        $this->assertSame(4250000.0, $this->funds()->balance($fund));
        $this->assertSame(750000.0, $this->funds()->replenishmentDue($fund));
    }

    // ----------------------------------------------- deactivation and deletion

    public function test_deleting_a_fund_still_holding_cash_is_refused(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/masih memegang saldo/');

        $this->funds()->delete($fund);
    }

    public function test_deleting_a_fund_with_document_history_is_refused_but_an_unused_one_deletes(): void
    {
        $used = $this->makeFund();
        $this->makeVoucher($used);

        try {
            $this->funds()->delete($used);
            $this->fail('Expected the delete to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('riwayat voucher/kasbon', $e->getMessage());
        }

        $unused = $this->makeFund(['code' => 'KK-BARU']);
        $this->funds()->delete($unused);

        $this->assertSoftDeleted('fin_petty_cash_funds', ['id' => $unused->id]);
    }

    /**
     * The generic list screen reads payload.data as an ARRAY. A bare paginator
     * serialises as an object with its own nested data key — truthy, no
     * .length — so "Kas Kecil & Kasbon" rendered its empty state forever while
     * the drawer existed, and Ekspor CSV stayed disabled.
     */
    public function test_the_fund_index_returns_a_flat_array_with_pagination_in_meta(): void
    {
        $fund = $this->makeFund();

        $response = $this->actingAs($this->adminUser())
            ->getJson('/api/finance/petty-cash-funds')
            ->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('current_page', $data, 'the paginator must not leak into data');
        $this->assertSame($fund->code, $data[0]['code']);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.current_page'));
    }

    public function test_repointing_the_coa_account_is_refused_while_the_drawer_holds_cash(): void
    {
        $fund = $this->makeFund();
        $this->fundDrawer($fund, 5000000);

        $other = $this->makeFundAccount('1-1190', 'Kas Kecil Cadangan');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak dapat diganti selama saldonya bukan nol/');

        $this->funds()->update($fund, ['coa_account_id' => $other->id]);
    }
}
