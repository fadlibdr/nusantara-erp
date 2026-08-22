<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use LogicException;
use Modules\Finance\Models\Account;
use Modules\Finance\Services\CashFlowService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Laporan arus kas PSAK 2 metode langsung. The invariant that carries the
 * whole report: saldo awal + operasi + investasi + pendanaan + lainnya =
 * saldo akhir, with opening/closing recomputed from independent GL sums —
 * dropping any cash-touching line is arithmetically impossible to hide.
 */
class CashFlowStatementTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->makeBankAccount('1-1210');
        $this->makeBankAccount('1-1220', ['code' => 'BANK-MDR', 'name' => 'Mandiri Proyek']);
    }

    private function cashFlows(): CashFlowService
    {
        return app(CashFlowService::class);
    }

    private function activityRow(array $report, string $activity, string $code): ?array
    {
        foreach ($report['activities'][$activity]['rows'] as $row) {
            if ($row['account_code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    public function test_a_customer_receipt_is_an_operating_inflow_on_its_counter_account(): void
    {
        // Dr Bank / Cr Piutang — the counter line 1-1300 carries the story.
        $this->postJournal([
            ['1-1210', 200000000, 0],
            ['1-1300', 0, 200000000],
        ], '2026-03-20', 'Penerimaan termin');

        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $row = $this->activityRow($report, 'operasi', '1-1300');
        $this->assertSame(200000000.0, $row['inflow']);
        $this->assertSame(0.0, $row['outflow']);
        $this->assertSame(200000000.0, $report['activities']['operasi']['total']);
        $this->assertSame(200000000.0, $report['net_change']);
        $this->assertTrue($report['reconciled']);
    }

    public function test_an_asset_purchase_is_investing_and_a_loan_drawdown_is_financing(): void
    {
        $this->postJournal([
            ['1-2400', 150000000, 0],
            ['1-1210', 0, 150000000],
        ], '2026-03-10', 'Beli peralatan proyek');
        $this->postJournal([
            ['1-1210', 500000000, 0],
            ['2-2100', 0, 500000000],
        ], '2026-03-12', 'Pencairan kredit investasi');

        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $this->assertSame(-150000000.0, $report['activities']['investasi']['total']);
        $this->assertSame(150000000.0, $this->activityRow($report, 'investasi', '1-2400')['outflow']);
        $this->assertSame(500000000.0, $report['activities']['pendanaan']['total']);
        // 500.000.000 - 150.000.000 = 350.000.000
        $this->assertSame(350000000.0, $report['net_change']);
        $this->assertTrue($report['reconciled']);
    }

    public function test_a_wapu_receipt_decomposes_into_gross_settlement_and_tax_slices(): void
    {
        // The PaymentService receipt shape: invoice 1.110.000.000 settled in
        // full while the owner keeps PPN 110.000.000 (wapu) and PPh final
        // 17.500.000 — the bank receives 982.500.000.
        $this->postJournal([
            ['1-1210', 982500000, 0],
            ['1-1700', 17500000, 0],
            ['2-1300', 110000000, 0],
            ['1-1300', 0, 1110000000],
        ], '2026-04-05', 'Penerimaan termin dipotong pajak');

        $report = $this->cashFlows()->statement('2026-04-01', '2026-04-30');

        // Per counter line, no proration: gross receipt on 1-1300, the two
        // withheld slices as operating outflows.
        $this->assertSame(1110000000.0, $this->activityRow($report, 'operasi', '1-1300')['inflow']);
        $this->assertSame(17500000.0, $this->activityRow($report, 'operasi', '1-1700')['outflow']);
        $this->assertSame(110000000.0, $this->activityRow($report, 'operasi', '2-1300')['outflow']);
        // 1.110.000.000 - 17.500.000 - 110.000.000 = 982.500.000 = uang masuk bank
        $this->assertSame(982500000.0, $report['activities']['operasi']['total']);
        $this->assertTrue($report['reconciled']);
    }

    public function test_an_unmapped_counter_account_lands_visibly_in_lainnya_and_still_reconciles(): void
    {
        Account::query()->create([
            'code' => '9-9999',
            'name' => 'Akun Uji Tanpa Peta',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
        ]);

        $journal = $this->postJournal([
            ['9-9999', 1000000, 0],
            ['1-1210', 0, 1000000],
        ], '2026-03-15', 'Pengeluaran tak terpetakan');

        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $row = $this->activityRow($report, 'lainnya', '9-9999');
        $this->assertSame(1000000.0, $row['outflow']);
        // The evidence trail: the journal code rides the row so an accountant
        // can open the exact voucher instead of hunting.
        $this->assertContains($journal->code, $row['journal_codes']);
        $this->assertSame(-1000000.0, $report['activities']['lainnya']['total']);
        // Visible AND counted: lainnya participates in the reconciliation.
        $this->assertSame(-1000000.0, $report['net_change']);
        $this->assertTrue($report['reconciled']);
    }

    public function test_a_pure_bank_to_bank_transfer_moves_no_activity(): void
    {
        $this->postJournal([
            ['1-1220', 300000000, 0],
            ['1-1210', 0, 300000000],
        ], '2026-03-18', 'Pindah dana ke rekening proyek');

        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $this->assertSame(0.0, $report['activities']['operasi']['total']);
        $this->assertSame(0.0, $report['activities']['investasi']['total']);
        $this->assertSame(0.0, $report['activities']['pendanaan']['total']);
        $this->assertSame(0.0, $report['activities']['lainnya']['total']);
        $this->assertSame(300000000.0, $report['internal_transfers']);
        $this->assertSame(0.0, $report['net_change']);
        $this->assertTrue($report['reconciled']);
    }

    public function test_a_transfer_journal_carrying_an_admin_fee_classifies_only_the_fee(): void
    {
        // Dr Mandiri 999.000 + Dr Beban Admin 1.000 / Cr BCA 1.000.000 —
        // only the 7-2100 line is a counter line, so the transfer part
        // self-resolves without being flagged as an internal transfer.
        $this->postJournal([
            ['1-1220', 999000, 0],
            ['7-2100', 1000, 0],
            ['1-1210', 0, 1000000],
        ], '2026-03-19', 'Transfer antar bank dengan biaya admin');

        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $this->assertSame(-1000.0, $report['activities']['operasi']['total']);
        $this->assertSame(1000.0, $this->activityRow($report, 'operasi', '7-2100')['outflow']);
        $this->assertSame(0.0, $report['internal_transfers']);
        $this->assertSame(-1000.0, $report['net_change']);
        $this->assertTrue($report['reconciled']);
    }

    public function test_draft_and_soft_deleted_journals_never_reach_the_statement(): void
    {
        $this->draftJournal([
            ['1-1210', 999000000, 0],
            ['1-1300', 0, 999000000],
        ], '2026-03-25');

        $deleted = $this->postJournal([
            ['1-1210', 777000000, 0],
            ['1-1300', 0, 777000000],
        ], '2026-03-26', 'Jurnal yang kemudian dihapus');
        $deleted->delete();

        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $this->assertSame(0.0, $report['activities']['operasi']['total']);
        $this->assertSame(0.0, $report['net_change']);
        $this->assertTrue($report['reconciled']);
    }

    /**
     * THE identity: the statement must tie to the change in cash balances for
     * the period, including a boundary journal on the very last day — the
     * whereDate lesson DanglingDocuments documents.
     */
    public function test_the_statement_ties_to_the_change_in_bank_balances_for_the_period(): void
    {
        // Saldo pembuka Februari: setoran modal 400.000.000.
        $this->postJournal([
            ['1-1210', 400000000, 0],
            ['3-1100', 0, 400000000],
        ], '2026-02-10', 'Setoran modal');

        // Maret: hari pertama, tengah bulan, dan HARI TERAKHIR.
        $this->postJournal([
            ['1-1210', 100000000, 0],
            ['1-1300', 0, 100000000],
        ], '2026-03-01', 'Penerimaan awal bulan');
        $this->postJournal([
            ['5-1100', 60000000, 0],
            ['1-1210', 0, 60000000],
        ], '2026-03-15', 'Bayar material tunai');
        $this->postJournal([
            ['1-1210', 25000000, 0],
            ['1-1300', 0, 25000000],
        ], '2026-03-31', 'Penerimaan hari terakhir');

        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $this->assertSame(400000000.0, $report['opening_balance']);
        // 400.000.000 + 100.000.000 - 60.000.000 + 25.000.000 = 465.000.000
        $this->assertSame(465000000.0, $report['closing_balance']);
        // 100.000.000 - 60.000.000 + 25.000.000 = 65.000.000
        $this->assertSame(65000000.0, $report['activities']['operasi']['total']);
        $this->assertSame(65000000.0, $report['net_change']);
        $this->assertSame(
            $report['closing_balance'],
            round($report['opening_balance'] + $report['net_change'], 2),
        );
        $this->assertTrue($report['reconciled']);

        // Per-account roll-forward for the pool.
        $bca = collect($report['accounts'])->firstWhere('account_code', '1-1210');
        $this->assertSame(400000000.0, $bca['opening']);
        $this->assertSame(465000000.0, $bca['closing']);
    }

    public function test_the_psak_electives_are_printed_on_the_report(): void
    {
        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');

        $this->assertSame([
            'Bunga diterima (7-11xx) disajikan sebagai aktivitas operasi.',
            'Bunga pinjaman dibayar (7-2200) disajikan sebagai aktivitas pendanaan.',
        ], $report['policy']);
    }

    public function test_a_from_date_after_the_to_date_is_refused(): void
    {
        try {
            $this->cashFlows()->statement('2026-03-31', '2026-03-01');
            $this->fail('A reversed period should be refused.');
        } catch (LogicException $e) {
            $this->assertSame("Statement 'from' date must not be after 'to'.", $e->getMessage());
        }

        // The works-pair: the same bounds in the right order pass.
        $report = $this->cashFlows()->statement('2026-03-01', '2026-03-31');
        $this->assertTrue($report['reconciled']);
    }

    // ------------------------------------------------------------- endpoint

    public function test_the_endpoint_requires_fin_view_and_validates_its_period(): void
    {
        $this->actingAs($this->userWith([]), 'sanctum')
            ->getJson('/api/finance/reports/cash-flow?from=2026-03-01&to=2026-03-31')
            ->assertForbidden();

        $viewer = $this->userWith(['fin.view']);

        // to before from dies in validation, never reaching the service.
        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/finance/reports/cash-flow?from=2026-03-31&to=2026-03-01')
            ->assertStatus(422);

        // A period report has no sensible default — from/to are required.
        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/finance/reports/cash-flow')
            ->assertStatus(422);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/finance/reports/cash-flow?from=2026-03-01&to=2026-03-31')
            ->assertOk();

        $this->assertTrue($response->json('data.reconciled'));
        $this->assertSame('2026-03-01', $response->json('data.from'));
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
