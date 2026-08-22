<?php

namespace Tests\Feature\Estimation;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\BoqSection;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Models\CostBudgetItem;
use Modules\Estimation\Services\AhspService;
use Modules\Estimation\Services\BoqService;
use Modules\Estimation\Services\EstimationFormService;
use Modules\Estimation\Services\RapService;
use Tests\ErpTestCase;

/**
 * Formulir rumah untuk modul Estimasi — RAB, AHSP, RAP.
 *
 * All three are ARITHMETIC ON PAPER, which is what makes them different from
 * the CRM sheets: an estimator reads the columns and adds them up, so a printed
 * column that does not foot to its own printed total destroys the sheet's whole
 * purpose even though every individual figure came out of the database.
 *
 * Two consequences are asserted below and nowhere else:
 *
 *  - the AHSP overhead line is the DIFFERENCE between the analysis price and
 *    the component sum, so A+B+C, D, E and F foot exactly. Deriving E as
 *    D × overhead% separately is off by up to a rupiah from the price every BOQ
 *    item using this analysis was actually built with;
 *  - the RAP margin percentage is a RULED BLANK when the BOQ it is measured
 *    against is worth nothing, because 0% margin and "cannot be computed" are
 *    different claims and only one of them is true.
 */
class EstimationFormPrintTest extends ErpTestCase
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

    // -------------------------------------------------------------- fixtures

    /**
     * Two bagian, three items, subtotals and grand total computed by
     * BoqService itself — so what the sheet prints is what the module stores,
     * never a second reading of the same lines.
     */
    private function boq(): Boq
    {
        $boq = Boq::query()->create([
            'code' => 'BOQ/2026/0001',
            'title' => 'RAB Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)',
            'version' => 1,
            'status' => 'approved',
        ]);

        $persiapan = BoqSection::query()->create([
            'boq_id' => $boq->id,
            'section_no' => 'A',
            'name' => 'PEKERJAAN PERSIAPAN',
            'sort_order' => 1,
        ]);

        $struktur = BoqSection::query()->create([
            'boq_id' => $boq->id,
            'section_no' => 'B',
            'name' => 'PEKERJAAN STRUKTUR',
            'sort_order' => 2,
        ]);

        BoqItem::query()->create([
            'boq_id' => $boq->id,
            'section_id' => $persiapan->id,
            'wbs_code' => 'A.1',
            'description' => 'Mobilisasi & demobilisasi peralatan',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 388_400_000,
            'amount' => 388_400_000,
            'sort_order' => 1,
        ]);

        BoqItem::query()->create([
            'boq_id' => $boq->id,
            'section_id' => $persiapan->id,
            'wbs_code' => 'A.2',
            'description' => 'Direksi keet, pagar sementara & fasilitas proyek',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 111_600_000,
            'amount' => 111_600_000,
            'sort_order' => 2,
        ]);

        BoqItem::query()->create([
            'boq_id' => $boq->id,
            'section_id' => $struktur->id,
            'wbs_code' => 'B.1',
            'description' => 'Galian tanah basement & pondasi',
            'qty' => 12_000,
            'unit' => 'm3',
            'unit_price' => 105_740,
            'amount' => 1_268_880_000,
            'sort_order' => 1,
        ]);

        return app(BoqService::class)->recalcTotals($boq)->fresh();
    }

    /**
     * One analysis in the shape of the owner's own AHSP spreadsheet: upah,
     * bahan, alat, then D / E / F.
     *
     * Built through AhspService so est_ahsp.unit_price is the module's own
     * cached answer — the number every BOQ item priced from this analysis
     * carries — and not a figure typed into a fixture.
     */
    private function ahsp(): Ahsp
    {
        return app(AhspService::class)->create([
            'code' => 'A.4.3.1.3',
            'name' => "Membuat 1 m3 beton ready mix K-300 (f'c 26,4 MPa)",
            'unit' => 'm3',
            'category' => 'sipil',
            'overhead_pct' => 10,
            'notes' => 'Mengacu SNI 7394:2008 dan SE Dirjen Bina Konstruksi 182/2025.',
            'components' => [
                ['component_type' => 'labor', 'name' => 'Pekerja', 'unit' => 'OH', 'coefficient' => 0.75, 'unit_price' => 150_000],
                ['component_type' => 'labor', 'name' => 'Mandor', 'unit' => 'OH', 'coefficient' => 0.025, 'unit_price' => 250_000],
                ['component_type' => 'material', 'name' => 'Semen PC 50 kg', 'unit' => 'zak', 'coefficient' => 0.25, 'unit_price' => 65_000],
                ['component_type' => 'equipment', 'name' => 'Concrete mixer 0,3 m3', 'unit' => 'sewa-hari', 'coefficient' => 0.05, 'unit_price' => 350_000],
            ],
        ]);
    }

    private function rap(Boq $boq, array $overrides = []): CostBudget
    {
        $budget = CostBudget::query()->create(array_merge([
            'code' => 'RAP/2026/0001',
            'boq_id' => $boq->id,
            'target_margin_pct' => 15,
            'status' => 'draft',
        ], $overrides));

        foreach ([
            ['cost_category' => 'material', 'description' => 'Beton ready mix K-300', 'amount' => 1_000_000_000],
            ['cost_category' => 'labor', 'description' => 'Upah borong struktur', 'amount' => 400_000_000],
            ['cost_category' => 'subcon', 'description' => 'Subkon galian & dewatering', 'amount' => 100_000_000],
        ] as $line) {
            CostBudgetItem::query()->create($line + [
                'cost_budget_id' => $budget->id,
                'boq_item_id' => $boq->items()->first()->id,
                'qty' => 1,
                'unit' => 'ls',
                'unit_price' => $line['amount'],
            ]);
        }

        return app(RapService::class)->recalcTotals($budget)->fresh();
    }

    // ------------------------------------------------------------- catalogue

    public function test_the_catalogue_carries_the_three_estimation_documents(): void
    {
        $catalogue = collect(
            $this->actingAs($this->adminUser())
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('estimation/boqs', $catalogue['rab']['resource'] ?? null);
        $this->assertSame('estimation/ahsp', $catalogue['ahsp']['resource'] ?? null);
        $this->assertSame('estimation/cost-budgets', $catalogue['rap']['resource'] ?? null);
    }

    // -------------------------------------------------------------- RAB

    public function test_the_rab_prints_a_recap_per_bagian_then_every_item(): void
    {
        $html = $this->forms->html('rab', ['id' => $this->boq()->id]);

        $this->assertStringContainsString('RENCANA ANGGARAN BIAYA', $html);
        $this->assertStringContainsString('BOQ/2026/0001', $html);

        // The recap: one line per bagian, each carrying the subtotal the module
        // itself cached.
        $this->assertStringContainsString('PEKERJAAN PERSIAPAN', $html);
        $this->assertStringContainsString('PEKERJAAN STRUKTUR', $html);
        $this->assertStringContainsString('500.000.000,00', $html);
        $this->assertStringContainsString('1.268.880.000,00', $html);

        // Every item, in bagian order, with its own wbs code.
        $this->assertStringContainsString('A.1', $html);
        $this->assertStringContainsString('Mobilisasi &amp; demobilisasi peralatan', $html);
        $this->assertStringContainsString('Galian tanah basement &amp; pondasi', $html);
        // 12.000 m3, printed as a quantity rather than as money.
        $this->assertStringContainsString('12.000', $html);

        $this->assertStringContainsString('1.768.880.000,00', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    public function test_the_rab_spells_its_grand_total_in_words(): void
    {
        $html = $this->forms->html('rab', ['id' => $this->boq()->id]);

        $this->assertStringContainsString('TERBILANG', $html);
        $this->assertStringContainsString(
            'Satu miliar tujuh ratus enam puluh delapan juta delapan ratus delapan puluh ribu rupiah',
            $html,
        );
    }

    // -------------------------------------------------------------- AHSP

    public function test_the_ahsp_groups_its_components_and_foots_to_the_stored_unit_price(): void
    {
        $ahsp = $this->ahsp();

        $html = $this->forms->html('ahsp', ['id' => $ahsp->id]);

        $this->assertStringContainsString('ANALISA HARGA SATUAN PEKERJAAN', $html);
        $this->assertStringContainsString('A.4.3.1.3', $html);

        // The three groups of SE Dirjen Bina Konstruksi 182/2025, each with its
        // own jumlah: 0,75 × 150.000 + 0,025 × 250.000 = 118.750.
        $this->assertStringContainsString('TENAGA KERJA', $html);
        $this->assertStringContainsString('BAHAN', $html);
        $this->assertStringContainsString('PERALATAN', $html);
        $this->assertStringContainsString('112.500,00', $html);
        $this->assertStringContainsString('118.750,00', $html);
        $this->assertStringContainsString('16.250,00', $html);
        $this->assertStringContainsString('17.500,00', $html);

        // D = 152.500, E = 15.250, F = 167.750 — and F is exactly the price the
        // module cached when the analysis was saved.
        $this->assertStringContainsString('152.500,00', $html);
        $this->assertStringContainsString('15.250,00', $html);
        $this->assertStringContainsString('167.750,00', $html);
        $this->assertSame(167_750.0, (float) $ahsp->unit_price);
    }

    /**
     * An analysis with no equipment at all says so in a sentence. It does not
     * print a zero row, which on a form an estimator signs reads as "we costed
     * a mixer at nothing".
     */
    public function test_a_component_group_with_no_rows_says_so(): void
    {
        $ahsp = app(AhspService::class)->create([
            'code' => 'A.4.1.1.7',
            'name' => 'Pemasangan 1 m2 dinding bata merah 1/2 bata 1:4',
            'unit' => 'm2',
            'category' => 'arsitektur',
            'overhead_pct' => 10,
            'components' => [
                ['component_type' => 'labor', 'name' => 'Tukang batu', 'unit' => 'OH', 'coefficient' => 0.2, 'unit_price' => 175_000],
            ],
        ]);

        $html = $this->forms->html('ahsp', ['id' => $ahsp->id]);

        $this->assertStringContainsString('Tidak ada komponen', $html);
        $this->assertStringContainsString('35.000,00', $html);
        $this->assertStringContainsString('38.500,00', $html);
    }

    // -------------------------------------------------------------- RAP

    public function test_the_rap_prints_the_budget_against_the_boq_it_was_derived_from(): void
    {
        $boq = $this->boq();

        $html = $this->forms->html('rap', ['id' => $this->rap($boq)->id]);

        $this->assertStringContainsString('RENCANA ANGGARAN PELAKSANAAN', $html);
        $this->assertStringContainsString('RAP/2026/0001', $html);
        $this->assertStringContainsString('BOQ/2026/0001', $html);

        // Nilai BOQ, total anggaran, target margin and the margin the two
        // numbers actually produce — the whole point of the document.
        $this->assertStringContainsString('1.768.880.000,00', $html);
        $this->assertStringContainsString('1.500.000.000,00', $html);
        $this->assertStringContainsString('268.880.000,00', $html);
        // Target 15%, realised 17,93% — both as mark-up on cost, so the two
        // lines are comparable and this budget beats its target.
        $this->assertStringContainsString('15%', $html);
        $this->assertStringContainsString('17,93%', $html);

        // The per-category recap, in the module's own vocabulary.
        $this->assertStringContainsString('Material', $html);
        $this->assertStringContainsString('Upah', $html);
        $this->assertStringContainsString('Subkon', $html);
        $this->assertStringContainsString('400.000.000,00', $html);
        $this->assertStringContainsString('Beton ready mix K-300', $html);

        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * The realised margin is quoted on the SAME basis as the target it sits
     * next to — mark-up on cost, which is what RapService deflates the BOQ by.
     * A RAP that hit its 15% target reads 15%, not the 13,04% the other
     * perfectly ordinary definition (margin on revenue) would print under it.
     */
    public function test_the_realised_margin_is_quoted_on_the_same_basis_as_the_target(): void
    {
        $boq = $this->boq();
        $budget = app(RapService::class)->generateFromBoq($this->rap($boq), 15);

        $forms = app(EstimationFormService::class);

        $this->assertSame(15.0, $forms->rapMarginPct($budget->fresh()));
    }

    /**
     * A RAP nobody has costed yet has no margin at all — neither in rupiah nor
     * as a percentage. Both cells are ruled.
     *
     * The rupiah line is the one that matters: "margin = the whole BOQ" is what
     * an empty budget arithmetically produces, and printed on an approval sheet
     * it reads as a job with no costs rather than as a budget nobody filled in.
     */
    public function test_an_uncosted_rap_prints_no_margin_at_all(): void
    {
        $budget = CostBudget::query()->create([
            'code' => 'RAP/2026/0009',
            'boq_id' => $this->boq()->id,
            'target_margin_pct' => 15,
            'status' => 'draft',
        ]);

        $forms = app(EstimationFormService::class);

        $this->assertNull($forms->rapMarginAmount($budget));
        $this->assertNull($forms->rapMarginPct($budget));

        $html = $this->forms->html('rap', ['id' => $budget->id]);

        $this->assertStringContainsString('MARGIN RENCANA', $html);
        $this->assertStringContainsString('RAP ini belum memiliki rincian anggaran.', $html);
        $this->assertStringContainsString('fill-line', $html);
    }
}
