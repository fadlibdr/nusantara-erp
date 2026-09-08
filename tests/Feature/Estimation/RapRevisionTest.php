<?php

namespace Tests\Feature\Estimation;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Services\RapService;
use Modules\Finance\Services\BudgetRealisationService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * F-2 / T2.5 — revisi RAP, dan janji yang menyertainya.
 *
 * KLAIM YANG PALING PENTING DI BERKAS INI BUKAN "REVISI BEKERJA" MELAINKAN
 * "YANG SUDAH ADA TIDAK BERUBAH". Setiap RAP yang berdiri hari ini menjadi
 * revisi 0 yang belum digantikan, dan aturan lama — "disetujui, id terbesar" —
 * menjawab persis sama dengan aturan baru. Itu dibuktikan dengan menghitung
 * kedua aturan berdampingan pada data yang sama, termasuk proyek dengan DUA
 * RAP disetujui yang tidak berhubungan, bukan dengan membaca kodenya.
 */
class RapRevisionTest extends ErpTestCase
{
    private RapService $service;

    private ?User $maker = null;

    private ?User $checkerUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RapService::class);
    }

    private function project(string $code): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => 'Proyek '.$code,
            'type' => 'construction',
            'status' => 'active',
            'contract_value' => 5_000_000_000,
        ]);
    }

    /**
     * @param  array<string, float>  $byCategory
     */
    private function rap(Project $project, array $byCategory, DocumentStatus $status = DocumentStatus::Approved): CostBudget
    {
        $boq = Boq::query()->create([
            'project_id' => $project->id,
            'title' => 'RAB '.$project->code,
            'status' => DocumentStatus::Approved,
        ]);
        $section = $boq->sections()->create(['section_no' => 'A', 'name' => 'Struktur']);

        /** @var CostBudget $rap */
        $rap = CostBudget::query()->create([
            'boq_id' => $boq->id,
            'project_id' => $project->id,
            'target_margin_pct' => 10,
            'status' => $status,
        ]);

        foreach ($byCategory as $category => $amount) {
            $item = $boq->items()->create([
                'section_id' => $section->id,
                'wbs_code' => 'A.'.$category,
                'description' => 'Paket '.$category,
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $amount,
                'amount' => $amount,
            ]);

            $rap->items()->create([
                'boq_item_id' => $item->id,
                'cost_category' => $category,
                'description' => 'Paket '.$category,
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $amount,
                'amount' => $amount,
            ]);
        }

        return $this->service->recalcTotals($rap)->refresh();
    }

    private function maker(): User
    {
        return $this->maker ??= $this->adminUser();
    }

    private function checker(): User
    {
        if ($this->checkerUser !== null) {
            return $this->checkerUser;
        }

        $this->maker();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Pemeriksa RAP',
            'email' => 'checker.rap@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $this->checkerUser = $user;
    }

    /** Aturan LAMA, ditulis ulang apa adanya: disetujui, id terbesar. */
    private function budgetUnderTheOldRule(int $projectId): ?float
    {
        $rapId = DB::table('est_cost_budgets')
            ->where('project_id', $projectId)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->value('id');

        if ($rapId === null) {
            return null;
        }

        return round((float) DB::table('est_cost_budget_items')->where('cost_budget_id', $rapId)->sum('amount'), 2);
    }

    // ------------------------------------- yang sudah ada tidak boleh berubah

    /**
     * RAP yang tidak pernah direvisi — termasuk proyek yang menyimpan DUA RAP
     * disetujui yang tidak berhubungan — dijawab identik oleh aturan lama dan
     * aturan baru.
     */
    public function test_an_approved_rap_without_revisions_answers_exactly_as_before(): void
    {
        $satu = $this->project('PRJ-2026-941');
        $this->rap($satu, ['material' => 300_000_000, 'subcon' => 200_000_000]);

        // Dua RAP disetujui pada satu proyek: sah sebelum F-2, dan yang
        // menang tetap yang ber-id terbesar — di kedua aturan.
        $dua = $this->project('PRJ-2026-942');
        $this->rap($dua, ['material' => 100_000_000]);
        $this->rap($dua, ['material' => 700_000_000, 'subcon' => 50_000_000]);

        // Draf dan yang ditolak tidak pernah mengatur apa pun.
        $tiga = $this->project('PRJ-2026-943');
        $this->rap($tiga, ['material' => 900_000_000], DocumentStatus::Draft);
        $this->rap($tiga, ['material' => 400_000_000]);
        $this->rap($tiga, ['material' => 800_000_000], DocumentStatus::Rejected);

        $empat = $this->project('PRJ-2026-944'); // tanpa RAP sama sekali

        $budgets = app(BudgetRealisationService::class);

        foreach ([$satu, $dua, $tiga, $empat] as $project) {
            $new = $budgets->project($project->id)['budget'];
            $old = $this->budgetUnderTheOldRule($project->id);

            $this->assertSame($old, $new, "[{$project->code}] aturan baru menjawab berbeda dari aturan lama");
        }

        $this->assertSame(500000000.0, $budgets->project($satu->id)['budget']);
        $this->assertSame(750000000.0, $budgets->project($dua->id)['budget']);
        $this->assertSame(400000000.0, $budgets->project($tiga->id)['budget']);
        $this->assertNull($budgets->project($empat->id)['budget']);
    }

    // ------------------------------------------------------------ revisi RAP

    public function test_approving_a_revision_supersedes_its_predecessor_and_moves_the_governing_budget(): void
    {
        $project = $this->project('PRJ-2026-945');
        $rev0 = $this->rap($project, ['material' => 300_000_000, 'subcon' => 200_000_000]);

        $budgets = app(BudgetRealisationService::class);
        $this->assertSame(500000000.0, $budgets->project($project->id)['budget']);

        $rev1 = $this->service->revise($rev0, ['revision_reason' => 'CCO-01: penambahan lingkup struktur'], $this->maker());
        $this->service->replaceItems($rev1, [[
            'boq_item_id' => $rev1->items()->value('boq_item_id'),
            'cost_category' => 'material',
            'description' => 'Paket material (revisi)',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 650_000_000,
        ]]);

        // Selama revisinya belum disetujui, yang mengatur MASIH revisi 0.
        $rev1->submit($this->maker());
        $this->assertSame(500000000.0, app(BudgetRealisationService::class)->project($project->id)['budget']);
        $this->assertNull($rev0->refresh()->superseded_at);

        $this->service->approve($rev1, $this->checker());

        $this->assertSame(1, (int) $rev1->refresh()->revision);
        $this->assertNotNull($rev0->refresh()->superseded_at);
        $this->assertSame($rev1->id, (int) $rev0->superseded_by_id);
        $this->assertFalse($rev0->isGoverning());
        $this->assertTrue($rev1->isGoverning());

        // Dan anggarannya berpindah — SATU jawaban, bukan dua RAP approved.
        $this->assertSame(650000000.0, app(BudgetRealisationService::class)->project($project->id)['budget']);
        $this->assertSame($rev1->code, app(BudgetRealisationService::class)->project($project->id)['rap_code']);
    }

    /** Isi pendahulunya tidak disentuh satu byte pun — rantai append-only. */
    public function test_superseding_writes_only_two_columns_on_the_predecessor(): void
    {
        $project = $this->project('PRJ-2026-946');
        $rev0 = $this->rap($project, ['material' => 300_000_000]);

        $before = DB::table('est_cost_budgets')->where('id', $rev0->id)->first();
        $itemsBefore = DB::table('est_cost_budget_items')->where('cost_budget_id', $rev0->id)->get()->toArray();

        $rev1 = $this->service->revise($rev0, ['revision_reason' => 'Eskalasi harga besi'], $this->maker());
        $rev1->submit($this->maker());
        $this->service->approve($rev1, $this->checker());

        $after = DB::table('est_cost_budgets')->where('id', $rev0->id)->first();

        foreach ((array) $before as $column => $value) {
            if (in_array($column, ['superseded_at', 'superseded_by_id', 'updated_at'], true)) {
                continue;
            }

            $this->assertSame($value, ((array) $after)[$column], "kolom {$column} pendahulunya ikut berubah");
        }

        $this->assertEquals($itemsBefore, DB::table('est_cost_budget_items')->where('cost_budget_id', $rev0->id)->get()->toArray());
    }

    public function test_a_revision_without_a_reason_is_refused(): void
    {
        $rev0 = $this->rap($this->project('PRJ-2026-947'), ['material' => 100_000_000]);

        $this->expectExceptionMessage('wajib menyebutkan alasan');
        $this->service->revise($rev0, ['revision_reason' => '   '], $this->maker());
    }

    public function test_only_an_approved_and_still_governing_rap_can_be_revised(): void
    {
        $project = $this->project('PRJ-2026-948');
        $draft = $this->rap($project, ['material' => 100_000_000], DocumentStatus::Draft);

        try {
            $this->service->revise($draft, ['revision_reason' => 'apa pun'], $this->maker());
            $this->fail('RAP draf seharusnya tidak bisa direvisi');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cukup diubah langsung', $e->getMessage());
        }

        $rev0 = $this->rap($project, ['material' => 100_000_000]);
        $rev1 = $this->service->revise($rev0, ['revision_reason' => 'CCO-02'], $this->maker());
        $rev1->submit($this->maker());
        $this->service->approve($rev1, $this->checker());

        $this->expectExceptionMessage('sudah digantikan revisi berikutnya');
        $this->service->revise($rev0->refresh(), ['revision_reason' => 'terlambat'], $this->maker());
    }

    /**
     * Revisi yang DITOLAK tidak menggantikan apa pun: pendahulunya tetap
     * berlaku, dan gerbang tetap membaca angkanya.
     */
    public function test_a_rejected_revision_leaves_the_predecessor_governing(): void
    {
        $project = $this->project('PRJ-2026-949');
        $rev0 = $this->rap($project, ['material' => 300_000_000]);

        $rev1 = $this->service->revise($rev0, ['revision_reason' => 'usulan yang ditolak'], $this->maker());
        $rev1->submit($this->maker());
        $this->service->reject($rev1, $this->checker(), 'tidak disetujui direksi');

        $this->assertNull($rev0->refresh()->superseded_at);
        $this->assertTrue($rev0->isGoverning());
        $this->assertSame(300000000.0, app(BudgetRealisationService::class)->project($project->id)['budget']);
    }

    // -------------------------------------------------------- riwayat selisih

    public function test_the_revision_history_carries_the_difference_between_revisions(): void
    {
        $project = $this->project('PRJ-2026-950');
        $rev0 = $this->rap($project, ['material' => 300_000_000, 'subcon' => 200_000_000]);

        $rev1 = $this->service->revise($rev0, ['revision_reason' => 'CCO-03: subkon bertambah'], $this->maker());
        $subconItem = $rev1->items()->where('cost_category', 'subcon')->first();
        $subconItem->forceFill(['amount' => 350_000_000, 'unit_price' => 350_000_000])->save();
        $this->service->recalcTotals($rev1);
        $rev1->submit($this->maker());
        $this->service->approve($rev1, $this->checker());

        $chain = $this->service->revisionChain($rev1->refresh());

        $this->assertCount(2, $chain);
        $this->assertNull($chain[0]['delta_total'], 'revisi 0 tidak punya pendahulu — selisihnya bukan 0');
        $this->assertSame(0, $chain[0]['revision']);
        $this->assertFalse($chain[0]['is_governing']);

        $this->assertSame(1, $chain[1]['revision']);
        $this->assertTrue($chain[1]['is_governing']);
        $this->assertSame(150000000.0, $chain[1]['delta_total']);
        $this->assertSame(150000000.0, $chain[1]['delta_subcon']);
        $this->assertSame(0.0, $chain[1]['delta_non_subcon'], 'sisi non-subkon tidak bergerak');
        $this->assertSame('CCO-03: subkon bertambah', $chain[1]['revision_reason']);

        $diff = $this->service->revisionDiff($rev1);

        $this->assertCount(1, $diff, 'hanya kategori yang benar-benar berubah yang muncul');
        $this->assertSame('subcon', $diff[0]['cost_category']);
        $this->assertSame(200000000.0, $diff[0]['amount_from']);
        $this->assertSame(350000000.0, $diff[0]['amount_to']);
        $this->assertSame(150000000.0, $diff[0]['delta']);
        $this->assertSame($rev0->code, $diff[0]['from_code']);
    }

    // -------------------------------------------------------------- endpoint

    public function test_the_endpoints_revise_and_report_the_chain(): void
    {
        Sanctum::actingAs($this->maker());

        $project = $this->project('PRJ-2026-951');
        $rev0 = $this->rap($project, ['material' => 100_000_000]);

        $this->postJson("/api/estimation/cost-budgets/{$rev0->id}/revise", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('revision_reason');

        $revision = $this->postJson("/api/estimation/cost-budgets/{$rev0->id}/revise", [
            'revision_reason' => 'Addendum I',
        ])->assertCreated()->json('data');

        $this->assertSame(1, $revision['revision']);
        $this->assertSame($rev0->id, $revision['revised_from_id']);
        $this->assertFalse($revision['is_governing']);

        $chain = $this->getJson("/api/estimation/cost-budgets/{$revision['id']}/revisions")->assertOk()->json('data');
        $this->assertCount(2, $chain);
        $this->assertTrue($chain[0]['is_governing'], 'revisi 0 masih yang mengatur sampai revisinya disetujui');
    }

    // ------------------------------------- satu proyek, satu RAP yang mengatur

    /**
     * DUA REVISI PARALEL: ditolak saat revisi KEDUA dibuat, sebelum ada yang
     * mengerjakan anggarannya.
     *
     * Sebelum verifikasi F-2 keduanya lahir dengan nomor revisi KEMBAR (dua-dua
     * "revisi 2"), dan keduanya bisa disetujui.
     */
    public function test_a_second_live_revision_of_one_rap_is_refused(): void
    {
        $project = $this->project('PRJ-2026-952');
        $rev0 = $this->rap($project, ['material' => 400_000_000]);

        $first = $this->service->revise($rev0, ['revision_reason' => 'CCO-05 cabang pertama'], $this->maker());

        try {
            $this->service->revise($rev0->refresh(), ['revision_reason' => 'CCO-05 cabang kedua'], $this->maker());
            $this->fail('revisi kedua dari RAP yang sama seharusnya ditolak');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('revisi yang belum selesai', $e->getMessage());
            $this->assertStringContainsString($first->code, $e->getMessage());
        }

        // Sesudah revisi pertama DITOLAK, jalannya terbuka lagi: yang dijaga
        // adalah percabangan, bukan hak merevisi.
        $first->submit($this->maker());
        $this->service->reject($first, $this->checker(), 'salah kategori');

        $second = $this->service->revise($rev0->refresh(), ['revision_reason' => 'CCO-05 usulan kedua'], $this->maker());
        $this->assertSame(1, (int) $second->revision);
    }

    /**
     * DUA RAP DISETUJUI PADA SATU PROYEK: ditolak di persetujuannya.
     *
     * Cabang keduanya dibuat langsung lewat model — persis bentuk baris yang
     * bisa dibuat versi sebelum verifikasi F-2 (dan yang bisa berdiri di data
     * lama) — supaya yang diuji adalah PINTU PERSETUJUANNYA, bukan pintu revisi.
     * Terukur pada versi sebelum perbaikan: keduanya 200 OK, dua baris approved
     * dengan superseded_at NULL, dan gerbang memakai yang ber-id terbesar
     * sementara layar riwayat mencetak yang lain.
     */
    public function test_a_second_governing_rap_cannot_be_approved_for_one_project(): void
    {
        $project = $this->project('PRJ-2026-953');
        $rev0 = $this->rap($project, ['material' => 400_000_000]);

        $cabangA = $this->service->revise($rev0, ['revision_reason' => 'cabang A'], $this->maker());
        $cabangA->items()->first()->forceFill(['amount' => 500_000_000, 'unit_price' => 500_000_000])->save();
        $this->service->recalcTotals($cabangA);

        /** @var CostBudget $cabangB */
        $cabangB = CostBudget::query()->create([
            'boq_id' => $rev0->boq_id,
            'project_id' => $project->id,
            'target_margin_pct' => $rev0->target_margin_pct,
            'status' => DocumentStatus::Draft,
            'revision' => 1,
            'revised_from_id' => $rev0->id,
            'revision_reason' => 'cabang B',
        ]);
        $cabangB->items()->create([
            'boq_item_id' => $rev0->items()->value('boq_item_id'),
            'cost_category' => 'material',
            'description' => 'Paket material (cabang B)',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 900_000_000,
            'amount' => 900_000_000,
        ]);
        $this->service->recalcTotals($cabangB);

        $cabangA->submit($this->maker());
        $cabangB->submit($this->maker());

        $this->service->approve($cabangA, $this->checker());

        try {
            $this->service->approve($cabangB->refresh(), $this->checker());
            $this->fail('RAP kedua yang mengatur seharusnya ditolak');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('sudah punya RAP yang berlaku ('.$cabangA->code.')', $e->getMessage());
        }

        $governing = DB::table('est_cost_budgets')
            ->where('project_id', $project->id)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('superseded_at')
            ->pluck('code')
            ->all();

        $this->assertSame([$cabangA->code], $governing, 'tepat satu RAP yang mengatur, tidak dua');
        $this->assertSame(500000000.0, app(BudgetRealisationService::class)->project($project->id)['budget']);

        // Dan riwayat revisi cabang B memuat cabang B — sebelum perbaikan ini
        // rantainya berjalan maju dari akar lewat anak ber-id terkecil, jadi
        // RAP yang dibuka orangnya tidak ada di riwayatnya sendiri.
        $this->assertContains(
            $cabangB->code,
            array_column($this->service->revisionChain($cabangB->refresh()), 'code'),
        );
    }

    /**
     * RAP KEDUA YANG TIDAK BERHUBUNGAN (BOQ lain) juga ditolak: gerbang hanya
     * bisa membaca satu anggaran, dan diam-diam memilih yang ber-id terbesar
     * adalah cara sebuah proyek kehilangan separuh anggarannya tanpa satu
     * kalimat pun di layar.
     */
    public function test_an_unrelated_second_rap_cannot_be_approved_while_one_governs(): void
    {
        $project = $this->project('PRJ-2026-954');
        $pertama = $this->rap($project, ['material' => 300_000_000]);

        $kedua = $this->rap($project, ['material' => 800_000_000], DocumentStatus::Draft);
        $kedua->submit($this->maker());

        $this->expectExceptionMessage('sudah punya RAP yang berlaku ('.$pertama->code.')');
        $this->service->approve($kedua, $this->checker());
    }

    // ------------------------------------------- jalan keluar data warisan

    /**
     * PROYEK WARISAN DENGAN DUA RAP DISETUJUI PUNYA JALAN KELUAR — dan sebelum
     * verifikasi putaran 2 ia terkunci selamanya (temuan f2-rap-1).
     *
     * Bentuk data ini SAH sebelum F-2, dan justru karena ia ada maka commit
     * dce6b27 memilih TIDAK memasang indeks unik parsial (indeks itu akan
     * menolak bermigrasi persis di pemasangan yang paling membutuhkannya).
     * Tetapi tanpa jalan keluar, assertNoGoverningRival menolak SETIAP
     * persetujuan berikutnya, dan RAP disetujui tidak bisa ditolak
     * ("Cannot reject document … while status is approved") — jadi anggaran
     * proyek itu tidak bisa direvisi lagi, selamanya.
     */
    public function test_legacy_data_with_two_governing_raps_can_be_untangled(): void
    {
        $project = $this->project('PRJ-2026-955');
        $lama = $this->rap($project, ['material' => 1_000_000_000]);
        $dipakai = $this->rap($project, ['material' => 700_000_000]);

        // Yang dibaca gerbang adalah yang ber-id terbesar…
        $this->assertSame(700000000.0, app(BudgetRealisationService::class)->project($project->id)['budget']);

        // …dan setiap revisi berikutnya ditolak, dengan kalimat yang kini
        // menyebutkan jalan keluarnya.
        $revisi = $this->service->revise($dipakai, ['revision_reason' => 'CCO-11'], $this->maker());
        $revisi->submit($this->maker());

        try {
            $this->service->approve($revisi->refresh(), $this->checker());
            $this->fail('RAP kedua yang mengatur seharusnya ditolak');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('sudah punya RAP yang berlaku ('.$lama->code.')', $e->getMessage());
            $this->assertStringContainsString('nyatakan RAP itu DIGANTIKAN', $e->getMessage());
        }

        // JALAN KELUARNYA: nyatakan RAP warisan itu sudah digantikan oleh yang
        // sungguh dipakai. Alasannya WAJIB dan tercatat.
        $this->service->supersede($lama->refresh(), $this->checker(), 'Data warisan: sejak 2026 gerbang membaca '.$dipakai->code.'.');

        $lama->refresh();
        $this->assertNotNull($lama->superseded_at);
        $this->assertSame($dipakai->id, (int) $lama->superseded_by_id);
        // APPEND-ONLY: statusnya dan isinya tidak disentuh satu byte pun.
        $this->assertSame(DocumentStatus::Approved, $lama->status);
        $this->assertSame(1000000000.0, round((float) $lama->items()->sum('amount'), 2));
        $this->assertFalse($lama->isGoverning());

        $trail = $lama->approvals()->orderByDesc('id')->first();
        $this->assertSame('superseded', $trail->action);
        $this->assertStringContainsString('Data warisan', (string) $trail->note);

        // Dan anggaran proyek itu bisa direvisi lagi.
        $this->service->approve($revisi->refresh(), $this->checker());
        $this->assertTrue($revisi->refresh()->isGoverning());
        $this->assertNotNull($dipakai->refresh()->superseded_at);
    }

    /**
     * SATU-SATUNYA RAP yang berlaku tidak bisa menyatakan dirinya digantikan:
     * proyek tanpa RAP disetujui membuat gerbang anggaran DIAM, dan setiap PO
     * berikutnya lewat tanpa diperiksa. Sebuah jalan keluar tidak boleh
     * menjadi jalan mematikan gerbangnya.
     */
    public function test_the_only_governing_rap_cannot_declare_itself_superseded(): void
    {
        $project = $this->project('PRJ-2026-956');
        $satu = $this->rap($project, ['material' => 500_000_000]);

        try {
            $this->service->supersede($satu, $this->checker(), 'coba-coba');
            $this->fail('RAP satu-satunya seharusnya tidak bisa digantikan begitu saja');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('satu-satunya RAP yang berlaku', $e->getMessage());
        }

        $this->assertNull($satu->refresh()->superseded_at);
        $this->assertSame(500000000.0, app(BudgetRealisationService::class)->project($project->id)['budget']);
    }

    /** Alasan WAJIB — sebuah anggaran yang berhenti berlaku tanpa sebab tertulis tidak bisa diaudit. */
    public function test_declaring_a_rap_superseded_demands_a_reason(): void
    {
        $project = $this->project('PRJ-2026-957');
        $lama = $this->rap($project, ['material' => 100_000_000]);
        $this->rap($project, ['material' => 200_000_000]);

        $this->expectExceptionMessage('wajib menyebutkan alasan');
        $this->service->supersede($lama, $this->checker(), '   ');
    }

    /**
     * DAN RIWAYATNYA MENANDAI TEPAT SATU BARIS YANG MENGATUR. `is_governing`
     * adalah predikat per baris ("approved && belum digantikan"), jadi pada
     * data warisan KEDUA baris menandai dirinya mengatur — layar riwayat
     * mencetak Rp 1.000.000.000 sebagai "berlaku" sementara gerbang menolak
     * dengan Rp 700.000.000 milik yang lain (temuan f2-rap-1, separuh kedua).
     */
    public function test_exactly_one_rap_is_flagged_governing_even_in_legacy_data(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-958');
        $lama = $this->rap($project, ['material' => 1_000_000_000]);
        $dipakai = $this->rap($project, ['material' => 700_000_000]);

        $this->assertFalse($lama->isGoverning(), 'yang dibaca gerbang adalah RAP lain');
        $this->assertTrue($dipakai->isGoverning());

        foreach ([$lama, $dipakai] as $rap) {
            $payload = $this->getJson("/api/estimation/cost-budgets/{$rap->id}")->assertOk()->json('data');
            $this->assertSame($rap->id === $dipakai->id, $payload['is_governing'], "is_governing salah pada {$rap->code}");
        }

        $chain = $this->service->revisionChain($lama);
        $flags = array_column($chain, 'is_governing', 'code');
        $this->assertFalse($flags[$lama->code]);
    }

    /**
     * DAN JALAN KELUARNYA PUNYA RUTE — kalimat penolakan yang menyuruh operator
     * menekan tombol yang tidak ada adalah cacat yang sama yang ditutup OVB
     * pada putaran lalu (DELETE 422, reject 422, PUT 422, cancel 404).
     */
    public function test_the_way_out_is_reachable_over_http(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-959');
        $lama = $this->rap($project, ['material' => 100_000_000]);
        $dipakai = $this->rap($project, ['material' => 200_000_000]);

        // Alasan wajib, dan servernya yang menolak — bukan hanya layarnya.
        $this->postJson("/api/estimation/cost-budgets/{$lama->id}/supersede", [])->assertStatus(422);

        $payload = $this->postJson("/api/estimation/cost-budgets/{$lama->id}/supersede", [
            'reason' => 'Data warisan: dua RAP disetujui berdampingan sejak sebelum F-2.',
        ])->assertOk()->json('data');

        $this->assertNotNull($payload['superseded_at']);
        $this->assertSame($dipakai->id, $payload['superseded_by_id']);
        $this->assertFalse($payload['is_governing']);
        $this->assertSame('approved', $payload['status'], 'isinya tidak disentuh — hanya dua kolom penggantian');

        // Yang berlaku tinggal satu, dan gerbang membacanya.
        $this->assertSame(200000000.0, app(BudgetRealisationService::class)->project($project->id)['budget']);
    }
}
