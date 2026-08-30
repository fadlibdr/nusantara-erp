<?php

namespace Tests\Feature\Crm;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\RkkDocument;
use Modules\Crm\Models\TenderPackage;
use Modules\Crm\Services\RkkService;
use Modules\Crm\Services\TenderPackageService;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\BoqSection;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\RiskRegisterEntry;
use Modules\Projects\Services\RiskRegisterService;
use Tests\ErpTestCase;

/**
 * P7 — RKK penawaran: tautan IBPRP hidup (P6) dan biaya SMKK yang menempel
 * pada baris BoQ betulan.
 */
class RkkDocumentTest extends ErpTestCase
{
    private function rkkService(): RkkService
    {
        return app(RkkService::class);
    }

    private function makePackage(): TenderPackage
    {
        $lead = Lead::query()->create(['name' => 'Panitia Lelang', 'status' => 'new']);

        return app(TenderPackageService::class)->create([
            'lead_id' => $lead->id,
            'title' => 'Pembangunan Gedung Kantor',
        ]);
    }

    private function makeProject(): Project
    {
        return Project::query()->create([
            'code' => 'PRJ-2026-900',
            'name' => 'Proyek uji RKK',
            'type' => 'construction',
            'status' => 'preparation',
        ]);
    }

    private function makeRiskEntry(Project $project, array $data = []): RiskRegisterEntry
    {
        return app(RiskRegisterService::class)->create(array_merge([
            'project_id' => $project->id,
            'activity' => 'Pekerjaan galian basement',
            'hazard' => 'Longsoran dinding galian',
            'impact' => 'Tertimbun',
            'likelihood' => 3,
            'severity' => 4,
            'controls' => 'Sheet pile + dewatering + pengawasan',
        ], $data), $this->adminUser());
    }

    /** @return array{0: Boq, 1: BoqItem} */
    private function makeBoqWithSmkkLine(float $amount = 125_000_000): array
    {
        $boq = Boq::query()->create([
            'code' => 'BOQ/2026/9001',
            'title' => 'RAB Gedung Kantor',
            'status' => 'draft',
        ]);

        $section = BoqSection::query()->create([
            'boq_id' => $boq->id,
            'section_no' => 'X',
            'name' => 'Pekerjaan Penerapan SMKK',
        ]);

        $item = BoqItem::query()->create([
            'boq_id' => $boq->id,
            'section_id' => $section->id,
            'wbs_code' => 'X.1',
            'description' => 'Alat pelindung diri & rambu keselamatan',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $amount,
            'amount' => $amount,
        ]);

        return [$boq, $item];
    }

    private function makeRkk(array $data = []): RkkDocument
    {
        return $this->rkkService()->create(array_merge([
            'tender_package_id' => $this->makePackage()->id,
            'title' => 'RKK Penawaran — Gedung Kantor',
            'policy' => 'Perusahaan berkomitmen nihil kecelakaan kerja.',
            'program' => 'Induksi K3, toolbox harian, inspeksi mingguan.',
        ], $data));
    }

    public function test_an_rkk_is_numbered_and_carries_the_four_permen_pupr_sections(): void
    {
        $rkk = $this->makeRkk();

        $this->assertStringStartsWith('RKK/', $rkk->code);
        $this->assertNotNull($rkk->policy);
        $this->assertNotNull($rkk->program);
        $this->assertSame([], $this->rkkService()->ibprpRows($rkk->fresh()));
        $this->assertSame(0.0, $this->rkkService()->smkkTotal($rkk->fresh()));
    }

    // ------------------------------------------------------------- IBPRP

