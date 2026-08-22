<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Services\DocumentImportService;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Quotation;
use Modules\Crm\Models\QuotationItem;
use Modules\Crm\Services\QuotationService;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Loading penawaran from the spreadsheet the sales engineer already has.
 *
 * The engine behind this — typed rows, grouping, casts, the checksum — is
 * asserted in tests/Feature/Core/DocumentImportTest against fixture definitions.
 * What is asserted HERE is the `quotations` definition itself: that the payload
 * it assembles is the payload QuotationStoreRequest describes, that it goes
 * through QuotationService and nothing else, and therefore that a penawaran
 * which arrived as a row in a file is indistinguishable from one somebody typed
 * into the form — same line numbering, same recomputed subtotal / DPP / PPN /
 * total, same QTN number from the sequence, same draft status.
 *
 * The three refusals that matter most have a test each: a single bad line
 * refuses its whole penawaran (a penawaran quietly missing one line is a
 * penawaran that is wrong forever, and its contract carries the error into the
 * project), an approved penawaran is never overwritten, and a pelanggan code
 * that does not resolve is refused rather than nulled.
 */
class QuotationImportTest extends ErpTestCase
{
    private const RESOURCE = 'quotations';

    private DocumentImportService $imports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imports = app(DocumentImportService::class);
    }

    // ------------------------------------------------------------ it lands

    /**
     * The whole point, in one test: two penawaran in one workbook, each with its
     * own lines, each priced by the service rather than by the sheet.
     *
     * PNW-GRAHA: 24 x 4.250.000 = 102.000.000 and 1 x 18.500.000 = 18.500.000
     *            subtotal 120.500.000, PPN 11% = 13.255.000, total 133.755.000.
     */
    public function test_a_file_of_penawaran_becomes_penawaran_with_their_lines_and_totals(): void
    {
        $this->customer('CUST-001');
        $this->customer('CUST-002');

        $result = $this->imports->commit(self::RESOURCE, 'penawaran.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-GRAHA', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Upgrade CCTV Gudang Cakung', 'lingkup' => 'integrasi',
                'berlaku_sampai' => '31/08/2026', 'ppn_persen' => '11'],
            ['tipe' => 'item', 'dokumen' => 'PNW-GRAHA', 'uraian' => 'Kamera IP dome 4MP',
                'volume' => '24', 'satuan' => 'unit', 'harga_satuan' => '4.250.000', 'jumlah' => '102.000.000'],
            ['tipe' => 'item', 'dokumen' => 'PNW-GRAHA', 'uraian' => 'Instalasi dan konfigurasi NVR',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '18.500.000', 'jumlah' => '18.500.000'],
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-ARTHA', 'pelanggan_kode' => 'CUST-002',
                'judul' => 'Renovasi Kantor Cabang Bank Artha', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-ARTHA', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '25.000.000', 'jumlah' => '25.000.000'],
        ));

        $this->assertSame(2, $result['created'], json_encode($result['documents']));
        $this->assertSame(0, $result['skipped']);

        $graha = Quotation::query()->where('title', 'Upgrade CCTV Gudang Cakung')->sole();

        $this->assertSame(DocumentStatus::Draft, $graha->status);
        $this->assertSame('system_integration', $graha->scope_type->value);
        $this->assertSame('2026-08-31', $graha->valid_until->toDateString());
        // The QTN number is the sequence's to assign; no file may supply one.
        $this->assertStringStartsWith('QTN/', $graha->code);

        $this->assertEqualsWithDelta(120_500_000.0, (float) $graha->subtotal, 0.01);
        $this->assertEqualsWithDelta(120_500_000.0, (float) $graha->dpp, 0.01);
        $this->assertEqualsWithDelta(13_255_000.0, (float) $graha->ppn_amount, 0.01);
        $this->assertEqualsWithDelta(133_755_000.0, (float) $graha->total, 0.01);

        $this->assertSame(
            ['Kamera IP dome 4MP', 'Instalasi dan konfigurasi NVR'],
            $graha->items()->pluck('description')->all(),
        );
        // line_no is syncItems', not the file's: crm_quotation_items carries
        // unique(quotation_id, line_no) and a sheet that could set it could
        // duplicate it.
        $this->assertSame([1, 2], $graha->items()->pluck('line_no')->all());
        $this->assertEqualsWithDelta(102_000_000.0, (float) $graha->items()->first()->amount, 0.01);

        // The commit hands back the assigned codes, which is the only thing that
        // makes a create-import recoverable: re-uploading the same file with the
        // label still in the dokumen column would mint a second penawaran.
        $this->assertSame($graha->code, $result['codes']['PNW-GRAHA']);
    }

    /**
     * The claim this whole feature rests on, asserted directly: the same data
     * through the form and through the importer produces the same document.
     */
    public function test_an_imported_penawaran_is_indistinguishable_from_one_typed_in(): void
    {
        $customer = $this->customer('CUST-001');

        $typed = app(QuotationService::class)->create([
            'customer_id' => $customer->id,
            'title' => 'Upgrade CCTV Gudang Cakung',
            'scope_type' => 'system_integration',
            'valid_until' => '2026-08-31',
            'discount_amount' => 500_000,
            'ppn_rate' => 11,
            'notes' => 'Harga belum termasuk pekerjaan sipil.',
            'items' => [
                ['description' => 'Kamera IP dome 4MP', 'qty' => 24, 'unit' => 'unit', 'unit_price' => 4_250_000],
                ['description' => 'Instalasi dan konfigurasi NVR', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 18_500_000],
            ],
        ]);

        $this->imports->commit(self::RESOURCE, 'penawaran.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-GRAHA', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Upgrade CCTV Gudang Cakung', 'lingkup' => 'integrasi',
                'berlaku_sampai' => '31/08/2026', 'diskon' => '500.000', 'ppn_persen' => '11',
                'catatan' => 'Harga belum termasuk pekerjaan sipil.'],
            ['tipe' => 'item', 'dokumen' => 'PNW-GRAHA', 'uraian' => 'Kamera IP dome 4MP',
                'volume' => '24', 'satuan' => 'unit', 'harga_satuan' => '4.250.000'],
            ['tipe' => 'item', 'dokumen' => 'PNW-GRAHA', 'uraian' => 'Instalasi dan konfigurasi NVR',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '18.500.000'],
        ));

        $imported = Quotation::query()->where('id', '>', $typed->id)->sole();

        foreach (['customer_id', 'title', 'scope_type', 'status', 'subtotal', 'discount_amount',
            'dpp', 'ppn_rate', 'ppn_amount', 'total', 'notes'] as $column) {
            $this->assertEquals(
                $typed->{$column},
                $imported->{$column},
                "{$column} differs between the typed and the imported penawaran",
            );
        }

        $this->assertEquals(
            $typed->items()->get(['line_no', 'description', 'qty', 'unit', 'unit_price', 'amount'])->toArray(),
            $imported->items()->get(['line_no', 'description', 'qty', 'unit', 'unit_price', 'amount'])->toArray(),
        );
    }

    /** The words a sales engineer writes, not the words the enum stores. */
    public function test_lingkup_accepts_the_indonesian_words_the_sales_team_writes(): void
    {
        $this->customer('CUST-001');

        $rows = [];

        foreach (['konstruksi' => 'A', 'integrasi' => 'B', 'pemeliharaan' => 'C'] as $word => $label) {
            $rows[] = ['tipe' => 'dokumen', 'dokumen' => "PNW-{$label}", 'pelanggan_kode' => 'CUST-001',
                'judul' => "Penawaran {$label}", 'lingkup' => $word];
            $rows[] = ['tipe' => 'item', 'dokumen' => "PNW-{$label}", 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'];
        }

        $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(...$rows));

        $this->assertSame(
            ['construction', 'system_integration', 'maintenance'],
            Quotation::query()->orderBy('title')->get()->map(fn (Quotation $q) => $q->scope_type->value)->all(),
        );
    }

    /**
     * A blank ppn_persen means "the rate we charge", not "nothing".
     *
     * crm_quotations.ppn_rate is NOT NULL, so an empty cell that reached the
     * insert as null would refuse the whole penawaran with an SQLSTATE nobody
     * can act on. It resolves to the house rate instead — and to the rate in
     * force, not the one hard-coded when this file was written.
     */
    public function test_a_blank_ppn_column_uses_the_rate_in_force(): void
    {
        $this->customer('CUST-001');
        $this->setSetting('tax.ppn_rate', 12);

        $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-A', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Tanpa kolom ppn terisi', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-A', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '10.000.000'],
        ));

        $quotation = Quotation::query()->sole();

        $this->assertEqualsWithDelta(12.0, (float) $quotation->ppn_rate, 0.0001);
        $this->assertEqualsWithDelta(1_200_000.0, (float) $quotation->ppn_amount, 0.01);
    }

    /** prospek_kode is optional, and when it is filled it must resolve to that lead. */
    public function test_a_prospek_kode_binds_the_penawaran_to_its_lead(): void
    {
        $this->customer('CUST-001');

        $lead = Lead::query()->create([
            'code' => 'LEAD-001', 'name' => 'Bapak Rudi Hartono',
            'company_name' => 'PT Graha Sentosa Propertindo', 'status' => 'qualified',
        ]);

        $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-A', 'pelanggan_kode' => 'CUST-001',
                'prospek_kode' => 'LEAD-001', 'judul' => 'Dari prospek', 'lingkup' => 'integrasi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-A', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame($lead->id, Quotation::query()->sole()->lead_id);
    }

    // ------------------------------------------------------------- refusals

    /**
     * One unreadable cell refuses the penawaran it belongs to — all of it — and
     * the penawaran beside it in the same file still lands.
     *
     * A penawaran that imports two lines out of three still adds up, still looks
     * finished, and is quoted short by the missing line for as long as it lives.
     */
    public function test_one_bad_line_refuses_its_whole_penawaran_and_leaves_the_others(): void
    {
        $this->customer('CUST-001');

        $result = $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-RUSAK', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Ada sel yang tidak terbaca', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-RUSAK', 'uraian' => 'Baris yang baik',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
            // A capital O where a zero belongs — what a scan or a retype leaves.
            ['tipe' => 'item', 'dokumen' => 'PNW-RUSAK', 'uraian' => 'Baris yang rusak',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.250.00O'],
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-BAIK', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Tetangga yang baik', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-BAIK', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '2.000.000'],
        ));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('bukan angka', json_encode($result['documents']));

        // Not one line of the refused penawaran was written — not even the good one.
        $this->assertSame(['Tetangga yang baik'], Quotation::query()->pluck('title')->all());
        $this->assertSame(1, QuotationItem::query()->count());
    }

    /**
     * The file's own JUMLAH column is the only check on how we read its numbers.
     *
     * 24 x 4.250.000 is 102.000.000; a sheet that says 10.200.000 has been read
     * differently by one of us, and guessing which is not an option.
     */
    public function test_the_files_own_total_refuses_a_line_we_read_differently(): void
    {
        $this->customer('CUST-001');

        $preview = $this->imports->preview(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-A', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Jumlah tidak cocok', 'lingkup' => 'integrasi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-A', 'uraian' => 'Kamera IP dome 4MP',
                'volume' => '24', 'satuan' => 'unit', 'harga_satuan' => '4.250.000', 'jumlah' => '10.200.000'],
        ));

        $this->assertFalse($preview['documents'][0]['valid']);
        $this->assertStringContainsString(
            'Periksa pemisah ribuan/desimal',
            implode(' ', $preview['documents'][0]['rows'][0]['errors']),
        );
    }

    /**
     * An approved penawaran is a quoted price somebody may already have signed
     * against; the import must not be the door round that.
     */
    public function test_an_approved_penawaran_is_never_overwritten(): void
    {
        $customer = $this->customer('CUST-001');

        $approved = app(QuotationService::class)->create([
            'customer_id' => $customer->id,
            'title' => 'Harga yang sudah disetujui',
            'scope_type' => 'construction',
            'items' => [['description' => 'Pekerjaan persiapan', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 50_000_000]],
        ]);
        $approved->forceFill(['status' => DocumentStatus::Approved])->save();

        $result = $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $approved->code, 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Diam-diam diturunkan', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => $approved->code, 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '5.000.000'],
        ));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Disetujui', json_encode($result['documents']));

        $approved->refresh();
        $this->assertSame('Harga yang sudah disetujui', $approved->title);
        $this->assertEqualsWithDelta(50_000_000.0, (float) $approved->subtotal, 0.01);
    }

    /** A draft penawaran is the case the guard above must not also refuse. */
    public function test_a_draft_penawaran_is_updated_in_place_and_its_lines_replaced(): void
    {
        $customer = $this->customer('CUST-001');

        $draft = app(QuotationService::class)->create([
            'customer_id' => $customer->id,
            'title' => 'Revisi pertama',
            'scope_type' => 'construction',
            'items' => [
                ['description' => 'Baris lama satu', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1_000_000],
                ['description' => 'Baris lama dua', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 2_000_000],
            ],
        ]);

        $result = $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $draft->code, 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Revisi kedua', 'lingkup' => 'konstruksi', 'ppn_persen' => '11'],
            ['tipe' => 'item', 'dokumen' => $draft->code, 'uraian' => 'Baris baru',
                'volume' => '2', 'satuan' => 'ls', 'harga_satuan' => '5.000.000'],
        ));

        $this->assertSame(1, $result['updated'], json_encode($result['documents']));
        $this->assertSame(1, Quotation::query()->count(), 'an update must never mint a second penawaran');

        $draft->refresh();
        $this->assertSame('Revisi kedua', $draft->title);
        // Lines are replaced wholesale, exactly as syncItems does from the form.
        $this->assertSame(['Baris baru'], $draft->items()->pluck('description')->all());
        $this->assertEqualsWithDelta(10_000_000.0, (float) $draft->subtotal, 0.01);
    }

    /**
     * One wrong digit in a code you meant to update would otherwise mint a
     * second penawaran with a fresh number, and nobody would notice until two
     * of them disagreed.
     */
    public function test_a_code_shaped_like_a_penawaran_number_that_does_not_exist_is_refused(): void
    {
        $this->customer('CUST-001');

        $result = $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'QTN/2026/IX/0099', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Nomor salah ketik', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'QTN/2026/IX/0099', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('tidak ditemukan', json_encode($result['documents']));
        $this->assertSame(0, Quotation::query()->count());
    }

    /**
     * A penawaran attached to no pelanggan is a different document, not the same
     * one with a field missing — crm_quotations.customer_id is a NOT NULL FK.
     */
    public function test_an_unknown_pelanggan_kode_refuses_the_penawaran(): void
    {
        $result = $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-A', 'pelanggan_kode' => 'CUST-TIDAK-ADA',
                'judul' => 'Pelanggan tidak dikenal', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-A', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString(
            'pelanggan_kode: "CUST-TIDAK-ADA" tidak ditemukan',
            implode(' ', $result['documents'][0]['errors']),
        );
    }

    /** QuotationStoreRequest says items min:1, and a header with no lines is a mistake. */
    public function test_a_penawaran_without_a_single_item_row_is_refused(): void
    {
        $this->customer('CUST-001');

        $result = $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-A', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Kepala tanpa rincian', 'lingkup' => 'konstruksi'],
        ));

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('tidak ada satu pun baris rincian', json_encode($result['documents']));
    }

    /**
     * Re-pointing a penawaran at another pelanggan is legitimate work, so it
     * warns rather than refusing — but it is also exactly what one mistyped code
     * does, and the contract minted from this penawaran later bills whoever it
     * names.
     */
    public function test_moving_a_penawaran_to_another_pelanggan_warns_and_still_lands(): void
    {
        $first = $this->customer('CUST-001');
        $this->customer('CUST-002');

        $draft = app(QuotationService::class)->create([
            'customer_id' => $first->id,
            'title' => 'Pindah pelanggan',
            'scope_type' => 'construction',
            'items' => [['description' => 'Pekerjaan persiapan', 'qty' => 1, 'unit' => 'ls', 'unit_price' => 1_000_000]],
        ]);

        $result = $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $draft->code, 'pelanggan_kode' => 'CUST-002',
                'judul' => 'Pindah pelanggan', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => $draft->code, 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertSame(1, $result['updated']);
        $this->assertSame('CUST-002', $draft->refresh()->customer->code);

        $preview = $this->imports->preview(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => $draft->code, 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Pindah pelanggan', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => $draft->code, 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        ));

        $this->assertStringContainsString(
            'pelanggan penawaran ini berubah dari CUST-002',
            implode(' ', $preview['documents'][0]['warnings']),
        );
    }

    // ------------------------------------------------------------ endpoints

    /**
     * An import both creates and updates, so create alone must not be enough —
     * otherwise somebody who may only draft penawaran can rewrite every priced
     * one in the system by uploading a sheet.
     */
    public function test_importing_penawaran_needs_crm_create_and_update(): void
    {
        $this->customer('CUST-001');

        $payload = ['filename' => 'p.csv', 'content' => $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-A', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Lewat API', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-A', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '1', 'satuan' => 'ls', 'harga_satuan' => '1.000.000'],
        )];

        $this->actingAs($this->userWithOnly(['crm.view', 'crm.create']))
            ->postJson('/api/core/document-import/'.self::RESOURCE.'/import', $payload)
            ->assertForbidden();

        $this->assertSame(0, Quotation::query()->count());

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/api/core/document-import/'.self::RESOURCE.'/preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.summary.to_create', 1);

        $this->assertSame(0, Quotation::query()->count(), 'a preview writes nothing');

        $this->actingAs($admin)
            ->postJson('/api/core/document-import/'.self::RESOURCE.'/import', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', 1);
    }

    /**
     * The export is what makes a create-import recoverable: its dokumen column
     * carries the assigned QTN number, so the round trip is update-in-place.
     */
    public function test_the_export_returns_a_file_the_importer_accepts_back(): void
    {
        $this->customer('CUST-001');

        $this->imports->commit(self::RESOURCE, 'p.csv', $this->file(
            ['tipe' => 'dokumen', 'dokumen' => 'PNW-A', 'pelanggan_kode' => 'CUST-001',
                'judul' => 'Bolak-balik', 'lingkup' => 'konstruksi'],
            ['tipe' => 'item', 'dokumen' => 'PNW-A', 'uraian' => 'Pekerjaan persiapan',
                'volume' => '2', 'satuan' => 'ls', 'harga_satuan' => '3.000.000'],
        ));

        $csv = $this->imports->export(self::RESOURCE);
        $code = Quotation::query()->sole()->code;

        $this->assertStringContainsString($code, $csv);
        $this->assertStringContainsString('CUST-001', $csv);

        $result = $this->imports->commit(self::RESOURCE, 'ekspor.csv', base64_encode($csv));

        $this->assertSame(1, $result['updated'], json_encode($result['documents']));
        $this->assertSame(1, Quotation::query()->count());
        $this->assertEqualsWithDelta(6_000_000.0, (float) Quotation::query()->sole()->subtotal, 0.01);
    }

    // -------------------------------------------------------------- fixtures

    private function customer(string $code): Customer
    {
        return Customer::query()->create([
            'code' => $code, 'name' => "Pelanggan {$code}", 'city' => 'Jakarta',
            'payment_term_days' => 30, 'status' => 'active',
        ]);
    }

    /**
     * A base64 CSV whose rows are keyed by column heading, so a test says what
     * it means instead of counting commas.
     *
     * The headings come from the SHIPPED TEMPLATE's own first line, never from a
     * list typed in here. A test carrying its own copy would go on passing after
     * the registry renamed a column, while the template an operator downloads
     * had stopped matching what the importer reads — and the symptom of that is
     * an import that lands nothing and explains nothing.
     *
     * @param  array<string, string>  ...$rows
     */
    private function file(array ...$rows): string
    {
        $headers = str_getcsv(
            (string) strtok($this->imports->template(self::RESOURCE), "\n"), ',', '"', '\\',
        );
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($headers as $header) {
                $value = (string) ($row[$header] ?? '');
                $cells[] = str_contains($value, ',') ? '"'.$value.'"' : $value;
            }

            $lines[] = implode(',', $cells);
        }

        return base64_encode(implode("\n", $lines)."\n");
    }

    private function userWithOnly(array $permissions): User
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('terbatas', 'web');
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->get());

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Sales Engineer', 'email' => 'sales@test.local',
            'password' => 'password', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
