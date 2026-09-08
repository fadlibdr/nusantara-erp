<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\WatchedThresholds;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\OverheadBudget;
use Modules\Finance\Services\OverheadBudgetService;
use Tests\ErpTestCase;

/**
 * F-2 / T2.4 — OVB, anggaran overhead per tahun buku.
 *
 * Tiga hal yang diuji, dan ketiganya adalah janji paket ini:
 *
 *  1. SATU YANG DISETUJUI PER TAHUN, dijaga di layanan DAN di basis data.
 *     Layanannya menolak dengan kalimat yang menyebut kode yang sudah berdiri;
 *     indeks uniknya menolak walau layanan dilewati sama sekali.
 *  2. REALISASI DARI JURNAL YANG SUDAH ADA — debit − kredit akun yang
 *     dianggarkan, pada jurnal TERPOSTING bertanggal tahun itu. Termasuk
 *     31 Desember, hari yang akan dibuang sebuah BETWEEN di SQLite.
 *  3. FORWARD-ONLY: menyetujui OVB tidak menulis satu baris jurnal pun.
 */
class OverheadBudgetTest extends ErpTestCase
{
    private OverheadBudgetService $service;

    private ?User $maker = null;

    private ?User $checkerUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
        $this->service = app(OverheadBudgetService::class);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(array $pairs): array
    {
        $lines = [];

        foreach ($pairs as $code => $amount) {
            $lines[] = ['account_id' => $this->account($code)->id, 'amount' => $amount];
        }

        return $lines;
    }

    private function budget(int $year, array $pairs): OverheadBudget
    {
        return $this->service->create([
            'period_year' => $year,
            'lines' => $this->lines($pairs),
        ], $this->maker());
    }

