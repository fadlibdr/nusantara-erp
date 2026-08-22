<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Vendor;
use Tests\ErpTestCase;

/**
 * Temuan #34 tahap 3 — RFQ: lembar banding penawaran vendor.
 *
 * Sebelum dokumen ini, "sudah banding harga tiga vendor" hidup di spreadsheet
 * pribadi staf pengadaan: tak bisa diaudit, tak tertaut PR, dan harga
 * pemenangnya diketik ulang ke PO dengan tangan — salah ketik satu digit pada
 * PO Rp 128 juta tidak terdeteksi siapa pun. RFQ menyimpan tabulasinya di
 * tempat kerjanya: undangan vendor, harga per vendor per baris, pemenang per
 * baris, dan "Buat PO dari RFQ" membawa harga pemenang tanpa pengetikan ulang.
 *
 * Sengaja kurus: tidak ada portal vendor, tidak ada email — staf pengadaan
 * mengetikkan penawaran yang diterimanya.
 */
class RfqTest extends ErpTestCase
{
    private function vendor(string $code, string $name): Vendor
    {
        return Vendor::query()->create([
            'code' => $code,
            'name' => $name,
            'classification' => 'material',
            'is_pkp' => false,
            'is_subcontractor' => false,
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    private function approvedPr(): PurchaseRequisition
    {
        /** @var PurchaseRequisition $pr */
        $pr = PurchaseRequisition::query()->create([
            'needed_date' => '2026-08-25',
            'status' => DocumentStatus::Approved,
        ]);

        $pr->items()->create([
            'line_no' => 1,
            'description' => 'Semen PCC 50 kg',
            'qty' => 200,
            'unit' => 'zak',
            'estimated_price' => 75_000,
            'boq_item_id' => 501,
        ]);
        $pr->items()->create([
            'line_no' => 2,
            'description' => 'Besi beton D16',
            'qty' => 100,
            'unit' => 'btg',
            'estimated_price' => 145_000,
            'boq_item_id' => 502,
        ]);

        return $pr;
    }

    /** RFQ dari PR dengan dua vendor terundang, siap diisi penawaran. */
    private function rfqFromPr(Vendor $a, Vendor $b): array
    {
        $pr = $this->approvedPr();

        $response = $this->postJson('/api/procurement/rfqs', [
            'purchase_requisition_id' => $pr->id,
            'rfq_date' => '2026-08-08',
            'vendor_ids' => [$a->id, $b->id],
        ])->assertStatus(201);

        return [Rfq::query()->findOrFail((int) $response->json('data.id')), $pr];
    }

    private function quote(Rfq $rfq, Vendor $vendor, array $prices)
    {
        $items = $rfq->items()->orderBy('line_no')->get();

        return $this->postJson("/api/procurement/rfqs/{$rfq->id}/quotes", [
            'quotes' => collect($prices)->map(fn ($price, $index) => [
                'rfq_item_id' => $items[$index]->id,
                'vendor_id' => $vendor->id,
                'unit_price' => $price,
            ])->values()->all(),
        ]);
    }

    /**
     * Ubah pada RFQ draf dulu MENGHANGUSKAN matriks penawaran: form generik
     * selalu mengirim items, syncItems menghapus-dan-membuat-ulang, dan FK
     * cascade membawa semua sel harga yang sudah diketik ikut lenyap — padahal
     * yang diubah hanya judulnya.
     */
    public function test_an_ubah_that_keeps_the_lines_keeps_the_typed_quotes(): void
    {
        Sanctum::actingAs($this->adminUser());

        $a = $this->vendor('VND-Q1', 'PT Semen Andalan');
        $b = $this->vendor('VND-Q2', 'CV Baja Kuat');
        [$rfq] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [72_000, 140_000])->assertOk();

        $items = $rfq->items()->orderBy('line_no')->get();

        $this->putJson("/api/procurement/rfqs/{$rfq->id}", [
            'notes' => 'Judul dan catatan diperbarui',
            'items' => $items->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'unit' => $line->unit,
            ])->values()->all(),
        ])->assertOk();

        $this->assertSame(2, $rfq->refresh()->items()->withCount('quotes')->get()->sum('quotes_count'),
            'Sel harga yang sudah diketik harus selamat dari Ubah yang tidak menyentuh barisnya.');
    }

