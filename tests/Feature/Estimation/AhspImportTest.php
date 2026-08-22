<?php

namespace Tests\Feature\Estimation;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\DocumentStatus;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\AhspComponent;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Tests\ErpTestCase;

/**
 * Importing a book of analyses from one sheet — the `ahsp` definition as it
 * ships.
 *
 * An AHSP is koefisien x harga satuan, summed, plus overhead, and that number
 * then prices every BOQ item built from the analysis. So what is asserted here
 * is the arithmetic first: that est_ahsp.unit_price is exactly
 * sum(koefisien x harga) x (1 + overhead/100) for what the file carried, that a
 * koefisien of 1,05 is never read as one thousand and fifty, and that a
 * component row missing from the file is caught by the book's own printed price
 * instead of quietly making the analysis cheap.
 *
 * The rest is what a price book does in practice: one workbook holds two hundred
 * analyses, the same workbook is re-uploaded when prices move, and the code in
 * the group column is the analysis's own domain code rather than a document
 * number — so an import of the same book twice must update, never duplicate.
 */
class AhspImportTest extends ErpTestCase
{
    use EstimationImportFixtures;

    // ------------------------------------------------------------ the numbers

    /** One sheet, two analyses, each priced from its own components. */
    public function test_a_book_of_several_analyses_becomes_several_analyses_each_priced_from_its_components(): void
    {
        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.2.3.1.1', 'uraian' => 'Penggalian 1 m3 tanah biasa sedalam 1 m',
                'satuan' => 'm3', 'kategori' => 'sipil', 'overhead_persen' => '10'],
            ['tipe' => 'komponen', 'kode' => 'A.2.3.1.1', 'uraian' => 'Pekerja', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,75', 'harga_satuan' => '110.000', 'jumlah' => '82.500'],
            ['tipe' => 'komponen', 'kode' => 'A.2.3.1.1', 'uraian' => 'Mandor', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,025', 'harga_satuan' => '165.000', 'jumlah' => '4.125'],
            ['tipe' => 'analisa', 'kode' => 'A.4.1.1.7', 'uraian' => 'Pemasangan 1 m2 dinding bata merah 1/2 bata',
                'satuan' => 'm2', 'kategori' => 'arsitektur', 'overhead_persen' => '15'],
            ['tipe' => 'komponen', 'kode' => 'A.4.1.1.7', 'uraian' => 'Bata merah', 'satuan' => 'bh',
                'jenis' => 'bahan', 'koefisien' => '70', 'harga_satuan' => '800', 'jumlah' => '56.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.1.1.7', 'uraian' => 'Tukang batu', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,1', 'harga_satuan' => '145.000', 'jumlah' => '14.500'],
        ]));

        $this->assertSame(2, $result['created']);
        $this->assertSame([], $result['errors']);

        $galian = Ahsp::query()->where('code', 'A.2.3.1.1')->sole();
        $dinding = Ahsp::query()->where('code', 'A.4.1.1.7')->sole();

        // 0,75 x 110.000 = 82.500 and 0,025 x 165.000 = 4.125; 86.625 + 10%.
        $this->assertEqualsWithDelta(95_287.5, (float) $galian->unit_price, 0.01);
        // 70 x 800 = 56.000 and 0,1 x 145.000 = 14.500; 70.500 + 15%.
        $this->assertEqualsWithDelta(81_075.0, (float) $dinding->unit_price, 0.01);

        // And the identity itself, on whatever the file happened to carry.
        foreach ([$galian, $dinding] as $analysis) {
            $base = 0.0;

            foreach ($analysis->components as $component) {
                $base += round((float) $component->coefficient * (float) $component->unit_price, 2);
            }

            $this->assertEqualsWithDelta(
                round($base * (1 + (float) $analysis->overhead_pct / 100), 2),
                (float) $analysis->unit_price,
                0.01,
                "unit price of {$analysis->code} is not sum(koefisien x harga) plus overhead",
            );
        }

