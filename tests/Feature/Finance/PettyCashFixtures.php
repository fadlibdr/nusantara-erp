<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Kasbon;
use Modules\Finance\Models\PettyCashFund;
use Modules\Finance\Models\PettyCashVoucher;
use Modules\Finance\Services\KasbonService;
use Modules\Finance\Services\PettyCashFundService;
use Modules\Finance\Services\PettyCashVoucherService;

/**
 * Shared scaffolding for the kas kecil / kasbon suites, in the taste of
 * PeriodFixtures: it assembles rows and nothing else. Every expected number is
 * spelled out, with its arithmetic, in the test that asserts it.
 */
trait PettyCashFixtures
{
    protected function funds(): PettyCashFundService
    {
        return app(PettyCashFundService::class);
    }

    protected function vouchers(): PettyCashVoucherService
    {
        return app(PettyCashVoucherService::class);
    }

    protected function kasbons(): KasbonService
    {
        return app(KasbonService::class);
    }

    /**
     * The drawer keeper. Deliberately a THIRD person besides financeUser (the
     * maker) and financeApprover (the checker), because the custodian guard is
     * about identity, not permission — the whole suite turns on who this is.
     */
    protected function custodianUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'kasir@test.local'],
            ['name' => 'Siti Rahayu', 'password' => 'password', 'is_active' => true],
        );
    }

    /**
     * A postable 1-11xx child under the 1-1100 Kas group — what the COA CRUD
     * would create for a new drawer.
     */
    protected function makeFundAccount(string $code = '1-1110', string $name = 'Kas Kecil Kantor Pusat'): Account
    {
        return Account::query()->create([
            'code' => $code,
            'name' => $name,
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'parent_id' => $this->accountId('1-1100'),
        ]);
    }

    /**
     * A drawer with a Rp 5.000.000 float held by custodianUser(), on its own
     * fresh 1-11xx leaf.
     */
    protected function makeFund(array $attributes = []): PettyCashFund
    {
        $sequence = PettyCashFund::query()->withTrashed()->count() + 1;

        return $this->funds()->create(array_merge([
            'code' => 'KK-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Kas Kecil Kantor Pusat',
            'coa_account_id' => $this->makeFundAccount('1-11'.str_pad((string) ($sequence * 10), 2, '0', STR_PAD_LEFT))->id,
            'custodian_id' => $this->custodianUser()->id,
            'float_amount' => 5000000,
        ], $attributes));
    }

    /**
     * Opening cash in the drawer, seeded straight through the ledger the way
     * the report suites seed openings: Dr fund leaf / Cr 1-1210 Bank. The
     * production path is a replenishment PAY — exercised in its own suite.
     */
    protected function fundDrawer(PettyCashFund $fund, float $amount, string $date = '2026-06-01'): void
    {
        $this->postJournal(
            [[$fund->coaAccount->code, $amount, 0], ['1-1210', 0, $amount]],
            $date,
            "Pendanaan awal {$fund->code}",
        );
    }

    protected function makeVoucher(PettyCashFund $fund, array $attributes = []): PettyCashVoucher
    {
        return $this->vouchers()->create(array_merge([
            'fund_id' => $fund->id,
            'voucher_date' => '2026-06-10',
            'category' => 'bbm_tol',
            'description' => 'BBM + tol survei site',
            'amount' => 150000,
        ], $attributes), $this->custodianUser());
    }

    protected function makeKasbon(PettyCashFund $fund, int $employeeId, array $attributes = []): Kasbon
    {
        return $this->kasbons()->create(array_merge([
            'fund_id' => $fund->id,
            'employee_id' => $employeeId,
            'advance_date' => '2026-06-05',
            'amount' => 1000000,
            'purpose' => 'Belanja material harian minggu ini',
        ], $attributes), $this->custodianUser());
    }
}
