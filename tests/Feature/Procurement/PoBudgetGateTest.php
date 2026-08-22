<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\CostBudget;
use Modules\Finance\Models\ProjectCost;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;

/**
 * Temuan #33 — gate anggaran saat PO diajukan.
 *
 * CommitmentService sudah tahu berapa yang dijanjikan tetapi hanya melapor;
 * tidak ada satu pun pintu yang membacanya SEBELUM janji baru dibuat. PO yang
 * menjebol RAP tetap lolos dan baru kelihatan di laporan profitabilitas —
 * saat komitmennya sudah ditandatangani.
 *
 * Sisi PO diuji terhadap anggaran RAP NON-SUBKON (material+upah+alat+overhead):
 * komitmen PO tidak pernah bisa dipecah per kategori lebih halus dari itu,
 * sedangkan SPK punya kategorinya sendiri — fixture di sini sengaja memasang
 * anggaran subkon besar supaya gate yang salah menjumlah seluruh RAP akan
 * meloloskan PO ini dan tes gagal.
 */
class PoBudgetGateTest extends ErpTestCase
{
    private Project $project;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-901',
            'name' => 'Proyek Uji Gate Anggaran',
            'type' => 'construction',
            'status' => 'active',
        ]);

        $this->vendor = Vendor::query()->create([
            'code' => 'VND-0001',
            'name' => 'PT Semen Distribusi Utama',
            'classification' => 'material',
            'is_pkp' => false,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    /**
     * RAP disetujui: non-subkon Rp 75 juta (material), subkon Rp 200 juta.
     * Komitmen PO berjalan Rp 35 juta, realisasi material Rp 15 juta —
     * sisa non-subkon Rp 25 juta.
     */
    private function seedBudgetCommitmentAndActual(): void
    {
        $boq = Boq::query()->create([
            'project_id' => $this->project->id,
            'title' => 'RAB Gedung Uji Anggaran',
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
            ['material', 'Semen & besi', 75_000_000],
            ['subcon', 'Pekerjaan struktur subkon', 200_000_000],
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

        // Komitmen berjalan: PO lain yang sudah disetujui, belum ditagih.
        $this->po(35_000_000, DocumentStatus::Approved);

        // Realisasi: tagihan material yang sudah menjadi biaya proyek.
        ProjectCost::query()->create([
            'project_id' => $this->project->id,
            'cost_date' => '2026-07-15',
            'cost_category' => 'material',
            'description' => 'Tagihan material Juli',
            'amount' => 15_000_000,
        ]);
    }

    private function po(float $dpp, DocumentStatus $status): PurchaseOrder
    {
        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
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
            'description' => 'Material struktur',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => $dpp,
            'amount' => $dpp,
        ]);

        return $po;
    }

    private function submit(PurchaseOrder $po, array $payload = [])
    {
        return $this->postJson("/api/procurement/purchase-orders/{$po->id}/submit", $payload);
    }

    public function test_a_po_over_the_remaining_budget_is_refused_with_every_number_named(): void
    {
        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $po = $this->po(45_000_000, DocumentStatus::Draft); // sisa hanya 25 juta

        $response = $this->submit($po)->assertStatus(422);

        $message = (string) $response->json('errors.budget.0');
        $this->assertStringContainsString('Rp 75.000.000', $message); // anggaran non-subkon
        $this->assertStringContainsString('Rp 15.000.000', $message); // realisasi
        $this->assertStringContainsString('Rp 35.000.000', $message); // komitmen berjalan
        $this->assertStringContainsString('Rp 45.000.000', $message); // dokumen ini
        $this->assertStringContainsString('Rp 20.000.000', $message); // pelampauan

        $po->refresh();
        $this->assertSame(DocumentStatus::Draft, $po->status);
        $this->assertSame(0, $po->approvals()->count());
    }

    public function test_the_confirm_flag_lets_the_acknowledged_overshoot_through_in_warn_mode(): void
    {
        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $po = $this->po(45_000_000, DocumentStatus::Draft);

        $this->submit($po, ['confirm_over_budget' => true])->assertOk();
        $this->assertSame(DocumentStatus::Submitted, $po->fresh()->status);
    }

    public function test_a_po_within_the_remaining_budget_submits_without_ceremony(): void
    {
        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $po = $this->po(20_000_000, DocumentStatus::Draft); // <= sisa 25 juta

        $this->submit($po)->assertOk();
        $this->assertSame(DocumentStatus::Submitted, $po->fresh()->status);
    }

    public function test_block_mode_refuses_even_a_confirmed_overshoot(): void
    {
        config()->set('erp.procurement.budget_gate', 'block');

        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $po = $this->po(45_000_000, DocumentStatus::Draft);

        $response = $this->submit($po, ['confirm_over_budget' => true])->assertStatus(422);
        $this->assertStringContainsString('Rp 20.000.000', (string) $response->json('message'));
        $this->assertSame(DocumentStatus::Draft, $po->fresh()->status);
    }

    public function test_off_mode_disables_the_gate(): void
    {
        config()->set('erp.procurement.budget_gate', 'off');

        Sanctum::actingAs($this->adminUser());
        $this->seedBudgetCommitmentAndActual();

        $po = $this->po(45_000_000, DocumentStatus::Draft);

        $this->submit($po)->assertOk();
    }

    public function test_without_an_approved_rap_there_is_no_budget_to_gate_on(): void
    {
        Sanctum::actingAs($this->adminUser());

        $po = $this->po(45_000_000, DocumentStatus::Draft);

        $this->submit($po)->assertOk();
    }
}
