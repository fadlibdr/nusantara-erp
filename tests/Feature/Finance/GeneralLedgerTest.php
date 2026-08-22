<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\Journal;
use Modules\Finance\Services\GeneralLedgerService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Buku besar — the journal lines behind one account's balance.
 *
 * The guarantee this suite exists for is the drill-down's whole reason to be
 * trusted: the ledger's closing balance for a month is EXACTLY the figure
 * ReportService::trialBalance() prints for that account and month. A
 * drill-down that lands on a different total from the report it explains would
 * make a reader distrust both numbers, and there would be no third report to
 * settle the argument.
 */
class GeneralLedgerTest extends ErpTestCase
{
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-03 09:00:00');
        $this->seedLedger(2026);

        // Februari: setoran modal 500.000.000.
        $this->postJournal([
            ['1-1210', 500000000, 0],
            ['3-1100', 0, 500000000],
        ], '2026-02-01', 'Setoran modal');

        // Maret: penagihan termin (DPP 1.000.000.000 + PPN 110.000.000).
        $this->postJournal([
            ['1-1300', 1110000000, 0],
            ['4-1100', 0, 1000000000],
            ['2-1300', 0, 110000000],
        ], '2026-03-10', 'Invoice termin 1');

        // Maret: tagihan material 300.000.000.
        $this->postJournal([
            ['5-1100', 300000000, 0],
            ['2-1100', 0, 300000000],
        ], '2026-03-15', 'Tagihan material');

