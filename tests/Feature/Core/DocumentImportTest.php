<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Exceptions\SelfApprovalException;
use Modules\Core\Services\DocumentImportService;
use Modules\Core\Support\ImportableDocuments;
use Modules\Core\Support\SegregationOfDuties;
use Modules\Core\Support\SpreadsheetReader;
use Modules\Crm\Http\Requests\QuotationStoreRequest;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\QuotationItem;
use Modules\Crm\Services\QuotationService;
use Modules\Estimation\Http\Requests\AhspStoreRequest;
use Modules\Estimation\Http\Requests\BoqStoreRequest;
use Modules\Estimation\Models\Ahsp;
use Modules\Estimation\Models\Boq;
use Modules\Estimation\Models\BoqItem;
use Modules\Estimation\Services\AhspService;
use Modules\Estimation\Services\BoqService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * The engine behind importing a document that is a PARENT PLUS LINES.
 *
 * Everything here exercises DocumentImportService itself, against three fixture
 * definitions installed in place of the real registry: one flat parent+lines
 * shape (a penawaran), one with a middle level (a BOQ with its bagian), and one
 * whose group column IS the document's own stored code (an AHSP), which is the
 * shape that exercises update_rules. The
 * four shipped definitions are their modules' own business and carry their own
 * tests; what is asserted here is the grammar every one of them inherits — how a
 * row is typed, what happens to a row nobody can type, where a line attaches,
 * what a preview may not do, and what a refusal costs.
 *
 * The fixture registry is installed by a container binding rather than by a
 * register()/fake() hook on ImportableDocuments, so nothing in this file exists
 * in production.
 */
