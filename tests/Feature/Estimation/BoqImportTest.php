<?php

namespace Tests\Feature\Estimation;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Services\RapService;
use Tests\ErpTestCase;

/**
 * Importing a whole RAB from one sheet — the `boqs` definition as it ships, not
 * a fixture of it.
 *
 * What the engine guarantees for every document type (the tipe grammar, the
 * banner scan, per-document transactions) is asserted once in
 * tests/Feature/Core/DocumentImportTest. What is asserted here is everything
 * that is true of a BOQ specifically: that the numbers FOOT — an item's amount
 * is volume x harga satuan, a section's subtotal is the sum of its items and the
 * BOQ's total is the sum of its sections — that an estimator's own two- and
 * three-level numbering survives intact, and that replacing the sections of a
 * BOQ other documents already point at is refused rather than done quietly.
 *
 * A BOQ whose totals do not foot is worse than no import at all: it is a bid
 * priced against a number nobody can reproduce.
 */
class BoqImportTest extends ErpTestCase
{
    use EstimationImportFixtures;

    // ------------------------------------------------------------ the numbers

    /**
     * The first page of every RAB in the country: two sections, three work
     * items, a SUB TOTAL row that must not become a fourth.
     */
    public function test_a_sheet_of_sections_and_items_becomes_a_boq_whose_totals_foot(): void
    {
        $result = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Gedung Kantor Graha Sentosa'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.2', 'uraian' => 'Direksi keet',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '25.000.000', 'jumlah' => '25.000.000'],
            ['tipe' => 'abaikan', 'dokumen' => 'RAB-GRAHA', 'uraian' => 'SUB TOTAL I', 'jumlah' => '43.750.000'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'II', 'uraian' => 'Pekerjaan Struktur'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '2.1', 'uraian' => 'Galian tanah pondasi',
                'volume' => '450', 'satuan' => 'm3', 'harga_satuan' => '85.000', 'jumlah' => '38.250.000'],
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['errors']);

        $boq = Boq::query()->sole();
        $sections = $boq->sections()->get();

        // The SUB TOTAL row is read and skipped, never stored as a work item.
        $this->assertSame(3, $boq->items()->count());

        // 1.500 m2 of site clearing is fifteen hundred square metres: a volume
        // groups its dots exactly the way money does. Read as 1,5 the whole RAB
        // would import at a thousandth of itself and still add up.
        $lahan = $boq->items()->where('wbs_code', '1.1')->sole();
        $this->assertEqualsWithDelta(1500.0, (float) $lahan->qty, 0.001);
        $this->assertEqualsWithDelta(12_500.0, (float) $lahan->unit_price, 0.01);
        $this->assertEqualsWithDelta(18_750_000.0, (float) $lahan->amount, 0.01);

        // Every item: amount = volume x harga satuan.
        foreach ($boq->items()->get() as $item) {
            $this->assertEqualsWithDelta(
                (float) $item->qty * (float) $item->unit_price,
                (float) $item->amount,
                0.01,
                "amount of {$item->wbs_code} is not volume x harga satuan",
            );
        }

        // Every section: subtotal = sum of its own items.
        $this->assertSame(['I', 'II'], $sections->pluck('section_no')->all());

        foreach ($sections as $section) {
            $this->assertEqualsWithDelta(
                (float) $section->items()->sum('amount'),
                (float) $section->subtotal,
                0.01,
                "subtotal of section {$section->section_no} is not the sum of its items",
            );
        }

        $this->assertEqualsWithDelta(43_750_000.0, (float) $sections[0]->subtotal, 0.01);
        $this->assertEqualsWithDelta(38_250_000.0, (float) $sections[1]->subtotal, 0.01);

        // And the BOQ: total = sum of the sections = sum of every item.
        $this->assertEqualsWithDelta((float) $sections->sum('subtotal'), (float) $boq->total, 0.01);
        $this->assertEqualsWithDelta((float) $boq->items()->sum('amount'), (float) $boq->total, 0.01);
        $this->assertEqualsWithDelta(82_000_000.0, (float) $boq->total, 0.01);
    }

    /**
     * The estimator's own arithmetic is the only check on our reading of a
     * separator, so a line whose jumlah disagrees is refused, not averaged.
     */
    public function test_the_files_own_jumlah_refuses_a_line_we_read_differently(): void
    {
        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Salah baca'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            // 1.500 x 12.500 is 18.750.000, and the sheet says a thousandth of it.
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750'],
        ]));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString(
            'volume x harga satuan',
            implode(' ', $preview['documents'][0]['rows'][1]['errors']),
        );
        $this->assertSame(0, Boq::query()->count());
    }

    // -------------------------------------------------------- priced by AHSP

    /**
     * The line that makes the feature worth building: an analysis code and a
     * quantity, and the price, unit and description come from the analysis.
     */
    public function test_an_item_carrying_only_an_analysis_code_and_a_volume_is_priced_from_the_analysis(): void
    {
        $ahsp = $this->readyMixAnalysis();

        $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Struktur dari analisa'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'II', 'uraian' => 'Pekerjaan Struktur'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '2.1', 'ahsp_kode' => 'A.4.3.1.3', 'volume' => '120'],
        ]));

        $item = BoqItem::query()->sole();

        $this->assertSame($ahsp->id, (int) $item->ahsp_id);
        $this->assertSame('Membuat 1 m3 beton ready mix K-300', $item->description);
        $this->assertSame('m3', $item->unit);
        // 1,02 x 1.150.000 + 0,25 x 145.000 + 0,5 x 45.000 = 1.231.750, +10% = 1.354.925.
        $this->assertEqualsWithDelta(1_354_925.0, (float) $item->unit_price, 0.01);
        $this->assertEqualsWithDelta(120 * 1_354_925.0, (float) $item->amount, 0.01);
        $this->assertEqualsWithDelta((float) $item->amount, (float) Boq::query()->value('total'), 0.01);
    }

    /**
     * The one shape where the checksum and the analysis disagree by design, and
     * the importer must not pick a winner.
     *
     * A row that names an analysis but leaves harga_satuan empty is priced by
     * the analysis, so the file's own jumlah describes a price we are not using.
     * Refusing says so; ignoring the column would let a sheet whose printed
     * total is right import at a price nobody in the room agreed to.
     */
    public function test_an_analysis_priced_line_that_still_states_a_jumlah_is_refused_rather_than_guessed(): void
    {
        $this->readyMixAnalysis();

        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Harga dari dua tempat'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'II', 'uraian' => 'Pekerjaan Struktur'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '2.1', 'ahsp_kode' => 'A.4.3.1.3',
                'volume' => '120', 'jumlah' => '162.591.000'],
        ]));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertSame(0, Boq::query()->count());
    }

    /**
     * The preview must never state a confident Rp 0 for a real bill of
     * quantities.
     *
     * The importer's per-document total is volume x the price the SHEET
     * carried, and harga_satuan is empty on exactly the lines the analysis
     * prices — the shape the template's own worked example teaches. So a RAB
     * that commits at Rp 162.591.000 previewed at Rp 0, on the one screen an
     * estimator uses to check a bid before saving it. The analysis prices are
     * resolved for the preview and the number is restated.
     */
    public function test_a_preview_says_what_the_analysis_priced_lines_are_worth_and_that_the_total_omits_them(): void
    {
        $this->readyMixAnalysis();

        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Sebagian dari analisa'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.2', 'ahsp_kode' => 'A.4.3.1.3',
                'volume' => '120'],
        ]));

        $document = $preview['documents'][0];
        $warnings = implode(' ', $document['warnings']);

        $this->assertTrue($document['valid'], implode(' | ', $document['errors']));
        // What the engine can compute from the sheet alone, unchanged.
        $this->assertEqualsWithDelta(18_750_000.0, (float) $document['totals']['computed_total'], 0.01);
        // And what it cannot: 120 x 1.354.925 = 162.591.000, so the RAB is
        // 181.341.000 and not the eighteen million the total column shows.
        $this->assertStringContainsString('1 dari 2 baris', $warnings);
        $this->assertStringContainsString('18.750.000,00', $warnings);
        $this->assertStringContainsString('162.591.000,00', $warnings);
        $this->assertStringContainsString('181.341.000,00', $warnings);

        // And the commit agrees with what the warning promised.
        $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Sebagian dari analisa'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.2', 'ahsp_kode' => 'A.4.3.1.3',
                'volume' => '120'],
        ]));

        $this->assertEqualsWithDelta(181_341_000.0, (float) Boq::query()->value('total'), 0.01);
    }

    /**
     * A sheet whose every line carries its own price has nothing to explain, and
     * must not grow a warning that would teach operators to ignore them.
     */
    public function test_a_boq_whose_lines_all_carry_their_own_price_says_nothing_about_analyses(): void
    {
        $this->readyMixAnalysis();

        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Harga ditulis sendiri'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            // Naming the analysis but stating a price of its own is priced by
            // the sheet, exactly as addItem reads it — so it is not "inherited".
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Beton K-300',
                'ahsp_kode' => 'A.4.3.1.3', 'volume' => '10', 'satuan' => 'm3',
                'harga_satuan' => '1.400.000', 'jumlah' => '14.000.000'],
        ]));

        $document = $preview['documents'][0];

        $this->assertTrue($document['valid'], implode(' | ', $document['errors']));
        $this->assertEqualsWithDelta(14_000_000.0, (float) $document['totals']['computed_total'], 0.01);
        $this->assertStringNotContainsString('analisa AHSP', implode(' ', $document['warnings']));
    }

    // ----------------------------------------------------------- the numbering

    /**
     * 1 / 1.1 / 1.1.1 — three levels on the sheet, one level in the schema.
     *
     * est_boq_sections is flat, so every level that has rincian of its own is
     * written as its own bagian and the numbering carries the hierarchy. Nothing
     * is invented, nothing is flattened away, and the order is the estimator's.
     */
    public function test_a_three_level_numbering_becomes_one_bagian_per_level_that_has_its_own_items(): void
    {
        $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Penomoran berjenjang'],
            // A level-1 heading with nothing directly under it: legal, and it
            // keeps the estimator's own outline visible in the RAB.
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1', 'uraian' => 'PEKERJAAN STRUKTUR'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pekerjaan Pondasi'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1.1', 'uraian' => 'Galian tanah pondasi',
                'volume' => '450', 'satuan' => 'm3', 'harga_satuan' => '85.000', 'jumlah' => '38.250.000'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1.2', 'uraian' => 'Urugan kembali',
                'volume' => '120', 'satuan' => 'm3', 'harga_satuan' => '45.000', 'jumlah' => '5.400.000'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.2', 'uraian' => 'Pekerjaan Beton'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.2.1', 'uraian' => 'Beton K-300',
                'volume' => '80', 'satuan' => 'm3', 'harga_satuan' => '1.400.000', 'jumlah' => '112.000.000'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => '2', 'uraian' => 'PEKERJAAN ARSITEKTUR'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '2.1', 'uraian' => 'Dinding bata merah',
                'volume' => '640', 'satuan' => 'm2', 'harga_satuan' => '185.000', 'jumlah' => '118.400.000'],
        ]));

        $boq = Boq::query()->sole();
        $sections = $boq->sections()->get();

        $this->assertSame(['1', '1.1', '1.2', '2'], $sections->pluck('section_no')->all());
        $this->assertSame([1, 2, 3, 4], $sections->pluck('sort_order')->all());

        // Each item joined the bagian directly above it, and the outline heading
        // that carries none is simply worth nothing.
        $this->assertSame(0, $sections[0]->items()->count());
        $this->assertEqualsWithDelta(0.0, (float) $sections[0]->subtotal, 0.01);
        $this->assertSame(['1.1.1', '1.1.2'], $sections[1]->items()->pluck('wbs_code')->all());
        $this->assertSame(['1.2.1'], $sections[2]->items()->pluck('wbs_code')->all());
        $this->assertSame(['2.1'], $sections[3]->items()->pluck('wbs_code')->all());

        $this->assertEqualsWithDelta(43_650_000.0, (float) $sections[1]->subtotal, 0.01);
        $this->assertEqualsWithDelta((float) $sections->sum('subtotal'), (float) $boq->total, 0.01);
        $this->assertEqualsWithDelta(274_050_000.0, (float) $boq->total, 0.01);
    }

    /**
     * est_boq_sections.section_no is 10 characters. A number past that is
     * refused, and refusing is the whole point: truncating 1.1.1.1.1.1 and
     * 1.1.1.1.1.2 would silently merge two sections of a bill into one.
     */
    public function test_a_bagian_number_longer_than_the_column_allows_is_refused_rather_than_truncated(): void
    {
        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Penomoran terlalu dalam'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1.1.1.1.1', 'uraian' => 'Terlalu panjang'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1.1.1.1.1.1', 'uraian' => 'Pekerjaan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000', 'jumlah' => '1.000.000'],
        ]));

        $document = $preview['documents'][0];

        $this->assertFalse($document['valid']);
        // Named by the operator's own column heading, not by section_no.
        $this->assertStringContainsString('nomor', implode(' ', $document['rows'][0]['errors']));
        $this->assertSame(0, Boq::query()->count());

        // The boundary itself is fine: exactly ten characters lands.
        $result = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-PAS', 'judul' => 'Pas sepuluh karakter'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-PAS', 'nomor' => '1.1.1.1.1', 'uraian' => 'Masih muat'],
            ['tipe' => 'item', 'dokumen' => 'RAB-PAS', 'nomor' => '1.1.1.1.1.1', 'uraian' => 'Pekerjaan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000', 'jumlah' => '1.000.000'],
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertSame('1.1.1.1.1', Boq::query()->sole()->sections()->value('section_no'));
    }

    /** est_boq_items.wbs_code is 20 characters, and the same argument applies. */
    public function test_an_item_number_longer_than_the_column_allows_is_refused(): void
    {
        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Nomor item panjang'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'A.1.1.1.1.1.1.1.1.1.1', 'uraian' => 'Pekerjaan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000', 'jumlah' => '1.000.000'],
        ]));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('nomor', implode(' ', $preview['documents'][0]['rows'][1]['errors']));
        $this->assertSame(0, Boq::query()->count());

        // Twenty characters exactly is a legal item number and lands.
        $result = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-PAS', 'judul' => 'Nomor item pas'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-PAS', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-PAS', 'nomor' => 'A.1.1.1.1.1.1.1.1.1', 'uraian' => 'Pekerjaan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000', 'jumlah' => '1.000.000'],
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertSame('A.1.1.1.1.1.1.1.1.1', BoqItem::query()->sole()->wbs_code);
    }

    /** There is exactly one way to be wrong about where a line belongs. */
    public function test_an_item_before_any_bagian_row_is_refused(): void
    {
        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Tanpa bagian'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
        ]));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('sebelum baris bagian', implode(' ', $preview['documents'][0]['rows'][0]['errors']));
        $this->assertSame(0, Boq::query()->count());
    }

    /**
     * A repeated item number is legal here and lethal later: RAP lines and WBS
     * tasks find a BOQ line BY NUMBER, and an ambiguous one is refused outright
     * rather than bound to whichever row came first.
     */
    public function test_a_repeated_item_number_inside_one_boq_is_a_warning_not_a_refusal(): void
    {
        $result = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Nomor ganda'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'A.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'II', 'uraian' => 'Pekerjaan Struktur'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'A.1', 'uraian' => 'Galian tanah pondasi',
                'volume' => '450', 'satuan' => 'm3', 'harga_satuan' => '85.000', 'jumlah' => '38.250.000'],
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, BoqItem::query()->where('wbs_code', 'A.1')->count());

        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-KEDUA', 'judul' => 'Nomor ganda lagi'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-KEDUA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-KEDUA', 'nomor' => 'A.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000', 'jumlah' => '1.000'],
            ['tipe' => 'item', 'dokumen' => 'RAB-KEDUA', 'nomor' => 'A.1', 'uraian' => 'Sekali lagi',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000', 'jumlah' => '1.000'],
        ]));

        $this->assertTrue($preview['documents'][0]['valid']);
        $this->assertStringContainsString('A.1', implode(' ', $preview['documents'][0]['warnings']));
        $this->assertStringContainsString('nomor item berulang', implode(' ', $preview['documents'][0]['warnings']));
    }

    // ------------------------------------------------------------- the target

    /** A code that resolves to nothing is refused; it is never silently nulled. */
    public function test_an_unknown_project_code_refuses_the_boq_and_writes_nothing(): void
    {
        $this->project('PRJ-2026-001');

        $result = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-ADA', 'judul' => 'Proyek ada', 'proyek_kode' => 'PRJ-2026-001'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-ADA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-ADA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-SALAH', 'judul' => 'Proyek salah ketik', 'proyek_kode' => 'PRJ-2026-010'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-SALAH', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-SALAH', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
        ]));

        // The good document beside it still lands: a file is not all-or-nothing,
        // a DOCUMENT is.
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(['Proyek ada'], Boq::query()->pluck('title')->all());
        $this->assertStringContainsString('PRJ-2026-010', implode(' ', $result['documents'][0]['errors']));
    }

    /** Re-uploading under the assigned number rewrites that BOQ, never a second one. */
    public function test_re_importing_under_the_assigned_code_updates_in_place_and_recomputes_the_totals(): void
    {
        $first = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Gedung Kantor Graha Sentosa'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
        ]));

        $code = $first['codes']['RAB-GRAHA'];
        $this->assertStringStartsWith('BOQ/', $code);

        $id = (int) Boq::query()->value('id');

        $second = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => $code, 'judul' => 'Gedung Kantor Graha Sentosa (revisi harga)'],
            ['tipe' => 'bagian', 'dokumen' => $code, 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => $code, 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '14.000', 'jumlah' => '21.000.000'],
        ]));

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);

        $boq = Boq::query()->sole();

        $this->assertSame($id, (int) $boq->id);
        $this->assertSame('Gedung Kantor Graha Sentosa (revisi harga)', $boq->title);
        // Sections and items were replaced wholesale, not appended to.
        $this->assertSame(1, $boq->sections()->count());
        $this->assertSame(1, $boq->items()->count());
        $this->assertEqualsWithDelta(21_000_000.0, (float) $boq->total, 0.01);
        $this->assertEqualsWithDelta((float) $boq->items()->sum('amount'), (float) $boq->total, 0.01);
    }

    /** No template carries a status column, and no file may edit a signed BOQ. */
    public function test_an_approved_boq_is_never_overwritten(): void
    {
        $created = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Sudah disetujui'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
        ]));

        $code = $created['codes']['RAB-GRAHA'];
        Boq::query()->sole()->forceFill(['status' => DocumentStatus::Approved])->save();

        $result = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => $code, 'judul' => 'Diam-diam diubah'],
            ['tipe' => 'bagian', 'dokumen' => $code, 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => $code, 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '99.000', 'jumlah' => '148.500.000'],
        ]));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Versi Baru', implode(' ', $result['documents'][0]['errors']));

        $boq = Boq::query()->sole();
        $this->assertSame('Sudah disetujui', $boq->title);
        $this->assertEqualsWithDelta(18_750_000.0, (float) $boq->total, 0.01);
    }

    // --------------------------------------------------- what else points here

    /**
     * est_cost_budget_items.boq_item_id cascades on delete, so replacing this
     * BOQ's sections would DELETE the lines of a RAP that can no longer be
     * regenerated — leaving an approved-in-progress budget of zero.
     */
    public function test_a_boq_whose_lines_an_unapproved_rap_already_holds_is_refused(): void
    {
        $code = $this->boqWithBudget(DocumentStatus::Submitted);

        $result = $this->imports()->commit('boqs', 'rab.csv', $this->rewriteOf($code));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);

        $errors = implode(' ', $result['documents'][0]['errors']);
        $this->assertStringContainsString('RAP/', $errors);
        $this->assertStringContainsString('terhapus', $errors);

        // Nothing moved: the BOQ still carries its original line and the RAP
        // still carries its budget.
        $this->assertSame('Pembersihan lahan', BoqItem::query()->sole()->description);
        $this->assertGreaterThan(0, CostBudget::query()->sole()->items()->count());
    }

    /**
     * A draft RAP can be rebuilt from the new BOQ, so this one goes through —
     * but silently losing the budget lines is exactly how a RAP ends up reading
     * Rp 0 for a month, so it is said out loud.
     */
    public function test_a_boq_whose_lines_only_a_draft_rap_holds_is_imported_with_a_warning(): void
    {
        $code = $this->boqWithBudget(DocumentStatus::Draft);

        $preview = $this->imports()->preview('boqs', 'rab.csv', $this->rewriteOf($code));

        $this->assertTrue($preview['documents'][0]['valid']);
        $this->assertStringContainsString('Generate dari BOQ', implode(' ', $preview['documents'][0]['warnings']));

        $result = $this->imports()->commit('boqs', 'rab.csv', $this->rewriteOf($code));

        $this->assertSame(1, $result['updated']);
        $this->assertSame('Galian tanah pondasi', BoqItem::query()->sole()->description);
    }

    /**
     * prj_wbs_tasks.boq_item_id has no constraint at all, so those links would
     * DANGLE rather than disappear: MaterialVarianceService reads the BOQ item
     * through it for every leaf task's theory quantity, and a dangling id
     * computes no theory — the report keeps working and quietly under-reports
     * the material the job needs.
     */
    public function test_a_boq_whose_lines_wbs_tasks_reference_is_refused(): void
    {
        $created = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Sudah dijadwalkan'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
        ]));

        DB::table('prj_wbs_tasks')->insert([
            'project_id' => $this->project(),
            'boq_item_id' => (int) BoqItem::query()->value('id'),
            'wbs_code' => 'A.1',
            'name' => 'Pembersihan lahan',
            'weight_pct' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->imports()->commit('boqs', 'rab.csv', $this->rewriteOf($created['codes']['RAB-GRAHA']));

        $this->assertSame(0, $result['updated']);
        $this->assertStringContainsString('tugas WBS proyek', implode(' ', $result['documents'][0]['errors']));
        $this->assertSame('Pembersihan lahan', BoqItem::query()->sole()->description);
    }

    // ------------------------------------------------------ template & export

    /**
     * The worked example the template ships with is the first thing an operator
     * uncomments, so it has to import as it stands — including its checksum
     * column and its analysis-priced line.
     */
    public function test_the_shipped_template_imports_as_it_ships(): void
    {
        $this->project('PRJ-2026-001');
        $this->readyMixAnalysis();

        $result = $this->imports()->commit('boqs', 'rab.csv', $this->templateWithExampleEnabled('boqs'));

        $this->assertSame(1, $result['created'], implode(' | ', $result['documents'][0]['errors'] ?? []));
        $this->assertSame(0, $result['skipped']);

        $boq = Boq::query()->sole();

        $this->assertSame('Gedung Kantor Graha Sentosa', $boq->title);
        $this->assertSame(['I'], $boq->sections()->pluck('section_no')->all());
        $this->assertSame(['1.1', '1.2'], $boq->items()->orderBy('sort_order')->pluck('wbs_code')->all());
        // 1.500 x 12.500 = 18.750.000, plus 120 m3 priced from A.4.3.1.3.
        $this->assertEqualsWithDelta(18_750_000.0 + 120 * 1_354_925.0, (float) $boq->total, 0.01);
    }

    /**
     * Export, edit in Excel, import back — the argument for the whole feature,
     * and the path where a number the export writes has to survive being read
     * again. A volume of 450 written back as "450.000" would return as 450.000
     * m3 and the checksum would refuse a file we produced ourselves.
     */
    public function test_the_export_round_trips_back_through_the_importer(): void
    {
        $this->readyMixAnalysis();

        $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Gedung Kantor Graha Sentosa'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Galian tanah pondasi',
                'volume' => '450', 'satuan' => 'm3', 'harga_satuan' => '85.000', 'jumlah' => '38.250.000'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.2', 'ahsp_kode' => 'A.4.3.1.3', 'volume' => '120'],
        ]));

        $before = (float) Boq::query()->value('total');

        $result = $this->imports()->commit('boqs', 'rab.csv', base64_encode($this->imports()->export('boqs')));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated'], implode(' | ', $result['documents'][0]['errors'] ?? []));
        $this->assertSame(1, Boq::query()->count());

        $boq = Boq::query()->sole();

        $this->assertEqualsWithDelta(450.0, (float) $boq->items()->where('wbs_code', '1.1')->value('qty'), 0.001);
        $this->assertEqualsWithDelta($before, (float) $boq->total, 0.01);
        $this->assertEqualsWithDelta((float) $boq->items()->sum('amount'), (float) $boq->total, 0.01);
    }

    /**
     * Both Estimation documents are reachable from the screen.
     *
     * The engine's own tests run against a fixture registry, so this is the one
     * place the SHIPPED entries are walked end to end by the endpoint — a
     * malformed definition throws in normalise() the moment the list is drawn,
     * and it would throw on the operator's screen rather than in a test.
     */
    public function test_the_screen_lists_both_estimation_documents_and_serves_their_templates(): void
    {
        $user = $this->adminUser();

        $listed = $this->actingAs($user)->getJson('/api/core/document-import')->assertOk()->json('data');
        $keys = array_column($listed, 'key');

        $this->assertContains('boqs', $keys);
        $this->assertContains('ahsp', $keys);

        $boqs = $listed[array_search('boqs', $keys, true)];

        $this->assertSame('Estimation', $boqs['module']);
        $this->assertSame('dokumen', $boqs['group_column']);
        $this->assertSame(['dokumen', 'bagian', 'item'], array_column($boqs['row_types'], 'tipe'));
        $this->assertTrue($boqs['can_import']);

        $ahsp = $listed[array_search('ahsp', $keys, true)];

        // The AHSP group column is the analysis code itself, and it is also a
        // column of the header row — the only definition where that is true.
        $this->assertSame('kode', $ahsp['group_column']);
        $this->assertSame(['analisa', 'komponen'], array_column($ahsp['row_types'], 'tipe'));

        foreach (['boqs', 'ahsp'] as $resource) {
            $this->actingAs($user)
                ->get("/api/core/document-import/{$resource}/template")
                ->assertOk()
                ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
    }

    // ------------------------------------------------------------------ setup

    /** A BOQ of one line with a RAP already generated from it, at $status. */
    private function boqWithBudget(DocumentStatus $status): string
    {
        $created = $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Sudah dianggarkan'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '1.1', 'uraian' => 'Pembersihan lahan',
                'volume' => '1.500', 'satuan' => 'm2', 'harga_satuan' => '12.500', 'jumlah' => '18.750.000'],
        ]));

        $rap = app(RapService::class);
        $budget = $rap->create(['boq_id' => (int) Boq::query()->value('id'), 'target_margin_pct' => 15]);
        $rap->generateFromBoq($budget);
        $budget->forceFill(['status' => $status])->save();

        return $created['codes']['RAB-GRAHA'];
    }

    /** The same BOQ, sent back with a different line — i.e. a section replacement. */
    private function rewriteOf(string $code): string
    {
        return $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => $code, 'judul' => 'Ditulis ulang'],
            ['tipe' => 'bagian', 'dokumen' => $code, 'nomor' => 'I', 'uraian' => 'Pekerjaan Persiapan'],
            ['tipe' => 'item', 'dokumen' => $code, 'nomor' => '1.1', 'uraian' => 'Galian tanah pondasi',
                'volume' => '450', 'satuan' => 'm3', 'harga_satuan' => '85.000', 'jumlah' => '38.250.000'],
        ]);
    }
}