        // Maret: pelunasan sebagian 200.000.000.
        $this->postJournal([
            ['1-1210', 200000000, 0],
            ['1-1300', 0, 200000000],
        ], '2026-03-20', 'Penerimaan bank');
    }

    private function ledger(): GeneralLedgerService
    {
        return app(GeneralLedgerService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function march(string $code, array $overrides = []): array
    {
        return $this->ledger()->ledger(
            $this->accountId($code),
            $overrides['from'] ?? '2026-03-01',
            $overrides['to'] ?? '2026-03-31',
            $overrides['project_id'] ?? null,
            $overrides['page'] ?? 1,
            $overrides['per_page'] ?? null,
        );
    }

    // ------------------------------------------------------------ the pin

    public function test_the_closing_balance_equals_the_trial_balance_for_every_account(): void
    {
        $trialBalance = $this->reports()->trialBalance(2026, 3);
        $this->assertNotEmpty($trialBalance['rows']);

        foreach ($trialBalance['rows'] as $row) {
            $ledger = $this->march($row['account_code']);

            $this->assertSame(
                $row['closing_debit'],
                $ledger['closing_debit'],
                "Buku besar {$row['account_code']} tidak sama dengan neraca saldo (debit).",
            );
            $this->assertSame(
                $row['closing_credit'],
                $ledger['closing_credit'],
                "Buku besar {$row['account_code']} tidak sama dengan neraca saldo (kredit).",
            );

            // The opening and the movement have to agree too, or the closing
            // figure could be right for the wrong reasons.
            $this->assertSame($row['opening_debit'], $ledger['opening_debit']);
            $this->assertSame($row['opening_credit'], $ledger['opening_credit']);
            $this->assertSame($row['debit'], $ledger['movement']['debit']);
            $this->assertSame($row['credit'], $ledger['movement']['credit']);
        }
    }

    public function test_the_closing_balance_still_ties_to_the_trial_balance_on_a_later_page(): void
    {
        // Empat penerimaan bank lagi di Maret, supaya 1-1210 punya lima baris.
        foreach ([5000000, 6000000, 7000000, 8000000] as $index => $amount) {
            $this->postJournal([
                ['1-1210', $amount, 0],
                ['1-1300', 0, $amount],
            ], '2026-03-2'.($index + 1), 'Penerimaan tambahan');
        }

        $trialBalance = $this->reports()->trialBalance(2026, 3);
        $bank = collect($trialBalance['rows'])->firstWhere('account_code', '1-1210');

        $page2 = $this->march('1-1210', ['per_page' => 2, 'page' => 2]);

        // Header figures belong to the WINDOW, never to the page: page 2 of 3
        // must still report the month's closing balance.
        $this->assertSame($bank['closing_debit'], $page2['closing_debit']);
        $this->assertSame(3, $page2['pagination']['last_page']);
        $this->assertCount(2, $page2['rows']);
    }

    // ------------------------------------------------- running balance

    public function test_the_running_balance_walks_from_the_opening_to_the_closing_balance(): void
    {
        $ledger = $this->march('1-1210');

        // Saldo awal Maret = setoran modal Februari 500.000.000.
        $this->assertSame(500000000.0, $ledger['opening']);
        $this->assertCount(1, $ledger['rows']);

        $row = $ledger['rows'][0];
        $this->assertSame(200000000.0, $row['debit']);
        $this->assertSame(0.0, $row['credit']);
        // 500.000.000 + 200.000.000 = 700.000.000.
        $this->assertSame(700000000.0, $row['balance']);
        $this->assertSame(700000000.0, $ledger['closing']);
        $this->assertSame($ledger['closing'], end($ledger['rows'])['balance']);
    }

    public function test_the_second_page_continues_the_running_balance_instead_of_restarting(): void
    {
        // Lima penerimaan 10.000.000 sehingga saldo berjalan mudah dilacak:
        // 500.000.000 awal, lalu +10 juta per baris.
        foreach (range(1, 5) as $day) {
            $this->postJournal([
                ['1-1210', 10000000, 0],
                ['1-1300', 0, 10000000],
            ], '2026-04-0'.$day, 'Penerimaan '.$day);
        }

        $april = ['from' => '2026-04-01', 'to' => '2026-04-30', 'per_page' => 2];

        $page1 = $this->march('1-1210', $april + ['page' => 1]);
        $page2 = $this->march('1-1210', $april + ['page' => 2]);
        $page3 = $this->march('1-1210', $april + ['page' => 3]);

        // Saldo awal April = saldo akhir Maret.
        $this->assertSame(700000000.0, $page1['opening']);
        $this->assertSame(700000000.0, $page1['page_opening']);
        $this->assertSame([710000000.0, 720000000.0], array_column($page1['rows'], 'balance'));

        // The point of the whole exercise: page 2 opens where page 1 stopped —
        // a running balance that restarted at 700.000.000 on every page would
        // report the third receipt as the first.
        $this->assertSame(720000000.0, $page2['page_opening']);
        $this->assertSame([730000000.0, 740000000.0], array_column($page2['rows'], 'balance'));
        $this->assertSame(740000000.0, $page3['page_opening']);
        $this->assertSame([750000000.0], array_column($page3['rows'], 'balance'));

        // And the last row of the last page is the period's closing balance.
        $this->assertSame(750000000.0, $page3['closing']);
        $this->assertSame($page3['closing'], end($page3['rows'])['balance']);
        $this->assertSame(5, $page3['pagination']['total']);
    }

    public function test_a_page_beyond_the_last_one_reports_no_rows_but_still_the_period_totals(): void
    {
        $ledger = $this->march('1-1210', ['page' => 9]);

        $this->assertSame([], $ledger['rows']);
        $this->assertSame(700000000.0, $ledger['closing']);
        $this->assertSame(1, $ledger['pagination']['total']);
    }

    // ------------------------------------------------------------ signs

    public function test_a_credit_normal_account_reads_positive_on_its_own_side(): void
    {
        // 2-1300 PPN Keluaran: kredit 110.000.000 adalah UTANG sebesar
        // 110.000.000, bukan minus 110.000.000.
        $ppn = $this->march('2-1300');
        $this->assertSame('credit', $ppn['account']['normal_balance']);
        $this->assertSame(110000000.0, $ppn['closing']);
        $this->assertSame(110000000.0, $ppn['closing_credit']);
        $this->assertSame(110000000.0, $ppn['rows'][0]['balance']);

        // The works-pair on the other side: a debit-normal expense is positive
        // too, so "positive" means "normal", not "debit".
        $beban = $this->march('5-1100');
        $this->assertSame('debit', $beban['account']['normal_balance']);
        $this->assertSame(300000000.0, $beban['closing']);
        $this->assertSame(300000000.0, $beban['closing_debit']);
    }

    public function test_a_credit_on_a_debit_normal_account_drives_the_running_balance_down(): void
    {
        // Piutang: +1.110.000.000 lalu −200.000.000 = 910.000.000.
        $ar = $this->march('1-1300');

        $this->assertSame([1110000000.0, 910000000.0], array_column($ar['rows'], 'balance'));
        $this->assertSame(910000000.0, $ar['closing']);
    }

    // --------------------------------------------------- what never shows

    public function test_a_draft_journal_never_appears_in_the_ledger(): void
    {
        $this->draftJournal([
            ['1-1210', 999000000, 0],
            ['1-1300', 0, 999000000],
        ], '2026-03-25');

        $ledger = $this->march('1-1210');

        // Satu baris saja — yang draf tidak ikut, dan saldonya tidak bergerak.
        $this->assertCount(1, $ledger['rows']);
        $this->assertSame(700000000.0, $ledger['closing']);

        // The works-pair: post an identical journal and it does show up.
        $this->postJournal([
            ['1-1210', 999000000, 0],
            ['1-1300', 0, 999000000],
        ], '2026-03-25', 'Penerimaan besar');

        $after = $this->march('1-1210');
        $this->assertCount(2, $after['rows']);
        $this->assertSame(1699000000.0, $after['closing']);
    }

    public function test_a_soft_deleted_journal_leaves_the_ledger_and_its_balance(): void
    {
        $journal = $this->postJournal([
            ['1-1210', 40000000, 0],
            ['1-1300', 0, 40000000],
        ], '2026-03-28', 'Penerimaan yang dihapus');

        $before = $this->march('1-1210');
        $this->assertCount(2, $before['rows']);
        $this->assertSame(740000000.0, $before['closing']);

        Journal::query()->whereKey($journal->id)->delete();

        $after = $this->march('1-1210');
        $this->assertCount(1, $after['rows']);
        $this->assertSame(700000000.0, $after['closing']);
    }

    public function test_lines_outside_the_date_window_are_not_in_the_rows_but_are_in_the_opening(): void
    {
        // Februari punya satu baris di 1-1210 (setoran modal). Jendela Maret
        // tidak memuatnya sebagai baris, tetapi WAJIB memuatnya sebagai saldo
        // awal — buku besar yang membuang riwayat sebelum jendelanya membuat
        // saldo berjalannya tidak berarti apa-apa.
        $march = $this->march('1-1210');
        $this->assertSame(500000000.0, $march['opening']);
        $this->assertSame(['2026-03-20'], array_column($march['rows'], 'journal_date'));

        $february = $this->march('1-1210', ['from' => '2026-02-01', 'to' => '2026-02-28']);
        $this->assertSame(0.0, $february['opening']);
        $this->assertSame(500000000.0, $february['closing']);
    }

    // ------------------------------------------------------ project lens

    public function test_the_project_filter_narrows_both_the_rows_and_the_opening_balance(): void
    {
        $project = $this->makeProject();
        $other = $this->makeProject(['name' => 'Renovasi Gudang Cikarang']);

        // Februari: 100 juta beban material untuk proyek 1 (saldo awal Maret).
        $this->postJournal([
            ['5-1100', 100000000, 0, $project->id],
            ['2-1100', 0, 100000000],
        ], '2026-02-20', 'Material Februari');

        // Maret: 50 juta proyek 1, 70 juta proyek lain.
        $this->postJournal([
            ['5-1100', 50000000, 0, $project->id],
            ['2-1100', 0, 50000000],
        ], '2026-03-18', 'Material proyek 1');
        $this->postJournal([
            ['5-1100', 70000000, 0, $other->id],
            ['2-1100', 0, 70000000],
        ], '2026-03-19', 'Material proyek 2');

        $filtered = $this->march('5-1100', ['project_id' => $project->id]);

        // Saldo awal ikut disaring: 100 juta proyek 1 saja, bukan seluruh
        // saldo perusahaan — saldo berjalan yang dibuka dari angka perusahaan
        // tidak akan pernah cocok dengan satu baris pun di bawahnya.
        $this->assertSame(100000000.0, $filtered['opening']);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame(50000000.0, $filtered['rows'][0]['debit']);
        $this->assertSame(150000000.0, $filtered['closing']);
        $this->assertSame($project->id, $filtered['rows'][0]['project_id']);
        $this->assertSame($project->code, $filtered['rows'][0]['project_code']);

        // The works-pair: unfiltered, the same account carries every project —
        // and THAT is the figure the trial balance reports.
        $all = $this->march('5-1100');
        $this->assertCount(3, $all['rows']);
        $this->assertSame(520000000.0, $all['closing']);

        $trialBalance = collect($this->reports()->trialBalance(2026, 3)['rows'])
            ->firstWhere('account_code', '5-1100');
        $this->assertSame($trialBalance['closing_debit'], $all['closing_debit']);
    }

    // ------------------------------------------------------ what a row says

    public function test_every_row_names_the_journal_and_the_document_behind_it(): void
    {
        $invoice = $this->makeArInvoiceForLedger();

        $ledger = $this->march('1-1300', ['from' => '2026-03-01', 'to' => '2026-03-31']);
        $row = collect($ledger['rows'])->firstWhere('reference_type', 'ar_invoice');

        $journal = $this->singleJournalFor('ar_invoice', (int) $invoice->id);

        $this->assertNotNull($row, 'Baris invoice harus terlihat di buku besar piutang.');
        $this->assertSame($invoice->id, $row['reference_id']);
        $this->assertSame('Invoice termin', $row['reference_label']);
        // The row points at the journal that carries it, by id and by code —
        // that pair is what the screen turns into a link, so a row nobody can
        // open would make the drill-down stop one step short.
        $this->assertSame($journal->id, $row['journal_id']);
        $this->assertSame($journal->code, $row['journal_code']);
        $this->assertSame('2026-03-22', $row['journal_date']);
        $this->assertNotEmpty($row['description']);
    }

    public function test_an_unmapped_reference_type_shows_itself_instead_of_reading_as_no_reference(): void
    {
        // postJournal() files everything under reference_type 'test', which is
        // deliberately absent from the label map: a document type a later
        // module introduces must be visible on the day it is introduced, not
        // silently blank.
        $row = $this->march('1-1210')['rows'][0];

        $this->assertSame('test', $row['reference_type']);
        $this->assertSame('test', $row['reference_label']);
    }

    // ---------------------------------------------------------- refusals

    public function test_an_account_outside_the_chart_is_refused(): void
    {
        try {
            $this->ledger()->ledger(999999, '2026-03-01', '2026-03-31');
            $this->fail('Akun yang tidak ada seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertSame('Akun #999999 tidak ditemukan di bagan akun.', $e->getMessage());
        }

        // The works-pair: a real account answers.
        $this->assertSame('1-1210', $this->march('1-1210')['account']['code']);
    }

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        try {
            $this->march('1-1210', ['from' => '2026-03-31', 'to' => '2026-03-01']);
            $this->fail('Rentang tanggal terbalik seharusnya ditolak.');
        } catch (LogicException $e) {
            $this->assertSame('Tanggal akhir tidak boleh mendahului tanggal awal.', $e->getMessage());
        }

        // The works-pair: a single-day window is legal, not an edge case.
        $oneDay = $this->march('1-1210', ['from' => '2026-03-20', 'to' => '2026-03-20']);
        $this->assertCount(1, $oneDay['rows']);
    }

    public function test_the_page_size_is_capped_so_one_request_cannot_pull_the_whole_history(): void
    {
        $huge = $this->march('1-1210', ['per_page' => 100000]);
        $this->assertSame(GeneralLedgerService::MAX_PER_PAGE, $huge['pagination']['per_page']);

        // The works-pair: a sane page size is honoured as asked.
        $this->assertSame(25, $this->march('1-1210', ['per_page' => 25])['pagination']['per_page']);
    }

    // ------------------------------------------------- the diagnosis gap

    public function test_an_account_dropped_from_the_trial_balance_still_explains_itself_here(): void
    {
        // Temuan T1/T41: menandai akun berjurnal sebagai "tidak dapat
        // diposting" membuat saldonya hilang dari neraca saldo dan neraca —
        // dan sebelum layar ini tidak ada apa pun yang menunjuk akun mana yang
        // ditandai. Buku besar tetap menyimpan barisnya dan mengatakan
        // statusnya, jadi penyebabnya bisa ditemukan.
        Account::query()->where('code', '1-1210')->update(['is_postable' => false]);

        $trialBalance = $this->reports()->trialBalance(2026, 3);
        $this->assertNull(collect($trialBalance['rows'])->firstWhere('account_code', '1-1210'));

        $ledger = $this->march('1-1210');
        $this->assertFalse($ledger['account']['is_postable']);
        $this->assertCount(1, $ledger['rows']);
        $this->assertSame(700000000.0, $ledger['closing']);
    }

    // ------------------------------------------------------------- HTTP

    public function test_the_endpoint_is_refused_without_fin_view_and_works_with_it(): void
    {
        $query = [
            'account_id' => $this->accountId('1-1210'),
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ];

        $this->actingAs($this->userWith([]), 'sanctum')
            ->getJson('/api/finance/reports/general-ledger?'.http_build_query($query))
            ->assertForbidden();

        $response = $this->actingAs($this->userWith(['fin.view']), 'sanctum')
            ->getJson('/api/finance/reports/general-ledger?'.http_build_query($query))
            ->assertOk();

        $this->assertSame('1-1210', $response->json('data.account.code'));
        $this->assertSame(500000000.0, (float) $response->json('data.opening'));
        $this->assertSame(700000000.0, (float) $response->json('data.closing'));
        $this->assertCount(1, $response->json('data.rows'));
    }

    public function test_the_endpoint_refuses_an_account_that_is_not_in_the_chart(): void
    {
        $user = $this->userWith(['fin.view']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/finance/reports/general-ledger?account_id=999999&from=2026-03-01&to=2026-03-31')
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');

        // The works-pair, through the same door.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/finance/reports/general-ledger?account_id='.$this->accountId('1-1210').'&from=2026-03-01&to=2026-03-31')
            ->assertOk();
    }

    public function test_the_endpoint_refuses_a_window_without_dates(): void
    {
        $user = $this->userWith(['fin.view']);
        $accountId = $this->accountId('1-1210');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/finance/reports/general-ledger?account_id='.$accountId)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/finance/reports/general-ledger?account_id={$accountId}&from=2026-03-31&to=2026-03-01")
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/finance/reports/general-ledger?account_id={$accountId}&from=2026-03-01&to=2026-03-31")
            ->assertOk();
    }

    // ------------------------------------------------------------ helpers

    /**
     * An approved termin invoice, so one ledger row carries a real source
     * document rather than the fixture's synthetic reference.
     */
    private function makeArInvoiceForLedger(): ArInvoice
    {
        $termin = $this->makeTermin($this->makeContract($this->makeCustomer()), 1, 'DP 20%', 20, 0);

        return $this->approveInvoice($this->arInvoices()->create([
            'termin_id' => $termin->id,
            'invoice_date' => '2026-03-22',
        ]));
    }

    /**
     * @param  array<int, string>  $permissions
     */
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
