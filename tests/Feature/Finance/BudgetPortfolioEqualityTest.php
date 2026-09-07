<?php

namespace Tests\Feature\Finance;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ProjectCost;
use Modules\Finance\Services\BudgetRealisationService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Models\Subcontract;
use Tests\ErpTestCase;

/**
 * F-2 / T2.3 — angka layar portofolio DAN angka gerbang adalah satu angka.
 *
 * KENAPA UJI INI BERBENTUK BEGINI. Membandingkan dua pemanggilan kelas yang
 * sama akan selalu hijau dan membuktikan nol: keduanya membaca kode yang sama.
 * Yang diuji di sini adalah PERILAKU gerbang di ujung dunia — sebuah PO
 * sungguhan diajukan lewat HTTP dengan DPP tepat sebesar `remaining_non_subcon`
 * yang dicetak portofolio (harus LOLOS), lalu satu sen di atasnya (harus 422
 * pada kunci `budget`). Kalau layar dan gerbang pernah berselisih satu rupiah,
 * salah satu dari dua pengajuan itu menjawab terbalik.
 *
 * Lima keadaan yang diminta kontrak paket, masing-masing sebuah proyek:
 * tanpa RAP, lampau anggaran, tepat di anggaran, nilai kontrak nol, dan proyek
 * dengan anggaran normal (batas atas + satu sen). Revisi RAP diuji terpisah di
 * RapRevisionTest, yang memakai kelas yang sama.
 */
