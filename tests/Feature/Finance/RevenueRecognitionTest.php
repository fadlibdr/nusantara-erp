<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Crm\Models\Contract;
use Modules\Crm\Services\ContractChangeOrderService;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\ArInvoice;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Services\RevenueRecognitionService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Pengakuan pendapatan PSAK 115 (d/h PSAK 72) — persentase penyelesaian.
 *
 * Revenue was recognised when a termin invoice was approved. PSAK 115 replaced
 * PSAK 34 in 2020 and recognises construction revenue OVER TIME by progress;
 * billing is a payment schedule, not performance. The live data made the gap
 * concrete: a 20% down payment sat in revenue for work 0,5% complete.
 *
 * The numbers asserted here are the worked examples from
 * docs/KEBIJAKAN-PENDAPATAN.md, spelled out with their arithmetic.
 */
class RevenueRecognitionTest extends ErpTestCase
{
    use FinanceFixtures;

    private RevenueRecognitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLedger(2026);
        $this->service = app(RevenueRecognitionService::class);
    }

    // -------------------------------------------------------------- fixtures

    private function contractWithProject(array $contractAttributes = []): array
    {
        $contract = $this->makeContract($this->makeCustomer(), array_merge([
            'value' => 1_000_000_000,
        ], $contractAttributes));

        $project = Project::query()->create([
            'code' => 'PRJ-'.str()->random(6),
            'name' => 'Proyek '.$contract->code,
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => 'construction',
            'status' => 'active',
        ]);

        return [$contract, $project];
    }

    private function makeRap(Project $project, float $total, string $status = 'approved'): void
    {
        $boqId = DB::table('est_boqs')->insertGetId([
            'code' => 'BOQ-'.str()->random(6),
            'title' => 'BOQ '.$project->code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('est_cost_budgets')->insert([
            'code' => 'RAP-'.str()->random(6),
            'boq_id' => $boqId,
            'project_id' => $project->id,
            'target_margin_pct' => 15,
            'total_budget' => $total,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Written through ProjectCostService::record() — the production write path
     * — NOT DB::table()->insert(). The raw insert stored bare 'Y-m-d' strings,
     * which the Eloquent `date` cast never produces ('2026-06-30 00:00:00'),
     * and that difference is exactly what hid the last-day-of-period bug from
     * forty passing tests.
     */
    private function addCost(Project $project, string $date, float $amount): void
    {
        $this->projectCosts()->record(
            $project->id,
            $date,
            CostCategory::Material,
            'test',
            (int) DB::table('fin_project_costs')->count() + 1,
            'Biaya uji',
            $amount,
        );
    }

    private function bill(Contract $contract, string $date, float $dpp, ?Project $against = null): ArInvoice
    {
        return $this->approveInvoice($this->arInvoices()->create([
            'customer_id' => $contract->customer_id,
            'contract_id' => $contract->id,
            'project_id' => $against?->id,
            'description' => 'Termin uji',
            'dpp' => $dpp,
            'ppn_rate' => 0.0,
            'invoice_date' => $date,
        ]));
    }

    /** A second site under the same contract — the multi-project shape. */
    private function addSite(Contract $contract, string $type = 'construction'): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-'.str()->random(6),
            'name' => 'Site B '.$contract->code,
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => $type,
            'status' => 'active',
        ]);
    }

    /** Through the real service, so the contract value moves the way it does live. */
    private function approveChangeOrder(Contract $contract, string $changeDate, float $valueChange): void
    {
        $service = app(ContractChangeOrderService::class);

        $order = $service->create([
            'contract_id' => $contract->id,
            'change_date' => $changeDate,
            'title' => 'Pekerjaan tambah uji',
            'value_change' => $valueChange,
        ]);

        $order->submit($this->financeUser());
        $service->approve($order->refresh(), $this->financeApprover());
    }

    private function cancellationDate(): string
    {
        $journal = DB::table('fin_journals')
            ->where('reference_type', 'ar_invoice_cancellation')
            ->orderBy('id')
            ->first();

        $this->assertNotNull($journal, 'pembatalan harus meninggalkan jurnal pembalik');

        return substr((string) $journal->journal_date, 0, 10);
    }

    private function balanceOf(string $accountCode): float
    {
        $accountId = $this->accountId($accountCode);

        $line = JournalLine::query()
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return round((float) $line->d - (float) $line->c, 2);
    }

    // ------------------------------------------------------ the core arithmetic

    /**
     * Kontrak 1.000jt, RAP 800jt, biaya 200jt → 25% selesai → pendapatan 250jt.
     * Tertagih 400jt → lebih tagih 150jt = LIABILITAS kontrak, dan run perdana
     * menarik 150jt keluar dari pendapatan (catch-up kumulatif).
     */
    public function test_billing_ahead_of_progress_becomes_a_contract_liability(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);
        $this->bill($contract, '2026-03-15', 400_000_000);

        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $line = $run->lines->firstWhere('contract_id', $contract->id);

        $this->assertEqualsWithDelta(25.0, (float) $line->progress_pct, 0.001);
        $this->assertEqualsWithDelta(250_000_000, (float) $line->revenue_cumulative, 0.01);
        $this->assertEqualsWithDelta(-150_000_000, (float) $line->contract_balance, 0.01);

        $this->service->post($run, $this->financeUser());

        // Ledger after: revenue shows EARNED, the excess billing sits in 2-1410.
        $this->assertEqualsWithDelta(-250_000_000, $this->balanceOf('4-1100'), 0.01, 'pendapatan buku besar = yang dihasilkan, bukan yang ditagih');
        $this->assertEqualsWithDelta(-150_000_000, $this->balanceOf('2-1410'), 0.01);
        $this->assertEqualsWithDelta(0, $this->balanceOf('1-1360'), 0.01);
    }

    /** Kebalikannya: kerja mendahului tagihan → aset kontrak. */
    public function test_progress_ahead_of_billing_becomes_a_contract_asset(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 400_000_000);   // 50% → earned 500jt
        $this->bill($contract, '2026-03-15', 200_000_000);

        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $this->service->post($run, $this->financeUser());

        $this->assertEqualsWithDelta(300_000_000, $this->balanceOf('1-1360'), 0.01);
        $this->assertEqualsWithDelta(-500_000_000, $this->balanceOf('4-1100'), 0.01);
    }

    /**
     * Para 45: tanpa RAP tidak ada estimasi andal → margin nol — pendapatan
     * sebesar biaya, bukan nol dan bukan tebakan.
     */
    public function test_a_project_without_a_budget_earns_zero_margin_revenue(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->addCost($project, '2026-03-10', 120_000_000);

        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $line = $run->lines->firstWhere('contract_id', $contract->id);

        $this->assertSame('none', $line->eac_source);
        $this->assertEqualsWithDelta(120_000_000, (float) $line->revenue_cumulative, 0.01);
    }

    /** Biaya menembus EAC: kemajuan mentok 100%, tidak pernah 120%. */
    public function test_progress_is_capped_at_one_hundred_percent(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 500_000_000);
        $this->addCost($project, '2026-03-10', 600_000_000);

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        $this->assertEqualsWithDelta(100.0, (float) $line->progress_pct, 0.001);
        $this->assertEqualsWithDelta(1_000_000_000, (float) $line->revenue_cumulative, 0.01);
    }

    // ------------------------------------------------------------ period deltas

    /** Run kedua hanya memposting SELISIH periode, bukan mengulang kumulatif. */
    public function test_a_later_period_posts_only_the_delta(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);   // 25% → 250jt
        $this->bill($contract, '2026-03-15', 400_000_000);

        $this->service->post($this->service->calculate(2026, 3, $this->financeUser()), $this->financeUser());

        $this->addCost($project, '2026-04-10', 200_000_000);   // kumulatif 50% → 500jt

        $april = $this->service->calculate(2026, 4, $this->financeUser());
        $line = $april->lines->firstWhere('contract_id', $contract->id);

        // Saldo baru: 500 − 400 = +100jt; sebelumnya −150jt → penyesuaian +250jt.
        $this->assertEqualsWithDelta(250_000_000, (float) $line->revenue_adjustment, 0.01);

        $this->service->post($april, $this->financeUser());

        // Melintasi nol: liabilitas 150 habis, aset 100 muncul — dua-duanya benar.
        $this->assertEqualsWithDelta(0, $this->balanceOf('2-1410'), 0.01);
        $this->assertEqualsWithDelta(100_000_000, $this->balanceOf('1-1360'), 0.01);
        $this->assertEqualsWithDelta(-500_000_000, $this->balanceOf('4-1100'), 0.01);
    }

    /**
     * CCO menaikkan nilai kontrak di antara dua periode → catch-up kumulatif
     * (para 21(b)) terjadi dengan sendirinya karena kumulatif selalu dihitung
     * dari nilai kontrak TERKINI.
     */
    public function test_an_approved_change_order_catches_up_cumulatively(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 400_000_000);   // 50%

        $this->service->post($this->service->calculate(2026, 3, $this->financeUser()), $this->financeUser());
        $this->assertEqualsWithDelta(-500_000_000, $this->balanceOf('4-1100'), 0.01);

        // Pekerjaan tambah disetujui: nilai kontrak 1.000jt → 1.200jt.
        $contract->forceFill(['value' => 1_200_000_000])->save();

        $april = $this->service->calculate(2026, 4, $this->financeUser());
        $line = $april->lines->firstWhere('contract_id', $contract->id);

        // Kumulatif baru 50% × 1.200 = 600jt; penyesuaian = +100jt catch-up.
        $this->assertEqualsWithDelta(600_000_000, (float) $line->revenue_cumulative, 0.01);
        $this->assertEqualsWithDelta(100_000_000, (float) $line->revenue_adjustment, 0.01);
    }

    // -------------------------------------------------------- onerous contracts

    /**
     * PSAK 237: EAC 1.200jt > harga 1.000jt pada kemajuan 50% → separuh rugi
     * sudah lewat margin negatif, separuh sisanya diprovisikan SEKARANG.
     */
    public function test_an_onerous_contract_provides_the_full_expected_loss_at_once(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 1_200_000_000);
        $this->addCost($project, '2026-03-10', 600_000_000);   // 50%

        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $line = $run->lines->firstWhere('contract_id', $contract->id);

        $this->assertEqualsWithDelta(100_000_000, (float) $line->provision_balance, 0.01);

        $this->service->post($run, $this->financeUser());

        $this->assertEqualsWithDelta(100_000_000, $this->balanceOf('5-1600'), 0.01);
        $this->assertEqualsWithDelta(-100_000_000, $this->balanceOf('2-1700'), 0.01);
    }

    /** Provisi dilepas seiring kemajuan — di akhir proyek saldonya nol. */
    public function test_the_loss_provision_unwinds_as_the_work_completes(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 1_200_000_000);
        $this->addCost($project, '2026-03-10', 600_000_000);
        $this->service->post($this->service->calculate(2026, 3, $this->financeUser()), $this->financeUser());

        $this->addCost($project, '2026-04-10', 600_000_000);   // 100%
        $this->service->post($this->service->calculate(2026, 4, $this->financeUser()), $this->financeUser());

        $this->assertEqualsWithDelta(0, $this->balanceOf('2-1700'), 0.01);
        $this->assertEqualsWithDelta(0, $this->balanceOf('5-1600'), 0.01);
    }

    // ------------------------------------------------------------------ scope

    /** Pemeliharaan: penagihan berkala ≈ garis lurus — mesin tidak menyentuhnya. */
    public function test_maintenance_contracts_are_left_on_the_billing_basis(): void
    {
        $this->makeContract($this->makeCustomer(), [
            'scope_type' => 'maintenance',
            'value' => 120_000_000,
        ]);

        $run = $this->service->calculate(2026, 3, $this->financeUser());

        $this->assertCount(0, $run->lines);
    }

    /** Kontrak draf belum memenuhi para 9 — tidak ikut diakui. */
    public function test_a_draft_contract_is_not_recognised(): void
    {
        [$contract] = $this->contractWithProject();
        $contract->forceFill(['status' => DocumentStatus::Draft])->save();

        $this->assertCount(0, $this->service->calculate(2026, 3, $this->financeUser())->lines);
    }

    // -------------------------------------------------------------- discipline

    public function test_a_posted_period_cannot_be_recalculated(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 100_000_000);
        $this->service->post($this->service->calculate(2026, 3, $this->financeUser()), $this->financeUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sudah diposting/');

        $this->service->calculate(2026, 3, $this->financeUser());
    }

    /** Sejarah hanya bertambah maju: periode lama tidak boleh diposting belakangan. */
    public function test_an_older_period_cannot_be_posted_after_a_newer_one(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-04-10', 100_000_000);
        $this->service->post($this->service->calculate(2026, 4, $this->financeUser()), $this->financeUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/periode sesudahnya|tidak dapat diposting/');

        $this->service->calculate(2026, 3, $this->financeUser());
    }

    public function test_a_posted_run_cannot_be_deleted(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 100_000_000);
        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $this->service->post($run, $this->financeUser());

        $this->expectException(LogicException::class);

        $this->service->delete($run->refresh());
    }

    /** EAC hasil telaah manajemen tidak menguap karena tombol hitung ulang. */
    public function test_an_eac_override_survives_recalculation(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);

        $this->service->calculate(2026, 3, $this->financeUser(), [$contract->id => 1_000_000_000.0]);
        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        $this->assertSame('override', $line->eac_source);
        $this->assertEqualsWithDelta(1_000_000_000, (float) $line->estimated_total_cost, 0.01);
        // 200/1.000 = 20%, bukan 25% versi RAP.
        $this->assertEqualsWithDelta(20.0, (float) $line->progress_pct, 0.001);
    }

    /** Jurnal run selalu seimbang — diperiksa dari buku besarnya sendiri. */
    public function test_the_adjusting_journal_balances(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 1_200_000_000);            // sekaligus onerous
        $this->addCost($project, '2026-03-10', 600_000_000);
        $this->bill($contract, '2026-03-15', 100_000_000);

        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $this->service->post($run, $this->financeUser());

        $journal = DB::table('fin_journals')
            ->where('reference_type', 'revenue_recognition')
            ->where('reference_id', $run->id)
            ->first();

        $this->assertNotNull($journal);
        $sums = JournalLine::query()->where('journal_id', $journal->id)
            ->selectRaw('SUM(debit) as d, SUM(credit) as c')->first();
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.01);
    }

    // --------------------------------------- regressions from the adversarial review

    /**
     * TEMUAN KRITIS: dua draf dihitung saat belum ada run terposting — keduanya
     * berbasis nol. Yang satu diposting, lalu yang lain diposting TANPA hitung
     * ulang → catch-up kumulatif terhitung dua kali. Posting kini menghitung
     * ulang dari basis data, sehingga draf basi tidak mungkin diposting.
     */
    public function test_posting_a_stale_draft_recomputes_instead_of_double_counting(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);   // 25% → 250jt

        $march = $this->service->calculate(2026, 3, $this->financeUser());
        $april = $this->service->calculate(2026, 4, $this->financeUser());   // draf basi: basis nol

        $this->service->post($march, $this->financeUser());
        $this->service->post($april->refresh(), $this->financeUser());

        // Tanpa hitung ulang otomatis, buku besar berakhir 500jt — dobel.
        $this->assertEqualsWithDelta(-250_000_000, $this->balanceOf('4-1100'), 0.01);
        $this->assertEqualsWithDelta(250_000_000, $this->balanceOf('1-1360'), 0.01);
    }

    /** Biaya yang mendarat di antara hitung dan posting ikut terhitung. */
    public function test_posting_uses_the_database_not_the_draft_on_screen(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);

        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $this->addCost($project, '2026-03-20', 200_000_000);   // datang setelah draf

        $this->service->post($run, $this->financeUser());

        // 400/800 = 50% × 1.000jt — bukan angka draf 250jt.
        $this->assertEqualsWithDelta(-500_000_000, $this->balanceOf('4-1100'), 0.01);
    }

    /**
     * Periode masa depan ditolak: salah ketik Desember akan menyapu setahun ke
     * satu jurnal dan — karena posting hanya maju — mengunci semua bulan di
     * antaranya tanpa jalan pulang.
     */
    public function test_a_future_period_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/belum berakhir/');

        $this->service->calculate(now()->year + 1, 1, $this->financeUser());
    }

    /**
     * Akun pendapatan mengikuti resolusi yang SAMA dengan invoice (tipe proyek
     * menang) — penyesuaian harus mendarat di akun yang dikredit penagihan,
     * atau disagregasi pendapatan per akun menjadi bohong.
     */
    public function test_the_adjustment_lands_on_the_same_account_the_invoice_credited(): void
    {
        [$contract, $project] = $this->contractWithProject(['scope_type' => 'system_integration']);
        // Proyeknya diketik 'construction' — invoice akan mengkredit 4-1100.
        $project->forceFill(['type' => 'construction'])->save();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 100_000_000);   // 12,5% → 125jt
        $this->bill($contract, '2026-03-15', 200_000_000);

        $this->service->post($this->service->calculate(2026, 3, $this->financeUser()), $this->financeUser());

        // Seluruh pergerakan di 4-1100; 4-1200 tidak tersentuh.
        $this->assertEqualsWithDelta(-125_000_000, $this->balanceOf('4-1100'), 0.01);
        $this->assertEqualsWithDelta(0, $this->balanceOf('4-1200'), 0.01);
    }

    /** RAP berstatus DITOLAK bukan estimasi siapa pun — jatuh ke margin nol. */
    public function test_a_rejected_rap_does_not_set_the_denominator_of_revenue(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 500_000_000, 'rejected');
        $this->addCost($project, '2026-03-10', 100_000_000);

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        $this->assertSame('none', $line->eac_source);
        $this->assertEqualsWithDelta(100_000_000, (float) $line->revenue_cumulative, 0.01);
    }

    /** Kontrak dua proyek: biaya KEDUA proyek masuk pengukuran kemajuan. */
    public function test_a_contract_split_over_two_projects_counts_both_cost_bases(): void
    {
        [$contract, $projectA] = $this->contractWithProject();
        $projectB = Project::query()->create([
            'code' => 'PRJ-'.str()->random(6),
            'name' => 'Site B '.$contract->code,
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => 'construction',
            'status' => 'active',
        ]);
        $this->makeRap($projectA, 400_000_000);
        $this->makeRap($projectB, 400_000_000);
        $this->addCost($projectA, '2026-03-10', 100_000_000);
        $this->addCost($projectB, '2026-03-10', 100_000_000);

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        // 200 / 800 gabungan = 25%, bukan 25%-nya satu site saja.
        $this->assertEqualsWithDelta(800_000_000, (float) $line->estimated_total_cost, 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $line->progress_pct, 0.001);
    }

    /**
     * Baris bertanggal HARI TERAKHIR periode ikut terukur. Eloquent menyimpan
     * kolom ber-cast `date` sebagai '2026-03-31 00:00:00', yang menurut
     * perbandingan string mentah lebih BESAR dari '2026-03-31' — sehingga
     * payroll dan penyusutan (selalu bertanggal akhir bulan) serta invoice
     * termin akhir bulan dulu hilang dari pengukuran bulannya sendiri.
     */
    public function test_costs_and_invoices_dated_on_the_last_day_of_the_period_are_measured(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 100_000_000);
        $this->addCost($project, '2026-03-31', 100_000_000);   // upah akhir bulan
        $this->bill($contract, '2026-03-31', 400_000_000);     // termin akhir bulan

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        // 200/800 = 25% → pendapatan 250jt; tertagih 400jt → liabilitas 150jt.
        // Tanpa baris akhir bulan: biaya 100jt (12,5%) dan tertagih NOL — bulan
        // ini overstated Rp 400jt di pendapatan.
        $this->assertEqualsWithDelta(200_000_000, (float) $line->cost_to_date, 0.01);
        $this->assertEqualsWithDelta(400_000_000, (float) $line->billed_cumulative, 0.01);
        $this->assertEqualsWithDelta(-150_000_000, (float) $line->contract_balance, 0.01);
    }

    /** Periode fiskal tertutup menolak posting — juga saat tidak ada pergerakan. */
    public function test_a_closed_fiscal_period_refuses_even_a_no_movement_posting(): void
    {
        FiscalPeriod::query()
            ->where('year', 2026)->where('month', 3)
            ->update(['status' => 'closed']);

        $run = $this->service->calculate(2026, 3, $this->financeUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak terbuka/');

        $this->service->post($run, $this->financeUser());
    }

    // ------------------------------------------------ EAC coverage, not presence

    /**
     * Satu RAP di antara dua site yang sama-sama berbiaya bukan estimasi
     * kontrak: penyebutnya menutupi satu site sementara pembilangnya
     * menghitung biaya keduanya.
     */
    public function test_a_rap_covering_only_some_of_the_cost_bearing_projects_falls_to_zero_margin(): void
    {
        [$contract, $projectA] = $this->contractWithProject();
        $projectB = $this->addSite($contract);            // dimobilisasi belakangan, belum ada RAP
        $this->makeRap($projectA, 400_000_000);
        $this->addCost($projectA, '2026-03-10', 100_000_000);
        $this->addCost($projectB, '2026-03-10', 100_000_000);

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        // Dengan penyebut hanya site A: 200/400 = 50% x 1.000jt = 500jt, dan
        // barisnya berlabel `rap_approved` — 250jt pendapatan terlalu dini yang
        // permanen begitu run-nya diposting. Para 45: margin nol, 200jt.
        $this->assertSame('none', $line->eac_source);
        $this->assertEqualsWithDelta(0, (float) $line->estimated_total_cost, 0.01);
        $this->assertEqualsWithDelta(200_000_000, (float) $line->revenue_cumulative, 0.01);
    }

    /** Site terdaftar yang belum berbiaya tidak membatalkan estimasi yang ada. */
    public function test_a_project_carrying_no_cost_yet_does_not_invalidate_the_estimate(): void
    {
        [$contract, $projectA] = $this->contractWithProject();
        $this->addSite($contract);                        // tanpa RAP, tanpa biaya
        $this->makeRap($projectA, 400_000_000);
        $this->addCost($projectA, '2026-03-10', 100_000_000);

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        $this->assertSame('rap_approved', $line->eac_source);
        $this->assertEqualsWithDelta(400_000_000, (float) $line->estimated_total_cost, 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $line->progress_pct, 0.001);
    }

    /** Jalan keluar dari cakupan sebagian: EAC manajemen berlaku sekontrak. */
    public function test_a_management_eac_measures_a_contract_its_raps_only_partly_cover(): void
    {
        [$contract, $projectA] = $this->contractWithProject();
        $projectB = $this->addSite($contract);
        $this->makeRap($projectA, 400_000_000);
        $this->addCost($projectA, '2026-03-10', 100_000_000);
        $this->addCost($projectB, '2026-03-10', 100_000_000);

        $line = $this->service->calculate(2026, 3, $this->financeUser(), [$contract->id => 800_000_000.0])
            ->lines->firstWhere('contract_id', $contract->id);

        $this->assertSame('override', $line->eac_source);
        $this->assertEqualsWithDelta(25.0, (float) $line->progress_pct, 0.001);
        $this->assertEqualsWithDelta(250_000_000, (float) $line->revenue_cumulative, 0.01);
    }

    // ------------------------------------------ harga transaksi per akhir periode

    /**
     * CCO yang disetujui SETELAH bulannya berakhir tidak boleh menyajikan
     * ulang bulan itu. Tutup buku di sini berjalan minggu pertama bulan
     * berikutnya, jadi CCO 2 Agustus sudah menempel di crm_contracts.value
     * ketika run Juli dihitung 3 Agustus.
     */
    public function test_a_change_order_approved_after_the_period_ended_does_not_restate_that_period(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-06-10', 400_000_000);      // 50%

        $this->service->post($this->service->calculate(2026, 6, $this->financeUser()), $this->financeUser());
        $this->assertEqualsWithDelta(-500_000_000, $this->balanceOf('4-1100'), 0.01);

        $this->approveChangeOrder($contract, '2026-08-02', 400_000_000);
        $this->assertEqualsWithDelta(1_400_000_000, (float) $contract->refresh()->value, 0.01);

        $line = $this->service->calculate(2026, 7, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        // Tanpa cut-off: 50% x 1.400jt = 700jt, dan Juli mendapat 200jt
        // pendapatan atas lingkup yang belum ada pada 31 Juli — di jurnal
        // bertanggal 31 Juli, pada run yang tidak dapat dihitung ulang lagi.
        $this->assertEqualsWithDelta(1_000_000_000, (float) $line->transaction_price, 0.01);
        $this->assertEqualsWithDelta(500_000_000, (float) $line->revenue_cumulative, 0.01);
        $this->assertEqualsWithDelta(0, (float) $line->revenue_adjustment, 0.01);
    }

    /** CCO bertanggal DALAM periode tetap terukur di bulannya — catch-up 21(b). */
    public function test_a_change_order_dated_inside_the_period_is_measured_in_that_period(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-06-10', 400_000_000);      // 50%

        $this->service->post($this->service->calculate(2026, 6, $this->financeUser()), $this->financeUser());

        $this->approveChangeOrder($contract, '2026-07-20', 400_000_000);

        $line = $this->service->calculate(2026, 7, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        // 50% x 1.400jt = 700jt kumulatif → penyesuaian +200jt di Juli.
        $this->assertEqualsWithDelta(1_400_000_000, (float) $line->transaction_price, 0.01);
        $this->assertEqualsWithDelta(700_000_000, (float) $line->revenue_cumulative, 0.01);
        $this->assertEqualsWithDelta(200_000_000, (float) $line->revenue_adjustment, 0.01);
    }

    // ----------------------------------------- tertagih per akhir periode, dua sumbu

    /**
     * Invoice yang sudah diukur run terposting lalu dibatalkan: pembalikannya
     * bertanggal HARI INI (JournalService::reversalDate, karena Maret sudah
     * terukur), sehingga catch-up POC-nya tidak boleh mendarat di April —
     * bulan yang tidak sedang disunting siapa pun.
     */
    public function test_cancelling_a_measured_invoice_does_not_catch_up_in_an_earlier_month(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);      // 25% → 250jt
        $invoice = $this->bill($contract, '2026-03-15', 400_000_000);

        $this->service->post($this->service->calculate(2026, 3, $this->financeUser()), $this->financeUser());
        $this->assertEqualsWithDelta(-150_000_000, $this->balanceOf('2-1410'), 0.01);

        $this->arInvoices()->cancel($invoice->refresh(), $this->financeApprover(), 'Termin salah tagih');
        $this->assertSame(now()->toDateString(), $this->cancellationDate());

        $line = $this->service->calculate(2026, 4, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        // Tagihan itu masih hidup di buku besar pada 30 April, jadi April tidak
        // bergerak. Difilter pada status TERKINI, April mengkredit 400jt yang
        // tidak ditagihkan siapa pun dan Agustus mendebit 400jt yang sama.
        $this->assertEqualsWithDelta(400_000_000, (float) $line->billed_cumulative, 0.01);
        $this->assertEqualsWithDelta(0, (float) $line->revenue_adjustment, 0.01);
    }

    /**
     * Dan catch-up-nya mendarat di bulan pembalikan, bersebelahan dengan jurnalnya.
     *
     * Jam dibekukan SEBELUM cancel(): reversalDate() menanggalkan pembalikan
     * pada "hari ini" karena Maret sudah terukur, dan tes ini lalu menghitung
     * Agustus. Ditulis pada Agustus 2026 tes ini kebetulan lulus; diukur 2 Sep
     * 2026 (HASIL-UJI-UX-2026-09 §2.1) ia merah karena pembalikan mendarat di
     * September sementara asersinya membaca Agustus. Bulan yang diuji adalah
     * bagian dari skenario, bukan tanggal kalender pelari tesnya.
     */
    public function test_the_catch_up_lands_in_the_month_the_reversal_was_posted(): void
    {
        $this->travelTo('2026-08-20');

        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);
        $invoice = $this->bill($contract, '2026-03-15', 400_000_000);

        $this->service->post($this->service->calculate(2026, 3, $this->financeUser()), $this->financeUser());
        $this->arInvoices()->cancel($invoice->refresh(), $this->financeApprover(), 'Termin salah tagih');
        $this->assertSame('2026-08-20', $this->cancellationDate());

        // Run Agustus baru dapat dihitung setelah Agustus berakhir.
        $this->travelTo('2026-09-05');
        $agustus = $this->service->calculate(2026, 8, $this->financeUser());
        $line = $agustus->lines->firstWhere('contract_id', $contract->id);

        $this->assertEqualsWithDelta(0, (float) $line->billed_cumulative, 0.01);
        $this->assertEqualsWithDelta(400_000_000, (float) $line->revenue_adjustment, 0.01);

        $this->service->post($agustus, $this->financeUser());

        // Pembalikan dan catch-up saling meniadakan di dalam satu bulan, dan
        // kumulatifnya berakhir tepat pada yang dihasilkan: 250jt.
        $this->assertEqualsWithDelta(-250_000_000, $this->balanceOf('4-1100'), 0.01);
        $this->assertEqualsWithDelta(0, $this->balanceOf('2-1410'), 0.01);
        $this->assertEqualsWithDelta(250_000_000, $this->balanceOf('1-1360'), 0.01);
    }

    /**
     * Kebalikannya, dan inilah sebabnya tanggal jurnal pembalik yang dibaca
     * dan bukan cancelled_at: bila belum ada run yang mengukur bulannya,
     * pembalikan mendarat di tanggal invoice sendiri — tagihan itu tidak
     * pernah hidup di akhir periode mana pun, jadi Maret harus melihat NOL.
     */
    public function test_an_invoice_reversed_back_into_its_own_month_was_never_billed_at_period_end(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);      // 25% → 250jt
        $invoice = $this->bill($contract, '2026-03-15', 400_000_000);

        $this->arInvoices()->cancel($invoice->refresh(), $this->financeApprover(), 'Termin salah tagih');
        $this->assertSame('2026-03-15', $this->cancellationDate());

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        $this->assertEqualsWithDelta(0, (float) $line->billed_cumulative, 0.01);
        $this->assertEqualsWithDelta(250_000_000, (float) $line->contract_balance, 0.01);
    }

    // ------------------------------------- akun pendapatan: input yang sama, bukan hanya aturan

    /**
     * Kontrak dua site dengan aliran pendapatan berbeda: penyesuaian mengikuti
     * akun yang BENAR-BENAR dikredit invoice-nya, bukan tipe proyek pertama.
     * Kalau tidak, 4-1100 ditinggal bersaldo DEBIT 150jt dan pengungkapan
     * disagregasi (docs/KEBIJAKAN-PENDAPATAN.md §8) salah di kedua aliran.
     */
    public function test_the_adjustment_follows_the_account_the_invoice_credited_on_a_multi_project_contract(): void
    {
        [$contract, $projectA] = $this->contractWithProject();
        $projectB = $this->addSite($contract, 'system_integration');
        $this->makeRap($projectA, 400_000_000);
        $this->makeRap($projectB, 400_000_000);
        $this->addCost($projectA, '2026-03-10', 100_000_000);
        $this->addCost($projectB, '2026-03-10', 100_000_000);     // 200/800 = 25% → 250jt

        $this->bill($contract, '2026-03-15', 400_000_000, $projectB);

        $run = $this->service->calculate(2026, 3, $this->financeUser());
        $line = $run->lines->firstWhere('contract_id', $contract->id);

        $this->assertSame('4-1200', $line->revenue_account);

        $this->service->post($run, $this->financeUser());

        $this->assertEqualsWithDelta(-250_000_000, $this->balanceOf('4-1200'), 0.01);
        $this->assertEqualsWithDelta(0, $this->balanceOf('4-1100'), 0.01);
    }

    /** Belum ditagih berarti belum ada invoice untuk diikuti — proyek utama yang menjawab. */
    public function test_an_unbilled_contract_takes_its_revenue_account_from_the_primary_project(): void
    {
        [$contract, $project] = $this->contractWithProject(['scope_type' => 'system_integration']);
        $project->forceFill(['type' => 'construction'])->save();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 100_000_000);

        $line = $this->service->calculate(2026, 3, $this->financeUser())
            ->lines->firstWhere('contract_id', $contract->id);

        $this->assertSame('4-1100', $line->revenue_account);
    }

    // -------------------------------------------------------------- endpoints

    public function test_the_endpoints_calculate_and_post(): void
    {
        [$contract, $project] = $this->contractWithProject();
        $this->makeRap($project, 800_000_000);
        $this->addCost($project, '2026-03-10', 200_000_000);
        $admin = $this->adminUser();

        $created = $this->actingAs($admin)
            ->postJson('/api/finance/revenue-recognition', ['period_year' => 2026, 'period_month' => 3])
            ->assertCreated();

        $id = $created->json('data.id');
        $this->assertEqualsWithDelta(25.0, (float) $created->json('data.lines.0.progress_pct'), 0.001);
        $this->assertEqualsWithDelta(750_000_000, (float) $created->json('data.lines.0.remaining_performance_obligation'), 0.01);

        $this->actingAs($admin)
            ->postJson("/api/finance/revenue-recognition/{$id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        // Ditolak dengan pesan, bukan 500, saat diposting dua kali.
        $this->actingAs($admin)
            ->postJson("/api/finance/revenue-recognition/{$id}/post")
            ->assertStatus(422);
    }
}