    public function test_the_rkk_links_live_ibprp_rows_and_reads_them_from_the_register(): void
    {
        $project = $this->makeProject();
        $entry = $this->makeRiskEntry($project);
        $rkk = $this->makeRkk(['project_id' => $project->id]);

        $this->rkkService()->syncIbprpLinks($rkk, [$entry->id]);

        $rows = $this->rkkService()->ibprpRows($rkk->fresh());

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['available']);
        $this->assertSame('Longsoran dinding galian', $rows[0]['hazard']);
        // Skornya dibaca dari register, bukan disalin saat menaut: 3 × 4 = 12.
        $this->assertSame(12, $rows[0]['initial_score']);
    }

    public function test_a_dangling_ibprp_link_is_refused_and_names_the_id(): void
    {
        $project = $this->makeProject();
        $rkk = $this->makeRkk(['project_id' => $project->id]);

        try {
            $this->rkkService()->syncIbprpLinks($rkk, [999_001]);
            $this->fail('Tautan IBPRP menggantung seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('999001', $e->getMessage());
        }

        $this->assertSame(0, $rkk->fresh()->ibprpLinks()->count());
    }

    /**
     * Baris IBPRP proyek LAIN bukan baris proyek ini. Register keselamatan satu
     * pekerjaan tidak boleh dipinjamkan ke penawaran pekerjaan lain.
     */
    public function test_an_ibprp_row_from_another_project_is_refused(): void
    {
        $mine = $this->makeProject();
        $other = Project::query()->create([
            'code' => 'PRJ-2026-901', 'name' => 'Proyek lain', 'type' => 'construction', 'status' => 'preparation',
        ]);
        $foreign = $this->makeRiskEntry($other);
        $rkk = $this->makeRkk(['project_id' => $mine->id]);

        $this->expectException(ValidationException::class);

        $this->rkkService()->syncIbprpLinks($rkk, [$foreign->id]);
    }

    /**
     * Baris register yang dihapus setelah ditaut tidak menghilangkan barisnya
     * dari lembar: ia hadir dengan sel-sel bergaris, karena penilaian risiko
     * yang lenyap adalah fakta tentang RKK-nya.
     */
    public function test_a_deleted_register_row_prints_as_a_ruled_line_not_as_nothing(): void
    {
        $project = $this->makeProject();
        $entry = $this->makeRiskEntry($project);
        $rkk = $this->makeRkk(['project_id' => $project->id]);
        $this->rkkService()->syncIbprpLinks($rkk, [$entry->id]);

        $entry->delete();

        $rows = $this->rkkService()->ibprpRows($rkk->fresh());

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['available']);
        $this->assertNull($rows[0]['hazard']);
        $this->assertNull($rows[0]['initial_score'], 'Sel bergaris, bukan skor 0.');
    }

    // --------------------------------------------------------- biaya SMKK

    public function test_smkk_cost_lines_tie_to_real_boq_rows_and_the_total_is_derived(): void
    {
        [$boq, $item] = $this->makeBoqWithSmkkLine(125_000_000);
        $rkk = $this->makeRkk(['boq_id' => $boq->id]);

        $this->rkkService()->syncSmkkCosts($rkk, [
            ['boq_item_id' => $item->id, 'category' => 'APD & rambu'],
        ]);

        $rows = $this->rkkService()->smkkRows($rkk->fresh());

        $this->assertSame(125_000_000.0, $rows[0]['amount']);
        $this->assertSame('Alat pelindung diri & rambu keselamatan', $rows[0]['description']);
        $this->assertSame(125_000_000.0, $this->rkkService()->smkkTotal($rkk->fresh()));

        // Nilainya TURUNAN: menyunting RAB langsung terbaca di RKK, karena
        // tidak ada rupiah kedua yang disimpan di sisi RKK.
        $item->update(['unit_price' => 150_000_000, 'amount' => 150_000_000]);

        $this->assertSame(150_000_000.0, $this->rkkService()->smkkTotal($rkk->fresh()));
    }

    /**
     * CERMIN dari test_a_deleted_register_row_prints_as_a_ruled_line, pada sisi
     * SMKK — janji yang tertulis di docblock RkkService dan yang tidak dijaga
     * FK mana pun (boq_item_id lintas modul, tanpa constraint): baris biaya
     * yang baris RAB-nya sudah lenyap melapor amount NULL dan DIKELUARKAN dari
     * jumlah, bukan dihitung nol — dan barisnya tetap hadir, karena baris RAB
     * yang lenyap adalah fakta tentang RKK-nya.
     */
    public function test_a_deleted_boq_row_reports_null_amount_and_is_excluded_from_the_total(): void
    {
        [$boq, $kept] = $this->makeBoqWithSmkkLine(125_000_000);

        $vanishing = BoqItem::query()->create([
            'boq_id' => $boq->id,
            'section_id' => $kept->section_id,
            'wbs_code' => 'X.2',
            'description' => 'Rambu dan barikade sementara',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 40_000_000,
            'amount' => 40_000_000,
        ]);

        $rkk = $this->makeRkk(['boq_id' => $boq->id]);

        $this->rkkService()->syncSmkkCosts($rkk, [
            ['boq_item_id' => $kept->id, 'category' => 'APD & rambu'],
            ['boq_item_id' => $vanishing->id, 'category' => 'Rambu sementara'],
        ]);

        $vanishing->delete();

        $rows = collect($this->rkkService()->smkkRows($rkk->fresh()))->keyBy('boq_item_id');

        // Barisnya TIDAK hilang dari lembar…
        $this->assertCount(2, $rows);

        $gone = $rows[$vanishing->id];
        $this->assertFalse($gone['available']);
        $this->assertNull($gone['amount'], 'Sel bergaris, bukan 0,00.');
        $this->assertNull($gone['description']);
        $this->assertNull($gone['qty']);
        $this->assertNull($gone['unit_price']);
        // …tetapi kategorinya (milik RKK, bukan milik RAB) tetap terbaca.
        $this->assertSame('Rambu sementara', $gone['category']);

        // Jumlahnya HANYA baris yang barisan BoQ-nya masih ada.
        $this->assertSame(125_000_000.0, $this->rkkService()->smkkTotal($rkk->fresh()));
    }

    /**
     * REGRESI N+1: endpoint satu-dokumen memuat 'smkkCosts.boqItem' seperti
     * index() — tanpa itu smkkRows() melihat relasi smkkCosts sudah dimuat,
     * melewatkan eager load-nya sendiri, dan me-lazy-load boqItem SATU QUERY
     * PER BARIS untuk setiap penyusunan respons.
     */
    public function test_single_document_endpoints_sweep_est_boq_items_once_not_per_line(): void
    {
        [$boq, $first] = $this->makeBoqWithSmkkLine(125_000_000);

        $lines = [['boq_item_id' => $first->id, 'category' => 'APD & rambu']];

        foreach ([2, 3] as $n) {
            $item = BoqItem::query()->create([
                'boq_id' => $boq->id,
                'section_id' => $first->section_id,
                'wbs_code' => 'X.'.$n,
                'description' => 'Baris SMKK ke-'.$n,
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => 10_000_000,
                'amount' => 10_000_000,
            ]);

            $lines[] = ['boq_item_id' => $item->id, 'category' => 'Baris ke-'.$n];
        }

        $rkk = $this->makeRkk(['boq_id' => $boq->id]);
        $this->rkkService()->syncSmkkCosts($rkk, $lines);

        $admin = $this->adminUser();

        $sweeps = 0;

        DB::listen(function (QueryExecuted $query) use (&$sweeps): void {
            if (str_contains($query->sql, '"est_boq_items"')) {
                $sweeps++;
            }
        });

        $this->actingAs($admin)->getJson("api/crm/rkk-documents/{$rkk->id}")->assertOk();
        $this->assertSame(1, $sweeps, 'show: satu sapuan est_boq_items untuk seluruh respons, bukan satu per baris SMKK.');

        $sweeps = 0;
        $this->actingAs($admin)
            ->putJson("api/crm/rkk-documents/{$rkk->id}", ['title' => 'RKK Penawaran — judul baru'])
            ->assertOk();
        $this->assertSame(1, $sweeps, 'update: satu sapuan est_boq_items untuk seluruh respons.');

        // Endpoint sinkron IBPRP tidak menyentuh est_boq_items untuk validasi,
        // jadi hitungannya murni penyusunan respons.
        $sweeps = 0;
        $this->actingAs($admin)
            ->putJson("api/crm/rkk-documents/{$rkk->id}/ibprp-links", ['ibprp_links' => []])
            ->assertOk();
        $this->assertSame(1, $sweeps, 'syncIbprpLinks: satu sapuan est_boq_items untuk seluruh respons.');
    }

    public function test_an_smkk_line_pointing_at_a_missing_boq_row_is_refused(): void
    {
        [$boq] = $this->makeBoqWithSmkkLine();
        $rkk = $this->makeRkk(['boq_id' => $boq->id]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->rkkService()->syncSmkkCosts($rkk, [['boq_item_id' => 999_002]]);
    }

    public function test_an_smkk_line_from_another_boq_is_refused(): void
    {
        [$boq] = $this->makeBoqWithSmkkLine();

        $otherBoq = Boq::query()->create(['code' => 'BOQ/2026/9002', 'title' => 'RAB lain', 'status' => 'draft']);
        $otherSection = BoqSection::query()->create([
            'boq_id' => $otherBoq->id, 'section_no' => 'A', 'name' => 'Pekerjaan lain',
        ]);
        $otherItem = BoqItem::query()->create([
            'boq_id' => $otherBoq->id, 'section_id' => $otherSection->id, 'wbs_code' => 'A.1',
            'description' => 'Baris RAB proyek lain', 'qty' => 1, 'unit' => 'ls',
            'unit_price' => 1_000_000, 'amount' => 1_000_000,
        ]);

        $rkk = $this->makeRkk(['boq_id' => $boq->id]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('bukan milik BoQ');

        $this->rkkService()->syncSmkkCosts($rkk, [['boq_item_id' => $otherItem->id]]);
    }
}