    private function postJournal(string $date, string $accountCode, float $debit): void
    {
        $journalId = DB::table('fin_journals')->insertGetId([
            'code' => 'JV/TEST/'.uniqid(),
            'journal_date' => $date,
            'description' => 'Beban overhead uji',
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fin_journal_lines')->insert([
            [
                'journal_id' => $journalId,
                'account_id' => $this->account($accountCode)->id,
                'description' => 'Beban',
                'debit' => $debit,
                'credit' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_id' => $journalId,
                'account_id' => $this->account('1-1100')->id,
                'description' => 'Kas',
                'debit' => 0,
                'credit' => $debit,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /** Pembalik: kredit pada akun bebannya, debit pada kas — jurnal terposting. */
    private function postReversal(string $date, string $accountCode, float $amount): void
    {
        $journalId = DB::table('fin_journals')->insertGetId([
            'code' => 'JV/REV/'.uniqid(),
            'journal_date' => $date,
            'description' => 'Pembalik beban overhead uji',
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fin_journal_lines')->insert([
            [
                'journal_id' => $journalId,
                'account_id' => $this->account($accountCode)->id,
                'description' => 'Pembalik beban',
                'debit' => 0,
                'credit' => $amount,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_id' => $journalId,
                'account_id' => $this->account('1-1100')->id,
                'description' => 'Kas',
                'debit' => $amount,
                'credit' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    // --------------------------------------------------- satu per tahun buku

    /**
     * Dua calon anggaran DIAJUKAN untuk tahun yang sama — itu sah, keduanya
     * masih usulan. Yang kedua ditolak saat DISETUJUI, dengan kalimat yang
     * menyebut kode anggaran yang sudah berdiri supaya orangnya tahu harus
     * membatalkan yang mana.
     */
    public function test_a_second_approved_budget_for_the_same_year_is_refused_by_the_service(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();

        $first = $this->budget(2026, ['6-1100' => 500_000_000]);
        $second = $this->budget(2026, ['6-1100' => 700_000_000]);

        $this->service->submit($first, $maker);
        $this->service->submit($second, $maker);

        $this->service->approve($first, $checker);

        $this->expectExceptionMessage('sudah punya anggaran overhead yang disetujui ('.$first->code.')');
        $this->service->approve($second, $checker);
    }

    /**
     * Dan penolakannya berbunyi LEBIH AWAL bila calon berikutnya baru diajukan
     * sesudah ada yang disetujui: mengajukan usulan yang sudah pasti tidak bisa
     * disetujui hanya memindahkan kekecewaannya ke meja penyetuju.
     */
    public function test_submitting_a_rival_after_one_is_approved_is_refused_at_submit(): void
    {
        $first = $this->budget(2026, ['6-1100' => 500_000_000]);
        $this->service->submit($first, $this->maker());
        $this->service->approve($first, $this->checker());

        $second = $this->budget(2026, ['6-1100' => 700_000_000]);

        $this->expectExceptionMessage('tidak dapat diajukan');
        $this->service->submit($second, $this->maker());
    }

    /**
     * Jaring terakhir: layanan dilewati sama sekali dan status ditulis langsung
     * ke basis data. Indeks unik parsial (SQLite) / kolom generated (MySQL)
     * dari migrasi 001500 yang menolak.
     */
    public function test_the_database_itself_refuses_two_approved_budgets_for_one_year(): void
    {
        $first = $this->budget(2026, ['6-1100' => 500_000_000]);
        $first->forceFill(['status' => DocumentStatus::Approved])->save();

        $second = $this->budget(2026, ['6-1100' => 700_000_000]);

        $this->expectException(QueryException::class);
        DB::table('fin_overhead_budgets')
            ->where('id', $second->id)
            ->update(['status' => DocumentStatus::Approved->value]);
    }

    public function test_two_draft_budgets_for_one_year_are_allowed_and_another_year_is_untouched(): void
    {
        $this->budget(2026, ['6-1100' => 500_000_000]);
        $this->budget(2026, ['6-1100' => 700_000_000]);

        $approved2026 = $this->budget(2026, ['6-1100' => 600_000_000]);
        $approved2026->forceFill(['status' => DocumentStatus::Approved])->save();

        $approved2027 = $this->budget(2027, ['6-1100' => 800_000_000]);
        $approved2027->forceFill(['status' => DocumentStatus::Approved])->save();

        $this->assertSame(4, OverheadBudget::query()->count());
        $this->assertSame($approved2026->id, $this->service->approvedFor(2026)?->id);
        $this->assertSame($approved2027->id, $this->service->approvedFor(2027)?->id);
    }

    public function test_a_budget_without_a_single_account_cannot_be_submitted(): void
    {
        $budget = $this->service->create(['period_year' => 2026], $this->maker());

        $this->expectExceptionMessage('belum punya satu akun pun');
        $this->service->submit($budget, $this->maker());
    }

    // --------------------------------------------------------------- kodenya

    /**
     * KODE OVB MEMBAWA TAHUN BUKUNYA (verifikasi F-2).
     *
     * Terukur sebelum perbaikan: OVB untuk tahun buku 2031 yang dibuat hari ini
     * menerima 'OVB/2026/IX/0001' dari fallback DocumentNumberService, dan
     * kalimat penolakan "satu per tahun" mencetak dua tahun berbeda dalam satu
     * kalimat. Urutannya pun terpisah per tahun buku: OVB kedua untuk 2031
     * adalah 0002, bukan melanjutkan ember tahun berjalan.
     */
    public function test_the_code_carries_the_budgeted_year_not_the_wall_clock_year(): void
    {
        $future = $this->budget(2031, ['6-1100' => 100_000_000]);
        $futureToo = $this->budget(2031, ['6-1100' => 200_000_000]);
        $thisYear = $this->budget(2026, ['6-1100' => 300_000_000]);

        $this->assertSame('OVB/2031/0001', $future->refresh()->code);
        $this->assertSame('OVB/2031/0002', $futureToo->refresh()->code);
        $this->assertSame('OVB/2026/0001', $thisYear->refresh()->code);

        // Dan kalimat penolakannya kini menyebut SATU tahun.
        $this->service->submit($future, $this->maker());
        $this->service->approve($future, $this->checker());

        try {
            $this->service->submit($futureToo, $this->maker());
            $this->fail('OVB kedua untuk tahun yang sama seharusnya ditolak');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Tahun buku 2031', $e->getMessage());
            $this->assertStringContainsString('OVB/2031/0001', $e->getMessage());
            $this->assertStringNotContainsString('OVB/2026', $e->getMessage());
        }
    }

    /**
     * TAHUN BUKU BERUBAH → KODENYA IKUT (verifikasi F-2 putaran 2).
     *
     * Kode dicetak sekali saat barisnya dibuat, sedangkan update() dan
     * OverheadBudgetUpdateRequest mengizinkan `period_year` diganti selama
     * dokumennya masih draf/ditolak. Terukur sebelum perbaikan:
     * create(2031) -> OVB/2031/0001; update({period_year: 2032}) -> period_year
     * 2032 dengan kode TETAP OVB/2031/0001 — dan kalimat penolakan "satu per
     * tahun" untuk 2032 akan berbunyi "Tahun buku 2032 sudah punya anggaran
     * overhead yang disetujui (OVB/2031/0001)": dua tahun berbeda dalam satu
     * kalimat, pada dokumen yang seluruh identitasnya adalah sebuah tahun
     * (cacat f2-money-10, yang commit 405fa97 tutup untuk jalur pembuatan).
     */
    public function test_changing_the_fiscal_year_of_an_editable_budget_reprints_its_code(): void
    {
        $budget = $this->budget(2031, ['6-1100' => 100_000_000]);
        $this->assertSame('OVB/2031/0001', $budget->refresh()->code);

        $this->service->update($budget, ['period_year' => 2032]);

        $budget->refresh();
        $this->assertSame(2032, $budget->period_year);
        $this->assertSame('OVB/2032/0001', $budget->code);

        // Tahun yang ditinggalkan tetap punya urutannya sendiri, dan kalimat
        // penolakannya menyebut SATU tahun.
        $tetap = $this->budget(2031, ['6-1100' => 200_000_000]);
        $this->assertSame('OVB/2031/0002', $tetap->refresh()->code);

        $this->service->submit($budget, $this->maker());
        $this->service->approve($budget, $this->checker());

        $kedua = $this->budget(2032, ['6-1100' => 300_000_000]);

        try {
            $this->service->submit($kedua, $this->maker());
            $this->fail('OVB kedua untuk tahun yang sama seharusnya ditolak');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Tahun buku 2032', $e->getMessage());
            $this->assertStringContainsString('OVB/2032/0001', $e->getMessage());
            $this->assertStringNotContainsString('OVB/2031', $e->getMessage());
        }
    }

    /** Tahun yang TIDAK berubah tidak menerbitkan nomor baru — kode dokumen tidak bergoyang tanpa sebab. */
    public function test_updating_a_budget_without_touching_its_year_keeps_its_code(): void
    {
        $budget = $this->budget(2033, ['6-1100' => 100_000_000]);
        $code = $budget->refresh()->code;

        $this->service->update($budget, ['notes' => 'catatan baru']);
        $this->assertSame($code, $budget->refresh()->code);

        $this->service->update($budget, ['period_year' => 2033, 'notes' => 'lagi']);
        $this->assertSame($code, $budget->refresh()->code);
    }

    // ----------------------------------------------------------- pembatalan

    /**
     * JALAN KELUAR YANG DIJANJIKAN KALIMATNYA (verifikasi F-2).
     *
     * Kalimat penolakan "satu per tahun" menyuruh operator "batalkan OVB/…
     * lebih dulu", dan diukur lewat HTTP tidak satu pun jalan itu ada pada OVB
     * yang sudah disetujui: DELETE 422, reject 422, PUT 422, cancel 404 — satu
     * tahun buku yang salah ketik terkunci selamanya.
     */
    public function test_an_approved_budget_can_be_cancelled_and_frees_its_year(): void
    {
        $first = $this->budget(2026, ['6-1100' => 500_000_000]);
        $this->service->submit($first, $this->maker());
        $this->service->approve($first, $this->checker());

        $journalsBefore = DB::table('fin_journals')->count();
        $linesBefore = DB::table('fin_journal_lines')->count();

        $this->service->cancel($first, $this->checker(), 'Salah ketik: anggaran sewa kantor tertukar dengan 2027.');

        $this->assertSame(DocumentStatus::Cancelled, $first->refresh()->status);
        $this->assertNotNull($first->cancelled_at);
        $this->assertSame($this->checker()->id, (int) $first->cancelled_by);
        $this->assertStringContainsString('Salah ketik', (string) $first->cancellation_reason);
        $this->assertNull($this->service->approvedFor(2026), 'tahun itu kembali tanpa OVB yang berlaku');

        // FORWARD-ONLY: sebuah anggaran tidak pernah memposting jurnal, jadi
        // pembatalannya tidak boleh membalik apa pun.
        $this->assertSame($journalsBefore, DB::table('fin_journals')->count());
        $this->assertSame($linesBefore, DB::table('fin_journal_lines')->count());

        // Jejaknya baris `cancelled` di trail yang sama dengan submit/approve.
        $this->assertSame(1, $first->approvals()->where('action', 'cancelled')->count());

        // Dan penggantinya kini bisa disetujui — termasuk oleh indeks uniknya,
        // yang hanya menghitung baris ber-status approved.
        $second = $this->budget(2026, ['6-1100' => 700_000_000]);
        $this->service->submit($second, $this->maker());
        $this->service->approve($second, $this->checker());

        $this->assertSame($second->id, $this->service->approvedFor(2026)?->id);
    }

    public function test_cancelling_demands_a_reason_and_refuses_a_draft(): void
    {
        $budget = $this->budget(2026, ['6-1100' => 500_000_000]);
        $this->service->submit($budget, $this->maker());
        $this->service->approve($budget, $this->checker());

        try {
            $this->service->cancel($budget, $this->checker(), '   ');
            $this->fail('pembatalan tanpa alasan seharusnya ditolak');
        } catch (LogicException $e) {
            $this->assertStringContainsString('wajib menyebutkan alasan', $e->getMessage());
        }

        $draft = $this->budget(2027, ['6-1100' => 100_000_000]);

        $this->expectExceptionMessage('cukup diubah, ditolak, atau dihapus');
        $this->service->cancel($draft, $this->checker(), 'apa pun');
    }

    /** Dan lewat rutenya, dengan izin fin.approve dan alasan yang divalidasi. */
    public function test_the_cancel_endpoint_exists_and_validates_its_reason(): void
    {
        $budget = $this->budget(2026, ['6-1100' => 500_000_000]);
        $this->service->submit($budget, $this->maker());
        $this->service->approve($budget, $this->checker());

        Sanctum::actingAs($this->checker());

        $this->postJson("/api/finance/overhead-budgets/{$budget->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/finance/overhead-budgets/{$budget->id}/cancel", [
            'reason' => 'Anggaran diganti setelah rapat direksi 7 September.',
        ])->assertOk();

        $this->assertSame(DocumentStatus::Cancelled, $budget->refresh()->status);
    }

    // ------------------------------------------------------------- realisasi

    public function test_realisation_comes_from_posted_journals_on_the_budgeted_accounts(): void
    {
        $budget = $this->budget(2026, ['6-1100' => 500_000_000, '6-1200' => 200_000_000]);
        $budget->forceFill(['status' => DocumentStatus::Approved])->save();

        $this->postJournal('2026-03-31', '6-1100', 120_000_000);
        // 31 Desember: hari yang dibuang sebuah BETWEEN di SQLite.
        $this->postJournal('2026-12-31', '6-1100', 80_000_000);
        // Tahun lain — tidak boleh ikut.
        $this->postJournal('2027-01-05', '6-1100', 999_000_000);

        $payload = $this->service->realisation(2026);
        $rows = collect($payload['rows'])->keyBy('account_code');

        $this->assertSame(200000000.0, $rows['6-1100']['actual'], '31 Des harus ikut, 2027 tidak');
        // Akun yang belum bermutasi sekali pun: null, bukan 0.
        $this->assertNull($rows['6-1200']['actual']);
        $this->assertNull($rows['6-1200']['pct']);
        $this->assertSame(700000000.0, $payload['total_budget']);
        $this->assertSame(200000000.0, $payload['total_actual']);
    }

    public function test_a_draft_journal_is_not_realisation(): void
    {
        $budget = $this->budget(2026, ['6-1100' => 500_000_000]);
        $budget->forceFill(['status' => DocumentStatus::Approved])->save();

        DB::table('fin_journals')->insert([
            'code' => 'JV/DRAFT/0001',
            'journal_date' => '2026-04-01',
            'description' => 'Belum diposting',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journalId = DB::table('fin_journals')->where('code', 'JV/DRAFT/0001')->value('id');
        DB::table('fin_journal_lines')->insert([
            'journal_id' => $journalId,
            'account_id' => $this->account('6-1100')->id,
            'debit' => 50_000_000,
            'credit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = collect($this->service->realisation(2026)['rows'])->keyBy('account_code');

        $this->assertNull($rows['6-1100']['actual'], 'jurnal draf bukan realisasi');
    }

    /**
     * OVB DISETUJUI, NOL JURNAL TERPOSTING: totalnya digaris seperti barisnya.
     *
     * Setiap baris akun sudah benar ("—", tidak_terukur) sejak awal; yang
     * berbohong adalah TOTALNYA, yang menjumlahkan null sebagai 0 dan mencetak
     * "Rp 0 · 0,0 % · Aman" hijau — di layar, tepat di bawah kalimat bantuannya
     * sendiri yang berbunyi 'bertanda "—", bukan Rp 0'. Registri Ambang
     * mengulangi kebohongan yang sama lewat SUM() atas nol baris.
     */
    public function test_an_approved_budget_without_a_single_posted_line_is_ruled_not_zero(): void
    {
        $budget = $this->budget(2026, ['6-1100' => 500_000_000, '6-1200' => 300_000_000]);
        $this->service->submit($budget, $this->maker());
        $this->service->approve($budget, $this->checker());

        $payload = $this->service->realisation(2026);

        $this->assertSame(800000000.0, $payload['total_budget']);
        $this->assertNull($payload['total_actual'], 'nol akun bermutasi bukan realisasi Rp 0');
        $this->assertNull($payload['pct']);
        $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, $payload['state']);
        $this->assertStringContainsString('BELUM TERUKUR', $payload['note']);

        foreach ($payload['rows'] as $row) {
            $this->assertNull($row['actual']);
            $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, $row['state']);
        }

        // Registri Core mengukur hal yang sama, jadi ia harus menggaris juga:
        // SUM() atas nol baris memulangkan NULL, dan (float) NULL = 0.0.
        $registry = collect(WatchedThresholds::scan()['measures'])->firstWhere('key', 'overhead_budget_pct');
        $row = $registry['rows'][0];

        $this->assertNull($row['actual']);
        $this->assertNull($row['pct']);
        $this->assertSame(WatchedThresholds::TIDAK_TERUKUR, $row['state']);
        $this->assertStringContainsString('belum terukur, bukan nol rupiah belanja', $row['note']);
    }

    /**
     * SEBAGIAN akun bermutasi: totalnya tetap angka — nol rupiah pada akun yang
     * belum bermutasi memang tidak menambah apa pun — tetapi catatannya
     * menyebut berapa akun yang belum terukur, dan mutasi bersih NOL RUPIAH
     * tetap dibedakan dari "belum ada jurnal".
     */
    public function test_a_partly_measured_budget_keeps_its_total_and_says_what_is_missing(): void
    {
        $budget = $this->budget(2026, ['6-1100' => 500_000_000, '6-1200' => 300_000_000]);
        $budget->forceFill(['status' => DocumentStatus::Approved])->save();

        $this->postJournal('2026-05-10', '6-1100', 120_000_000);

        $payload = $this->service->realisation(2026);

        $this->assertSame(120000000.0, $payload['total_actual']);
        $this->assertSame(15.0, $payload['pct']);
        $this->assertSame(WatchedThresholds::AMAN, $payload['state']);
        $this->assertStringContainsString('1 dari 2 akun belum bermutasi', $payload['note']);

        // Mutasi yang saling meniadakan (beban lalu pembaliknya) BUKAN "belum
        // terukur": ada yang tercatat, dan jumlahnya nol rupiah.
        $this->postJournal('2026-06-10', '6-1200', 50_000_000);
        $this->postReversal('2026-06-11', '6-1200', 50_000_000);

        $rows = collect($this->service->realisation(2026)['rows'])->keyBy('account_code');
        $this->assertSame(0.0, $rows['6-1200']['actual'], 'mutasi bersih nol rupiah adalah Rp 0, bukan "—"');
    }

    public function test_a_year_without_an_approved_budget_is_ruled_never_zero_percent(): void
    {
        $payload = $this->service->realisation(2026);

        $this->assertNull($payload['total_budget']);
        $this->assertNull($payload['total_actual']);
        $this->assertNull($payload['pct']);
        $this->assertSame(WatchedThresholds::TANPA_BATAS, $payload['state']);
        $this->assertStringContainsString('belum punya OVB yang disetujui', $payload['note']);
    }

    // ----------------------------------------------------------- forward-only

    public function test_approving_an_overhead_budget_posts_no_journal_at_all(): void
    {
        $before = DB::table('fin_journals')->count();
        $beforeLines = DB::table('fin_journal_lines')->count();

        $budget = $this->budget(2026, ['6-1100' => 500_000_000]);
        $this->service->submit($budget, $this->maker());
        $this->service->approve($budget, $this->checker());

        $this->assertSame($before, DB::table('fin_journals')->count());
        $this->assertSame($beforeLines, DB::table('fin_journal_lines')->count());
        $this->assertSame(DocumentStatus::Approved, $budget->refresh()->status);
    }

    // -------------------------------------------------------------- registri

    public function test_the_registry_reports_the_running_year_against_the_approved_budget(): void
    {
        $year = (int) date('Y');

        $budget = $this->budget($year, ['6-1100' => 400_000_000]);
        $budget->forceFill(['status' => DocumentStatus::Approved])->save();
        $this->postJournal($year.'-02-10', '6-1100', 380_000_000);

        WatchedThresholds::flushSchemaMemo();
        $measure = collect(WatchedThresholds::scan()['measures'])->firstWhere('key', 'overhead_budget_pct');

        $this->assertNotNull($measure);
        $row = $measure['rows'][0];

        $this->assertSame((string) $year, $row['subject']);
        $this->assertSame(380000000.0, $row['actual']);
        $this->assertSame(400000000.0, $row['limit']);
        $this->assertSame(95.0, $row['pct']);
        $this->assertSame(WatchedThresholds::MENDEKATI, $row['state']);
    }

    // -------------------------------------------------------------- endpoint

    public function test_the_screen_endpoints_answer_behind_their_permissions(): void
    {
        Sanctum::actingAs($this->maker());

        $created = $this->postJson('/api/finance/overhead-budgets', [
            'period_year' => 2026,
            'lines' => $this->lines(['6-1100' => 250_000_000]),
        ])->assertCreated()->json('data');

        $this->assertSame(250000000.0, (float) $created['total_amount']);
        $this->assertStringStartsWith('OVB/', $created['code']);

        $this->postJson("/api/finance/overhead-budgets/{$created['id']}/submit")->assertOk();

        Sanctum::actingAs($this->checker());
        $this->postJson("/api/finance/overhead-budgets/{$created['id']}/approve")->assertOk();

        Sanctum::actingAs($this->maker());
        $payload = $this->getJson('/api/finance/overhead-budgets/realisation?year=2026')->assertOk()->json('data');

        $this->assertSame($created['code'], $payload['code']);
        $this->assertEquals(250000000, $payload['total_budget']);
    }

    /**
     * STATUSNYA DIKIRIM DALAM BAHASA INDONESIA (verifikasi F-2 putaran 2).
     *
     * Diukur di Chromium, dua daftar berdampingan pada server yang sama:
     *   #/r/finance/overhead-budgets   baris 0: badge "approved"
     *   #/r/procurement/purchase-orders baris 0: badge "Disetujui"
     * Perendernya satu (cells.js: `row.status_label || … || raw`); yang hilang
     * adalah satu baris di Resource — OverheadBudgetResource memancarkan
     * `status` tetapi tidak `status_label`, berbeda dengan setiap resource
     * dokumen lain. Satu-satunya daftar dokumen di SPA yang mencetak status
     * dalam bahasa Inggris mentah, pada layar yang dikirim paket ini.
     */
    public function test_the_list_ships_an_indonesian_status_label(): void
    {
        Sanctum::actingAs($this->maker());

        $budget = $this->budget(2026, ['6-1100' => 100_000_000]);
        $this->service->submit($budget, $this->maker());
        $this->service->approve($budget, $this->checker());

        Sanctum::actingAs($this->maker());

        $row = collect($this->getJson('/api/finance/overhead-budgets')->assertOk()->json('data'))
            ->firstWhere('id', $budget->id);

        $this->assertSame('approved', $row['status']);
        $this->assertSame('Disetujui', $row['status_label']);

        $detail = $this->getJson("/api/finance/overhead-budgets/{$budget->id}")->assertOk()->json('data');
        $this->assertSame('Disetujui', $detail['status_label']);

        $this->service->cancel($budget->refresh(), $this->checker(), 'Salah ketik tahun buku.');

        $cancelled = $this->getJson("/api/finance/overhead-budgets/{$budget->id}")->assertOk()->json('data');
        $this->assertSame('Dibatalkan', $cancelled['status_label']);
    }

    /** Penyusun — dibuat SEKALI per tes (adminUser() memakai e-mail tetap). */
    private function maker(): User
    {
        return $this->maker ??= $this->adminUser();
    }

    /** Pemeriksa kedua — maker-checker trait menolak penyetuju yang mengajukan. */
    private function checker(): User
    {
        if ($this->checkerUser !== null) {
            return $this->checkerUser;
        }

        $this->maker();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pemeriksa OVB',
            'email' => 'checker.ovb@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $this->checkerUser = $user;
    }
}
