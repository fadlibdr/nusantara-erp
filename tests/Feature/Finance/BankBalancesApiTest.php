<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Finance\Models\Account;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * GET finance/reports/bank-balances — the dashboard tile's feed. The payload
 * is a PLAIN ARRAY on purpose: dashboard.js consumes list endpoints through
 * safe() and reduces client-side, so the tile itself stays a ~6-line seam for
 * the dashboard owners. What this suite pins: balances are POSTED GL sums
 * (drafts never count), inactive banks stay out ONCE THEY ARE EMPTY — never
 * while they still hold money, or a master-data flag could move the company's
 * cash total — and every postable 1-11% account without a bank row — Kas and
 * each kas kecil drawer — appears as a synthetic kas row so drawer money is
 * never invisible on the dashboard.
 */
class BankBalancesApiTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 09:00:00');
        $this->seedLedger(2026);
        $this->makeBankAccount('1-1210');
    }

    public function test_the_endpoint_is_refused_without_fin_view_and_works_with_it(): void
    {
        $this->actingAs($this->userWith([]), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')
            ->assertForbidden();

        // Saldo BCA: setoran 400 jt masuk, 150 jt keluar = 250 jt.
        $this->postJournal([
            ['1-1210', 400000000, 0],
            ['3-1100', 0, 400000000],
        ], '2026-08-01', 'Setoran modal');
        $this->postJournal([
            ['5-1100', 150000000, 0],
            ['1-1210', 0, 150000000],
        ], '2026-08-10', 'Bayar material');

        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')
            ->assertOk();

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->isNotEmpty());

        $bca = $rows->firstWhere('coa_code', '1-1210');
        $this->assertNotNull($bca['bank_account_id']);
        $this->assertSame('BCA Operasional', $bca['name']);
        // 400.000.000 − 150.000.000 = 250.000.000, per GL terposting.
        $this->assertSame(250000000.0, (float) $bca['balance']);
        $this->assertSame('2026-08-15', $bca['as_of']);

        // Kas is a postable leaf without a fin_bank_accounts row, so it rides
        // along as the synthetic kas row.
        $kas = $rows->firstWhere('coa_code', '1-1100');
        $this->assertNull($kas['bank_account_id']);
        $this->assertSame('KAS', $kas['code']);
        $this->assertNull($kas['bank_name']);
    }

    public function test_draft_journals_never_move_a_dashboard_balance(): void
    {
        $this->postJournal([
            ['1-1210', 100000000, 0],
            ['3-1100', 0, 100000000],
        ], '2026-08-01', 'Setoran modal');
        // A Rp 900 jt draft: visible in the journal list, invisible to cash.
        $this->draftJournal([
            ['1-1210', 900000000, 0],
            ['1-1300', 0, 900000000],
        ], '2026-08-12');

        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')
            ->assertOk();

        $bca = collect($response->json('data'))->firstWhere('coa_code', '1-1210');
        $this->assertSame(100000000.0, (float) $bca['balance']);
    }

    public function test_an_inactive_bank_account_is_excluded(): void
    {
        $this->makeBankAccount('1-1220', [
            'code' => 'BANK-MDR', 'name' => 'Mandiri Lama', 'is_active' => false,
        ]);

        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')
            ->assertOk();

        $rows = collect($response->json('data'));
        $this->assertNull($rows->firstWhere('coa_code', '1-1220'));
        // The works-pair: the active bank is still there.
        $this->assertNotNull($rows->firstWhere('coa_code', '1-1210'));
    }

    /**
     * …but only once it is EMPTY. is_active is a master-data flag on
     * fin_bank_accounts; it must not be able to move the company's cash total.
     * Marking BANK-MDR-PRJ inactive while Rp 11.352.000.000 was still in it
     * dropped the dashboard tile to −Rp 331.045.000 — a company apparently out
     * of cash — while the balance sheet, the PSAK 2 statement and
     * CashFlowActivityMap::cashAccountIds all still read the true figure.
     * Nothing on the tile said an account had been hidden.
     */
    public function test_an_inactive_bank_account_still_holding_money_is_not_hidden(): void
    {
        $this->makeBankAccount('1-1220', [
            'code' => 'BANK-MDR', 'name' => 'Mandiri Lama', 'is_active' => false,
        ]);
        // Rekening yang sedang ditutup, tapi uangnya belum dipindahkan.
        $this->postJournal([
            ['1-1220', 750000000, 0],
            ['3-1100', 0, 750000000],
        ], '2026-08-02', 'Setoran modal Mandiri');

        $rows = collect($this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')
            ->assertOk()
            ->json('data'));

        $mandiri = $rows->firstWhere('coa_code', '1-1220');
        $this->assertNotNull($mandiri, 'Money in a deactivated rekening must stay visible.');
        $this->assertSame(750000000.0, (float) $mandiri['balance']);
        // Ditandai, bukan disembunyikan — dasbor bisa menyebutkannya.
        $this->assertFalse($mandiri['is_active']);
        $this->assertTrue($rows->firstWhere('coa_code', '1-1210')['is_active']);
    }

    /**
     * Soft deletion is the same argument: cashAccountIds() reads
     * BankAccount::withTrashed() precisely because the general ledger still
     * holds the money of a rekening somebody deleted.
     */
    public function test_a_soft_deleted_bank_account_still_holding_money_is_not_hidden(): void
    {
        $mandiri = $this->makeBankAccount('1-1220', ['code' => 'BANK-MDR', 'name' => 'Mandiri Lama']);
        $this->postJournal([
            ['1-1220', 400000000, 0],
            ['3-1100', 0, 400000000],
        ], '2026-08-02', 'Setoran modal Mandiri');
        $mandiri->delete();

        $rows = collect($this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')
            ->assertOk()
            ->json('data'));

        $row = $rows->firstWhere('coa_code', '1-1220');
        $this->assertNotNull($row);
        $this->assertSame(400000000.0, (float) $row['balance']);
        $this->assertFalse($row['is_active']);
    }

    /**
     * The same rule applied to a kas kecil drawer: an emptied one stops
     * cluttering the tile, one that still holds cash never disappears from it.
     */
    public function test_a_deactivated_drawer_is_hidden_only_when_it_is_empty(): void
    {
        $drawer = Account::query()->create([
            'code' => '1-1110',
            'name' => 'Kas Kecil Proyek Selesai',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => false,
            'parent_id' => $this->accountId('1-1100'),
        ]);

        $rows = collect($this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')->assertOk()->json('data'));
        $this->assertNull($rows->firstWhere('coa_code', '1-1110'));

        // Rp 2.500.000 masih di laci: sekarang harus terlihat.
        $this->postJournal([
            ['1-1110', 2500000, 0],
            ['1-1210', 0, 2500000],
        ], '2026-08-05', 'Pengisian kas kecil');

        $rows = collect($this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')->assertOk()->json('data'));
        $row = $rows->firstWhere('coa_code', '1-1110');
        $this->assertNotNull($row);
        $this->assertSame(2500000.0, (float) $row['balance']);
        $this->assertFalse($row['is_active']);
        $this->assertSame($drawer->code, $row['coa_code']);
    }

    public function test_a_kas_kecil_drawer_leaf_appears_as_its_own_kas_row(): void
    {
        Account::query()->create([
            'code' => '1-1110',
            'name' => 'Kas Kecil Kantor Pusat',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'parent_id' => $this->accountId('1-1100'),
        ]);
        // Isi laci Rp 5.000.000 dari bank.
        $this->postJournal([
            ['1-1110', 5000000, 0],
            ['1-1210', 0, 5000000],
        ], '2026-08-05', 'Pengisian kas kecil');

        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/bank-balances')
            ->assertOk();

        $drawer = collect($response->json('data'))->firstWhere('coa_code', '1-1110');
        $this->assertNotNull($drawer, 'A drawer leaf must surface on the dashboard feed.');
        $this->assertNull($drawer['bank_account_id']);
        $this->assertSame('Kas Kecil Kantor Pusat', $drawer['name']);
        $this->assertSame(5000000.0, (float) $drawer['balance']);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna Uji',
            'email' => str()->random(8).'@nusantara.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
