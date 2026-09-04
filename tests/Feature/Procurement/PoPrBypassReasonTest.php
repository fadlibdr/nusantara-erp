<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;
use Modules\Procurement\Services\ProcurementFormService;
use Tests\ErpTestCase;

/**
 * A PO without a PR needs a recorded reason (T3.8, ANALISIS-PROSES E3).
 *
 * A direct PO is allowed by design (site emergencies), and the tree already
 * has the pattern for "allowed, but on the record": qualification_override_reason
 * stays on the PO, appears in the Resource, the Informasi panel and the printed
 * order's notes block. The PR bypass had none of that — measured 4 Sep 2026 on
 * production: PO/2026/III/0002, Rp 128 jt, purchase_requisition_id NULL, the
 * only trace of why being a comment in the seeder ("direct PO (PR ICT masih
 * submitted)"). pr_bypass_reason mirrors the override column end to end, with
 * the same honesty contract: recorded only when the bypass actually happens
 * (a reason typed for a PO that HAS a PR is not a bypass, and stays NULL).
 */
class PoPrBypassReasonTest extends ErpTestCase
{
    private const SENTENCE = 'Alasan tanpa PR wajib diisi bila PR kosong.';

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->adminUser());
    }

    private function vendor(): Vendor
    {
        return Vendor::query()->create([
            'name' => 'PT Pemasok Baja Utama',
            'classification' => 'material',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    private function approvedPr(): PurchaseRequisition
    {
        $pr = PurchaseRequisition::query()->create([
            'purpose' => 'Besi beton lantai 3',
            'needed_date' => '2026-08-20',
            'status' => DocumentStatus::Approved,
        ]);

        $pr->items()->create([
            'line_no' => 1,
            'description' => 'Besi beton D16',
            'qty' => 100,
            'unit' => 'btg',
            'estimated_price' => 150_000,
        ]);

        return $pr;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $this->vendor()->id,
            'order_date' => '2026-08-08',
            'expected_date' => '2026-08-22',
            'items' => [
                ['description' => 'Kabel NYY 4x10', 'qty' => 50, 'unit' => 'm', 'unit_price' => 125_000],
            ],
        ], $overrides);
    }

    public function test_a_po_without_a_pr_and_without_a_reason_is_refused_in_indonesian(): void
    {
        $this->postJson('/api/procurement/purchase-orders', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.pr_bypass_reason.0', self::SENTENCE);

        $this->assertSame(0, PurchaseOrder::query()->count(), 'A refused PO must not be created.');
    }

    /**
     * The audit trail, the way the override reason is visible: the column, the
     * Resource on show(), and the printed order's notes block.
     */
    public function test_a_direct_po_stores_its_reason_and_shows_it_wherever_the_override_reason_shows(): void
    {
        $reason = 'Pembelian darurat: PR ICT masih menunggu persetujuan, material tahap 1 harus tiba pekan ini.';

        $response = $this->postJson('/api/procurement/purchase-orders', $this->payload(['pr_bypass_reason' => $reason]))
            ->assertCreated()
            ->assertJsonPath('data.pr_bypass_reason', $reason);

        $po = PurchaseOrder::query()->findOrFail($response->json('data.id'));
        $this->assertSame($reason, $po->pr_bypass_reason);
        $this->assertNull($po->purchase_requisition_id);

        $this->getJson("/api/procurement/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.pr_bypass_reason', $reason);

        $this->assertStringContainsString(
            'Alasan tanpa PR : '.$reason,
            (string) app(ProcurementFormService::class)->orderNotes($po),
            'The printed order carries the reason in its notes block, next to the override reason.',
        );
    }

    /**
     * Mirror of PoQualificationOverrideAuditTest "vendor sehat": a reason
     * typed for a PO that HAS a PR describes no bypass and must not be kept —
     * keeping it would tag a regular PO as a direct one.
     */
    public function test_a_po_from_a_pr_needs_no_reason_and_drops_one_typed_anyway(): void
    {
        $pr = $this->approvedPr();

        $response = $this->postJson('/api/procurement/purchase-orders', $this->payload([
            'purchase_requisition_id' => $pr->id,
            'pr_bypass_reason' => 'Salah paham formulir — PO ini dari PR',
        ]))->assertCreated();

        $po = PurchaseOrder::query()->findOrFail($response->json('data.id'));
        $this->assertSame($pr->id, $po->purchase_requisition_id);
        $this->assertNull($po->pr_bypass_reason);

        $this->postJson('/api/procurement/purchase-orders', $this->payload(['purchase_requisition_id' => $pr->id]))
            ->assertCreated();
    }

    public function test_buat_po_dari_pr_carries_no_bypass_reason(): void
    {
        $po = app(PoService::class)->createFromPr($this->approvedPr(), ['vendor_id' => $this->vendor()->id]);

        $this->assertNotNull($po->purchase_requisition_id);
        $this->assertNull($po->pr_bypass_reason);
    }

    /**
     * Ubah renders the same form with the same required mark, so the server
     * matches it (the T3.5 precedent for expected_date): a PUT that carries an
     * empty PR and an empty reason is refused; a PUT without either key (a
     * line-only edit) keeps the stored reason; the reason itself stays editable.
     */
    public function test_an_ubah_cannot_blank_the_reason_of_a_direct_po(): void
    {
        $poId = $this->postJson('/api/procurement/purchase-orders', $this->payload(['pr_bypass_reason' => 'Darurat lapangan']))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/procurement/purchase-orders/{$poId}", ['purchase_requisition_id' => null, 'pr_bypass_reason' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.pr_bypass_reason.0', self::SENTENCE);

        $this->putJson("/api/procurement/purchase-orders/{$poId}", ['notes' => 'Kirim ke gudang proyek.'])
            ->assertOk()
            ->assertJsonPath('data.pr_bypass_reason', 'Darurat lapangan');

        $this->putJson("/api/procurement/purchase-orders/{$poId}", ['purchase_requisition_id' => null, 'pr_bypass_reason' => 'Darurat lapangan — stok habis'])
            ->assertOk()
            ->assertJsonPath('data.pr_bypass_reason', 'Darurat lapangan — stok habis');
    }

    /**
     * The API edge the form cannot produce: detaching the PR without giving a
     * reason would leave a direct PO with none. Refused with the same sentence.
     */
    public function test_detaching_the_pr_on_ubah_without_a_reason_is_refused(): void
    {
        $pr = $this->approvedPr();
        $poId = $this->postJson('/api/procurement/purchase-orders', $this->payload(['purchase_requisition_id' => $pr->id]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/procurement/purchase-orders/{$poId}", ['purchase_requisition_id' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.pr_bypass_reason.0', self::SENTENCE);

        $this->assertSame($pr->id, PurchaseOrder::query()->findOrFail($poId)->purchase_requisition_id);
    }

    /**
     * The other direction: a direct PO later linked to its PR is no longer a
     * bypass, so the reason is cleared — the same contract as the override
     * column, which is only ever stamped when the gate was actually passed.
     */
    public function test_linking_a_direct_po_to_a_pr_on_ubah_clears_the_bypass_reason(): void
    {
        $pr = $this->approvedPr();
        $poId = $this->postJson('/api/procurement/purchase-orders', $this->payload(['pr_bypass_reason' => 'Darurat lapangan']))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/procurement/purchase-orders/{$poId}", ['purchase_requisition_id' => $pr->id])
            ->assertOk()
            ->assertJsonPath('data.purchase_requisition_id', $pr->id)
            ->assertJsonPath('data.pr_bypass_reason', null);

        $this->assertNull(PurchaseOrder::query()->findOrFail($poId)->pr_bypass_reason);
    }
}