class BudgetPortfolioEqualityTest extends ErpTestCase
{
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::query()->create([
            'code' => 'VND-9001',
            'name' => 'PT Pemasok Uji Anggaran',
            'classification' => 'material',
            'is_pkp' => false,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    private function project(string $code, float $contractValue = 1_000_000_000): Project
    {
        return Project::query()->create([
            'code' => $code,
            'name' => 'Proyek '.$code,
            'type' => 'construction',
            'status' => 'active',
            'contract_value' => $contractValue,
        ]);
    }

    /**
     * RAP disetujui dengan dua sisi: non-subkon (material) dan subkon.
     */
    private function approvedRap(Project $project, float $nonSubcon, float $subcon, ?string $code = null): CostBudget
    {
        $boq = Boq::query()->create([
            'project_id' => $project->id,
            'title' => 'RAB '.$project->code,
            'status' => DocumentStatus::Approved,
        ]);
        $section = $boq->sections()->create(['section_no' => 'A', 'name' => 'Struktur']);

        /** @var CostBudget $rap */
        $rap = CostBudget::query()->create([
            'code' => $code,
            'boq_id' => $boq->id,
            'project_id' => $project->id,
            'target_margin_pct' => 10,
            'status' => DocumentStatus::Approved,
        ]);

        foreach ([['material', $nonSubcon], ['subcon', $subcon]] as [$category, $amount]) {
            if ($amount <= 0) {
                continue;
            }

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

        return $rap;
    }

    private function po(Project $project, float $dpp, DocumentStatus $status): PurchaseOrder
    {
        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $project->id,
            'order_date' => '2026-08-08',
            'payment_term_days' => 30,
            'subtotal' => $dpp,
            'discount_amount' => 0,
            'dpp' => $dpp,
            'ppn_rate' => 0,
            'ppn_amount' => 0,
            'total' => $dpp,
            'status' => $status,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'description' => 'Material',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $dpp,
            'amount' => $dpp,
        ]);

        return $po;
    }

    private function spk(Project $project, float $value, DocumentStatus $status): Subcontract
    {
        return Subcontract::query()->create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $project->id,
            'title' => 'Pekerjaan struktur '.$project->code,
            'scope' => 'Pekerjaan struktur',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-31',
            'value' => $value,
            'retention_pct' => 5,
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'status' => $status,
        ]);
    }

    private function cost(Project $project, string $category, float $amount, string $date = '2026-07-15'): void
    {
        ProjectCost::query()->create([
            'project_id' => $project->id,
            'cost_date' => $date,
            'cost_category' => $category,
            'description' => 'Realisasi uji',
            'amount' => $amount,
        ]);
    }

    /** @return array<string, mixed> baris portofolio proyek ini */
    private function portfolioRow(Project $project): array
    {
        foreach (app(BudgetRealisationService::class)->portfolio() as $row) {
            if ($row['project_code'] === $project->code) {
                return $row;
            }
        }

        $this->fail("proyek {$project->code} tidak ada di portofolio");
    }

    private function submitPo(PurchaseOrder $po)
    {
        return $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit");
    }

    private function submitSpk(Subcontract $spk)
    {
        return $this->postJson("/api/subcontract/subcontracts/{$spk->id}/submit");
    }

    // ------------------------------------------------- kesetaraan di batasnya

    /**
     * Batas atas yang dicetak portofolio ADALAH DPP terbesar yang diterima
     * gerbang. Satu sen di atasnya ditolak, dengan kalimat yang menyebut
     * sisa yang sama.
     */
    public function test_the_portfolio_remaining_is_exactly_the_largest_po_the_gate_accepts(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-911');
        $this->approvedRap($project, nonSubcon: 75_000_000, subcon: 200_000_000);
        $this->po($project, 35_000_000, DocumentStatus::Approved);   // komitmen berjalan
        $this->cost($project, 'material', 15_000_000);               // realisasi

        $row = $this->portfolioRow($project);
        $remaining = (float) $row['remaining_non_subcon'];

        $this->assertSame(25000000.0, $remaining, 'sisa non-subkon = 75jt − 15jt − 35jt');

        // Tepat di batas: diterima.
        $this->submitPo($this->po($project, $remaining, DocumentStatus::Draft))->assertOk();

        // Satu sen di atasnya: ditolak, dan kalimatnya menyebut sisa yang sama.
        $refusal = $this->submitPo($this->po($project, $remaining + 0.01, DocumentStatus::Draft));
        $refusal->assertStatus(422);
        $this->assertStringContainsString('menyisakan Rp 25.000.000', $refusal->json('errors.budget.0'));
    }

    public function test_the_portfolio_subcon_remaining_is_exactly_the_largest_spk_the_gate_accepts(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-912');
        $this->approvedRap($project, nonSubcon: 500_000_000, subcon: 200_000_000);
        $this->spk($project, 50_000_000, DocumentStatus::Approved);  // komitmen SPK berjalan
        $this->cost($project, 'subcon', 20_000_000);

        $row = $this->portfolioRow($project);
        $remaining = (float) $row['remaining_subcon'];

        $this->assertSame(130000000.0, $remaining, 'sisa subkon = 200jt − 20jt − 50jt');

        $this->submitSpk($this->spk($project, $remaining, DocumentStatus::Draft))->assertOk();

        $refusal = $this->submitSpk($this->spk($project, $remaining + 0.01, DocumentStatus::Draft));
        $refusal->assertStatus(422);
        $this->assertStringContainsString('menyisakan Rp 130.000.000', $refusal->json('errors.budget.0'));
    }

    // ------------------------------------------------------------ tepi kasus

    /**
     * Tanpa RAP disetujui: portofolio menggaris (null), TIDAK menulis Rp 0 —
     * dan gerbang diam, karena tidak ada anggaran yang bisa dilampaui.
     */
    public function test_a_project_without_an_approved_rap_is_ruled_on_screen_and_silent_at_the_gate(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-913');

        $row = $this->portfolioRow($project);
        $this->assertNull($row['budget']);
        $this->assertNull($row['remaining']);
        $this->assertNull($row['pct']);
        // TANPA_BATAS, bukan TIDAK_TERUKUR: yang diukur (realisasi + komitmen)
        // SELALU terukur — jumlah nol baris memang nol rupiah — dan yang tidak
        // ada di sini adalah BATASNYA. Entri rap_vs_kontrak_pct di registri
        // adalah kebalikannya, dan itulah kenapa kedua keadaan itu terpisah.
        $this->assertSame('tanpa_batas', $row['state']);

        // Rp 10 miliar pada proyek tanpa RAP: lolos, karena tidak ada aturannya.
        $this->submitPo($this->po($project, 10_000_000_000, DocumentStatus::Draft))->assertOk();
    }

    /**
     * Tepat di anggaran: sisa 0, portofolio 100 % dan keadaan LAMPAU (100 %
     * ada di sisi lampau — anggaran yang habis persis tidak menyisakan apa pun),
     * dan gerbang menolak dokumen bernilai berapa pun di atas nol.
     */
    public function test_exactly_at_budget_reads_one_hundred_percent_and_refuses_the_next_rupiah(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-914');
        $this->approvedRap($project, nonSubcon: 100_000_000, subcon: 0);
        $this->cost($project, 'material', 100_000_000);

        $row = $this->portfolioRow($project);
        $this->assertSame(0.0, $row['remaining']);
        $this->assertSame(100.0, $row['pct']);
        $this->assertSame('lampau', $row['state']);

        $this->submitPo($this->po($project, 1, DocumentStatus::Draft))->assertStatus(422);
    }

    /**
     * Sudah lewat anggaran: persentasenya DI ATAS 100 dan tidak dijepit — sebuah
     * layar yang menampilkan 100 % untuk proyek yang 140 % terpakai menyembunyikan
     * persis angka yang harus dibaca orang.
     */
    public function test_an_over_budget_project_reports_more_than_one_hundred_percent(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-915');
        $this->approvedRap($project, nonSubcon: 100_000_000, subcon: 0);
        $this->cost($project, 'material', 140_000_000);

        $row = $this->portfolioRow($project);

        $this->assertSame(140.0, $row['pct']);
        $this->assertSame(-40000000.0, $row['remaining']);
        $this->assertSame('lampau', $row['state']);
        $this->assertStringContainsString('sisa Rp 0', $row['sentence'], 'kalimatnya tidak boleh menjanjikan sisa negatif');
    }

    /**
     * Nilai kontrak nol adalah "belum dicatat", bukan kontrak nol rupiah:
     * digaris di portofolio, dan tidak mengubah satu pun angka anggaran.
     */
    public function test_a_zero_contract_value_is_ruled_and_changes_no_budget_number(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-916', contractValue: 0);
        $this->approvedRap($project, nonSubcon: 80_000_000, subcon: 0);

        $row = $this->portfolioRow($project);

        $this->assertNull($row['contract_value'], 'nilai kontrak 0 harus digaris, bukan dicetak Rp 0');
        $this->assertSame(80000000.0, $row['budget']);
        $this->assertSame(80000000.0, $row['remaining']);
    }

    /**
     * Ambang 90 % dari T2.1 dibaca di sini juga — satu ambang, satu keadaan,
     * dua layar.
     */
    public function test_ninety_percent_used_is_the_warning_state_the_registry_defines(): void
    {
        $project = $this->project('PRJ-2026-917');
        $this->approvedRap($project, nonSubcon: 100_000_000, subcon: 0);
        $this->cost($project, 'material', 90_000_000);

        $row = $this->portfolioRow($project);

        $this->assertSame(90.0, $row['pct']);
        $this->assertSame('mendekati', $row['state']);
        $this->assertSame(90.0, $row['warn_pct']);
    }

    /**
     * Dan registri Core menerbitkan angka yang SAMA — karena barisnya dipasok
     * dari kelas ini, bukan dihitung ulang di Core.
     */
    public function test_the_core_registry_publishes_the_same_number_as_the_portfolio(): void
    {
        Sanctum::actingAs($this->adminUser());

        $project = $this->project('PRJ-2026-918');
        $this->approvedRap($project, nonSubcon: 200_000_000, subcon: 0);
        $this->po($project, 60_000_000, DocumentStatus::Approved);
        $this->cost($project, 'material', 40_000_000);

        $row = $this->portfolioRow($project);

        $registry = collect($this->getJson('/api/core/thresholds')->assertOk()->json('data'))
            ->firstWhere('key', 'project_budget_pct');

        $this->assertNotNull($registry, 'entri project_budget_pct tidak dipasok Finance');

        $line = collect($registry['rows'])->firstWhere('subject', $project->code);

        // assertEquals, bukan assertSame: JSON tidak membedakan 100000000 dari
        // 100000000.0, dan yang diuji di sini adalah ANGKANYA.
        $this->assertEquals($row['used'], $line['actual']);
        $this->assertEquals($row['budget'], $line['limit']);
        $this->assertEquals($row['pct'], $line['pct']);
        $this->assertSame($row['state'], $line['state']);
    }
}
