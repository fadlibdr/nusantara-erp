<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Support\AttachableDocuments;
use Modules\Procurement\Models\NegotiationMinute;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * P2 — Berita Acara Negosiasi (BAN, DAN 31).
 */
class NegotiationMinuteTest extends ErpTestCase
{
    private function vendor(string $code = 'VND-N1'): Vendor
    {
        return Vendor::query()->create([
            'code' => $code, 'name' => 'PT Nego', 'classification' => 'material',
            'is_pkp' => false, 'is_subcontractor' => false,
            'payment_term_days' => 30, 'status' => 'active',
        ]);
    }

    private function rfq(Vendor $vendor): Rfq
    {
        $created = $this->postJson('/api/procurement/rfqs', [
            'rfq_date' => '2026-08-08', 'vendor_ids' => [$vendor->id],
            'items' => [['description' => 'Pompa', 'qty' => 1, 'unit' => 'unit']],
        ])->assertStatus(201);

        return Rfq::query()->findOrFail((int) $created->json('data.id'));
    }

    public function test_a_negotiation_minute_records_its_attendees_and_price_rows(): void
    {
        Sanctum::actingAs($this->adminUser());
        $vendor = $this->vendor();
        $rfq = $this->rfq($vendor);

        $response = $this->postJson('/api/procurement/negotiation-minutes', [
            'rfq_id' => $rfq->id, 'vendor_id' => $vendor->id, 'meeting_date' => '2026-08-10',
            'location' => 'Kantor proyek',
            'peserta' => [['nama' => 'Andi', 'jabatan' => 'Procurement', 'pihak' => 'kontraktor']],
            'items' => [['description' => 'Pompa', 'harga_awal' => 100_000_000, 'harga_nego' => 92_000_000]],
        ])->assertStatus(201);

        $this->assertStringStartsWith('BAN/', (string) $response->json('data.code'));

        $minute = NegotiationMinute::query()->with('items')->findOrFail((int) $response->json('data.id'));
        $this->assertCount(1, $minute->items);
        $this->assertSame('92000000.00', (string) $minute->items->first()->harga_nego);
        $this->assertSame('Andi', $minute->peserta[0]['nama']);
    }

    public function test_a_minute_cannot_be_recorded_for_an_uninvited_vendor(): void
    {
        Sanctum::actingAs($this->adminUser());
        $vendor = $this->vendor();
        $rfq = $this->rfq($vendor);
        $stranger = $this->vendor('VND-N2');

        $this->postJson('/api/procurement/negotiation-minutes', [
            'rfq_id' => $rfq->id, 'vendor_id' => $stranger->id, 'meeting_date' => '2026-08-10',
        ])->assertStatus(422);
    }

    public function test_the_negotiation_minute_is_attachable_for_its_daftar_hadir(): void
    {
        $this->assertTrue(AttachableDocuments::has('procurement/negotiation-minutes'));
        $this->assertSame(NegotiationMinute::class, AttachableDocuments::classFor('procurement/negotiation-minutes'));
        $this->assertSame('prc', AttachableDocuments::prefixFor('procurement/negotiation-minutes'));
    }
}