        $this->assertSame(2, $galian->components()->count());
        $this->assertSame(2, $dinding->components()->count());
    }

    /**
     * The load-bearing divergence in the whole importer: in a koefisien column a
     * lone dot is a decimal mark.
     *
     * Read the money way, 1.050 would be one thousand and fifty and every BOQ
     * item priced from this analysis would be a thousand times too expensive —
     * and the BOQ would still add up, so nothing downstream would ever say so.
     */
    public function test_a_koefisien_written_with_a_dot_is_a_ratio_and_never_thousands(): void
    {
        $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.10', 'uraian' => 'Pembesian 1 kg besi beton ulir',
                'satuan' => 'kg', 'kategori' => 'sipil', 'overhead_persen' => '10'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.10', 'uraian' => 'Besi beton ulir D16', 'satuan' => 'kg',
                'jenis' => 'bahan', 'koefisien' => '1.050', 'harga_satuan' => '12.500', 'jumlah' => '13.125'],
        ]));

        $component = AhspComponent::query()->sole();

        $this->assertEqualsWithDelta(1.05, (float) $component->coefficient, 0.000001);
        $this->assertEqualsWithDelta(12_500.0, (float) $component->unit_price, 0.01);
        // 1,05 x 12.500 = 13.125, +10% = 14.437,50 — not fourteen million.
        $this->assertEqualsWithDelta(14_437.5, (float) Ahsp::query()->value('unit_price'), 0.01);
    }

    /** Two decimal marks in a koefisien is refused rather than guessed at. */
    public function test_a_koefisien_with_two_dots_is_refused(): void
    {
        $preview = $this->imports()->preview('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.1', 'uraian' => 'Analisa', 'satuan' => 'm3', 'kategori' => 'sipil'],
            ['tipe' => 'komponen', 'kode' => 'A.1', 'uraian' => 'Pekerja', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '1.050.000', 'harga_satuan' => '110.000'],
        ]));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('koefisien', implode(' ', $preview['documents'][0]['rows'][0]['errors']));
        $this->assertSame(0, Ahsp::query()->count());
    }

    /**
     * A component row that never got copied is invisible to every other check:
     * the remaining lines all foot, and the analysis is simply cheap. The book's
     * own printed unit price is the only reading that notices.
     */
    public function test_a_component_row_left_out_of_the_file_is_caught_by_the_books_own_unit_price(): void
    {
        // The template's own analysis, minus the Vibrator beton line, with the
        // printed total left as it was.
        $preview = $this->imports()->preview('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.3', 'uraian' => 'Membuat 1 m3 beton ready mix K-300',
                'satuan' => 'm3', 'kategori' => 'sipil', 'overhead_persen' => '10', 'harga_analisa' => '1.354.925'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Ready Mix K-300', 'satuan' => 'm3',
                'jenis' => 'bahan', 'koefisien' => '1,02', 'harga_satuan' => '1.150.000', 'jumlah' => '1.173.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Tukang cor', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,25', 'harga_satuan' => '145.000', 'jumlah' => '36.250'],
        ]));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('harga_analisa', implode(' ', $preview['documents'][0]['errors']));
        $this->assertStringContainsString('tertinggal', implode(' ', $preview['documents'][0]['errors']));
        $this->assertSame(0, Ahsp::query()->count());
    }

    /** With every component present, the same printed price agrees and lands. */
    public function test_a_book_price_that_agrees_with_its_components_imports(): void
    {
        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.3', 'uraian' => 'Membuat 1 m3 beton ready mix K-300',
                'satuan' => 'm3', 'kategori' => 'sipil', 'overhead_persen' => '10', 'harga_analisa' => '1.354.925'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Ready Mix K-300', 'satuan' => 'm3',
                'jenis' => 'bahan', 'koefisien' => '1,02', 'harga_satuan' => '1.150.000', 'jumlah' => '1.173.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Tukang cor', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,25', 'harga_satuan' => '145.000', 'jumlah' => '36.250'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Vibrator beton', 'satuan' => 'jam',
                'jenis' => 'alat', 'koefisien' => '0,5', 'harga_satuan' => '45.000', 'jumlah' => '22.500'],
        ]));

        $this->assertSame(1, $result['created']);
        // The printed price is checked and thrown away; the stored one is ours.
        $this->assertEqualsWithDelta(1_354_925.0, (float) Ahsp::query()->value('unit_price'), 0.01);
    }

    // -------------------------------------------------------------- the words

    /** upah / bahan / alat are the words the book uses, and what it prints back. */
    public function test_the_indonesian_words_for_the_component_types_are_accepted(): void
    {
        $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'E.CCTV.01', 'uraian' => 'Instalasi 1 titik kamera CCTV dome',
                'satuan' => 'ttk', 'kategori' => 'elv'],
            ['tipe' => 'komponen', 'kode' => 'E.CCTV.01', 'uraian' => 'CCTV Dome 4MP', 'satuan' => 'unit',
                'jenis' => 'bahan', 'koefisien' => '1', 'harga_satuan' => '1.850.000'],
            ['tipe' => 'komponen', 'kode' => 'E.CCTV.01', 'uraian' => 'Teknisi ELV', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,5', 'harga_satuan' => '175.000'],
            ['tipe' => 'komponen', 'kode' => 'E.CCTV.01', 'uraian' => 'Tangga aluminium', 'satuan' => 'hari',
                'jenis' => 'alat', 'koefisien' => '0,25', 'harga_satuan' => '75.000'],
            ['tipe' => 'komponen', 'kode' => 'E.CCTV.01', 'uraian' => 'Helper', 'satuan' => 'OH',
                'jenis' => 'tenaga kerja', 'koefisien' => '0,5', 'harga_satuan' => '110.000'],
        ]));

        $types = AhspComponent::query()->orderBy('id')->pluck('component_type')->map(fn ($type) => $type->value)->all();

        $this->assertSame(['material', 'labor', 'equipment', 'labor'], $types);
        $this->assertSame('elv', Ahsp::query()->value('category')->value);
    }

    /** A word nobody uses is refused with the list of the ones that work. */
    public function test_an_unrecognised_component_type_is_refused_and_says_what_is_accepted(): void
    {
        $preview = $this->imports()->preview('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.1', 'uraian' => 'Analisa', 'satuan' => 'm3', 'kategori' => 'sipil'],
            ['tipe' => 'komponen', 'kode' => 'A.1', 'uraian' => 'Pekerja', 'satuan' => 'OH',
                'jenis' => 'borongan', 'koefisien' => '1', 'harga_satuan' => '110.000'],
        ]));

        $errors = implode(' ', $preview['documents'][0]['rows'][0]['errors']);

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('borongan', $errors);
        $this->assertStringContainsString('labor', $errors);
        $this->assertSame(0, Ahsp::query()->count());
    }

    // ------------------------------------------------------- the item catalogue

    /**
     * An unknown item code refuses the analysis and creates nothing.
     *
     * inv_items.category_id is NOT NULL, so auto-creating an item would have to
     * invent a category, and that junk then shows up in every picker, stock
     * report and COGS mapping — approved by nobody, as a side effect of an
     * estimating import.
     */
    public function test_an_unknown_item_code_refuses_the_analysis_and_creates_no_inventory_item(): void
    {
        $this->stockItem('ITM-0007');
        $before = DB::table('inv_items')->count();

        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.3', 'uraian' => 'Beton ready mix K-300',
                'satuan' => 'm3', 'kategori' => 'sipil'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Ready Mix K-300', 'satuan' => 'm3',
                'jenis' => 'bahan', 'item_kode' => 'ITM-9999', 'koefisien' => '1,02', 'harga_satuan' => '1.150.000'],
        ]));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('ITM-9999', implode(' ', $result['documents'][0]['errors'] ?: $result['documents'][0]['rows'][0]['errors']));
        $this->assertSame(0, Ahsp::query()->count());
        $this->assertSame($before, DB::table('inv_items')->count());
    }

    /**
     * A component that names no stocked item at all is ordinary: "Kawat beton"
     * is a line in an analysis, not a warehouse item.
     */
    public function test_a_component_naming_a_stocked_item_binds_to_it_and_one_naming_none_is_accepted(): void
    {
        $itemId = $this->stockItem('ITM-0007');

        $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.10', 'uraian' => 'Pembesian 1 kg besi beton ulir',
                'satuan' => 'kg', 'kategori' => 'sipil'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.10', 'uraian' => 'Ready Mix K-300', 'satuan' => 'm3',
                'jenis' => 'bahan', 'item_kode' => 'ITM-0007', 'koefisien' => '1,02', 'harga_satuan' => '1.150.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.10', 'uraian' => 'Kawat beton', 'satuan' => 'kg',
                'jenis' => 'bahan', 'koefisien' => '0,015', 'harga_satuan' => '25.000'],
        ]));

        $components = AhspComponent::query()->orderBy('id')->get();

        $this->assertSame($itemId, (int) $components[0]->item_id);
        $this->assertNull($components[1]->item_id);
    }

    // ------------------------------------------------------------ re-importing

    /**
     * A price book is re-uploaded whenever prices move, so the second upload
     * must rewrite the same analyses rather than mint a second set — and the
     * analysis code being the group column is exactly what makes that possible.
     */
    public function test_re_importing_the_same_book_updates_in_place_rather_than_duplicating(): void
    {
        $book = fn (string $price): string => $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.2.3.1.1', 'uraian' => 'Penggalian 1 m3 tanah biasa',
                'satuan' => 'm3', 'kategori' => 'sipil', 'overhead_persen' => '10'],
            ['tipe' => 'komponen', 'kode' => 'A.2.3.1.1', 'uraian' => 'Pekerja', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,75', 'harga_satuan' => $price],
        ]);

        $this->imports()->commit('ahsp', 'ahsp.csv', $book('110.000'));
        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $book('125.000'));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, Ahsp::query()->count());
        // Components were replaced wholesale, not appended: 0,75 x 125.000 x 1,1.
        $this->assertSame(1, AhspComponent::query()->count());
        $this->assertEqualsWithDelta(103_125.0, (float) Ahsp::query()->value('unit_price'), 0.01);
    }

    /**
     * The BOQ keeps the price it was signed with.
     *
     * est_boq_items copies description, unit and unit_price when the line is
     * added and nothing re-reads them, so re-pricing an analysis cannot move an
     * approved RAB under its signature. The estimator has to be told, though,
     * because "I updated the analysis" reads like "the RAB is updated".
     */
    public function test_re_pricing_an_analysis_leaves_an_approved_boqs_own_prices_alone(): void
    {
        $ahsp = $this->readyMixAnalysis();

        $this->imports()->commit('boqs', 'rab.csv', $this->file('boqs', [
            ['tipe' => 'dokumen', 'dokumen' => 'RAB-GRAHA', 'judul' => 'Gedung Kantor Graha Sentosa'],
            ['tipe' => 'bagian', 'dokumen' => 'RAB-GRAHA', 'nomor' => 'II', 'uraian' => 'Pekerjaan Struktur'],
            ['tipe' => 'item', 'dokumen' => 'RAB-GRAHA', 'nomor' => '2.1', 'ahsp_kode' => 'A.4.3.1.3', 'volume' => '120'],
        ]));

        $boq = Boq::query()->sole();
        $boq->forceFill(['status' => DocumentStatus::Approved])->save();

        $preview = $this->imports()->preview('ahsp', 'ahsp.csv', $this->dearerReadyMix());

        $this->assertStringContainsString('1 baris BOQ', implode(' ', $preview['documents'][0]['warnings']));
        $this->assertStringContainsString('TIDAK ikut berubah', implode(' ', $preview['documents'][0]['warnings']));

        $this->imports()->commit('ahsp', 'ahsp.csv', $this->dearerReadyMix());

        $item = BoqItem::query()->sole();

        // The analysis moved; the signed RAB did not.
        $this->assertGreaterThan((float) $ahsp->unit_price, (float) Ahsp::query()->value('unit_price'));
        $this->assertEqualsWithDelta(1_354_925.0, (float) $item->unit_price, 0.01);
        $this->assertEqualsWithDelta(120 * 1_354_925.0, (float) $item->amount, 0.01);
        $this->assertEqualsWithDelta((float) $item->amount, (float) $boq->refresh()->total, 0.01);
    }

    /**
     * A blank overhead cell means "leave it alone", never "make it 10".
     *
     * est_ahsp.overhead_pct is NOT NULL, so a sheet that carries the column and
     * leaves one row empty must neither crash on the constraint nor quietly
     * reset a 15% analysis to the house rate.
     */
    public function test_a_blank_overhead_leaves_an_existing_analysis_alone_and_a_new_one_takes_the_house_rate(): void
    {
        $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.LAMA', 'uraian' => 'Analisa lama', 'satuan' => 'm3',
                'kategori' => 'sipil', 'overhead_persen' => '15'],
            ['tipe' => 'komponen', 'kode' => 'A.LAMA', 'uraian' => 'Pekerja', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '1', 'harga_satuan' => '100.000'],
        ]));

        $this->assertEqualsWithDelta(115_000.0, (float) Ahsp::query()->where('code', 'A.LAMA')->value('unit_price'), 0.01);

        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.LAMA', 'uraian' => 'Analisa lama', 'satuan' => 'm3',
                'kategori' => 'sipil'],
            ['tipe' => 'komponen', 'kode' => 'A.LAMA', 'uraian' => 'Pekerja', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '1', 'harga_satuan' => '100.000'],
            ['tipe' => 'analisa', 'kode' => 'A.BARU', 'uraian' => 'Analisa baru', 'satuan' => 'm3',
                'kategori' => 'sipil'],
            ['tipe' => 'komponen', 'kode' => 'A.BARU', 'uraian' => 'Pekerja', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '1', 'harga_satuan' => '100.000'],
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['documents']);

        $lama = Ahsp::query()->where('code', 'A.LAMA')->sole();
        $baru = Ahsp::query()->where('code', 'A.BARU')->sole();

        $this->assertEqualsWithDelta(15.0, (float) $lama->overhead_pct, 0.0001);
        $this->assertEqualsWithDelta(115_000.0, (float) $lama->unit_price, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $baru->overhead_pct, 0.0001);
        $this->assertEqualsWithDelta(110_000.0, (float) $baru->unit_price, 0.01);
    }

    /**
     * The book price is checked against the overhead the SAVE will use.
     *
     * The template invites a blank overhead_persen ('tidak mengubah analisa yang
     * sudah ada'), and AhspService::update honours that by keeping the stored
     * rate. Checking the printed price against a flat 10% instead refused a book
     * re-imported at its own correct price and sent the estimator hunting for a
     * component row that was never missing.
     */
    public function test_a_blank_overhead_checks_the_book_price_against_the_rate_the_analysis_keeps(): void
    {
        $this->readyMixAt('15');

        // 1,02 x 1.150.000 + 0,25 x 145.000 + 0,5 x 45.000 = 1.231.750, +15%.
        $this->assertEqualsWithDelta(1_416_512.5, (float) Ahsp::query()->value('unit_price'), 0.01);

        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $this->readyMixStating('1.416.512,50'));

        $this->assertSame(1, $result['updated'], implode(' | ', $result['documents'][0]['errors'] ?? []));
        $this->assertSame([], $result['errors']);

        $ahsp = Ahsp::query()->sole();

        $this->assertEqualsWithDelta(15.0, (float) $ahsp->overhead_pct, 0.0001);
        $this->assertEqualsWithDelta(1_416_512.5, (float) $ahsp->unit_price, 0.01);
    }

    /**
     * And the same rate is what makes a DROPPED component visible.
     *
     * The guard was blind in the other direction too: an analysis kept at 15%
     * whose file lost a component still footed against 10% of the smaller base,
     * so the file was accepted and the analysis quietly lost the row. The
     * refusal has to name the rate it used, because "overhead 15%" against a
     * sheet whose cell is empty is otherwise unreadable.
     */
    public function test_a_dropped_component_is_still_caught_when_the_analysis_keeps_a_rate_of_its_own(): void
    {
        $book = fn (array $rows): string => $this->file('ahsp', array_merge([
            ['tipe' => 'analisa', 'kode' => 'A.9', 'uraian' => 'Analisa dua komponen', 'satuan' => 'm3',
                'kategori' => 'sipil'] + $rows['head'],
        ], $rows['components']));

        $bahan = ['tipe' => 'komponen', 'kode' => 'A.9', 'uraian' => 'Bahan utama', 'satuan' => 'm3',
            'jenis' => 'bahan', 'koefisien' => '1', 'harga_satuan' => '1.000.000'];
        $upah = ['tipe' => 'komponen', 'kode' => 'A.9', 'uraian' => 'Tukang', 'satuan' => 'OH',
            'jenis' => 'upah', 'koefisien' => '1', 'harga_satuan' => '200.000'];

        $this->imports()->commit('ahsp', 'ahsp.csv', $book([
            'head' => ['overhead_persen' => '15'],
            'components' => [$bahan, $upah],
        ]));

        // 1.200.000 + 15% = 1.380.000.
        $this->assertEqualsWithDelta(1_380_000.0, (float) Ahsp::query()->value('unit_price'), 0.01);

        // The re-upload lost the upah row and prints 1.100.000 — which is
        // exactly 1.000.000 plus the HOUSE rate, and therefore exactly what the
        // old check agreed with.
        $file = $book([
            'head' => ['harga_analisa' => '1.100.000'],
            'components' => [$bahan],
        ]);

        $preview = $this->imports()->preview('ahsp', 'ahsp.csv', $file);
        $errors = implode(' ', $preview['documents'][0]['errors']);

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('harga_analisa', $errors);
        $this->assertStringContainsString('overhead 15%', $errors);
        $this->assertStringContainsString('dari analisa tersimpan', $errors);
        $this->assertStringContainsString('1.150.000,00', $errors);

        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $file);

        // Nothing moved: both components still there, price untouched.
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, AhspComponent::query()->count());
        $this->assertEqualsWithDelta(1_380_000.0, (float) Ahsp::query()->value('unit_price'), 0.01);
    }

    /**
     * On a CREATE the blank cell really does mean 10%, because that is what
     * AhspService::create writes — and the message says so rather than implying
     * the sheet chose it.
     */
    public function test_a_new_analysis_with_a_blank_overhead_is_checked_against_the_house_rate(): void
    {
        $preview = $this->imports()->preview('ahsp', 'ahsp.csv', $this->readyMixStating('1.416.512,50'));
        $errors = implode(' ', $preview['documents'][0]['errors']);

        // 1.416.512,50 is the 15% price; a new analysis is priced at 10%.
        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('overhead 10%', $errors);
        $this->assertStringContainsString('bawaan analisa baru', $errors);
        $this->assertSame(0, Ahsp::query()->count());

        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $this->readyMixStating('1.354.925'));

        $this->assertSame(1, $result['created'], implode(' | ', $result['documents'][0]['errors'] ?? []));
        $this->assertEqualsWithDelta(1_354_925.0, (float) Ahsp::query()->value('unit_price'), 0.01);
    }

    // ------------------------------------------------------ template & export

    /** The worked example the template ships with has to import as it stands. */
    public function test_the_shipped_template_imports_as_it_ships(): void
    {
        $itemId = $this->stockItem('ITM-0007');

        $result = $this->imports()->commit('ahsp', 'ahsp.csv', $this->templateWithExampleEnabled('ahsp'));

        $this->assertSame(1, $result['created'], implode(' | ', $result['documents'][0]['errors'] ?? []));

        $ahsp = Ahsp::query()->sole();

        $this->assertSame('A.4.3.1.3', $ahsp->code);
        $this->assertSame(3, $ahsp->components()->count());
        $this->assertSame($itemId, (int) $ahsp->components()->orderBy('id')->value('item_id'));
        // The example's own harga_analisa, which the check just agreed with.
        $this->assertEqualsWithDelta(1_354_925.0, (float) $ahsp->unit_price, 0.01);
    }

    /**
     * Export, edit prices in Excel, import back. The coefficients are the risk:
     * 0,0004 written back at the wrong scale would re-import as a different
     * analysis entirely, and the round trip is the only place that shows it.
     */
    public function test_the_export_round_trips_back_through_the_importer(): void
    {
        $this->imports()->commit('ahsp', 'ahsp.csv', $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.10', 'uraian' => 'Pembesian 1 kg besi beton ulir',
                'satuan' => 'kg', 'kategori' => 'sipil', 'overhead_persen' => '10'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.10', 'uraian' => 'Besi beton ulir D16', 'satuan' => 'kg',
                'jenis' => 'bahan', 'koefisien' => '1,05', 'harga_satuan' => '12.500'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.10', 'uraian' => 'Mandor', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,0004', 'harga_satuan' => '165.000'],
        ]));

        $before = (float) Ahsp::query()->value('unit_price');

        $result = $this->imports()->commit('ahsp', 'ahsp.csv', base64_encode($this->imports()->export('ahsp')));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated'], implode(' | ', $result['documents'][0]['errors'] ?? []));
        $this->assertSame(1, Ahsp::query()->count());
        $this->assertSame(2, AhspComponent::query()->count());

        $mandor = AhspComponent::query()->where('name', 'Mandor')->sole();

        $this->assertEqualsWithDelta(0.0004, (float) $mandor->coefficient, 0.0000001);
        $this->assertEqualsWithDelta($before, (float) Ahsp::query()->value('unit_price'), 0.01);
    }

    /** The template's own analysis, imported at a stated overhead. */
    private function readyMixAt(string $overhead): void
    {
        $this->imports()->commit('ahsp', 'ahsp.csv', $this->readyMix(['overhead_persen' => $overhead]));
    }

    /** The same analysis, blank overhead, printing $price as its book price. */
    private function readyMixStating(string $price): string
    {
        return $this->readyMix(['harga_analisa' => $price]);
    }

    /** @param  array<string, string>  $head  extra cells on the analisa row */
    private function readyMix(array $head): string
    {
        return $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.3', 'uraian' => 'Membuat 1 m3 beton ready mix K-300',
                'satuan' => 'm3', 'kategori' => 'sipil'] + $head,
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Ready Mix K-300', 'satuan' => 'm3',
                'jenis' => 'bahan', 'koefisien' => '1,02', 'harga_satuan' => '1.150.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Tukang cor', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,25', 'harga_satuan' => '145.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Vibrator beton', 'satuan' => 'jam',
                'jenis' => 'alat', 'koefisien' => '0,5', 'harga_satuan' => '45.000'],
        ]);
    }

    /** The same analysis at a higher material price. */
    private function dearerReadyMix(): string
    {
        return $this->file('ahsp', [
            ['tipe' => 'analisa', 'kode' => 'A.4.3.1.3', 'uraian' => 'Membuat 1 m3 beton ready mix K-300',
                'satuan' => 'm3', 'kategori' => 'sipil', 'overhead_persen' => '10'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Ready Mix K-300', 'satuan' => 'm3',
                'jenis' => 'bahan', 'koefisien' => '1,02', 'harga_satuan' => '1.290.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Tukang cor', 'satuan' => 'OH',
                'jenis' => 'upah', 'koefisien' => '0,25', 'harga_satuan' => '145.000'],
            ['tipe' => 'komponen', 'kode' => 'A.4.3.1.3', 'uraian' => 'Vibrator beton', 'satuan' => 'jam',
                'jenis' => 'alat', 'koefisien' => '0,5', 'harga_satuan' => '45.000'],
        ]);
    }
}
