<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Core\Support\Terbilang;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\AwardDecision;
use Modules\Procurement\Models\BidEvaluation;
use Modules\Procurement\Models\NegotiationMinute;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Models\VendorEvaluation;
use Modules\Procurement\Services\ProcurementFormService;
use Modules\Procurement\Services\RfqService;
use Modules\Procurement\Services\VendorEvaluationService;
use Modules\Projects\Models\Project;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Formulir rumah untuk Pengadaan — PR, PO, tabulasi banding dan evaluasi vendor.
 *
 * Four documents that share one skeleton and disagree about exactly one thing:
 * how much of the paper the database can honestly answer. That disagreement is
 * what this file tests, because it is the only place the rule can break.
 *
 *   PERMINTAAN PEMBELIAN is the awkward one. prc_purchase_requisition_items
 *   .estimated_price is NOT NULL DEFAULT 0 and the store request makes it
 *   optional, so "0" on a requisition line means "the requester left it blank"
 *   far more often than it means "free of charge". Printed as 0,00 it reads as
 *   a price somebody stated — on the sheet an approver signs. So an unpriced
 *   line is RULED, and the estimate total is ruled too whenever any line is
 *   unpriced: a total that quietly treats unknowns as zeros understates the
 *   commitment being approved.
 *
 *   TABULASI BANDING is the one where an omission is the lie. A vendor invited
 *   who never bid must still appear — with a blank offer and "0 dari 2 baris
 *   ditawar" — because a comparison sheet listing only the vendors who
 *   answered is a comparison that claims to be wider than it was. Same reason
 *   the per-vendor total carries its completeness beside it: two vendors'
 *   totals are not comparable when one of them priced half the scope.
 *
 *   EVALUASI VENDOR prints the provenance sentence VendorEvaluationService
 *   wrote into the notes when it derived the delivery score, and never
 *   recomputes it. The GRN history behind that score keeps moving; a sheet
 *   that recomputed it would answer a different question every time it was
 *   reprinted, over a signature given once.
 */
class ProcurementPrintTest extends ErpTestCase
{
    private FormPrintService $forms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forms = app(FormPrintService::class);