    /** Baris yang benar-benar dihapus tetap membawa sel harganya — tabulasi lama atas barang baru adalah bohong. */
    public function test_a_removed_line_still_loses_its_quotes(): void
    {
        Sanctum::actingAs($this->adminUser());

        $a = $this->vendor('VND-Q3', 'PT Semen Andalan');
        $b = $this->vendor('VND-Q4', 'CV Baja Kuat');
        [$rfq] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [72_000, 140_000])->assertOk();

        $keep = $rfq->items()->orderBy('line_no')->first();

        $this->putJson("/api/procurement/rfqs/{$rfq->id}", [
            'items' => [[
                'id' => $keep->id,
                'item_id' => $keep->item_id,
                'description' => $keep->description,
                'qty' => (float) $keep->qty,
                'unit' => $keep->unit,
            ]],
        ])->assertOk();

        $fresh = $rfq->refresh()->items()->withCount('quotes')->get();
        $this->assertCount(1, $fresh);
        $this->assertSame(1, $fresh->sum('quotes_count'));
    }

    /** Bukti banding harga: begitu ada PO yang menunjuk RFQ ini, baris tidak boleh ditulis ulang. */
    public function test_line_edits_are_refused_once_a_po_references_the_rfq(): void
    {
        Sanctum::actingAs($this->adminUser());

        $a = $this->vendor('VND-Q5', 'PT Semen Andalan');
        $b = $this->vendor('VND-Q6', 'CV Baja Kuat');
        [$rfq] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [72_000, 140_000])->assertOk();
        $items = $rfq->items()->orderBy('line_no')->get();

        foreach ($items as $line) {
            $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", [
                'rfq_item_id' => $line->id, 'vendor_id' => $a->id,
            ])->assertOk();
        }

        $this->postJson("/api/procurement/rfqs/{$rfq->id}/create-po", ['vendor_id' => $a->id])
            ->assertStatus(201);

        $this->putJson("/api/procurement/rfqs/{$rfq->id}", [
            'items' => [[
                'description' => 'Barang lain sama sekali', 'qty' => 1, 'unit' => 'ls',
            ]],
        ])->assertStatus(422);

        // Catatan non-baris tetap boleh — bukti harganya utuh.
        $this->putJson("/api/procurement/rfqs/{$rfq->id}", ['notes' => 'arsip'])->assertOk();
    }

    public function test_an_rfq_born_from_an_approved_pr_copies_its_lines_and_budget_links(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$rfq] = $this->rfqFromPr($this->vendor('VND-A', 'PT Semen A'), $this->vendor('VND-B', 'PT Semen B'));

        $this->assertStringStartsWith('RFQ/', (string) $rfq->code);
        $this->assertSame(DocumentStatus::Draft, $rfq->status);

        $lines = $rfq->items()->orderBy('line_no')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('Semen PCC 50 kg', $lines[0]->description);
        $this->assertSame(501, (int) $lines[0]->boq_item_id); // tautan anggaran ikut
        $this->assertSame(2, $rfq->vendors()->count());
    }

    public function test_a_quote_from_an_uninvited_vendor_is_refused(): void
    {
        Sanctum::actingAs($this->adminUser());
        [$rfq] = $this->rfqFromPr($this->vendor('VND-A', 'PT Semen A'), $this->vendor('VND-B', 'PT Semen B'));

        $outsider = $this->vendor('VND-X', 'PT Penyusup Harga');

        $response = $this->quote($rfq, $outsider, [70_000, 140_000])->assertStatus(422);
        $this->assertStringContainsString('tidak diundang', (string) $response->json('message'));
    }

    public function test_choosing_a_whole_vendor_requires_a_complete_quote_and_names_the_missing_line(): void
    {
        Sanctum::actingAs($this->adminUser());
        $a = $this->vendor('VND-A', 'PT Semen A');
        $b = $this->vendor('VND-B', 'PT Semen B');
        [$rfq] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [70_000])->assertOk(); // hanya baris 1

        $response = $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", [
            'vendor_id' => $a->id,
        ])->assertStatus(422);

        $this->assertStringContainsString('baris 2', mb_strtolower((string) $response->json('message')));
    }

    public function test_winners_can_differ_per_line_and_the_last_choice_replaces_the_earlier_one(): void
    {
        Sanctum::actingAs($this->adminUser());
        $a = $this->vendor('VND-A', 'PT Semen A');
        $b = $this->vendor('VND-B', 'PT Semen B');
        [$rfq] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [70_000, 150_000])->assertOk();
        $this->quote($rfq, $b, [72_000, 139_000])->assertOk();

        $lines = $rfq->items()->orderBy('line_no')->get();

        // Seluruh RFQ ke A dulu, lalu baris 2 direbut B — pilihan per baris.
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", ['vendor_id' => $a->id])->assertOk();
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", [
            'vendor_id' => $b->id,
            'rfq_item_id' => $lines[1]->id,
        ])->assertOk();

        $winners = $rfq->items()->orderBy('line_no')->get()
            ->map(fn ($line) => (int) $line->quotes()->where('is_winner', true)->value('vendor_id'));

        $this->assertSame([$a->id, $b->id], $winners->all());
    }

    public function test_create_po_carries_the_winning_prices_without_retyping(): void
    {
        Sanctum::actingAs($this->adminUser());
        $a = $this->vendor('VND-A', 'PT Semen A');
        $b = $this->vendor('VND-B', 'PT Semen B');
        [$rfq, $pr] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [70_000, 150_000])->assertOk();
        $this->quote($rfq, $b, [72_000, 139_000])->assertOk();
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", ['vendor_id' => $a->id])->assertOk();

        $response = $this->postJson("/api/procurement/rfqs/{$rfq->id}/create-po", [
            'order_date' => '2026-08-10',
        ])->assertStatus(201);

        $this->assertSame('draft', (string) $response->json('data.status'));
        $this->assertSame($a->id, (int) $response->json('data.vendor_id'));
        $this->assertSame($rfq->id, (int) $response->json('data.rfq_id'));
        $this->assertSame($pr->id, (int) $response->json('data.purchase_requisition_id'));

        // Harga pemenang, kuantitas PR, dan tautan anggaran — tanpa diketik ulang.
        $this->assertSame(70000.0, (float) $response->json('data.items.0.unit_price'));
        $this->assertSame(150000.0, (float) $response->json('data.items.1.unit_price'));
        $this->assertSame(501, (int) $response->json('data.items.0.boq_item_id'));
        // subtotal = 200×70.000 + 100×150.000
        $this->assertSame(29_000_000.0, (float) $response->json('data.subtotal'));
    }

    public function test_create_po_with_winners_on_two_vendors_must_name_which_vendor(): void
    {
        Sanctum::actingAs($this->adminUser());
        $a = $this->vendor('VND-A', 'PT Semen A');
        $b = $this->vendor('VND-B', 'PT Semen B');
        [$rfq] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [70_000, 150_000])->assertOk();
        $this->quote($rfq, $b, [72_000, 139_000])->assertOk();

        $lines = $rfq->items()->orderBy('line_no')->get();
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", ['vendor_id' => $a->id, 'rfq_item_id' => $lines[0]->id])->assertOk();
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", ['vendor_id' => $b->id, 'rfq_item_id' => $lines[1]->id])->assertOk();

        // Tanpa vendor: dua pemenang, harus memilih.
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/create-po")->assertStatus(422);

        // Per vendor: masing-masing PO membawa baris kemenangannya sendiri.
        $poA = $this->postJson("/api/procurement/rfqs/{$rfq->id}/create-po", ['vendor_id' => $a->id])->assertStatus(201);
        $this->assertCount(1, (array) $poA->json('data.items'));
        $this->assertSame(70000.0, (float) $poA->json('data.items.0.unit_price'));

        $poB = $this->postJson("/api/procurement/rfqs/{$rfq->id}/create-po", ['vendor_id' => $b->id])->assertStatus(201);
        $this->assertSame(139000.0, (float) $poB->json('data.items.0.unit_price'));
    }

    public function test_a_closed_rfq_refuses_new_quotes_and_deletion_is_refused_once_a_po_exists(): void
    {
        Sanctum::actingAs($this->adminUser());
        $a = $this->vendor('VND-A', 'PT Semen A');
        $b = $this->vendor('VND-B', 'PT Semen B');
        [$rfq] = $this->rfqFromPr($a, $b);

        $this->quote($rfq, $a, [70_000, 150_000])->assertOk();
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/choose-winner", ['vendor_id' => $a->id])->assertOk();
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/create-po")->assertStatus(201);

        // PO sudah lahir dari lembar ini: menghapusnya menghapus dasar harga PO.
        $this->deleteJson("/api/procurement/rfqs/{$rfq->id}")->assertStatus(422);

        $this->postJson("/api/procurement/rfqs/{$rfq->id}/close")->assertOk();
        $this->assertSame(DocumentStatus::Closed, $rfq->fresh()->status);

        $this->quote($rfq, $a, [1, 1])->assertStatus(422);
    }
}
