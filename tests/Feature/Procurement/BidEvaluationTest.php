<?php

namespace Tests\Feature\Procurement;

use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Exceptions\BidWeightConfigException;
use Modules\Procurement\Models\BidEvaluation;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\BidEvaluationService;
use Modules\Procurement\Support\BidWeights;
use Tests\ErpTestCase;

/**
 * P2 — tabulasi penilaian penawaran berbobot (sistem nilai DAN 4.8).
 *
 * Skor harga TIDAK diinput: dihitung dari rasio penawaran vendor terhadap RAB.
 * Empat aspek lain diinput 0–100, total berbobot dan peringkat dihitung server.
 * Bobot yang tidak berjumlah 100 tidak boleh sampai ke tabulasi bertanda tangan.
 */
class BidEvaluationTest extends ErpTestCase
{
    private function vendor(string $code, string $name): Vendor
    {
        return Vendor::query()->create([
            'code' => $code, 'name' => $name, 'classification' => 'material',
            'is_pkp' => false, 'is_subcontractor' => false,
            'payment_term_days' => 30, 'status' => 'active',
        ]);
    }

    /** RFQ mandiri satu baris qty 1, dua vendor terundang, harga terisi. */
    private function rfqWithQuotes(Vendor $a, float $priceA, Vendor $b, float $priceB): Rfq
    {
        $response = $this->postJson('/api/procurement/rfqs', [
            'rfq_date' => '2026-08-08',
            'vendor_ids' => [$a->id, $b->id],
            'items' => [
                ['description' => 'Panel listrik', 'qty' => 1, 'unit' => 'unit'],
            ],
        ])->assertStatus(201);

        $rfq = Rfq::query()->findOrFail((int) $response->json('data.id'));
        $itemId = $rfq->items()->value('id');

        $this->postJson("/api/procurement/rfqs/{$rfq->id}/quotes", [
            'quotes' => [
                ['rfq_item_id' => $itemId, 'vendor_id' => $a->id, 'unit_price' => $priceA],
                ['rfq_item_id' => $itemId, 'vendor_id' => $b->id, 'unit_price' => $priceB],
            ],
        ])->assertOk();

        return $rfq;
    }

    // ----------------------------------------------------- harga score (DAN 4.8)

    public function test_harga_score_is_100_when_the_offer_matches_the_rab(): void
    {
        $service = app(BidEvaluationService::class);

        $this->assertSame(100.0, $service->hargaScore(1_000.0, 1_000.0));
    }

    public function test_harga_score_halves_when_the_offer_doubles_the_rab(): void
    {
        $service = app(BidEvaluationService::class);

        $this->assertSame(50.0, $service->hargaScore(2_000.0, 1_000.0));
        // 1250 vs 1000 -> 1000/1250 = 80.
        $this->assertSame(80.0, $service->hargaScore(1_250.0, 1_000.0));
    }

    public function test_an_offer_below_the_rab_is_capped_at_100(): void
    {
        $service = app(BidEvaluationService::class);

        // 800 vs 1000 -> 125, capped at 100 so a low bid cannot inflate the
        // weighted total above the aspect maximum.
        $this->assertSame(100.0, $service->hargaScore(800.0, 1_000.0));
    }

    public function test_harga_score_is_zero_without_a_rab_reference(): void
    {
        $service = app(BidEvaluationService::class);

        $this->assertSame(0.0, $service->hargaScore(1_000.0, null));
        $this->assertSame(0.0, $service->hargaScore(1_000.0, 0.0));
    }

    // ------------------------------------------------ weighted total & ranking