        Company::query()->create([
            'name' => 'PT Nusantara Karya Integrasi',
            'legal_name' => 'PT Nusantara Karya Integrasi',
            'npwp' => '01.234.567.8-012.000',
            'is_pkp' => true,
            'address' => 'Jl. Raya Cakung Cilincing KM 2 No. 88',
            'city' => 'Jakarta Timur',
            'province' => 'DKI Jakarta',
        ]);
    }

    // ------------------------------------------------------------- fixtures

    private function project(): Project
    {
        return Project::query()->firstOrCreate(['code' => 'PRJ-2026-001'], [
            'name' => 'Pengembangan Bandar Udara Sultan Hasanudin - Makassar',
            'type' => 'construction',
            'status' => 'active',
            'city' => 'Makassar',
            'province' => 'Sulawesi Selatan',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function vendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'name' => 'PT Baja Nusantara Sejahtera',
            'classification' => 'material',
            'is_pkp' => true,
            'npwp' => '02.345.678.9-023.000',
            'address' => 'Jl. Industri Raya No. 21, Bekasi',
            'city' => 'Bekasi',
            'phone' => '021-8899100',
            'pic_name' => 'Bapak Hendra',
            'payment_term_days' => 30,
            'status' => 'active',
        ], $attributes));
    }

    private function requester(): User
    {
        return User::query()->create([
            'name' => 'Bagas Prakoso',
            'email' => 'bagas@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    /**
     * Two lines: semen priced, besi unpriced. 200 x 75.000 = 15.000.000.
     */
    private function requisition(?float $secondPrice = null): PurchaseRequisition
    {
        /** @var PurchaseRequisition $pr */
        $pr = PurchaseRequisition::query()->create([
            'project_id' => $this->project()->id,
            'requested_by' => $this->requester()->id,
            'needed_date' => '2026-08-25',
            'purpose' => 'Pekerjaan struktur lantai 3',
            'status' => DocumentStatus::Approved,
        ]);

        $pr->items()->create([
            'line_no' => 1,
            'description' => 'Semen PCC 50 kg',
            'qty' => 200,
            'unit' => 'zak',
            'estimated_price' => 75_000,
        ]);

        $pr->items()->create([
            'line_no' => 2,
            'description' => 'Besi beton D16 panjang 12 m',
            'qty' => 100,
            'unit' => 'btg',
            // 0 is the column default, which on this table means "not
            // estimated" — see the class docblock.
            'estimated_price' => $secondPrice ?? 0,
        ]);

        return $pr->refresh();
    }

    private function purchaseOrder(array $attributes = []): PurchaseOrder
    {
        /** @var PurchaseOrder $po */
        $po = PurchaseOrder::query()->create(array_merge([
            'vendor_id' => $this->vendor()->id,
            'project_id' => $this->project()->id,
            'order_date' => '2026-03-01',
            'expected_date' => '2026-03-20',
            'payment_term_days' => 30,
            'subtotal' => 250_000_000,
            'discount_amount' => 0,
            'dpp' => 250_000_000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 27_500_000,
            'total' => 277_500_000,
            'delivery_address' => 'Gudang Proyek, Jl. Bandara Baru, Mandai, Makassar',
            'status' => DocumentStatus::Approved,
        ], $attributes));

        $po->items()->create([
            'line_no' => 1,
            'description' => 'Panel listrik LVMDP 1000A',
            'qty' => 2,
            'unit' => 'unit',
            'unit_price' => 125_000_000,
            'amount' => 250_000_000,
        ]);

        return $po->refresh();
    }

    /**
     * A tabulation with three invited vendors, one of whom never bid and one
     * who priced only half the scope.
     *
     * @return array{0: Rfq, 1: Vendor, 2: Vendor, 3: Vendor}
     */
    private function tabulation(bool $chooseWinner = true): array
    {
        $service = app(RfqService::class);

        $a = $this->vendor(['name' => 'PT Semen Andalan', 'classification' => 'material']);
        $b = $this->vendor(['name' => 'CV Baja Kuat', 'classification' => 'material']);
        $c = $this->vendor(['name' => 'PT Tidak Menawar', 'classification' => 'material']);

        $rfq = $service->create([
            'project_id' => $this->project()->id,
            'rfq_date' => '2026-08-08',
            'due_date' => '2026-08-15',
            'vendor_ids' => [$a->id, $b->id, $c->id],
            'items' => [
                ['description' => 'Semen PCC 50 kg', 'qty' => 200, 'unit' => 'zak'],
                ['description' => 'Besi beton D16 panjang 12 m', 'qty' => 100, 'unit' => 'btg'],
            ],
        ]);

        $lines = $rfq->items()->orderBy('line_no')->get();

        $service->recordQuotes($rfq, [
            ['rfq_item_id' => $lines[0]->id, 'vendor_id' => $a->id, 'unit_price' => 72_000],
            ['rfq_item_id' => $lines[1]->id, 'vendor_id' => $a->id, 'unit_price' => 140_000],
            // CV Baja Kuat only priced the besi — half the scope.
            ['rfq_item_id' => $lines[1]->id, 'vendor_id' => $b->id, 'unit_price' => 138_000],
        ]);

        if ($chooseWinner) {
            $service->chooseWinner($rfq->refresh(), $a->id);
        }

        return [$rfq->refresh(), $a, $b, $c];
    }

    private function grnHistory(Vendor $vendor): void
    {
        $po = PurchaseOrder::query()->create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-03-01',
            'expected_date' => '2026-03-10',
            'payment_term_days' => 30,
            'subtotal' => 15_000_000,
            'dpp' => 15_000_000,
            'total' => 15_000_000,
            'status' => DocumentStatus::Approved,
        ]);

        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'WH-UJI'],
            ['name' => 'Gudang Uji', 'is_active' => true],
        );

        GoodsReceipt::query()->create([
            'warehouse_id' => $warehouse->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $vendor->id,
            'receipt_date' => '2026-03-08',
            'status' => 'posted',
        ]);
    }

    private function fills(string $html): int
    {
        return substr_count($html, '<div class="fill"></div>');
    }

    // ------------------------------------------------- permintaan pembelian

    public function test_the_requisition_sheet_prints_its_requester_project_and_lines(): void
    {
        $pr = $this->requisition();

        $html = $this->forms->html('permintaan-pembelian', ['id' => $pr->id]);

        $this->assertStringContainsString('PERMINTAAN PEMBELIAN', $html);
        $this->assertStringContainsString('Form F/PP', $html);
        $this->assertStringContainsString($pr->code, $html);
        $this->assertStringContainsString('Bagas Prakoso', $html);
        $this->assertStringContainsString('Pengembangan Bandar Udara Sultan Hasanudin', $html);
        $this->assertStringContainsString('25 Agustus 2026', $html);
        $this->assertStringContainsString('Semen PCC 50 kg', $html);
        $this->assertStringContainsString('Besi beton D16 panjang 12 m', $html);
        $this->assertStringContainsString('75.000,00', $html);
        $this->assertStringContainsString('15.000.000,00', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * The whole reason this document is in the failing-test pass. A stored 0
     * on a requisition line is the column default, not a price — printed as
     * "0,00" it becomes a figure the approver's signature stands behind.
     *
     * Three ruled cells and no more: the unpriced line's harga and jumlah, and
     * the estimate total, which cannot be stated while any line is unpriced.
     */
    public function test_an_unpriced_requisition_line_is_ruled_and_never_zero(): void
    {
        $html = $this->forms->html('permintaan-pembelian', ['id' => $this->requisition()->id]);

        $this->assertSame(3, $this->fills($html));
        $this->assertStringNotContainsString('0,00</td>', $html);
    }

    public function test_the_estimate_total_prints_once_every_line_carries_one(): void
    {
        $html = $this->forms->html('permintaan-pembelian', ['id' => $this->requisition(145_000)->id]);

        // 200 x 75.000 + 100 x 145.000
        $this->assertStringContainsString('29.500.000,00', $html);
        $this->assertSame(0, $this->fills($html));
    }

    // ------------------------------------------------------ order pembelian

    public function test_the_purchase_order_sheet_carries_the_vendor_band_and_every_total(): void
    {
        $po = $this->purchaseOrder();

        $html = $this->forms->html('order-pembelian', ['id' => $po->id]);

        // The counterparty of a PO is a supplier, not the owner of a job.
        $this->assertStringContainsString('PEMASOK / VENDOR', $html);
        $this->assertStringContainsString('PT Baja Nusantara Sejahtera', $html);
        $this->assertStringContainsString('PROYEK', $html);
        $this->assertStringContainsString('SURAT PESANAN PEMBELIAN', $html);
        $this->assertStringContainsString('Form F/PO', $html);
        $this->assertStringContainsString('Panel listrik LVMDP 1000A', $html);
        $this->assertStringContainsString('250.000.000,00', $html);
        // The rate is read off the document, never typed into a template.
        $this->assertStringContainsString('PPN 11%', $html);
        $this->assertStringContainsString('27.500.000,00', $html);
        $this->assertStringContainsString('277.500.000,00', $html);
        // Terbilang of the total, spelled by Core's own speller so the house
        // sheet and the dompdf PO cannot word the same amount differently.
        $this->assertStringContainsString(Terbilang::rupiah(277_500_000), $html);
        $this->assertStringContainsString('Gudang Proyek, Jl. Bandara Baru', $html);
        $this->assertStringContainsString('20 Maret 2026', $html);
    }

    /**
     * The override reason is an audit trail (temuan #35) and belongs on the
     * paper the PO is filed as — but only when there was an override. It rides
     * in the notes block rather than an identity line for exactly that reason:
     * an identity line renders its label whether or not it has a value, so a
     * clean PO would print "OVERRIDE PRAKUALIFIKASI : ......" and invite
     * somebody to write one in.
     */
    public function test_a_qualification_override_is_printed_on_the_order_that_carries_one(): void
    {
        $po = $this->purchaseOrder([
            'qualification_override_reason' => 'SIUJK kedaluwarsa 3 hari, pengiriman tidak dapat ditunda.',
        ]);

        $html = $this->forms->html('order-pembelian', ['id' => $po->id]);

        $this->assertStringContainsString('Override prakualifikasi', $html);
        $this->assertStringContainsString('SIUJK kedaluwarsa 3 hari', $html);
    }

    public function test_an_order_without_an_override_says_nothing_about_one(): void
    {
        $html = $this->forms->html('order-pembelian', ['id' => $this->purchaseOrder()->id]);

        $this->assertStringNotContainsString('Override prakualifikasi', $html);
    }

    /**
     * A supplier archived since the order went out keeps its name on the order.
     *
     * prc_vendors soft-deletes on the ordinary path — a supplier stops trading
     * or is struck off the approved list — and the purchase orders raised
     * against it stay in the file for ever. The band of this sheet IS the
     * vendor and the sheet is a signed commitment to pay: loaded plainly, a
     * pesanan for Rp 277.500.000,00 printed with PEMASOK / VENDOR, NPWP and
     * the delivery contact all ruled, which is an order addressed to nobody
     * over a director's signature rule.
     */
    public function test_an_archived_vendor_keeps_its_name_on_the_order_it_was_sent(): void
    {
        $po = $this->purchaseOrder();

        $po->vendor->delete();

        $html = $this->forms->html('order-pembelian', ['id' => $po->id]);

        $this->assertStringContainsString('PEMASOK / VENDOR', $html);
        $this->assertStringContainsString('PT Baja Nusantara Sejahtera', $html);
        $this->assertStringContainsString('Bapak Hendra', $html);
        $this->assertStringContainsString('277.500.000,00', $html);
    }

    // ----------------------------------------------------- banding penawaran

    public function test_the_comparison_sheet_is_landscape_and_lists_every_quote(): void
    {
        [$rfq] = $this->tabulation();

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);

        $this->assertStringContainsString('TABULASI BANDING PENAWARAN', $html);
        $this->assertStringContainsString('Form F/TBP', $html);
        $this->assertStringContainsString('<body class="landscape">', $html);
        $this->assertStringContainsString('PT Semen Andalan', $html);
        $this->assertStringContainsString('CV Baja Kuat', $html);
        // 200 x 72.000 and 100 x 140.000, and the two of them together.
        $this->assertStringContainsString('14.400.000,00', $html);
        $this->assertStringContainsString('14.000.000,00', $html);
        $this->assertStringContainsString('28.400.000,00', $html);
    }

    /**
     * A vendor who was invited and never bid is part of the record of how wide
     * the comparison actually was. Dropping them would make the sheet claim a
     * banding of two where three were asked; printing "Rp 0,00" against them
     * would claim they offered to do it for nothing.
     */
    public function test_an_invited_vendor_who_never_bid_is_listed_with_a_ruled_offer(): void
    {
        [$rfq] = $this->tabulation();

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);

        $this->assertStringContainsString('PT Tidak Menawar', $html);
        $this->assertStringContainsString('0 dari 2 baris ditawar', $html);
        $this->assertStringContainsString('1 dari 2 baris ditawar', $html);
        // CV Baja Kuat won nothing and PT Tidak Menawar offered nothing:
        // one ruled award cell, plus a ruled offer AND a ruled award cell.
        $this->assertSame(3, $this->fills($html));
    }

    public function test_the_recommendation_names_the_winning_vendor(): void
    {
        [$rfq] = $this->tabulation();

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);

        $this->assertStringContainsString('PT Semen Andalan (2 baris)', $html);
    }

    /**
     * A tabulation nobody has decided yet recommends nothing. Printing the
     * cheapest column as a recommendation would be the sheet making the
     * decision the signature block exists to record.
     */
    public function test_a_tabulation_with_no_winner_recommends_nothing(): void
    {
        [$rfq] = $this->tabulation(chooseWinner: false);

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);

        $this->assertStringNotContainsString('baris)', $html);
        // Every award cell ruled (three vendors), the unbid offer ruled, and
        // the recommended total ruled with them.
        $this->assertSame(5, $this->fills($html));
    }

    // ------------------------------------------------------- evaluasi vendor

    public function test_the_evaluation_sheet_prints_the_four_criteria_and_the_final_score(): void
    {
        $vendor = $this->vendor();

        /** @var VendorEvaluation $evaluation */
        $evaluation = app(VendorEvaluationService::class)->create([
            'vendor_id' => $vendor->id,
            'period' => '2026-S1',
            'quality_score' => 5,
            'delivery_score' => 5,
            'price_score' => 3,
            'service_score' => 4,
        ]);

        $html = $this->forms->html('evaluasi-vendor', ['id' => $evaluation->id]);

        $this->assertStringContainsString('EVALUASI', $html);
        $this->assertStringContainsString('Form F/EV', $html);
        $this->assertStringContainsString('PT Baja Nusantara Sejahtera', $html);
        $this->assertStringContainsString('2026-S1', $html);
        $this->assertStringContainsString('Ketepatan waktu pengiriman', $html);
        $this->assertStringContainsString('Kewajaran harga', $html);
        // 5 + 5 + 3 + 4, over four criteria of equal weight.
        $this->assertStringContainsString('4,25', $html);
        $this->assertStringContainsString('25%', $html);
        // Four rows, four weights, and 25 % four times is exactly 100. That is
        // a property of THIS list's SIZE and not of deriving the weight —
        // EVALUATION_CRITERIA's docblock now says so, and three criteria would
        // print 33,33 three times and foot to 99,99 — so the count is checked
        // on the paper rather than assumed beside the constant. Counted at the
        // end of a CELL, because the band's own stylesheet says width: 25%.
        $this->assertSame(4, preg_match_all('~25%\s*<~', $html));
    }

    /**
     * The provenance sentence VendorEvaluationService wrote when it derived
     * the score, printed as written. Not recomputed: the GRN history behind it
     * keeps moving, and a sheet that recomputed would contradict, months
     * later, the number somebody signed.
     */
    public function test_the_delivery_score_provenance_is_printed_as_it_was_recorded(): void
    {
        $vendor = $this->vendor();
        $this->grnHistory($vendor);

        $evaluation = app(VendorEvaluationService::class)->create([
            'vendor_id' => $vendor->id,
            'period' => '2026-S1',
            'quality_score' => 4,
            'price_score' => 3,
            'service_score' => 4,
        ]);

        $html = $this->forms->html('evaluasi-vendor', ['id' => $evaluation->id]);

        $this->assertStringContainsString('dihitung otomatis', $html);
        $this->assertStringContainsString('1 dari 1 GRN tepat waktu', $html);
    }

    /**
     * The other half of the same rule: an evaluator who typed the delivery
     * score leaves no provenance, and the sheet must not manufacture one out
     * of a GRN history the evaluation never looked at.
     */
    public function test_a_manually_scored_evaluation_prints_no_provenance_sentence(): void
    {
        $vendor = $this->vendor();
        $this->grnHistory($vendor);

        $evaluation = app(VendorEvaluationService::class)->create([
            'vendor_id' => $vendor->id,
            'period' => '2026-S1',
            'quality_score' => 4,
            'delivery_score' => 2,
            'price_score' => 3,
            'service_score' => 4,
        ]);

        $html = $this->forms->html('evaluasi-vendor', ['id' => $evaluation->id]);

        $this->assertStringNotContainsString('otomatis', $html);
        // The pad's ruled catatan lines, not an invented explanation.
        $this->assertStringContainsString('class="rule"', $html);
    }

    // ---------------------------------------------------------- the endpoint

    public function test_every_procurement_document_is_catalogued_for_its_resource(): void
    {
        $catalogue = collect(
            $this->actingAs($this->adminUser())
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('procurement/purchase-requisitions', $catalogue['permintaan-pembelian']['resource']);
        $this->assertSame('procurement/purchase-orders', $catalogue['order-pembelian']['resource']);
        $this->assertSame('procurement/rfqs', $catalogue['banding-penawaran']['resource']);
        $this->assertSame('procurement/vendor-evaluations', $catalogue['evaluasi-vendor']['resource']);
    }

    public function test_printing_a_procurement_document_needs_the_modules_view(): void
    {
        $po = $this->purchaseOrder();
        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo('prc.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->refresh())
            ->get("/api/core/print/forms/order-pembelian/{$po->id}")
            ->assertForbidden();
    }

    // ------------------------------------------- vendor yang sudah dihapus

    /**
     * A supplier deleted after the tabulation was priced still has its name on
     * the row it won.
     *
     * prc_vendors soft-deletes. Loaded plainly the relation came back null and
     * the sheet ruled the VENDOR cell — beside a real unit price, a real
     * amount and a real "Ya", while recommendation() three methods away was
     * still naming the same supplier. A dotted rule in the vendor column of a
     * WINNING line is precisely the hazard the PEMENANG column was made
     * Ya/Tidak to avoid: it invites a name to be written in after signing.
     */
    public function test_a_soft_deleted_vendor_keeps_its_name_on_the_row_it_won(): void
    {
        [$rfq, $winner] = $this->tabulation();

        $winner->delete();

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->refresh()->id]);

        $this->assertStringContainsString('PT Semen Andalan', $html);
        $this->assertStringContainsString('PT Semen Andalan (2 baris)', $html);
        // The name we still hold, never the "Vendor #id" fallback that exists
        // for a row deleted outright.
        $this->assertStringNotContainsString('Vendor #', $html);
    }

    /**
     * The recommendation total lands under the money column it totals.
     *
     * The generic sheet prints a totals figure in the LAST column, so with
     * PEMENANG declared last a 14-character rupiah figure went into a 20mm
     * Ya/Tidak column. The flag is declared before the two money columns now.
     */
    public function test_the_recommendation_total_prints_under_the_amount_column(): void
    {
        [$rfq] = $this->tabulation();

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);

        // 200 zak x 72.000 + 100 btg x 140.000 = 28.400.000.
        $this->assertMatchesRegularExpression(
            '/JUMLAH \(Rp\)<\/th>\s*<\/tr>/s',
            $html,
            'JUMLAH (Rp) must be the last column of the tabulation, so the total foots under it.',
        );
        $this->assertStringContainsString('28.400.000,00', $html);
    }

    // ------------------------------------------ penilaian berbobot (P2)

    /**
     * Seed a weighted tabulation on the three-vendor RFQ: A and B scored, C
     * invited but never scored. Ranks and weighted totals are set by hand so the
     * PRINT is what is under test, not the scoring service.
     *
     * @return array{0: Rfq, 1: Vendor, 2: Vendor, 3: Vendor}
     */
    private function scoredTabulation(): array
    {
        [$rfq, $a, $b, $c] = $this->tabulation(false);

        // harga 50 / mutu 30 / waktu 5 / keuangan 10 / k3 5 (config default).
        // A: 100·.5 + 90·.3 + 80·.05 + 85·.1 + 70·.05 = 93,00  -> rank 1
        BidEvaluation::query()->create([
            'rfq_id' => $rfq->id, 'vendor_id' => $a->id,
            'rab_amount' => 30_000_000, 'offered_amount' => 28_000_000,
            'harga_score' => 100, 'mutu_score' => 90, 'waktu_score' => 80,
            'keuangan_score' => 85, 'k3_score' => 70, 'weighted_score' => 93, 'rank' => 1,
        ]);
        // B: 66,67·.5 + 70·.3 + 60·.05 + 50·.1 + 40·.05 = 64,34  -> rank 2
        BidEvaluation::query()->create([
            'rfq_id' => $rfq->id, 'vendor_id' => $b->id,
            'rab_amount' => 30_000_000, 'offered_amount' => 45_000_000,
            'harga_score' => 66.67, 'mutu_score' => 70, 'waktu_score' => 60,
            'keuangan_score' => 50, 'k3_score' => 40, 'weighted_score' => 64.34, 'rank' => 2,
        ]);
        // C (PT Tidak Menawar) is left unscored on purpose.

        return [$rfq->refresh(), $a, $b, $c];
    }

    public function test_the_weighted_tabulation_shows_the_weight_split_footing_to_100(): void
    {
        [$rfq] = $this->scoredTabulation();

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);

        $this->assertStringContainsString('BOBOT PENILAIAN', $html);
        $this->assertStringContainsString('TABULASI PENILAIAN BERBOBOT', $html);
        // The five aspect weights and the foot that proves the scale is whole.
        $this->assertStringContainsString('JUMLAH BOBOT (%)', $html);
        $this->assertMatchesRegularExpression('/JUMLAH BOBOT \(%\).*?>\s*100\s*</s', $html);
    }

    public function test_the_weighted_tabulation_ranks_the_scored_vendors_and_rules_the_unscored(): void
    {
        [$rfq, $a, $b, $c] = $this->scoredTabulation();

        $rows = app(ProcurementFormService::class)
            ->evaluationRows($rfq->load('bidEvaluations.vendor', 'vendors.vendor'));

        // Scored vendors first, in rank order; the unscored vendor last.
        $this->assertSame(1, $rows[0]['rank']);
        $this->assertSame('PT Semen Andalan', $rows[0]['vendor']);
        $this->assertSame(93.0, $rows[0]['weighted']);
        $this->assertSame(2, $rows[1]['rank']);
        $this->assertSame('CV Baja Kuat', $rows[1]['vendor']);

        // The honesty rule: the invited-but-unscored vendor is RULED across
        // every score cell — null in, ruled blank out — never a zero somebody
        // could read as a score that was given.
        $this->assertSame('PT Tidak Menawar', $rows[2]['vendor']);
        $this->assertNull($rows[2]['rank']);
        $this->assertNull($rows[2]['harga']);
        $this->assertNull($rows[2]['mutu']);
        $this->assertNull($rows[2]['weighted']);
        $this->assertSame('Belum dinilai', $rows[2]['note']);

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);
        $this->assertStringContainsString('Belum dinilai', $html);
        // 93,00 prints as "93"; 64,34 keeps its decimals.
        $this->assertStringContainsString('64,34', $html);
    }

    public function test_an_unscored_banding_prints_no_weighted_tabulation(): void
    {
        [$rfq] = $this->tabulation();

        $html = $this->forms->html('banding-penawaran', ['id' => $rfq->id]);

        // No vendor scored yet — the weighted grid is skipped entirely, no empty
        // scoring exercise asserted on a sheet that only priced offers.
        $this->assertStringNotContainsString('BOBOT PENILAIAN', $html);
        $this->assertStringNotContainsString('TABULASI PENILAIAN BERBOBOT', $html);
    }

    // ----------------------------------------- berita acara negosiasi

    private function negotiationMinute(): NegotiationMinute
    {
        [$rfq, $a] = $this->tabulation(false);

        /** @var NegotiationMinute $minute */
        $minute = NegotiationMinute::query()->create([
            'rfq_id' => $rfq->id,
            'vendor_id' => $a->id,
            'meeting_date' => '2026-08-16',
            'location' => 'Ruang Rapat Pengadaan, Kantor Pusat',
            'peserta' => [
                ['nama' => 'Bagas Prakoso', 'jabatan' => 'Manajer Pengadaan', 'pihak' => 'PT Nusantara Karya Integrasi'],
                ['nama' => 'Hendra Wijaya', 'jabatan' => 'Direktur', 'pihak' => 'PT Semen Andalan'],
            ],
            'notes' => 'Harga besi disepakati turun; semen tetap.',
        ]);

        $minute->items()->create([
            'line_no' => 1, 'description' => 'Semen PCC 50 kg', 'qty' => 200, 'unit' => 'zak',
            'harga_awal' => 72_000, 'harga_nego' => 70_000,
        ]);
        // Second line NOT negotiated yet — harga_nego is the column default 0,
        // which must be ruled, not printed as Rp 0,00.
        $minute->items()->create([
            'line_no' => 2, 'description' => 'Besi beton D16 panjang 12 m', 'qty' => 100, 'unit' => 'btg',
            'harga_awal' => 140_000, 'harga_nego' => 0,
        ]);

        return $minute->refresh();
    }

    public function test_the_negotiation_minute_prints_participants_and_negotiated_prices(): void
    {
        $minute = $this->negotiationMinute();

        $html = $this->forms->html('berita-acara-negosiasi', ['id' => $minute->id]);

        $this->assertStringContainsString('BERITA ACARA NEGOSIASI', $html);
        $this->assertStringContainsString('Form F/BAN', $html);
        $this->assertStringContainsString('<body class="landscape">', $html);
        // Attendees from the peserta json.
        $this->assertStringContainsString('Bagas Prakoso', $html);
        $this->assertStringContainsString('Hendra Wijaya', $html);
        // Negotiated line: 72.000 -> 70.000, selisih -2.000.
        $this->assertStringContainsString('72.000,00', $html);
        $this->assertStringContainsString('70.000,00', $html);
    }

    public function test_a_not_yet_negotiated_price_is_ruled_never_zero(): void
    {
        $minute = $this->negotiationMinute();

        $rows = app(ProcurementFormService::class)
            ->negotiationItemRows($minute);

        // Line 1 negotiated: both prices stated, selisih stated.
        $this->assertSame(72_000.0, $rows[0]['harga_awal']);
        $this->assertSame(70_000.0, $rows[0]['harga_nego']);
        $this->assertSame(-2_000.0, $rows[0]['selisih']);

        // Line 2 not negotiated: the 0 default is ruled, and a delta against an
        // unknown is not a delta.
        $this->assertSame(140_000.0, $rows[1]['harga_awal']);
        $this->assertNull($rows[1]['harga_nego']);
        $this->assertNull($rows[1]['selisih']);
    }

    // -------------------------------------------- keputusan pemenang

    private function awardDecision(array $attributes = []): AwardDecision
    {
        [$rfq, $a] = $this->tabulation(false);

        /** @var AwardDecision $award */
        $award = AwardDecision::query()->create(array_merge([
            'rfq_id' => $rfq->id,
            'vendor_id' => $a->id,
            'rab_amount' => 250_000_000,
            'awarded_amount' => 240_000_000,
            'deviation_amount' => 0,
            'committee' => [
                ['nama' => 'Andi Saputra', 'jabatan' => 'Ketua Panitia'],
                ['nama' => 'Rina Melati', 'jabatan' => 'Sekretaris'],
            ],
            'status' => DocumentStatus::Approved,
        ], $attributes));

        return $award->refresh();
    }

    public function test_the_award_sheet_names_the_winner_the_value_and_the_committee(): void
    {
        $award = $this->awardDecision();

        $html = $this->forms->html('keputusan-pemenang', ['id' => $award->id]);

        $this->assertStringContainsString('BERITA ACARA KEPUTUSAN PEMENANG', $html);
        $this->assertStringContainsString('Form F/AWD', $html);
        $this->assertStringContainsString('PT Semen Andalan', $html);
        $this->assertStringContainsString('240.000.000,00', $html);
        // Spelled by Core's Terbilang — the same speller the PO uses.
        $this->assertStringContainsString(Terbilang::rupiah(240_000_000), $html);
        $this->assertStringContainsString('Andi Saputra', $html);
        $this->assertStringContainsString('Rina Melati', $html);
    }

    public function test_a_deviation_reason_prints_only_when_the_award_deviates(): void
    {
        // Clean award (awarded at or below RAB, deviation 0): no reason on paper.
        $clean = $this->awardDecision();
        $cleanHtml = $this->forms->html('keputusan-pemenang', ['id' => $clean->id]);
        $this->assertStringNotContainsString('Alasan deviasi', $cleanHtml);

        // Deviating award: the reason is printed in the notes.
        $over = $this->awardDecision([
            'awarded_amount' => 300_000_000,
            'deviation_amount' => 50_000_000,
            'deviation_reason' => 'Harga pasar baja naik di atas RAB saat keputusan.',
        ]);
        $overHtml = $this->forms->html('keputusan-pemenang', ['id' => $over->id]);
        $this->assertStringContainsString('Alasan deviasi terhadap RAB', $overHtml);
        $this->assertStringContainsString('Harga pasar baja naik di atas RAB', $overHtml);
    }
}