class DocumentImportTest extends ErpTestCase
{
    private DocumentImportService $imports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ImportableDocuments::class, $this->fixtureRegistry());
        $this->imports = app(DocumentImportService::class);
    }

    // ------------------------------------------------------------- the shape

    /**
     * One workbook, many documents — mandatory for an AHSP price book and normal
     * for the twelve-branch ELV jobs this contractor actually quotes.
     */
    public function test_one_file_of_several_documents_becomes_several_documents(): void
    {
        $this->customer('CUST-1');
        $this->customer('CUST-2');

        $result = $this->imports->commit('penawaran-uji', 'penawaran.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Gedung Kantor Graha,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,2,ls,"1.500.000","3.000.000"',
            'item,PNW-A,,,,,Galian tanah pondasi,450,m3,"85.000","38.250.000"',
            'dokumen,PNW-B,CUST-2,Integrasi CCTV Bank Artha,integrasi,11,,,,,',
            'item,PNW-B,,,,,Kamera IP dome,24,unit,"4.250.000","102.000.000"',
        ));

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $quotation = Quotation::query()->where('title', 'Gedung Kantor Graha')->sole();
        $this->assertSame(2, $quotation->items()->count());
        $this->assertSame('construction', $quotation->scope_type->value);
        // recomputeTotals ran: 3.000.000 + 38.250.000, plus 11% PPN.
        $this->assertEqualsWithDelta(41_250_000.0, (float) $quotation->subtotal, 0.01);
        $this->assertEqualsWithDelta(45_787_500.0, (float) $quotation->total, 0.01);
    }

    /** Lines keep the order the estimator typed them in; nothing sorts them. */
    public function test_lines_land_in_the_order_the_sheet_lists_them(): void
    {
        $this->customer('CUST-1');

        $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Tiga baris,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Baris pertama,1,ls,1000,1000',
            'item,PNW-A,,,,,Baris kedua,1,ls,2000,2000',
            'item,PNW-A,,,,,Baris ketiga,1,ls,3000,3000',
        ));

        $this->assertSame(
            ['Baris pertama', 'Baris kedua', 'Baris ketiga'],
            QuotationItem::query()->orderBy('line_no')->pluck('description')->all(),
        );
    }

    /**
     * A real RAB opens with a merged title block — the project, the location —
     * before the table starts, so the header is not the first row.
     */
    public function test_the_header_row_is_found_under_a_banner(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', base64_encode(
            "RENCANA ANGGARAN BIAYA,,,,,,,,,,\n"
            ."Proyek  : Gedung Kantor Graha Sentosa,,,,,,,,,,\n"
            .",,,,,,,,,,\n"
            .$this->quotationHeader()."\n"
            ."dokumen,PNW-A,CUST-1,Di bawah kop surat,konstruksi,11,,,,,\n"
            ."item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000\n",
        ));

        $this->assertSame(1, $result['created']);
    }

    public function test_a_file_without_a_header_row_is_refused_with_one_message(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Baris judul kolom tidak ditemukan/');

        $this->imports->preview('penawaran-uji', 'p.csv', base64_encode(
            "NO,URAIAN,VOL,SAT,HARGA\n1,Pembersihan lahan,1500,m2,12500\n",
        ));
    }

    /**
     * A group cell merged down forty rows is one value in the top-left and null
     * in every row beneath it. Forward-filling a LINE is what that merge means.
     */
    public function test_a_merged_group_cell_forward_fills_onto_its_lines(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Sel digabung,konstruksi,11,,,,,',
            'item,,,,,,Baris pertama,1,ls,1000,1000',
            'item,,,,,,Baris kedua,1,ls,2000,2000',
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, QuotationItem::query()->count());
    }

    /**
     * A header row never forward-fills. If it did, one forgotten cell would merge
     * two documents into one and the second one's lines would vanish into the
     * first — silently, and with the totals still adding up.
     */
    public function test_a_document_row_without_its_group_never_joins_the_document_above(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Yang pertama,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Baris pertama,1,ls,1000,1000',
            'dokumen,,CUST-1,Lupa diisi,konstruksi,11,,,,,',
        ));

        $this->assertStringContainsString('dokumen wajib diisi', implode(' ', $preview['errors']));
    }

    // ----------------------------------------------------------- typed rows

    /**
     * The row that must never be skipped in silence.
     *
     * A SUB TOTAL line carries money, and a BOQ that quietly drops the rows it
     * did not understand imports 8% short with every column still looking right.
     */
    public function test_a_row_with_an_unrecognised_type_is_refused_not_skipped(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Ada subtotal,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
            'SUB TOTAL,PNW-A,,,,,,,,,1000',
        ));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('tidak dikenali', json_encode($result['documents']));
        $this->assertSame(0, Quotation::query()->count());
    }

    /** The escape hatch that makes the refusal above reasonable. */
    public function test_a_subtotal_row_marked_abaikan_is_skipped(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Subtotal ditandai,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
            'abaikan,PNW-A,,,,,SUB TOTAL,,,,1000',
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, QuotationItem::query()->count());
    }

    /**
     * The '#' that skips a row is a statement about the `tipe` column, and not
     * about whichever column the sheet happens to print first.
     *
     * Columns are located by NAME, so an estimator's own file may open with
     * uraian and carry tipe fourth — but the skip tested the PHYSICALLY first
     * cell. A tipe=item row worth Rp 999.000.000 whose uraian reads
     * "#3 Pekerjaan beton" was dropped with no error and no warning while the
     * line beneath it imported normally, and the penawaran footed 999 juta
     * short with every column still looking right. That is verbatim the
     * disaster SKIP_ALIASES keeps its vocabulary narrow to prevent.
     */
    public function test_a_hash_in_the_description_column_never_skips_a_priced_line(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', base64_encode(
            "uraian,tipe,dokumen,pelanggan_kode,judul,lingkup,ppn_persen,volume,satuan,harga_satuan,jumlah\n"
            .",dokumen,PNW-A,CUST-1,Uraian berawalan pagar,konstruksi,11,,,,\n"
            ."#3 Pekerjaan beton,item,PNW-A,,,,,1,ls,999.000.000,999.000.000\n"
            ."Pekerjaan persiapan,item,PNW-A,,,,,1,ls,1.000.000,1.000.000\n",
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame(
            ['#3 Pekerjaan beton', 'Pekerjaan persiapan'],
            QuotationItem::query()->orderBy('line_no')->pluck('description')->all(),
        );
        $this->assertEqualsWithDelta(1_000_000_000.0, (float) Quotation::query()->value('subtotal'), 0.01);
    }

    /**
     * A row we cannot attribute to any document may belong to any of them, so
     * this is the one failure that refuses the whole file rather than one group.
     */
    public function test_an_untypeable_row_before_any_document_refuses_the_whole_file(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'REKAP,,,,,,,,,,999',
            'dokumen,PNW-A,CUST-1,Sehat sepenuhnya,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ));

        $this->assertSame(0, $result['created']);
        $this->assertNotSame([], $result['errors']);
        $this->assertSame(0, Quotation::query()->count());
    }

    // ------------------------------------------------------- one bad document

    /**
     * The rule that separates this from the flat importer: a document is
     * all-or-nothing, but the file is not. A BOQ missing three lines is wrong
     * forever; the eleven branches beside it in the same workbook are not.
     */
    public function test_one_bad_line_refuses_its_own_document_and_the_others_still_land(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Yang sehat,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
            'dokumen,PNW-B,CUST-1,Yang cacat,konstruksi,11,,,,,',
            'item,PNW-B,,,,,Baris tanpa harga,1,ls,,',
            'item,PNW-B,,,,,Baris yang baik,1,ls,2000,2000',
            'dokumen,PNW-C,CUST-1,Yang sehat juga,konstruksi,11,,,,,',
            'item,PNW-C,,,,,Pekerjaan persiapan,1,ls,3000,3000',
        ));

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(
            ['Yang sehat', 'Yang sehat juga'],
            Quotation::query()->orderBy('id')->pluck('title')->all(),
        );
        // Not one line of the refused document, not even the good one.
        $this->assertSame(2, QuotationItem::query()->count());
    }

    /**
     * The half-written case the preview cannot catch: the parent row is already
     * in the table when the service refuses. Its own transaction has to take the
     * parent back out, and must not take the document beside it with it.
     */
    public function test_a_document_that_fails_mid_write_is_rolled_back_whole(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,GAGAL di tengah jalan,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
            'dokumen,PNW-B,CUST-1,Yang sehat,konstruksi,11,,,,,',
            'item,PNW-B,,,,,Pekerjaan persiapan,1,ls,2000,2000',
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Quotation::query()->count(), 'the failed parent was rolled back');
        $this->assertSame(1, QuotationItem::query()->count(), 'and so were its lines');
        $this->assertSame('Yang sehat', Quotation::query()->value('title'));
    }

    /** Nothing is written until somebody has seen what would happen. */
    public function test_a_preview_writes_nothing(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Belum masuk,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,2,ls,"1.500.000","3.000.000"',
            'dokumen,PNW-B,CUST-1,Juga belum masuk,konstruksi,11,,,,,',
            'item,PNW-B,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ));

        $this->assertTrue($preview['documents'][0]['valid']);
        $this->assertSame(2, $preview['summary']['to_create']);
        $this->assertSame(2, $preview['summary']['lines_read']);
        $this->assertSame(0, Quotation::query()->count());
        $this->assertSame(0, QuotationItem::query()->count());
        $this->assertSame(0, DB::table('crm_quotations')->count());
    }

    // --------------------------------------------------------------- numbers

    /**
     * The load-bearing parsing decision, and the reason there are four casts
     * instead of one. "1.050" in a harga column is a thousand and fifty rupiah;
     * in a koefisien column it is one-point-nought-five. Reading the second as
     * the first multiplies the unit price of every BOQ item using that analysis
     * by a thousand, and the BOQ still adds up.
     */
    public function test_money_reads_a_grouped_dot_as_thousands_and_a_coefficient_never_does(): void
    {
        $reader = app(SpreadsheetReader::class);

        $this->assertSame(1250.0, $reader->cast('money', '1.250'));
        $this->assertSame(12345678.0, $reader->cast('money', '12.345.678'));
        $this->assertSame(1250000.5, $reader->cast('money', '1.250.000,50'));
        $this->assertSame(1234567.89, $reader->cast('money', '1,234,567.89'));
        $this->assertSame(1.25, $reader->cast('money', '1.25'));

        $this->assertSame(1.05, $reader->cast('coefficient', '1.050'));
        $this->assertSame(1.05, $reader->cast('coefficient', '1,05'));
        $this->assertSame(0.0833, $reader->cast('coefficient', '0,0833'));
        $this->assertSame(1.05, $reader->cast('percent', '1.050'));
    }

    /**
     * A volume is COUNTED, not a ratio, so it groups its dots like money does —
     * 1.500 m2 of site clearing is on the first page of every RAB in the country.
     * A genuine decimal volume still reads as one, because only a strict
     * 1-3/3/3 grouping counts as thousands.
     */
    public function test_a_volume_groups_its_dots_the_way_money_does(): void
    {
        $reader = app(SpreadsheetReader::class);

        $this->assertSame(1500.0, $reader->cast('qty', '1.500'));
        $this->assertSame(1500.0, $reader->cast('qty', '1.500,000'));
        $this->assertSame(1.5, $reader->cast('qty', '1.5'));
        $this->assertSame(0.75, $reader->cast('qty', '0,75'));
        $this->assertSame(450.0, $reader->cast('qty', '450'));
    }

    public function test_a_coefficient_with_two_dots_is_refused_rather_than_guessed(): void
    {
        $this->expectExceptionMessageMatches('/lebih dari satu tanda desimal/');

        app(SpreadsheetReader::class)->cast('coefficient', '1.234.567');
    }

    /** Rp, IDR, a trailing ",-", a non-breaking space and (1.000) all mean money. */
    public function test_the_shapes_an_indonesian_sheet_writes_money_in(): void
    {
        $reader = app(SpreadsheetReader::class);

        $this->assertSame(1250000.0, $reader->cast('money', 'Rp 1.250.000'));
        $this->assertSame(1250000.0, $reader->cast('money', 'Rp1.250.000,-'));
        $this->assertSame(1250000.0, $reader->cast('money', "IDR\u{00A0}1.250.000"));
        $this->assertSame(-1250000.0, $reader->cast('money', '(1.250.000)'));
        $this->assertSame(11.0, $reader->cast('percent', '11%'));
    }

    /** There is no path in this engine that turns a cell it cannot read into 0. */
    public function test_an_unreadable_number_refuses_its_document_rather_than_becoming_zero(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Angka rusak,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,"1.250.00O",',
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('bukan angka', json_encode($result['documents']));
        $this->assertSame(0, Quotation::query()->count());
    }

    /**
     * The estimator's own arithmetic is the checksum against our parser — the
     * only defence against the one ambiguity the separator rules cannot settle
     * alone. The jumlah column is read and never stored.
     */
    public function test_the_files_own_total_refuses_a_line_we_read_differently(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Jumlah tidak cocok,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,2,ls,"1.500.000","300.000"',
        ));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('volume x harga satuan', $preview['documents'][0]['rows'][0]['errors'][0]);
    }

    /**
     * The refusal has to be true on a resource that has no analyses.
     *
     * The guard fires for ANY row declaring an amount pair whose product cannot
     * be computed — a blank volume does it as surely as a blank harga_satuan —
     * but it said "baris ini berharga dari analisa (harga satuan kosong)". A
     * penawaran line with a forgotten volume was told it was priced from an
     * analysis, in a module that has none, so the operator went looking for the
     * wrong cell entirely.
     */
    public function test_a_stated_jumlah_without_a_volume_names_the_cell_that_is_actually_empty(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Volume terlupa,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,,ls,"1.500.000","3.000.000"',
        ));

        $errors = implode(' ', $preview['documents'][0]['rows'][0]['errors']);

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('volume kosong', $errors);
        $this->assertStringNotContainsString('berharga dari analisa', $errors);
    }

    public function test_a_sub_rupiah_difference_is_only_a_warning(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Beda pembulatan,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,3,ls,"1.000,33","3.001"',
        ));

        $this->assertTrue($preview['documents'][0]['valid']);
        $this->assertNotSame([], $preview['documents'][0]['rows'][0]['warnings']);
    }

    /** A file with no jumlah column has no checksum, and the operator is told so. */
    public function test_a_file_without_a_jumlah_column_says_the_check_could_not_run(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', base64_encode(
            "tipe,dokumen,pelanggan_kode,judul,lingkup,uraian,volume,satuan,harga_satuan\n"
            ."dokumen,PNW-A,CUST-1,Tanpa kolom jumlah,konstruksi,,,,\n"
            ."item,PNW-A,,,,Pekerjaan persiapan,1,ls,1000\n",
        ));

        $this->assertTrue($preview['documents'][0]['valid']);
        $this->assertStringContainsString('jumlah', implode(' ', $preview['warnings']));
    }

    // --------------------------------------------------------------- columns

    /**
     * KWANTITAS where the importer wanted volume is the commonest real import
     * failure there is: every row validates, and the document is short a column.
     */
    public function test_a_column_the_importer_does_not_know_is_reported(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', base64_encode(
            "tipe,dokumen,pelanggan_kode,judul,lingkup,uraian,volume,satuan,harga_satuan,jumlah,kwantitas\n"
            ."dokumen,PNW-A,CUST-1,Kolom asing,konstruksi,,,,,,\n"
            ."item,PNW-A,,,,Pekerjaan persiapan,1,ls,1000,1000,99\n",
        ));

        $this->assertSame(['kwantitas'], $preview['unmapped_columns']);
        $this->assertStringContainsString('kwantitas', implode(' ', $preview['warnings']));
    }

    /** A required column missing from the FILE is one message, not N row errors. */
    public function test_a_missing_required_column_is_refused_before_any_row_is_read(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Kolom wajib tidak ditemukan.*volume/');

        $this->imports->preview('penawaran-uji', 'p.csv', base64_encode(
            "tipe,dokumen,pelanggan_kode,judul,lingkup,uraian,satuan,harga_satuan\n"
            ."dokumen,PNW-A,CUST-1,Kurang kolom,konstruksi,,,\n",
        ));
    }

    /**
     * A column the sheet does not carry at all must not blank what is stored.
     * The whole point of an update-by-code import is that somebody can send a
     * partial sheet — "just reprice section B" — without losing the notes.
     */
    public function test_a_column_absent_from_the_sheet_leaves_the_stored_value_alone(): void
    {
        $this->customer('CUST-1');
        $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Punya catatan,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ));

        $quotation = Quotation::query()->sole();
        $quotation->forceFill(['notes' => 'Catatan penting'])->save();

        $this->imports->commit('penawaran-uji', 'p.csv', base64_encode(
            "tipe,dokumen,pelanggan_kode,judul,lingkup,uraian,volume,satuan,harga_satuan\n"
            ."dokumen,{$quotation->code},CUST-1,Punya catatan,konstruksi,,,,\n"
            ."item,{$quotation->code},,,,Pekerjaan persiapan,1,ls,2000\n",
        ));

        $this->assertSame('Catatan penting', $quotation->refresh()->notes);
    }

    // -------------------------------------------------------- create /update

    public function test_an_existing_code_is_updated_in_place_and_its_lines_replaced(): void
    {
        $this->customer('CUST-1');
        $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Nama lama,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Baris lama,1,ls,1000,1000',
            'item,PNW-A,,,,,Baris lama kedua,1,ls,1000,1000',
        ));

        $code = Quotation::query()->value('code');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            "dokumen,{$code},CUST-1,Nama baru,konstruksi,11,,,,,",
            "item,{$code},,,,,Baris baru,3,ls,1000,3000",
        ));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, Quotation::query()->count());
        $this->assertSame('Nama baru', Quotation::query()->value('title'));
        $this->assertSame(1, QuotationItem::query()->count(), 'lines are replaced wholesale, not appended');
    }

    /**
     * One wrong digit in a code somebody meant to update would otherwise mint a
     * second document with a fresh number, and nobody would notice until two
     * penawaran for one job disagreed.
     */
    public function test_a_code_shaped_like_a_document_number_that_does_not_exist_is_refused(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,QTN/2026/VIII/9999,CUST-1,Nomor salah ketik,konstruksi,11,,,,,',
            'item,QTN/2026/VIII/9999,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('tidak ditemukan', $result['documents'][0]['errors'][0]);
        $this->assertSame(0, Quotation::query()->count());
    }

    /** A free label is not a document number, and creates. */
    public function test_a_free_label_in_the_group_column_creates_a_new_document(): void
    {
        $this->customer('CUST-1');

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,RAB-GRAHA,CUST-1,Label bebas,konstruksi,11,,,,,',
            'item,RAB-GRAHA,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ));

        $this->assertSame(1, $result['created']);
        // The assigned code comes back, so the operator can paste it into the
        // group column and every later upload is an update in place.
        $this->assertSame(Quotation::query()->value('code'), $result['codes']['RAB-GRAHA']);
    }

    /**
     * No template carries a status column, so no file can ask for one — and a
     * target past draft refuses its whole group rather than being rewritten.
     */
    public function test_an_approved_document_is_never_overwritten(): void
    {
        $this->customer('CUST-1');
        $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Sudah disetujui,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Baris asli,1,ls,1000,1000',
        ));

        $quotation = Quotation::query()->sole();
        $quotation->forceFill(['status' => DocumentStatus::Approved])->save();

        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            "dokumen,{$quotation->code},CUST-1,Coba ditimpa,konstruksi,11,,,,,",
            "item,{$quotation->code},,,,,Baris pengganti,9,ls,1000,9000",
        ));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Versi Baru', $result['documents'][0]['errors'][0]);
        $this->assertSame('Sudah disetujui', $quotation->refresh()->title);
        $this->assertSame('Baris asli', QuotationItem::query()->value('description'));
    }

    /** An unknown code is refused, never silently nulled. */
    public function test_an_unknown_lookup_code_refuses_the_document(): void
    {
        $result = $this->imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-TIDAK-ADA,Pelanggan entah siapa,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('CUST-TIDAK-ADA', json_encode($result['documents']));
    }

    /** The module's own request decides what a valid document is. */
    public function test_a_document_with_no_lines_is_refused_by_its_own_form_request(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Tanpa rincian,konstruksi,11,,,,,',
        ));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('rincian', implode(' ', $preview['documents'][0]['errors']));
    }

    // ------------------------------------------------------ the middle level

    /**
     * The BOQ shape: sections and their items in the same columns, told apart by
     * the tipe column and never by sniffing which cells are empty.
     */
    public function test_items_attach_to_the_section_above_them(): void
    {
        $result = $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Gedung Kantor Graha Sentosa,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,"1.500",m2,"12.500","18.750.000"',
            'item,RAB-GRAHA,,,A.2,Direksi keet,,1,ls,"25.000.000","25.000.000"',
            'abaikan,RAB-GRAHA,,,,SUB TOTAL A,,,,,"43.750.000"',
            'bagian,RAB-GRAHA,,,B,Pekerjaan Struktur,,,',
            'item,RAB-GRAHA,,,B.1,Galian tanah pondasi,,450,m3,"85.000","38.250.000"',
        ));

        $this->assertSame(1, $result['created']);

        $boq = Boq::query()->sole();
        $sections = $boq->sections()->get();

        $this->assertSame(['A', 'B'], $sections->pluck('section_no')->all());
        $this->assertSame(2, $sections[0]->items()->count());
        $this->assertSame(1, $sections[1]->items()->count());
        // recalcTotals ran over the sections it just built.
        $this->assertEqualsWithDelta(43_750_000.0, (float) $sections[0]->subtotal, 0.01);
        $this->assertEqualsWithDelta(82_000_000.0, (float) $boq->total, 0.01);
    }

    /**
     * There is exactly one way to be wrong about where a line belongs, and it
     * says so instead of inventing a section.
     */
    public function test_an_item_before_any_section_is_refused(): void
    {
        $preview = $this->imports->preview('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Tanpa bagian,,,,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,1500,m2,12500,18750000',
        ));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('sebelum baris bagian', $preview['documents'][0]['rows'][0]['errors'][0]);
        $this->assertSame(0, Boq::query()->count());
    }

    /**
     * The line that makes the whole feature worth building: an AHSP code and a
     * volume, and BoqService::addItem fills in the description, the unit and the
     * price from the analysis.
     */
    public function test_an_item_carrying_only_an_analysis_code_and_a_volume_is_priced_from_the_analysis(): void
    {
        $ahsp = Ahsp::query()->create([
            'code' => 'A.4.3.1.3', 'name' => 'Beton ready mix K-300', 'unit' => 'm3',
            'category' => 'sipil', 'overhead_pct' => 10, 'unit_price' => 1_150_000,
        ]);

        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Dari AHSP,,,,,,',
            'bagian,RAB-GRAHA,,,B,Pekerjaan Struktur,,,',
            'item,RAB-GRAHA,,,B.1,,A.4.3.1.3,120,,,',
        ));

        $item = BoqItem::query()->sole();

        $this->assertSame($ahsp->id, (int) $item->ahsp_id);
        $this->assertSame('Beton ready mix K-300', $item->description);
        $this->assertSame('m3', $item->unit);
        $this->assertEqualsWithDelta(138_000_000.0, (float) $item->amount, 0.01);
    }

    /** A section with nothing under it is legal, and worth saying out loud. */
    public function test_a_section_with_no_items_is_a_warning_not_a_refusal(): void
    {
        $preview = $this->imports->preview('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Bagian kosong,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,1500,m2,12500,18750000',
            'bagian,RAB-GRAHA,,,B,Belum diisi,,,',
        ));

        $this->assertTrue($preview['documents'][0]['valid']);
        $this->assertStringContainsString('tanpa satu pun baris', implode(' ', $preview['documents'][0]['warnings']));
    }

    /** A cross-module code that resolves to nothing refuses; it is never nulled. */
    public function test_an_unknown_project_code_refuses_the_boq(): void
    {
        DB::table('prj_projects')->insert([
            'code' => 'PRJ-2026-001', 'name' => 'Graha Sentosa', 'type' => 'building',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $good = $this->imports->preview('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-A,Proyek ada,PRJ-2026-001,,,,,',
            'bagian,RAB-A,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-A,,,A.1,Pembersihan lahan,,1500,m2,12500,18750000',
        ));

        $bad = $this->imports->preview('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-B,Proyek tidak ada,PRJ-TIDAK-ADA,,,,,',
            'bagian,RAB-B,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-B,,,A.1,Pembersihan lahan,,1500,m2,12500,18750000',
        ));

        $this->assertTrue($good['documents'][0]['valid']);
        $this->assertSame('PRJ-2026-001', $good['documents'][0]['header']['proyek_kode']);
        $this->assertFalse($bad['documents'][0]['valid']);
        $this->assertStringContainsString('PRJ-TIDAK-ADA', implode(' ', $bad['documents'][0]['errors']));
    }

    /**
     * A scoped lookup: the same line code means different things in different
     * parent documents, so it is resolved inside this document's own parent.
     *
     * This is what RAP is built on — est_boq_items.wbs_code is unique to nobody,
     * and a cost line bound to the wrong BOQ item understates one work package
     * and overstates another, with the RAP total still correct and every
     * variance report against it wrong forever.
     */
    public function test_a_scoped_lookup_resolves_inside_its_own_parent_document(): void
    {
        $this->twoBoqsSharingAWbsCode();
        $imports = $this->scopedLookupImporter();

        $inA = $imports->preview('boq-uji', 'b.csv', $this->scopedFile('RAB-A', 'A.1'));
        $inB = $imports->preview('boq-uji', 'b.csv', $this->scopedFile('RAB-B', 'A.1'));

        $this->assertTrue($inA['documents'][0]['valid']);
        $this->assertTrue($inB['documents'][0]['valid']);
        $this->assertNotSame(
            $this->boqItemId('RAB-A', 'A.1'),
            $this->boqItemId('RAB-B', 'A.1'),
            'the fixture is only meaningful while the two codes are genuinely different rows',
        );
    }

    public function test_a_line_code_that_is_not_in_this_parent_refuses_the_whole_document(): void
    {
        $this->twoBoqsSharingAWbsCode();

        $preview = $this->scopedLookupImporter()->preview('boq-uji', 'b.csv', $this->scopedFile('RAB-A', 'Z.9'));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('Z.9', json_encode($preview['documents'][0]['rows']));
    }

    /** Binding to the first of two matches is a silent mis-posting; it says so instead. */
    public function test_an_ambiguous_line_code_is_refused_rather_than_bound_to_the_first_match(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-A,BOQ RAB-A,,,,,,',
            'bagian,RAB-A,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-A,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
            'item,RAB-A,,,A.1,Pembersihan lahan lagi,,1,m2,1000,1000',
        ));

        $preview = $this->scopedLookupImporter()->preview('boq-uji', 'b.csv', $this->scopedFile('RAB-A', 'A.1'));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('cocok dengan 2 baris', json_encode($preview['documents'][0]['rows']));
    }

    // ------------------------------------------- the group column as the code

    /**
     * AHSP's shape, and the only one of the four where the group column IS the
     * stored code: an analysis code is a domain code (SNI A.2.3.1.1) that the
     * estimator supplies, not a number a sequence assigns. That makes the import
     * fully idempotent — the same book uploaded twice is the same book — and it
     * is the case the update_rules hook exists for, because the analysis would
     * otherwise trip its own Rule::unique on its own code.
     */
    public function test_a_book_of_analyses_re_imported_updates_itself_rather_than_duplicating(): void
    {
        $book = $this->ahspFile(
            'analisa,A.2.3.1.1,Galian tanah biasa sedalam 1 m,m3,sipil,10,,,,',
            'komponen,A.2.3.1.1,Pekerja,OH,,,upah,"0,750","150.000",',
            'komponen,A.2.3.1.1,Mandor,OH,,,upah,"0,025","200.000",',
            'analisa,A.4.3.1.3,Beton ready mix K-300,m3,sipil,10,,,,',
            'komponen,A.4.3.1.3,Beton ready mix K-300,m3,,,bahan,"1,020","1.050.000",',
        );

        $first = $this->imports->commit('ahsp-uji', 'ahsp.csv', $book);
        $second = $this->imports->commit('ahsp-uji', 'ahsp.csv', $book);

        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['updated']);
        $this->assertSame(2, Ahsp::query()->count());

        // recalcUnitPrice ran: (0,750 x 150.000 + 0,025 x 200.000) x 1,10.
        $galian = Ahsp::query()->where('code', 'A.2.3.1.1')->sole();
        $this->assertSame(2, $galian->components()->count());
        $this->assertEqualsWithDelta(129_250.0, (float) $galian->unit_price, 0.01);
    }

    /** upah / bahan / alat are the words the AHSP book itself uses. */
    public function test_the_indonesian_words_for_the_component_types_are_accepted(): void
    {
        $this->imports->commit('ahsp-uji', 'ahsp.csv', $this->ahspFile(
            'analisa,A.1,Pasangan bata merah,m2,sipil,10,,,,',
            'komponen,A.1,Tukang batu,OH,,,upah,"0,100","180.000",',
            'komponen,A.1,Bata merah,bh,,,bahan,"70,000","1.500",',
            'komponen,A.1,Molen,hari,,,alat,"0,050","350.000",',
        ));

        $this->assertSame(
            ['labor', 'material', 'equipment'],
            Ahsp::query()->sole()->components()->pluck('component_type')->map(fn ($type) => $type->value)->all(),
        );
    }

    /** A coefficient is a ratio: a lone dot is a decimal mark, end to end. */
    public function test_a_coefficient_survives_the_whole_pipeline_unmultiplied(): void
    {
        $this->imports->commit('ahsp-uji', 'ahsp.csv', $this->ahspFile(
            'analisa,A.1,Galian tanah biasa,m3,sipil,0,,,,',
            'komponen,A.1,Pekerja,OH,,,upah,1.050,"100.000",',
        ));

        $this->assertEqualsWithDelta(1.05, (float) Ahsp::query()->sole()->components()->value('coefficient'), 0.000001);
        $this->assertEqualsWithDelta(105_000.0, (float) Ahsp::query()->value('unit_price'), 0.01);
    }

    // ----------------------------------------------------- template / export

    public function test_the_template_names_every_row_type_and_what_each_one_needs(): void
    {
        $template = $this->imports->template('boq-uji');

        $this->assertStringStartsWith('tipe,dokumen,judul', $template);
        $this->assertStringContainsString('# tipe: dokumen =', $template);
        $this->assertStringContainsString('abaikan = baris subtotal', $template);
        $this->assertStringContainsString('# wajib pada baris bagian: nomor, uraian', $template);
    }

    /** The template's own comment lines must survive a round trip. */
    public function test_the_template_can_be_filled_in_and_sent_straight_back(): void
    {
        $filled = $this->imports->template('boq-uji')
            ."dokumen,RAB-DARI-TEMPLATE,Dari template,,,,,,\n"
            ."bagian,RAB-DARI-TEMPLATE,,,A,Pekerjaan Persiapan,,,\n"
            ."item,RAB-DARI-TEMPLATE,,,A.1,Pembersihan lahan,,1,m2,1000,1000\n";

        $result = $this->imports->commit('boq-uji', 'boq.csv', base64_encode($filled));

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['skipped'], 'the "# tipe:" hint lines must not be read as rows');
    }

    /**
     * Export, edit in Excel, import back. Because the exported group column
     * carries the real code, the round trip is always an update in place.
     */
    public function test_the_export_round_trips_back_through_the_importer(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Gedung Kantor Graha Sentosa,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,"1.500",m2,"12.500","18.750.000"',
        ));

        $exported = $this->imports->export('boq-uji');
        $result = $this->imports->commit('boq-uji', 'boq.csv', base64_encode($exported));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, Boq::query()->count());
        $this->assertEqualsWithDelta(18_750_000.0, (float) Boq::query()->value('total'), 0.01);
    }

    /** One document at a time, so an operator can fix one BOQ without touching the rest. */
    public function test_the_export_can_be_narrowed_to_one_document_or_one_status(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-A,BOQ pertama,,,,,,',
            'bagian,RAB-A,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-A,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
            'dokumen,RAB-B,BOQ kedua,,,,,,',
            'bagian,RAB-B,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-B,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
        ));

        $first = Boq::query()->where('title', 'BOQ pertama')->sole();

        $one = $this->imports->export('boq-uji', ['kode' => $first->code]);
        $this->assertStringContainsString('BOQ pertama', $one);
        $this->assertStringNotContainsString('BOQ kedua', $one);

        $this->assertStringContainsString('BOQ kedua', $this->imports->export('boq-uji', ['status' => 'draft']));
        $this->assertStringNotContainsString('BOQ kedua', $this->imports->export('boq-uji', ['status' => 'approved']));
    }

    /**
     * The two hooks the shipped definitions need and the engine cannot guess:
     * arithmetic only that document understands, and refusals only that module
     * knows about — a BOQ whose sections a RAP and a WBS already point at cannot
     * be replaced wholesale, and that is Estimation's knowledge, not Core's.
     */
    public function test_a_definition_may_add_its_own_arithmetic_check_and_its_own_blockers(): void
    {
        $this->customer('CUST-1');

        $this->app->instance(ImportableDocuments::class, new class($this->fixtureRegistry()) extends ImportableDocuments
        {
            public function __construct(private readonly ImportableDocuments $base) {}

            public function all(): array
            {
                $all = $this->base->all();

                $all['penawaran-uji']['checks'] = fn (array $payload, ?object $target): array => [
                    'errors' => count($payload['items'] ?? []) > 1 ? ['lebih dari satu baris tidak diizinkan oleh aturan modul ini.'] : [],
                    'warnings' => ['pemeriksaan khusus modul dijalankan.'],
                ];

                $all['penawaran-uji']['blockers'] = fn (object $target): array => ['dokumen ini dikunci oleh modul pemiliknya.'];

                return $all;
            }
        });

        $imports = app(DocumentImportService::class);

        $preview = $imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Dua baris,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Baris pertama,1,ls,1000,1000',
            'item,PNW-A,,,,,Baris kedua,1,ls,1000,1000',
        ));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('aturan modul ini', implode(' ', $preview['documents'][0]['errors']));
        $this->assertStringContainsString('khusus modul', implode(' ', $preview['documents'][0]['warnings']));

        // blockers only speak about a document that already exists.
        $imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-B,CUST-1,Satu baris,konstruksi,11,,,,,',
            'item,PNW-B,,,,,Baris pertama,1,ls,1000,1000',
        ));

        $code = Quotation::query()->value('code');
        $blocked = $imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            "dokumen,{$code},CUST-1,Coba diubah,konstruksi,11,,,,,",
            "item,{$code},,,,,Baris pertama,1,ls,2000,2000",
        ));

        $this->assertSame(0, $blocked['updated']);
        $this->assertStringContainsString('dikunci', implode(' ', $blocked['documents'][0]['errors']));
    }

    /** Excel executes a cell that starts with "=", and an exported title is text somebody typed. */
    public function test_the_export_neutralises_a_cell_that_would_run_as_a_formula(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,"=HYPERLINK(""http://jahat.example"",""klik"")",,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
        ));

        $this->assertStringContainsString("'=HYPERLINK", $this->imports->export('boq-uji'));
    }

    // ------------------------------------------------------------- the file

    /** A .xlsx is what an estimator actually has. */
    public function test_a_real_xlsx_workbook_is_read(): void
    {
        $this->customer('CUST-1');

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['RENCANA ANGGARAN BIAYA'],
            [],
            ['tipe', 'dokumen', 'pelanggan_kode', 'judul', 'lingkup', 'ppn_persen', 'uraian', 'volume', 'satuan', 'harga_satuan', 'jumlah'],
            ['dokumen', 'PNW-A', 'CUST-1', 'Dari Excel', 'konstruksi', 11],
            ['item', 'PNW-A', null, null, null, null, 'Pekerjaan persiapan', 2, 'ls', 1500000, 3000000],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $content = base64_encode((string) file_get_contents($path));
        @unlink($path);

        $result = $this->imports->commit('penawaran-uji', 'penawaran.xlsx', $content);

        $this->assertSame(1, $result['created']);
        $this->assertEqualsWithDelta(3_000_000.0, (float) Quotation::query()->value('subtotal'), 0.01);
    }

    public function test_an_unreadable_file_type_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Format berkas tidak didukung/');

        $this->imports->preview('penawaran-uji', 'boq.pdf', $this->quotationFile('dokumen,PNW-A,CUST-1,x,konstruksi,11,,,,,'));
    }

    // -------------------------------------------------------------- registry

    /**
     * Reusing a module's FormRequest couples Core to a class it does not own.
     * AhspUpdateRequest::rules() reads $this->route('ahsp') and would fatal if it
     * were instantiated bare; only the Store requests are safe. If somebody later
     * adds a $this->input() to one of them, this test is what breaks — not a live
     * import at a customer's go-live.
     */
    public function test_every_registered_definition_is_well_formed_and_its_request_readable(): void
    {
        $registry = new ImportableDocuments;

        foreach ($registry->keys() as $key) {
            $definition = $registry->definition($key);

            $this->assertIsCallable($definition['create'], "{$key}: create harus callable");
            $this->assertIsCallable($definition['update'], "{$key}: update harus callable");
            $this->assertNotSame('', $definition['permission']);

            if ($definition['request'] !== null) {
                $this->assertIsArray((new $definition['request'])->rules(), "{$key}: rules() harus terbaca tanpa request hidup");
            }
        }

        // The fixture definitions go through exactly the same gate.
        foreach ($this->fixtureRegistry()->keys() as $key) {
            $this->assertIsArray($this->fixtureRegistry()->definition($key));
        }
    }

    public function test_a_malformed_definition_is_refused_by_name(): void
    {
        $registry = new class extends ImportableDocuments
        {
            public function all(): array
            {
                return ['rusak' => ['label' => 'Rusak', 'permission' => 'est', 'model' => Boq::class, 'group' => 'dokumen', 'rows' => [], 'create' => fn () => null, 'update' => fn () => null]];
            }
        };

        $this->expectExceptionMessageMatches('/tepat satu baris berperan header/');

        $registry->definition('rusak');
    }

    // ------------------------------------------------------------- endpoints

    public function test_the_endpoint_previews_then_imports(): void
    {
        $this->customer('CUST-1');
        $admin = $this->adminUser();

        $payload = ['filename' => 'penawaran.csv', 'content' => $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Lewat API,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        )];

        $this->actingAs($admin)->postJson('/api/core/document-import/penawaran-uji/preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.summary.to_create', 1);

        $this->assertSame(0, Quotation::query()->count(), 'a preview writes nothing');

        $this->actingAs($admin)->postJson('/api/core/document-import/penawaran-uji/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', 1);
    }

    public function test_the_template_and_export_endpoints_serve_csv(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get('/api/core/document-import/boq-uji/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->actingAs($admin)->get('/api/core/document-import/boq-uji/export')->assertOk();
    }

    public function test_an_unknown_resource_is_a_not_found(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/api/core/document-import/kucing/template')
            ->assertNotFound();
    }

    /**
     * An import both creates and updates, so it needs both rights. Somebody who
     * may only create must not be able to rewrite an estimator's BOQ by
     * uploading a sheet.
     */
    public function test_importing_needs_update_as_well_as_create(): void
    {
        $this->customer('CUST-1');

        $this->actingAs($this->userWithOnly(['crm.view', 'crm.create']))
            ->postJson('/api/core/document-import/penawaran-uji/import', [
                'filename' => 'p.csv',
                'content' => $this->quotationFile(
                    'dokumen,PNW-A,CUST-1,Menyelinap,konstruksi,11,,,,,',
                    'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
                ),
            ])
            ->assertForbidden();

        $this->assertSame(0, Quotation::query()->count());
    }

    /**
     * A refusal has to name what it refused.
     *
     * guarded() picks mayRead for the template and the export and mayImport for
     * the upload, then answered both with the one sentence "Anda tidak memiliki
     * izin untuk mengimpor dokumen ini." — so somebody refused a TEMPLATE
     * DOWNLOAD for lacking crm.view was sent to ask for crm.create and
     * crm.update, neither of which would have opened the door.
     */
    public function test_a_refusal_says_which_of_the_two_things_was_refused(): void
    {
        $user = $this->userWithOnly(['est.view']);

        $this->actingAs($user)
            ->get('/api/core/document-import/penawaran-uji/template')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengunduh template atau ekspor dokumen ini.');

        $this->actingAs($user)
            ->get('/api/core/document-import/penawaran-uji/export')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengunduh template atau ekspor dokumen ini.');

        $this->actingAs($user)
            ->postJson('/api/core/document-import/penawaran-uji/preview', ['filename' => 'p.csv', 'content' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengimpor dokumen ini.');
    }

    /** Each document type declares its own permission, and holding the other is no help. */
    public function test_the_list_shows_only_the_documents_the_caller_may_read(): void
    {
        $user = $this->userWithOnly(['est.view']);

        $response = $this->actingAs($user)
            ->getJson('/api/core/document-import')
            ->assertOk();

        $this->assertSame(['boq-uji', 'ahsp-uji'], array_column($response->json('data'), 'key'));
        $this->assertFalse($response->json('data.0.can_import'), 'view alone is not enough to import');

        // Holding the other module's rights is no help at all.
        $this->actingAs($user)
            ->get('/api/core/document-import/penawaran-uji/template')
            ->assertForbidden();
    }

    // ---------------------------------------------- maker-checker on import

    /**
     * Measured on production 4 Sep 2026 (HASIL-UJI §6 P-3): a PR that reached
     * `submitted` without submit() carried no `submitted` row, so maker-checker
     * saw no maker and its own requester approved it. A file is that path in
     * another coat — the module's service is handed the payload and may leave
     * the document submitted with nobody having clicked Ajukan. The engine
     * knows who uploaded the file, so the row names the importer, and the
     * guard refuses the importer one screen later.
     */
    public function test_a_document_landed_as_submitted_names_the_importer_as_its_maker(): void
    {
        $this->customer('CUST-1');
        $importer = $this->adminUser();
        $other = $this->userWithOnly(['crm.approve']);

        $result = $this->imports->commit('penawaran-diajukan-uji', 'penawaran.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Diajukan lewat berkas,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ), $importer);

        $this->assertSame(1, $result['created']);

        $quotation = Quotation::query()->sole();
        $this->assertSame(DocumentStatus::Submitted, $quotation->status);
        $this->assertSame(
            [['submitted', $importer->id]],
            $quotation->approvals()->get()->map(fn ($row) => [$row->action, (int) $row->user_id])->all(),
        );
        $this->assertSame($importer->id, SegregationOfDuties::submitterIdOf($quotation));

        try {
            $quotation->approve($importer);
            $this->fail('The importer asserted the document and must not approve it.');
        } catch (SelfApprovalException $e) {
            $this->assertStringContainsString($importer->name, $e->getMessage());
        }

        $quotation->approve($other);
        $this->assertSame(DocumentStatus::Approved, $quotation->fresh()->status);
    }

    /** A draft asserts nothing, so nothing is recorded — the pre-existing path, pinned. */
    public function test_a_document_landed_as_a_draft_records_no_submission(): void
    {
        $this->customer('CUST-1');
        $importer = $this->adminUser();

        $this->imports->commit('penawaran-uji', 'penawaran.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Masih draf,konstruksi,11,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ), $importer);

        $quotation = Quotation::query()->sole();
        $this->assertSame(DocumentStatus::Draft, $quotation->status);
        $this->assertSame(0, $quotation->approvals()->count());
    }

    /** The endpoint hands the logged-in user to the engine — the row is the importer's, not nobody's. */
    public function test_the_endpoint_names_the_logged_in_user_as_the_importer(): void
    {
        $this->customer('CUST-1');
        $admin = $this->adminUser();

        $this->actingAs($admin)->postJson('/api/core/document-import/penawaran-diajukan-uji/import', [
            'filename' => 'penawaran.csv',
            'content' => $this->quotationFile(
                'dokumen,PNW-A,CUST-1,Lewat API langsung diajukan,konstruksi,11,,,,,',
                'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
            ),
        ])->assertOk()->assertJsonPath('data.created', 1);

        $this->assertSame(
            (int) $admin->id,
            (int) Quotation::query()->sole()->approvals()->where('action', 'submitted')->sole()->user_id,
        );
    }

    // ------------------------------------------- what an empty cell may write

    /**
     * A default is for a NOT NULL insert on a document being CREATED. It is not
     * a value the importer may write over one that is already stored.
     *
     * The order used to be the other way round — default first, then the
     * absent-column guard — which made the guard dead for every defaulted
     * column: a hand-made sheet with no `ppn_persen` column at all reset a
     * deliberately zero-rated penawaran to the house rate, and the same
     * mechanism zeroed a negotiated discount. No error, no warning, "1
     * diperbarui", and eleven million rupiah of difference.
     */
    public function test_a_defaulted_column_absent_from_the_sheet_never_writes_its_default(): void
    {
        $this->customer('CUST-1');
        $imports = $this->defaultedImporter();

        // An export of services, deliberately at 0%.
        $imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Ekspor jasa,konstruksi,0,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,"100.000.000","100.000.000"',
        ));

        $quotation = Quotation::query()->sole();
        $this->assertEqualsWithDelta(0.0, (float) $quotation->ppn_rate, 0.0001);

        // A partial sheet that reprices one line and never mentions ppn_persen.
        $result = $imports->commit('penawaran-uji', 'p.csv', base64_encode(
            "tipe,dokumen,pelanggan_kode,judul,lingkup,uraian,volume,satuan,harga_satuan,jumlah\n"
            ."dokumen,{$quotation->code},CUST-1,Ekspor jasa,konstruksi,,,,,\n"
            ."item,{$quotation->code},,,,Pekerjaan persiapan,1,ls,\"100.000.000\",\"100.000.000\"\n",
        ));

        $this->assertSame(1, $result['updated']);
        $this->assertEqualsWithDelta(0.0, (float) $quotation->refresh()->ppn_rate, 0.0001);
        $this->assertEqualsWithDelta(100_000_000.0, (float) $quotation->total, 0.01);
    }

    /** The half the default exists for: a blank cell in a column the file has. */
    public function test_a_default_fills_a_blank_cell_of_a_column_the_file_carries_while_creating(): void
    {
        $this->customer('CUST-1');

        $this->defaultedImporter()->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Tarif rumah,konstruksi,,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,"1.000.000","1.000.000"',
        ));

        $this->assertEqualsWithDelta(11.0, (float) Quotation::query()->value('ppn_rate'), 0.0001);
    }

    /** ...and never on an update, where there is a stored rate to protect. */
    public function test_a_blank_cell_on_an_update_does_not_fall_back_to_the_default(): void
    {
        $this->customer('CUST-1');
        $imports = $this->defaultedImporter();

        $imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Tarif dirundingkan,konstruksi,5,,,,,',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,"1.000.000","1.000.000"',
        ));

        $code = Quotation::query()->value('code');

        $imports->commit('penawaran-uji', 'p.csv', $this->quotationFile(
            "dokumen,{$code},CUST-1,Tarif dirundingkan,konstruksi,,,,,,",
            "item,{$code},,,,,Pekerjaan persiapan,2,ls,\"1.000.000\",\"2.000.000\"",
        ));

        $quotation = Quotation::query()->sole();
        $this->assertEqualsWithDelta(5.0, (float) $quotation->ppn_rate, 0.0001, 'a blank cell is not a request for the house rate');
        $this->assertEqualsWithDelta(2_000_000.0, (float) $quotation->subtotal, 0.01, 'the update itself still landed');
    }

    /**
     * The template's own documented update route: download the template — which
     * carries every column — put the existing number in `dokumen`, retype the
     * rows being changed, and leave the rest blank because they are not
     * changing. That used to detach the BOQ from its project.
     */
    public function test_a_blank_cell_on_an_update_leaves_a_stored_link_alone(): void
    {
        $projectId = $this->project('PRJ-2026-001');

        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Gedung Kantor Graha Sentosa,PRJ-2026-001,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
        ));

        $boq = Boq::query()->sole();
        $this->assertSame($projectId, (int) $boq->project_id);

        $result = $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            "dokumen,{$boq->code},Gedung Kantor Graha Sentosa,,,,,,",
            "bagian,{$boq->code},,,A,Pekerjaan Persiapan,,,",
            "item,{$boq->code},,,A.1,Pembersihan lahan,,2,m2,1000,2000",
        ));

        $this->assertSame(1, $result['updated']);
        $this->assertSame($projectId, (int) $boq->refresh()->project_id, 'a blank cell must not detach the BOQ from its project');
        $this->assertEqualsWithDelta(2_000.0, (float) $boq->total, 0.01, 'the reprice itself still landed');
    }

    /** Clearing a value is an instruction, and a column may declare it as one. */
    public function test_a_column_the_registry_marks_clearable_is_cleared_by_a_blank_cell(): void
    {
        $this->project('PRJ-2026-001');
        $imports = $this->clearableProjectImporter();

        $imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Gedung Kantor Graha Sentosa,PRJ-2026-001,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
        ));

        $boq = Boq::query()->sole();

        $imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            "dokumen,{$boq->code},Gedung Kantor Graha Sentosa,,,,,,",
            "bagian,{$boq->code},,,A,Pekerjaan Persiapan,,,",
            "item,{$boq->code},,,A.1,Pembersihan lahan,,1,m2,1000,1000",
        ));

        $this->assertNull($boq->refresh()->project_id);
    }

    /** And the template says which of the two a blank cell is. */
    public function test_the_template_says_what_an_empty_cell_means(): void
    {
        $this->assertStringContainsString(
            '# pada dokumen yang SUDAH ADA: sel kosong tidak mengubah nilai tersimpan',
            $this->imports->template('boq-uji'),
        );

        $this->assertStringContainsString(
            'kecuali proyek_kode, yang justru dikosongkan',
            $this->clearableProjectImporter()->template('boq-uji'),
        );
    }

    // ------------------------------------------------ what an update destroys

    /**
     * A bagian is a heading, not a line. Counting it as a detail row let a file
     * carrying section titles and no work items satisfy the only substance
     * check there was: `updated: 1`, `refused: 0`, items 0, total 0,00 — a live
     * BOQ emptied and reported as a success.
     */
    public function test_a_file_of_headings_with_no_work_rows_never_empties_a_document(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Gedung Kantor Graha Sentosa,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,"1.500",m2,"12.500","18.750.000"',
            'item,RAB-GRAHA,,,A.2,Direksi keet,,1,ls,"25.000.000","25.000.000"',
        ));

        $boq = Boq::query()->sole();

        $result = $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            "dokumen,{$boq->code},Gedung Kantor Graha Sentosa,,,,,,",
            "bagian,{$boq->code},,,A,Pekerjaan Persiapan,,,",
        ));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('seluruh isinya akan terhapus', implode(' ', $result['documents'][0]['errors']));
        $this->assertSame(2, $boq->refresh()->items()->count());
        $this->assertEqualsWithDelta(43_750_000.0, (float) $boq->total, 0.01);
    }

    /** The same rule on a create: a document of headings alone is not a document. */
    public function test_a_new_document_of_headings_alone_is_refused(): void
    {
        $preview = $this->imports->preview('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Hanya judul bagian,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
        ));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('tanpa satu pun baris rincian', implode(' ', $preview['documents'][0]['errors']));
    }

    /**
     * An update replaces the lines wholesale and nothing compared the incoming
     * sheet against the document it overwrites. An estimator who filtered the
     * export in Excel and copied the visible rows got action=update, valid=true,
     * errors=[] and warnings=[] — literally empty — over three sections and
     * three items worth 1,3 milyar. There is no undo.
     */
    public function test_the_preview_says_how_many_lines_an_update_would_delete(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Gedung Kantor Graha Sentosa,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,"1.500",m2,"12.500","18.750.000"',
            'item,RAB-GRAHA,,,A.2,Direksi keet,,1,ls,"25.000.000","25.000.000"',
            'bagian,RAB-GRAHA,,,B,Pekerjaan Struktur,,,',
            'item,RAB-GRAHA,,,B.1,Galian tanah pondasi,,450,m3,"85.000","38.250.000"',
        ));

        $boq = Boq::query()->sole();

        $file = $this->boqFile(
            "dokumen,{$boq->code},Gedung Kantor Graha Sentosa,,,,,,",
            "bagian,{$boq->code},,,A,Pekerjaan Persiapan,,,",
            "item,{$boq->code},,,A.1,Pembersihan lahan,,\"1.500\",m2,\"12.500\",\"18.750.000\"",
        );

        $preview = $this->imports->preview('boq-uji', 'boq.csv', $file);
        $document = $preview['documents'][0];

        $this->assertTrue($document['valid'], 'a shrinking sheet is legal — it just has to be visible');
        $this->assertSame(3, $document['replaces']['lines']);
        $this->assertSame(1, $document['replaces']['incoming_lines']);
        $this->assertSame(2, $document['replaces']['deleted']);
        $this->assertEqualsWithDelta(82_000_000.0, $document['replaces']['total'], 0.01);
        $this->assertStringContainsString('2 baris akan DIHAPUS', implode(' ', $document['warnings']));

        // And it survives the commit, which used to report only refusals — so
        // the one warning that mattered was thrown away by the call that made
        // it true.
        $result = $this->imports->commit('boq-uji', 'boq.csv', $file);

        $this->assertSame(1, $result['updated']);
        $this->assertStringContainsString('2 baris akan DIHAPUS', implode(' ', $result['warnings']));
    }

    /** A create has nothing to replace, and says so rather than guessing. */
    public function test_a_create_reports_no_replacement_at_all(): void
    {
        $preview = $this->imports->preview('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-BARU,BOQ baru,,,,,,',
            'bagian,RAB-BARU,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-BARU,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
        ));

        $this->assertNull($preview['documents'][0]['replaces']);
    }

    // --------------------------------------------------------- the file again

    /**
     * "0.750" is three quarters, not seven hundred and fifty.
     *
     * No Indonesian sheet writes 750 as 0.750, but every English-locale export
     * writes three quarters of a cubic metre exactly that way — and \d{1,3}
     * matched the leading zero group, so 0,75 m3 of concrete was stored as 750
     * and the BOQ footed perfectly at a thousand times the money.
     */
    public function test_a_leading_zero_group_is_a_decimal_and_not_a_thousands_group(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Angka desimal,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Struktur,,,',
            'item,RAB-GRAHA,,,A.1,Beton K-300,,"0.750",m3,"1.350.000","1.012.500"',
            'item,RAB-GRAHA,,,A.2,Pembersihan lahan,,"1.500",m2,"12.500","18.750.000"',
        ));

        $items = BoqItem::query()->orderBy('wbs_code')->get();

        $this->assertEqualsWithDelta(0.75, (float) $items[0]->qty, 0.000001);
        // The deliberate rule this must not disturb: a grouped dot in a volume
        // column is still thousands.
        $this->assertEqualsWithDelta(1500.0, (float) $items[1]->qty, 0.000001);
    }

    /**
     * csvCell prefixes an apostrophe to a cell Excel would run as a formula.
     * Nothing took it off again, so one round trip through the endpoint that
     * exists FOR round trips permanently rewrote every description, section
     * name and code beginning with = + - or @ — and dash-led bullets are
     * ubiquitous in an Indonesian BOQ.
     */
    public function test_the_export_round_trip_keeps_a_dash_led_description_intact(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,Uraian berawalan tanda,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,- Pembersihan lahan,,1,m2,1000,1000',
            'item,RAB-GRAHA,,,A.2,+ Urugan kembali,,1,m3,2000,2000',
        ));

        $exported = $this->imports->export('boq-uji');

        // The guard is still written — Excel must not run the cell.
        $this->assertStringContainsString("'- Pembersihan lahan", $exported);

        $result = $this->imports->commit('boq-uji', 'boq.csv', base64_encode($exported));

        $this->assertSame(1, $result['updated']);
        $this->assertSame(
            ['- Pembersihan lahan', '+ Urugan kembali'],
            BoqItem::query()->orderBy('wbs_code')->pluck('description')->all(),
        );
    }

    /**
     * A BOQ item priced from an AHSP analysis carries no unit price in the
     * sheet — it is costed on commit. The preview used to print a confident
     * "Rp 0" for the whole bill directly underneath a warning naming the real
     * figure, so one card showed two totals that contradicted each other.
     */
    public function test_a_boq_priced_from_an_analysis_reports_no_total_rather_than_zero(): void
    {
        // The analysis must exist, or the line is refused before it is ever priced.
        $this->imports->commit('ahsp-uji', 'ahsp.csv', $this->ahspFile(
            'analisa,A.2.3.1.1,Galian tanah biasa sedalam 1 m,m3,sipil,10,,,,',
            'komponen,A.2.3.1.1,Pekerja,OH,,,upah,"0,750","150.000",',
        ));

        $preview = $this->imports->preview('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-AHSP,Dari analisa,,,,,,',
            'bagian,RAB-AHSP,,,A,Pekerjaan Tanah,,,',
            'item,RAB-AHSP,,,A.1,Galian tanah,A.2.3.1.1,120,m3,,',
        ));

        $totals = $preview['documents'][0]['totals'];

        // The one line the sheet prices is none: the whole bill comes from the
        // analysis, so the partial sum is 0 — but unpriced_lines says WHY, and
        // the screen labels it rather than calling it the total.
        $this->assertEqualsWithDelta(0.0, (float) $totals['computed_total'], 0.01);
        $this->assertSame(1, $totals['unpriced_lines']);
    }

    /**
     * The guard and its inverse must be a true pair.
     *
     * On INPUT a leading apostrophe is genuinely ambiguous — Excel itself
     * strips one, so a hand-made file cannot say whether it meant the
     * character or the escape. What must hold is the ROUND TRIP: whatever is
     * STORED comes back byte-identical after export and re-import. Without
     * doubling the apostrophe on write, a stored "'- Galian" came back
     * "- Galian" and lost a character on every cycle.
     */
    public function test_a_stored_apostrophe_survives_the_export_round_trip(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-APOS,Apostrof asli,,,,,,',
            'bagian,RAB-APOS,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-APOS,,,A.1,Galian tanah,,1,m3,1000,1000',
        ));

        // Store the awkward value the way a user editing the record would.
        BoqItem::query()->where('wbs_code', 'A.1')->update(['description' => "'- Galian tanah"]);

        $exported = $this->imports->export('boq-uji');
        $this->imports->commit('boq-uji', 'boq.csv', base64_encode($exported));

        $this->assertSame(
            ["'- Galian tanah"],
            BoqItem::query()->where('wbs_code', 'A.1')->pluck('description')->all(),
            'a stored leading apostrophe must survive an export/import round trip',
        );
    }

    /**
     * A description pasted out of Word carries a newline inside its quoted cell.
     * Splitting the file on newlines before parsing cut that cell in two, so one
     * logical row became two physical ones and every column after it shifted —
     * the row was refused for cells that were plainly filled.
     */
    public function test_a_newline_inside_a_quoted_cell_is_one_row_and_not_two(): void
    {
        $result = $this->imports->commit('boq-uji', 'boq.csv', base64_encode(
            "tipe,dokumen,judul,proyek_kode,nomor,uraian,ahsp_kode,volume,satuan,harga_satuan,jumlah\n"
            ."dokumen,RAB-GRAHA,Uraian dua baris,,,,,,\n"
            ."bagian,RAB-GRAHA,,,A,Pekerjaan ELV,,,\n"
            ."item,RAB-GRAHA,,,A.1,\"Kamera IP dome 4MP\n(termasuk bracket)\",,24,unit,\"4.250.000\",\"102.000.000\"\n",
        ));

        $this->assertSame(1, $result['created'], json_encode($result['documents']));
        $this->assertSame(1, BoqItem::query()->count());
        $this->assertSame("Kamera IP dome 4MP\n(termasuk bracket)", BoqItem::query()->value('description'));
    }

    /**
     * readCsv dropped blank lines while every refusal names "baris N" derived
     * from the grid index, so one blank separator row — normal between two
     * documents in a workbook — pointed every later message at the wrong row,
     * and the drift compounded with each one.
     */
    public function test_a_blank_line_does_not_shift_the_reported_line_numbers(): void
    {
        $preview = $this->imports->preview('boq-uji', 'boq.csv', base64_encode(
            "tipe,dokumen,judul,proyek_kode,nomor,uraian,ahsp_kode,volume,satuan,harga_satuan,jumlah\n"
            ."\n"
            ."dokumen,RAB-GRAHA,Ada baris kosong,,,,,,\n"
            ."bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,\n"
            ."\n"
            ."item,RAB-GRAHA,,,A.1,Pembersihan lahan,,dua puluh,m2,1000,1000\n",
        ));

        $document = $preview['documents'][0];

        $this->assertSame(3, $document['line'], 'the dokumen row is on line 3 of the file');
        $this->assertSame(4, $document['rows'][0]['line']);
        $this->assertSame(6, $document['rows'][1]['line'], 'the refused item is on line 6, not line 4');
        $this->assertFalse($document['rows'][1]['valid']);
    }

    // ------------------------------------------------------------- the bounds

    /**
     * The cap used to sit at the bottom of the typed branch, so a row whose
     * `tipe` nobody recognised was pushed and returned above it: 20.000 garbage
     * rows sailed past the 5.000 limit, each carrying a 200-character message
     * and its whole cell map, and the preview answered with a multi-megabyte
     * JSON body. A file is bounded by the rows it contains.
     */
    /**
     * A real RAB carries one SUB TOTAL row per section plus a rekapitulasi
     * block. Those rows allocate nothing — counting them against the record
     * budget refused ordinary contractor files at the door, which is the work
     * this importer exists to accept.
     */
    public function test_subtotal_rows_do_not_spend_the_record_budget(): void
    {
        $this->customer('CUST-1');

        $rows = ['dokumen,PNW-A,CUST-1,Banyak subtotal,konstruksi,11,,,,,'];

        // Right up to the record cap in real lines, plus a pile of subtotals.
        for ($index = 0; $index < SpreadsheetReader::MAX_ROWS - 1; $index++) {
            $rows[] = 'item,PNW-A,,,,,Baris nyata,1,ls,1000,1000';
            if ($index % 5 === 0) {
                $rows[] = 'abaikan,PNW-A,,,,,SUB TOTAL,,,,';
            }
        }

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(...$rows));

        // 1.000 subtotal rows rode along without consuming the budget.
        $this->assertSame([], $preview['errors'] ?? []);
        $this->assertSame(SpreadsheetReader::MAX_ROWS - 1, $preview['summary']['lines_read']);
    }

    public function test_the_row_cap_holds_for_rows_the_engine_could_not_type(): void
    {
        $this->customer('CUST-1');

        $rows = ['dokumen,PNW-A,CUST-1,Banjir baris,konstruksi,11,,,,,'];

        for ($index = 0; $index < SpreadsheetReader::MAX_ROWS; $index++) {
            $rows[] = 'xx,PNW-A,,,,,Baris tak bertipe,1,ls,1000,1000';
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi 5\.000 baris isi/');

        $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(...$rows));
    }

    /** Below the bound the same rows are reported one by one, as before. */
    public function test_untyped_rows_below_the_cap_are_still_reported_row_by_row(): void
    {
        $this->customer('CUST-1');

        $preview = $this->imports->preview('penawaran-uji', 'p.csv', $this->quotationFile(
            'dokumen,PNW-A,CUST-1,Beberapa baris salah,konstruksi,11,,,,,',
            'xx,PNW-A,,,,,Baris tak bertipe,1,ls,1000,1000',
            'item,PNW-A,,,,,Pekerjaan persiapan,1,ls,1000,1000',
        ));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString('tidak dikenali', $preview['documents'][0]['rows'][0]['errors'][0]);
    }

    /**
     * The row bound has to be applied while the workbook is being BUILT.
     * Applied to a grid PhpSpreadsheet had already materialised in full, a
     * valid 901 KB .xlsx of 120.000 rows — inside the 5 MB upload cap —
     * expanded to 136 MB of PHP arrays and held a worker for 23 seconds before
     * anything counted a row.
     *
     * Asserted against the READER and not through the importer on purpose: the
     * importer's own row cap would refuse this file too, and would have done so
     * before the fix as well, so only the reader can show that the workbook is
     * never built in the first place.
     */
    public function test_a_workbook_taller_than_the_row_cap_is_refused_while_it_is_read(): void
    {
        $rows = [['tipe', 'dokumen', 'pelanggan_kode', 'judul', 'lingkup', 'ppn_persen', 'uraian', 'volume', 'satuan', 'harga_satuan', 'jumlah']];

        // Past the PHYSICAL ceiling, which is deliberately far above the record
        // cap so a real RAB's subtotal rows do not spend the record budget.
        for ($index = 0; $index < 20100; $index++) {
            $rows[] = ['item', 'PNW-A', null, null, null, null, 'Baris', 1, 'ls', 1000, 1000];
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $content = base64_encode((string) file_get_contents($path));
        @unlink($path);
        $spreadsheet->disconnectWorksheets();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi 20\.000 baris/');

        app(SpreadsheetReader::class)->grid('penawaran.xlsx', $content);
    }

    /** A workbook inside the bound still comes back whole, row for row. */
    public function test_a_workbook_inside_the_row_cap_is_read_in_full(): void
    {
        $rows = [['tipe', 'dokumen', 'judul']];

        for ($index = 0; $index < 200; $index++) {
            $rows[] = ['item', 'PNW-A', "Baris {$index}"];
        }

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $content = base64_encode((string) file_get_contents($path));
        @unlink($path);
        $spreadsheet->disconnectWorksheets();

        $this->assertCount(201, app(SpreadsheetReader::class)->grid('penawaran.xlsx', $content));
    }

    /**
     * The physical ceiling has to bind a .csv as well.
     *
     * It was enforced by the PhpSpreadsheet read filter, which only the workbook
     * path uses, so a .csv was bounded by nothing but the 5 MB upload cap: a
     * 1,8 MB file of 80.000 `abaikan` rows previewed without a murmur, and a
     * 2,1 MB file of 150.001 lines was read into memory whole. The 5.000-record
     * cap cannot save it — `abaikan` rows are exempt from that cap by design, so
     * a real RAB's subtotals do not spend the record budget.
     *
     * Asserted against the READER, exactly as the workbook case is: it is the
     * only place that can show the rows are never built at all.
     */
    public function test_a_csv_taller_than_the_physical_row_cap_is_refused_while_it_is_read(): void
    {
        $header = "tipe,dokumen,pelanggan_kode,judul,lingkup,ppn_persen,uraian,volume,satuan,harga_satuan,jumlah\n";
        $subtotal = "abaikan,PNW-A,,,,,SUB TOTAL,,,,\n";

        // Inside the ceiling the file still comes back whole, row for row.
        $this->assertCount(20000, app(SpreadsheetReader::class)->grid(
            'penawaran.csv',
            base64_encode($header.str_repeat($subtotal, 19999)),
        ));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi 20\.000 baris/');

        app(SpreadsheetReader::class)->grid('penawaran.csv', base64_encode($header.str_repeat($subtotal, 20000)));
    }

    /**
     * ?status= on a resource whose table has no status column matched nothing on
     * SQLite and was a 500 on MySQL. Either way the operator downloaded a file
     * containing the header row and nothing else — from the endpoint that IS
     * the recovery path after a create-import.
     */
    public function test_a_status_filter_is_refused_by_a_resource_that_has_no_status_column(): void
    {
        $this->imports->commit('ahsp-uji', 'ahsp.csv', $this->ahspFile(
            'analisa,A.1.1,Membuat 1 m3 beton,m3,sipil,10,,,,',
            'komponen,A.1.1,Semen,zak,,,bahan,"6,5","65.000","422.500"',
        ));

        // The unfiltered export carries it, so an empty answer is not "no data".
        $this->assertStringContainsString('A.1.1', $this->imports->export('ahsp-uji'));

        $this->actingAs($this->adminUser())
            ->get('/api/core/document-import/ahsp-uji/export?status=draft')
            ->assertStatus(422)
            ->assertJsonPath('message', 'AHSP (fixture mesin impor) tidak memiliki kolom status, sehingga tidak dapat disaring menurut status. Hapus parameter status dari permintaan ekspor.');
    }

    /** A resource that HAS the column still filters on it. */
    public function test_a_status_filter_still_works_where_there_is_a_status_column(): void
    {
        $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
            'dokumen,RAB-GRAHA,BOQ masih draft,,,,,,',
            'bagian,RAB-GRAHA,,,A,Pekerjaan Persiapan,,,',
            'item,RAB-GRAHA,,,A.1,Pembersihan lahan,,1,m2,1000,1000',
        ));

        $response = $this->actingAs($this->adminUser())
            ->get('/api/core/document-import/boq-uji/export?status=draft')
            ->assertOk();

        $this->assertStringContainsString('BOQ masih draft', $response->getContent());
    }

    // --------------------------------------------------------------- fixtures

    private function project(string $code): int
    {
        return (int) DB::table('prj_projects')->insertGetId([
            'code' => $code, 'name' => "Proyek {$code}", 'type' => 'building',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The penawaran fixture with a defaulted column, which the shipped
     * quotations definition has two of — diskon and ppn_persen, both NOT NULL
     * in crm_quotations.
     */
    private function defaultedImporter(): DocumentImportService
    {
        $this->app->instance(ImportableDocuments::class, new class($this->fixtureRegistry()) extends ImportableDocuments
        {
            public function __construct(private readonly ImportableDocuments $base) {}

            public function all(): array
            {
                $all = $this->base->all();

                foreach ($all['penawaran-uji']['rows']['dokumen']['columns'] as $index => $column) {
                    if ($column['header'] === 'ppn_persen') {
                        $all['penawaran-uji']['rows']['dokumen']['columns'][$index]['default'] = 11.0;
                    }
                }

                return $all;
            }
        });

        return app(DocumentImportService::class);
    }

    /** The BOQ fixture whose project link a blank cell is allowed to clear. */
    private function clearableProjectImporter(): DocumentImportService
    {
        $this->app->instance(ImportableDocuments::class, new class($this->fixtureRegistry()) extends ImportableDocuments
        {
            public function __construct(private readonly ImportableDocuments $base) {}

            public function all(): array
            {
                $all = $this->base->all();

                foreach ($all['boq-uji']['rows']['dokumen']['columns'] as $index => $column) {
                    if ($column['header'] === 'proyek_kode') {
                        $all['boq-uji']['rows']['dokumen']['columns'][$index]['blank'] = 'clear';
                    }
                }

                return $all;
            }
        });

        return app(DocumentImportService::class);
    }

    private function quotationHeader(): string
    {
        return 'tipe,dokumen,pelanggan_kode,judul,lingkup,ppn_persen,uraian,volume,satuan,harga_satuan,jumlah';
    }

    private function quotationFile(string ...$rows): string
    {
        return base64_encode($this->quotationHeader()."\n".implode("\n", $rows)."\n");
    }

    private function boqFile(string ...$rows): string
    {
        return base64_encode(
            "tipe,dokumen,judul,proyek_kode,nomor,uraian,ahsp_kode,volume,satuan,harga_satuan,jumlah\n"
            .implode("\n", $rows)."\n",
        );
    }

    /**
     * Two BOQs that each carry a line numbered A.1 — the ordinary case, since a
     * wbs_code is unique to nothing at the database level.
     */
    private function twoBoqsSharingAWbsCode(): void
    {
        foreach (['RAB-A', 'RAB-B'] as $label) {
            $this->imports->commit('boq-uji', 'boq.csv', $this->boqFile(
                "dokumen,{$label},BOQ {$label},,,,,,",
                "bagian,{$label},,,A,Pekerjaan Persiapan,,,",
                "item,{$label},,,A.1,Pembersihan lahan,,1,m2,1000,1000",
            ));
        }
    }

    private function boqItemId(string $title, string $wbs): int
    {
        return (int) BoqItem::query()
            ->where('boq_id', Boq::query()->where('title', "BOQ {$title}")->value('id'))
            ->where('wbs_code', $wbs)
            ->value('id');
    }

    private function scopedFile(string $parentTitle, string $wbs): string
    {
        $code = Boq::query()->where('title', "BOQ {$parentTitle}")->value('code');

        return base64_encode(
            "tipe,dokumen,judul,boq_induk,nomor,uraian,item_boq,volume,satuan,harga_satuan,jumlah\n"
            ."dokumen,RAP-BARU,Anggaran pelaksanaan,{$code},,,,,,,\n"
            ."bagian,RAP-BARU,,,A,Pekerjaan Persiapan,,,,,\n"
            ."item,RAP-BARU,,,A.1,Pembersihan lahan,{$wbs},1,m2,1000,1000\n",
        );
    }

    /**
     * The BOQ fixture with a scoped line lookup bolted on, standing in for the
     * shape RAP will register: a header that names its parent document, and
     * lines whose codes are only meaningful inside it.
     */
    private function scopedLookupImporter(): DocumentImportService
    {
        $this->app->instance(ImportableDocuments::class, new class($this->fixtureRegistry()) extends ImportableDocuments
        {
            public function __construct(private readonly ImportableDocuments $base) {}

            public function all(): array
            {
                $all = $this->base->all();

                $all['boq-uji']['rows']['dokumen']['columns'][] = [
                    'header' => 'boq_induk', 'field' => 'parent_boq_id', 'required' => true,
                    'lookup' => ['est_boqs', 'code'],
                ];

                $all['boq-uji']['rows']['item']['columns'][] = [
                    'header' => 'item_boq', 'field' => 'parent_boq_item_id', 'required' => true,
                    'lookup' => ['est_boq_items', 'wbs_code'], 'scope' => ['boq_id', 'parent_boq_id'],
                ];

                return $all;
            }
        });

        return app(DocumentImportService::class);
    }

    private function ahspFile(string ...$rows): string
    {
        return base64_encode(
            "tipe,kode,uraian,satuan,kategori,overhead_persen,jenis,koefisien,harga_satuan,jumlah\n"
            .implode("\n", $rows)."\n",
        );
    }

    private function customer(string $code): Customer
    {
        return Customer::query()->create([
            'code' => $code, 'name' => "Pelanggan {$code}", 'city' => 'Jakarta',
            'payment_term_days' => 30, 'status' => 'active',
        ]);
    }

    private function userWithOnly(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('terbatas', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Estimator', 'email' => 'estimator@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Three definitions that between them use every part of the contract: a
     * header plus flat lines, a header plus sections plus lines under them, and
     * one whose group column IS the document's own stored code.
     */
    private function fixtureRegistry(): ImportableDocuments
    {
        return new class extends ImportableDocuments
        {
            public function all(): array
            {
                $definitions = [
                    'penawaran-uji' => [
                        'label' => 'Penawaran (fixture mesin impor)',
                        'module' => 'Crm',
                        'permission' => 'crm',
                        'model' => Quotation::class,
                        'document_type' => 'QTN',
                        'group' => 'dokumen',
                        'request' => QuotationStoreRequest::class,
                        'rows' => [
                            'dokumen' => [
                                'label' => 'Kepala penawaran',
                                'role' => 'header',
                                'columns' => [
                                    ['header' => 'pelanggan_kode', 'field' => 'customer_id', 'required' => true, 'lookup' => ['crm_customers', 'code']],
                                    ['header' => 'judul', 'field' => 'title', 'required' => true],
                                    ['header' => 'lingkup', 'field' => 'scope_type', 'required' => true, 'enum' => [
                                        'construction' => ['konstruksi'],
                                        'system_integration' => ['integrasi'],
                                        'maintenance' => ['pemeliharaan'],
                                    ]],
                                    ['header' => 'ppn_persen', 'field' => 'ppn_rate', 'cast' => 'percent'],
                                ],
                            ],
                            'item' => [
                                'label' => 'Baris penawaran',
                                'role' => 'line',
                                'relation' => 'items',
                                'amount' => ['qty', 'unit_price'],
                                'columns' => [
                                    ['header' => 'uraian', 'field' => 'description', 'required' => true],
                                    ['header' => 'volume', 'field' => 'qty', 'required' => true, 'cast' => 'qty'],
                                    ['header' => 'satuan', 'field' => 'unit'],
                                    ['header' => 'harga_satuan', 'field' => 'unit_price', 'required' => true, 'cast' => 'money'],
                                    ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                                ],
                            ],
                        ],
                        'create' => function (array $payload) {
                            $quotation = app(QuotationService::class)->create($payload);

                            // Stands in for a constraint or a service guard firing
                            // AFTER the parent row is written — the only way to
                            // prove the per-document transaction rolls back whole.
                            if (str_contains((string) $payload['title'], 'GAGAL')) {
                                throw new LogicException('penawaran ini sengaja gagal setelah induknya tertulis.');
                            }

                            return $quotation;
                        },
                        'update' => fn (object $target, array $payload) => app(QuotationService::class)->update($target, $payload),
                    ],

                    'boq-uji' => [
                        'label' => 'BOQ (fixture mesin impor)',
                        'module' => 'Estimation',
                        'permission' => 'est',
                        'model' => Boq::class,
                        'document_type' => 'BOQ',
                        'group' => 'dokumen',
                        'request' => BoqStoreRequest::class,
                        'rows' => [
                            'dokumen' => [
                                'label' => 'Kepala BOQ',
                                'role' => 'header',
                                'columns' => [
                                    ['header' => 'judul', 'field' => 'title', 'required' => true],
                                    ['header' => 'proyek_kode', 'field' => 'project_id', 'lookup' => ['prj_projects', 'code']],
                                ],
                            ],
                            'bagian' => [
                                'label' => 'Judul bagian',
                                'role' => 'group',
                                'relation' => 'sections',
                                'columns' => [
                                    ['header' => 'nomor', 'field' => 'section_no', 'required' => true],
                                    ['header' => 'uraian', 'field' => 'name', 'required' => true],
                                ],
                            ],
                            'item' => [
                                'label' => 'Baris pekerjaan',
                                'role' => 'line',
                                'parent' => 'bagian',
                                'relation' => 'items',
                                'amount' => ['qty', 'unit_price'],
                                'columns' => [
                                    ['header' => 'nomor', 'field' => 'wbs_code', 'required' => true],
                                    ['header' => 'uraian', 'field' => 'description'],
                                    ['header' => 'ahsp_kode', 'field' => 'ahsp_id', 'lookup' => ['est_ahsp', 'code']],
                                    ['header' => 'volume', 'field' => 'qty', 'required' => true, 'cast' => 'qty'],
                                    ['header' => 'satuan', 'field' => 'unit'],
                                    ['header' => 'harga_satuan', 'field' => 'unit_price', 'cast' => 'money'],
                                    ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                                ],
                            ],
                        ],
                        'create' => fn (array $payload) => app(BoqService::class)->create($payload),
                        'update' => fn (object $target, array $payload) => app(BoqService::class)->update($target, $payload),
                    ],

                    'ahsp-uji' => [
                        'label' => 'AHSP (fixture mesin impor)',
                        'module' => 'Estimation',
                        'permission' => 'est',
                        'model' => Ahsp::class,
                        // No document_type: an analysis code is the estimator's
                        // own domain code, so any unknown code creates rather
                        // than being suspected of being a mistyped number.
                        'document_type' => null,
                        'group' => 'kode',
                        'request' => AhspStoreRequest::class,
                        // The one named exception: an analysis updating itself
                        // must not trip its own Rule::unique on its own code.
                        'update_rules' => function (array $rules, object $target): array {
                            $rules['code'] = ['required', 'string', 'max:40',
                                Rule::unique('est_ahsp', 'code')->ignore($target->id)->whereNull('deleted_at')];

                            return $rules;
                        },
                        'rows' => [
                            'analisa' => [
                                'label' => 'Kepala analisa',
                                'role' => 'header',
                                'aliases' => ['dokumen'],
                                'columns' => [
                                    // The group column doubles as a payload field:
                                    // this code is the document's real code.
                                    ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text'],
                                    ['header' => 'uraian', 'field' => 'name', 'required' => true],
                                    ['header' => 'satuan', 'field' => 'unit', 'required' => true],
                                    ['header' => 'kategori', 'field' => 'category', 'required' => true],
                                    ['header' => 'overhead_persen', 'field' => 'overhead_pct', 'cast' => 'percent'],
                                ],
                            ],
                            'komponen' => [
                                'label' => 'Komponen analisa',
                                'role' => 'line',
                                'aliases' => ['item'],
                                'relation' => 'components',
                                'amount' => ['coefficient', 'unit_price'],
                                'columns' => [
                                    ['header' => 'uraian', 'field' => 'name', 'required' => true],
                                    ['header' => 'satuan', 'field' => 'unit', 'required' => true],
                                    ['header' => 'jenis', 'field' => 'component_type', 'required' => true, 'enum' => [
                                        'labor' => ['upah'],
                                        'material' => ['bahan'],
                                        'equipment' => ['alat'],
                                    ]],
                                    ['header' => 'koefisien', 'field' => 'coefficient', 'required' => true, 'cast' => 'coefficient'],
                                    ['header' => 'harga_satuan', 'field' => 'unit_price', 'required' => true, 'cast' => 'money'],
                                    ['header' => 'jumlah', 'checksum' => true, 'cast' => 'money'],
                                ],
                            ],
                        ],
                        'create' => fn (array $payload) => app(AhspService::class)->create($payload),
                        'update' => fn (object $target, array $payload) => app(AhspService::class)->update($target, $payload),
                    ],
                ];

                // The penawaran shape again, but landed as `submitted` by its
                // own create — the seeded-PR shape of 4 Sep 2026 (a status
                // written with no Ajukan behind it) arriving through a file.
                // No shipped definition does this today; the fixture exists so
                // the engine's maker-checker row is pinned before one does.
                $definitions['penawaran-diajukan-uji'] = array_merge($definitions['penawaran-uji'], [
                    'label' => 'Penawaran langsung diajukan (fixture mesin impor)',
                    'create' => function (array $payload) {
                        $quotation = app(QuotationService::class)->create($payload);
                        $quotation->forceFill(['status' => DocumentStatus::Submitted])->save();

                        return $quotation;
                    },
                ]);

                return $definitions;
            }
        };
    }
}