    public function test_the_weighted_total_uses_the_configured_weights_and_computed_harga(): void
    {
        Sanctum::actingAs($this->adminUser());

        $a = $this->vendor('VND-E1', 'PT Alfa');
        $b = $this->vendor('VND-E2', 'PT Beta');
        $rfq = $this->rfqWithQuotes($a, 1_000, $b, 2_000);

        // RAB 1000: A offers 1000 -> harga 100; B offers 2000 -> harga 50.
        // Aspek lain di-set agar totalnya bisa dihitung tangan.
        $this->postJson("/api/procurement/rfqs/{$rfq->id}/evaluations", [
            'evaluations' => [
                ['vendor_id' => $a->id, 'rab_amount' => 1_000, 'mutu_score' => 80, 'waktu_score' => 100, 'keuangan_score' => 90, 'k3_score' => 100],
                ['vendor_id' => $b->id, 'rab_amount' => 1_000, 'mutu_score' => 100, 'waktu_score' => 100, 'keuangan_score' => 100, 'k3_score' => 100],
            ],
        ])->assertOk();

        $evalA = BidEvaluation::query()->where('rfq_id', $rfq->id)->where('vendor_id', $a->id)->firstOrFail();

        // harga 100*0.5 + mutu 80*0.3 + waktu 100*0.05 + keuangan 90*0.1 + k3 100*0.05
        // = 50 + 24 + 5 + 9 + 5 = 93.00
        $this->assertSame('100.00', (string) $evalA->harga_score);
        $this->assertSame('93.00', (string) $evalA->weighted_score);
    }

    public function test_ranking_is_automatic_and_highest_weighted_score_wins(): void
    {
        Sanctum::actingAs($this->adminUser());

        $a = $this->vendor('VND-R1', 'PT Alfa');
        $b = $this->vendor('VND-R2', 'PT Beta');
        $rfq = $this->rfqWithQuotes($a, 1_000, $b, 2_000);

        $this->postJson("/api/procurement/rfqs/{$rfq->id}/evaluations", [
            'evaluations' => [
                // B is cheaper-scored worse (offered 2x RAB) but perfect elsewhere;
                // A is on-budget. A's weighted total (93) beats B's here.
                ['vendor_id' => $a->id, 'rab_amount' => 1_000, 'mutu_score' => 80, 'waktu_score' => 100, 'keuangan_score' => 90, 'k3_score' => 100],
                ['vendor_id' => $b->id, 'rab_amount' => 1_000, 'mutu_score' => 100, 'waktu_score' => 100, 'keuangan_score' => 100, 'k3_score' => 100],
            ],
        ])->assertOk();

        $evalA = BidEvaluation::query()->where('rfq_id', $rfq->id)->where('vendor_id', $a->id)->firstOrFail();
        $evalB = BidEvaluation::query()->where('rfq_id', $rfq->id)->where('vendor_id', $b->id)->firstOrFail();

        // B: harga 50*0.5=25 + 30 + 5 + 10 + 5 = 75; A = 93. A ranks 1.
        $this->assertSame(1, $evalA->rank);
        $this->assertSame(2, $evalB->rank);
    }

    public function test_a_vendor_not_invited_cannot_be_evaluated(): void
    {
        Sanctum::actingAs($this->adminUser());

        $a = $this->vendor('VND-I1', 'PT Alfa');
        $b = $this->vendor('VND-I2', 'PT Beta');
        $stranger = $this->vendor('VND-I3', 'PT Gamma');
        $rfq = $this->rfqWithQuotes($a, 1_000, $b, 1_000);

        $this->postJson("/api/procurement/rfqs/{$rfq->id}/evaluations", [
            'evaluations' => [['vendor_id' => $stranger->id, 'rab_amount' => 1_000, 'mutu_score' => 50]],
        ])->assertStatus(422);
    }

    // ------------------------------------------------ boot-weight refusal

    public function test_the_shipped_bid_weights_sum_to_100(): void
    {
        // No exception on the shipped config.
        BidWeights::assertValid(BidWeights::weights());

        $this->assertSame(100.0, array_sum(BidWeights::weights()));
    }

    public function test_a_misweighted_config_is_refused(): void
    {
        $this->expectException(BidWeightConfigException::class);

        BidWeights::assertValid(['harga' => 60, 'mutu' => 30, 'waktu' => 5, 'keuangan' => 10, 'k3' => 5]); // 110
    }

    public function test_a_missing_aspect_is_refused(): void
    {
        $this->expectException(BidWeightConfigException::class);

        BidWeights::assertValid(['harga' => 50, 'mutu' => 30, 'waktu' => 5, 'keuangan' => 15]); // no k3
    }
}
