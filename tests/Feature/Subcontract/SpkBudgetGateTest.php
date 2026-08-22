<?php

namespace Tests\Feature\Subcontract;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ProjectCost;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Models\Subcontract;
use Tests\ErpTestCase;

/**
 * Temuan #33, sisi SPK — cermin gate anggaran PO.
 *
 * SPK diukur terhadap anggaran RAP KATEGORI SUBKON: komitmen SPK persis
 * kategori itu (CommitmentService memisahkannya dari komitmen PO), jadi
 * ketiga angkanya — anggaran, realisasi, komitmen — bicara tentang ember yang
 * sama. Fixture memasang anggaran material raksasa supaya gate yang salah
 * menjumlah seluruh RAP meloloskan SPK ini dan tesnya gagal.
 */
class SpkBudgetGateTest extends ErpTestCase
{
    private Project $project;

    private Vendor $subcontractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-902',
            'name' => 'Proyek Uji Gate Anggaran SPK',
            'type' => 'construction',
            'status' => 'active',
        ]);

        $this->subcontractor = Vendor::query()->create([
            'code' => 'VND-0002',
            'name' => 'CV Baja Konstruksi Mandiri',
            'classification' => 'sipil',
            'is_pkp' => false,
            'is_subcontractor' => true,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    /**
     * RAP disetujui: subkon Rp 200 juta, material Rp 900 juta (umpan untuk
     * gate yang salah ember). Komitmen SPK berjalan Rp 120 juta, realisasi
     * subkon Rp 30 juta — sisa subkon Rp 50 juta.
     */
    private function seedBudgetCommitmentAndActual(): void
    {
        $boq = Boq::query()->create([
            'project_id' => $this->project->id,
            'title' => 'RAB Gedung Uji Anggaran SPK',
            'status' => DocumentStatus::Approved,
        ]);
        $section = $boq->sections()->create(['section_no' => 'A', 'name' => 'Struktur']);

        $rap = CostBudget::query()->create([
            'boq_id' => $boq->id,
            'project_id' => $this->project->id,
            'target_margin_pct' => 10,
            'status' => DocumentStatus::Approved,
        ]);

        foreach ([
            ['subcon', 'Pekerjaan struktur subkon', 200_000_000],
            ['material', 'Material seluruh proyek', 900_000_000],
        ] as [$category, $description, $amount]) {
            $boqItem = $boq->items()->create([
                'section_id' => $section->id,
                'wbs_code' => 'A.'.$category,
                'description' => $description,
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $amount,
                'amount' => $amount,
            ]);

            $rap->items()->create([
                'boq_item_id' => $boqItem->id,
                'cost_category' => $category,
                'description' => $description,
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $amount,
                'amount' => $amount,
            ]);
        }

        // Komitmen berjalan: SPK lain yang sudah disetujui, belum diopname.
        $this->spk(120_000_000, DocumentStatus::Approved);

        // Realisasi subkon: opname yang sudah menjadi biaya proyek.
        ProjectCost::query()->create([
            'project_id' => $this->project->id,
            'cost_date' => '2026-07-15',
            'cost_category' => 'subcon',
            'description' => 'Opname struktur Juli',
            'amount' => 30_000_000,
        ]);
    }

    private function spk(float $value, DocumentStatus $status): Subcontract
    {
        return Subcontract::query()->create([
            'vendor_id' => $this->subcontractor->id,
            'project_id' => $this->project->id,
            'title' => 'Pekerjaan struktur baja',
            'value' => $value,
            'ppn_rate' => 0,
            'retention_pct' => 5,
            'pph_scheme' => 'pelaksanaan_bersertifikat',
            'pph_rate' => 2.65,
            'status' => $status,
        ]);
    }

    private function submit(Subcontract $spk, array $payload = [])
    {
        return $this->postJson("/api/subcontract/subcontracts/{$spk->id}/submit", $payload);
    }

    public function test_an_spk_over_the_remaining_subcon_budget_is_refused_with_every_number_named(): void
    {
        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $spk = $this->spk(80_000_000, DocumentStatus::Draft); // sisa subkon hanya 50 juta

        $response = $this->submit($spk)->assertStatus(422);

        $message = (string) $response->json('errors.budget.0');
        $this->assertStringContainsString('Rp 200.000.000', $message); // anggaran subkon
        $this->assertStringContainsString('Rp 30.000.000', $message);  // realisasi
        $this->assertStringContainsString('Rp 120.000.000', $message); // komitmen berjalan
        $this->assertStringContainsString('Rp 80.000.000', $message);  // dokumen ini
        $this->assertStringContainsString('Rp 30.000.000', $message);  // pelampauan (80 - 50)

        $spk->refresh();
        $this->assertSame(DocumentStatus::Draft, $spk->status);
        $this->assertSame(0, $spk->approvals()->count());
    }

    public function test_the_confirm_flag_lets_the_acknowledged_overshoot_through_in_warn_mode(): void
    {
        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $spk = $this->spk(80_000_000, DocumentStatus::Draft);

        $this->submit($spk, ['confirm_over_budget' => true])->assertOk();
        $this->assertSame(DocumentStatus::Submitted, $spk->fresh()->status);
    }

    public function test_an_spk_within_the_remaining_subcon_budget_submits_without_ceremony(): void
    {
        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $spk = $this->spk(40_000_000, DocumentStatus::Draft); // <= sisa 50 juta

        $this->submit($spk)->assertOk();
        $this->assertSame(DocumentStatus::Submitted, $spk->fresh()->status);
    }

    public function test_block_mode_refuses_even_a_confirmed_overshoot(): void
    {
        config()->set('erp.procurement.budget_gate', 'block');

        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $spk = $this->spk(80_000_000, DocumentStatus::Draft);

        $this->submit($spk, ['confirm_over_budget' => true])->assertStatus(422);
        $this->assertSame(DocumentStatus::Draft, $spk->fresh()->status);
    }

    public function test_off_mode_disables_the_gate(): void
    {
        config()->set('erp.procurement.budget_gate', 'off');

        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $this->submit($this->spk(80_000_000, DocumentStatus::Draft))->assertOk();
    }
}
