<?php

namespace Tests\Feature\Inventory;

use Modules\Core\Models\Company;
use Modules\Core\Services\FormPrintService;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\IssueReturn;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\PurchaseReturn;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\IssueReturnService;
use Modules\Inventory\Services\PurchaseReturnService;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Formulir rumah untuk Persediaan — tujuh dokumen gudang.
 *
 * THE ONE RULE THIS WHOLE FILE IS ABOUT, and it is not a rule the other print
 * lanes had to discover: in this module a stored 0 in a MONEY column usually
 * means "nobody has valued this yet".
 *
 * Five of the seven documents are written with unit_cost = 0 and only ever
 * valued later, each with the same comment beside the zero in its own service:
 *
 *   inv_transfer_items       0 until sendTransfer freezes the source average
 *   inv_issue_items          0 until postIssue freezes the warehouse average
 *   inv_stock_adjustment_items  0 until postAdjustment values the difference
 *   inv_purchase_return_items   0 until postPurchaseReturn copies the receipt
 *   inv_issue_return_items      0 until postIssueReturn copies the bon
 *
 * A draft surat jalan printed with "0,00" in HARGA SATUAN does not say the
 * goods are free; it says the sheet was printed before anybody worked out what
 * was on the truck. Under a driver's signature that reads as a valuation, and
 * a claim for goods lost in transit is settled off exactly that paper. So an
 * unvalued line is RULED, its jumlah is RULED, and the document total is RULED
 * with it — measured from the document's own status, never from the zero.
 *
 * The GRN is the deliberate exception and the contrast worth keeping: its
 * unit_cost is TYPED BY THE RECEIVING CLERK at creation, and a zero-cost line
 * has to be confirmed explicitly (temuan #72). Zero there is an assertion
 * somebody made, so it prints as 0,00.
 *
 * The other decisions each sheet forced:
 *
 *   KONDISI is a hand column. There is no qty_rejected anywhere in this ERP —
 *   not on the GRN, not on the transfer — so the receiving storeman writes
 *   rejects on the line and initials it. The column is ruled on every row and
 *   the sheet says in its own footnote why.
 *
 *   KENDARAAN and NO. POLISI are not stored at all. Ruled, never guessed.
 *
 *   SALDO STOK IS DATED BY THE PRINTER. inv_stock_balances keeps no history:
 *   the only day this sheet can honestly state is the day it came off the
 *   printer, so ?tanggal= cannot re-date it into a claim about last month.
 *
 *   BARANG DALAM PERJALANAN is on the saldo sheet because T28/T29 found the
 *   figure missing from the screen: goods that have left one warehouse and not
 *   arrived at the other sit in NEITHER balance, and a stock take that does
 *   not know that reports a shortfall at one end and a surplus at the other.
 */
class InventoryPrintTest extends ErpTestCase
{
    use InventoryFixtures;

    private FormPrintService $forms;

    private Project $project;

    private Warehouse $gudangProyek;

    private Warehouse $gudangPusat;

    private Item $semen;

    private Item $besi;

    private ?GoodsReceipt $grn = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);
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

        $this->project = Project::query()->create([
            'code' => 'PRJ-2026-001',
            'name' => 'Pengembangan Bandar Udara Sultan Hasanudin - Makassar',
            'type' => 'construction',
            'status' => 'active',
            'city' => 'Makassar',
            'province' => 'Sulawesi Selatan',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'consultant_name' => 'PT Ciriajasa Cipta Mandiri',
        ]);

        $this->gudangProyek = $this->makeWarehouse('WH-MKS', [
            'name' => 'Gudang Proyek Makassar',
            'project_id' => $this->project->id,
            'address' => 'Jl. Bandara Baru, Mandai, Maros',
            'keeper_employee_id' => $this->keeper()->id,
        ]);

        $this->gudangPusat = $this->makeWarehouse('WH-PUSAT', ['name' => 'Gudang Pusat Cakung']);

        $this->semen = $this->makeItem('Semen PCC 50 kg', ['unit' => 'zak']);
        $this->besi = $this->makeItem('Besi beton D16 panjang 12 m', ['unit' => 'btg']);
    }

    // ------------------------------------------------------------- fixtures

    private function keeper(): Employee
    {
        return Employee::query()->firstOrCreate(
            ['code' => 'EMP-0007'],
            [
                'name' => 'Sugeng Riyadi',
                'nik_ktp' => '3273010101900001',
                'gender' => 'L',
                'birth_date' => '1990-01-01',
                'ptkp_status' => 'K/1',
                'join_date' => '2022-02-01',
                'employment_type' => 'tetap',
                'position' => 'Kepala Gudang',
                'department' => 'Logistik',
                'base_salary' => 8_500_000,
                'status' => 'active',
            ],
        );
    }

    private function wbsTask(): WbsTask
    {
        return WbsTask::query()->create([
            'project_id' => $this->project->id,
            'wbs_code' => 'C.1',
            'name' => 'Pekerjaan struktur lantai 3',
            'weight_pct' => 12.5,
            'sort_order' => 1,
        ]);
    }

    /**
     * The receipt every other document in this file hangs off: 200 zak semen
     * at 75.000 and 100 batang besi at 140.000, delivered against a PO into
     * the project warehouse. 15.000.000 + 14.000.000 = 29.000.000.
     *
     * Memoised, because most tests here need both the receipt and something
     * raised against the stock it brought in, and a second delivery would move
     * the warehouse average the later documents are read against.
     */
    private function receipt(): GoodsReceipt
    {
        return $this->grn ??= $this->stock()->postReceipt($this->makeGrn(
            $this->gudangProyek,
            [[$this->semen, 200, 75_000], [$this->besi, 100, 140_000]],
            '2026-03-10',
            [
                'vendor_id' => $this->vendor()->id,
                'purchase_order_id' => $this->makeGoodsPurchaseOrder($this->gudangProyek)->id,
                'delivery_note_no' => 'SJ/BNS/2026/03/0912',
            ],
        ));
    }

    /** 40 zak off the project warehouse at the 75.000 they came in at. */
    private function postedIssue(): Issue
    {
        $this->receipt();

        $issue = $this->makeIssue(
            $this->gudangProyek,
            [[$this->semen, 40, $this->wbsTask()->id]],
            (int) $this->project->id,
            '2026-03-15',
            ['purpose' => 'Pengecoran kolom lantai 3 zona B'],
        );

        return $this->stock()->postIssue($issue);
    }

    /** 20 zak on the road from the central warehouse to the site. */
    private function sentTransfer(): Transfer
    {
        // The central warehouse has to hold the stock before it can send it:
        // 100 zak at 15.000, which is the average sendTransfer freezes onto
        // the transfer lines.
        $this->receiveStock($this->gudangPusat, $this->semen, 100, 15_000, '2026-03-01');

        return $this->stock()->sendTransfer(
            $this->makeTransfer($this->gudangPusat, $this->gudangProyek, [[$this->semen, 20]], '2026-03-20')
        );
    }

    private function draftPurchaseReturn(GoodsReceipt $grn): PurchaseReturn
    {
        return app(PurchaseReturnService::class)->create([
            'goods_receipt_id' => $grn->id,
            'return_date' => '2026-03-28',
            'returned_by' => $this->warehouseUser()->id,
            'reason' => 'Sepuluh batang besi bengkok dan berkarat, ditolak oleh pengawas.',
            'items' => [
                ['grn_item_id' => (int) $grn->items()->where('item_id', $this->besi->id)->sole()->id, 'qty' => 10],
            ],
        ]);
    }

    private function draftIssueReturn(Issue $issue): IssueReturn
    {
        return app(IssueReturnService::class)->create([
            'issue_id' => $issue->id,
            'return_date' => '2026-03-30',
            'returned_by' => $this->warehouseUser()->id,
            'reason' => 'Sisa material pengecoran dikembalikan ke gudang proyek.',
            'items' => [
                ['issue_item_id' => (int) $issue->items()->sole()->id, 'qty' => 8],
            ],
        ]);
    }

    /** Ruled cells inside body tables; the identity block rules with a span. */
    private function fills(string $html): int
    {
        return substr_count($html, '<div class="fill"></div>');
    }

    /** Today, as the sheet spells a date. */
    private function todayLabel(): string
    {
        $months = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return now()->format('d').' '.$months[(int) now()->format('n')].' '.now()->format('Y');
    }

    // -------------------------------------------------- penerimaan barang

    public function test_the_receipt_sheet_prints_its_vendor_delivery_note_and_priced_lines(): void
    {
        $grn = $this->receipt();

        $html = $this->forms->html('penerimaan-barang', ['id' => $grn->id]);

        $this->assertStringContainsString('BUKTI PENERIMAAN BARANG', $html);
        $this->assertStringContainsString('Form F/BPB', $html);
        $this->assertStringContainsString($grn->code, $html);
        // The counterparty of a delivery is the supplier who made it.
        $this->assertStringContainsString('PEMASOK / VENDOR', $html);
        $this->assertStringContainsString('PT Semen Distribusi Utama', $html);
        $this->assertStringContainsString('SJ/BNS/2026/03/0912', $html);
        $this->assertStringContainsString('Gudang Proyek Makassar', $html);
        $this->assertStringContainsString('10 Maret 2026', $html);
        $this->assertStringContainsString('Semen PCC 50 kg', $html);
        $this->assertStringContainsString('75.000,00', $html);
        $this->assertStringContainsString('15.000.000,00', $html);
        $this->assertStringContainsString('14.000.000,00', $html);
        $this->assertStringContainsString('29.000.000,00', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    /**
     * THE JOB FIRST, THE SUPPLIER SECOND, THE WAREHOUSE LAST — the fall-through
     * the receipt's heading is declared with, one test per branch.
     *
     * The hole it fills is real and was on every receipt in the live data: a
     * GRN with no vendor printed an EMPTY centred heading, a bold line and its
     * margins where the subject belongs. Written unconditionally as the vendor
     * it filled far more than the hole — it DISPLACED THE JOB on a receipt into
     * a site warehouse, putting the supplier at the top of a sheet that already
     * names him twice and leaving the job in small print.
     */
    public function test_a_receipt_into_a_site_warehouse_is_headed_by_the_job(): void
    {
        $html = $this->forms->html('penerimaan-barang', ['id' => $this->receipt()->id]);

        $this->assertStringContainsString(
            '<div class="judul">PENGEMBANGAN BANDAR UDARA SULTAN HASANUDIN - MAKASSAR</div>',
            $html,
        );
        // And the supplier is not lost: he is the counterparty of the band.
        $this->assertStringContainsString('PEMASOK / VENDOR', $html);
        $this->assertStringContainsString('PT Semen Distribusi Utama', $html);
    }

    /** No job behind the shed, so the supplier heads it. */
    public function test_a_receipt_into_a_central_warehouse_is_headed_by_the_supplier(): void
    {
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->gudangPusat,
            [[$this->semen, 10, 75_000]],
            '2026-03-04',
            ['vendor_id' => $this->vendor()->id],
        ));

        $html = $this->forms->html('penerimaan-barang', ['id' => $grn->id]);

        $this->assertStringContainsString('<div class="judul">PT SEMEN DISTRIBUSI UTAMA</div>', $html);
    }

    /**
     * And with neither — the shape the live data actually holds, a found-stock
     * receipt into the central store — the warehouse heads it. The branch that
     * used to be a blank bold line.
     */
    public function test_a_receipt_with_no_job_and_no_supplier_is_headed_by_the_warehouse(): void
    {
        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->gudangPusat, [[$this->semen, 10, 75_000]], '2026-03-04')
        );

        $html = $this->forms->html('penerimaan-barang', ['id' => $grn->id]);

        $this->assertStringContainsString('<div class="judul">GUDANG PUSAT CAKUNG</div>', $html);
    }

    /**
     * The one column on this sheet the ERP cannot answer at all. There is no
     * qty_rejected on inv_goods_receipt_items and no rejection document — a
     * partial rejection is handled afterwards as a retur pembelian — so the
     * storeman writes it on the line, and the sheet says so rather than
     * leaving the reader to guess what the empty column is for.
     */
    public function test_the_condition_column_is_ruled_on_every_line_and_footnoted(): void
    {
        $html = $this->forms->html('penerimaan-barang', ['id' => $this->receipt()->id]);

        // Two lines, two ruled kondisi cells, and nothing else ruled.
        $this->assertSame(2, $this->fills($html));
        $this->assertStringContainsString('KONDISI / KETERANGAN', $html);
        $this->assertStringContainsString('tidak menyimpan jumlah barang ditolak', $html);
    }

    /**
     * A zero on a GRN line is not the zero the rest of this module carries: the
     * receiving clerk types the price, and a zero-cost line has to be
     * confirmed explicitly before the API accepts it (temuan #72). It is an
     * assertion somebody made, so it prints.
     */
    public function test_a_confirmed_zero_cost_receipt_line_prints_zero_rather_than_a_rule(): void
    {
        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->gudangPusat, [[$this->semen, 5, 0]], '2026-03-02')
        );

        $html = $this->forms->html('penerimaan-barang', ['id' => $grn->id]);

        $this->assertStringContainsString('0,00', $html);
        // Only the kondisi cell of the single line.
        $this->assertSame(1, $this->fills($html));
    }

    // ------------------------------------------------------- bon material

    public function test_the_issue_sheet_carries_the_project_band_and_the_house_day_count(): void
    {
        $issue = $this->postedIssue();

        $html = $this->forms->html('bon-material', ['id' => $issue->id]);

        $this->assertStringContainsString('BON PENGELUARAN BARANG', $html);
        $this->assertStringContainsString('Form F/BM', $html);
        $this->assertStringContainsString($issue->code, $html);
        // A bon is a SITE document: it is filed in the project folder, so it
        // carries the four-party band and the contract day count with it.
        $this->assertStringContainsString('KONSULTAN MK', $html);
        $this->assertStringContainsString('PT Ciriajasa Cipta Mandiri', $html);
        $this->assertStringContainsString('HARI KE', $html);
        $this->assertStringContainsString('Pengembangan Bandar Udara Sultan Hasanudin', $html);
        $this->assertStringContainsString('Pengecoran kolom lantai 3 zona B', $html);
        $this->assertStringContainsString('C.1', $html);
        // 40 zak at the 75.000 postIssue froze off the warehouse average.
        $this->assertStringContainsString('75.000,00', $html);
        $this->assertStringContainsString('3.000.000,00', $html);
    }

    /**
     * inv_issue_items.unit_cost is 0 until postIssue reads the warehouse
     * average. Printed as "0,00" on a draft bon it states a value for material
     * the storeman is about to hand over — the figure a project manager reads
     * when he asks what left the gudang this week.
     */
    public function test_an_unposted_bon_rules_its_prices_instead_of_printing_zero(): void
    {
        $issue = $this->makeIssue(
            $this->gudangProyek,
            [[$this->semen, 40]],
            (int) $this->project->id,
            '2026-03-15',
        );

        $html = $this->forms->html('bon-material', ['id' => $issue->id]);

        // Two ruled money cells on the line, one ruled total, one ruled WBS.
        $this->assertSame(4, $this->fills($html));
        $this->assertStringNotContainsString('0,00', $html);
        $this->assertStringContainsString('Draf', $html);
    }

    /**
     * A cancelled bon must never be handed over as a live one. The reversal
     * lives in the ledger where nobody at the gudang will look; on the paper
     * it belongs where the reader's eye already is.
     */
    public function test_a_cancelled_bon_says_so_on_the_paper(): void
    {
        $issue = $this->postedIssue();
        $this->stock()->cancelIssue($issue, 'Salah proyek, dibuatkan bon pengganti.', $this->warehouseUser()->id);

        $html = $this->forms->html('bon-material', ['id' => $issue->id]);

        $this->assertStringContainsString('Dibatalkan', $html);
        $this->assertStringContainsString('Salah proyek', $html);
    }

    /**
     * The bon's heading takes the same fall-through: the job, else the shed it
     * came out of.
     *
     * An OFFICE bon is the branch that earns it — inv_issues.project_id is
     * nullable and a bon drawn on the central store for the workshop
     * legitimately has none, which used to print a blank bold line above the
     * form title.
     */
    public function test_an_office_bon_is_headed_by_the_warehouse_it_came_out_of(): void
    {
        $this->receiveStock($this->gudangPusat, $this->semen, 50, 15_000, '2026-03-01');
        $issue = $this->stock()->postIssue(
            $this->makeIssue($this->gudangPusat, [[$this->semen, 5]], null, '2026-03-15')
        );

        $html = $this->forms->html('bon-material', ['id' => $issue->id]);

        $this->assertStringContainsString('<div class="judul">GUDANG PUSAT CAKUNG</div>', $html);
        // No job, so no contract arithmetic to print about one.
        $this->assertStringNotContainsString('HARI KE', $html);
    }

    /** And a site bon is headed by the job, not by the shed. */
    public function test_a_site_bon_is_headed_by_the_job(): void
    {
        $html = $this->forms->html('bon-material', ['id' => $this->postedIssue()->id]);

        $this->assertStringContainsString(
            '<div class="judul">PENGEMBANGAN BANDAR UDARA SULTAN HASANUDIN - MAKASSAR</div>',
            $html,
        );
    }

    // ------------------------------------------------ surat jalan transfer

    public function test_the_transfer_note_names_both_warehouses_and_rules_the_vehicle(): void
    {
        $transfer = $this->sentTransfer();

        $html = $this->forms->html('surat-jalan-transfer', ['id' => $transfer->id]);

        $this->assertStringContainsString('SURAT JALAN ANTAR GUDANG', $html);
        $this->assertStringContainsString('Form F/SJ', $html);
        $this->assertStringContainsString($transfer->code, $html);
        $this->assertStringContainsString('Gudang Pusat Cakung', $html);
        $this->assertStringContainsString('Gudang Proyek Makassar', $html);
        $this->assertStringContainsString('20 Maret 2026', $html);
        // Nothing in this ERP records a truck or a plate.
        $this->assertStringContainsString('KENDARAAN', $html);
        $this->assertStringContainsString('NO. POLISI', $html);
        // 20 zak at the 15.000 sendTransfer froze off the source average.
        $this->assertStringContainsString('15.000,00', $html);
        $this->assertStringContainsString('300.000,00', $html);
    }

    /**
     * The sharpest case in this file. A draft transfer's unit_cost is a column
     * default, not a price — TransferService writes 0 and sendTransfer freezes
     * the real one — and a surat jalan is exactly the paper an insurer is
     * shown when a truck is robbed.
     */
    public function test_a_draft_transfer_note_rules_every_value_it_has_not_frozen_yet(): void
    {
        $transfer = $this->makeTransfer($this->gudangPusat, $this->gudangProyek, [[$this->semen, 20]], '2026-03-20');

        $html = $this->forms->html('surat-jalan-transfer', ['id' => $transfer->id]);

        $this->assertStringNotContainsString('0,00', $html);
        // Harga satuan, jumlah, the hand-filled qty diterima, and the total.
        $this->assertSame(4, $this->fills($html));
    }

    /** Both ends in the heading, joined by the arrow. Half a route is half a document. */
    public function test_the_transfer_note_is_headed_by_both_ends(): void
    {
        $html = $this->forms->html('surat-jalan-transfer', ['id' => $this->sentTransfer()->id]);

        $this->assertStringContainsString(
            '<div class="judul">GUDANG PUSAT CAKUNG → GUDANG PROYEK MAKASSAR</div>',
            $html,
        );
    }

    /**
     * A warehouse name that begins with a multi-byte character survives the
     * heading intact.
     *
     * The first version of this heading built "A → B" unconditionally and then
     * trimmed the arrow off with trim($s, " \t\n\r\0\x0B→"). trim()'s character
     * list is a list of BYTES and '→' is three of them (E2 86 92), so it
     * stripped those bytes individually from both ends of the whole string: a
     * name opening with any U+2xxx character — an em dash, an ellipsis, the
     * curly quotes a keyboard produces by itself — lost its leading E2 and came
     * out as "\x80\x9CGudang Transit” Cakung", which is not even valid UTF-8,
     * at the top of a signed surat jalan.
     */
    public function test_a_transfer_heading_keeps_a_multi_byte_warehouse_name_whole(): void
    {
        $transit = $this->makeWarehouse('WH-TRANSIT', ['name' => '“Gudang Transit” Cakung']);
        $this->receiveStock($transit, $this->semen, 40, 15_000, '2026-03-01');

        $transfer = $this->stock()->sendTransfer(
            $this->makeTransfer($transit, $this->gudangProyek, [[$this->semen, 20]], '2026-03-20')
        );

        $html = $this->forms->html('surat-jalan-transfer', ['id' => $transfer->id]);

        $this->assertStringContainsString('“GUDANG TRANSIT” CAKUNG → GUDANG PROYEK MAKASSAR', $html);
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'the sheet must be valid UTF-8');
    }

    // ------------------------------------------------- berita acara opname

    public function test_the_opname_sheet_prints_system_counted_and_the_valued_difference(): void
    {
        $this->receipt();
        $adjustment = $this->makeAdjustment($this->gudangProyek, [[$this->semen, 196]], '2026-03-26');
        $this->stock()->postAdjustment($adjustment);

        $html = $this->forms->html('berita-acara-opname', ['id' => $adjustment->refresh()->id]);

        $this->assertStringContainsString('BERITA ACARA STOCK OPNAME', $html);
        $this->assertStringContainsString('Form F/BAO', $html);
        $this->assertStringContainsString('<body class="landscape">', $html);
        $this->assertStringContainsString('Stock Opname', $html);
        $this->assertStringContainsString('Gudang Proyek Makassar', $html);
        // 200 in the system, 196 counted, 4 short at 75.000 = -300.000.
        $this->assertStringContainsString('200', $html);
        $this->assertStringContainsString('196', $html);
        $this->assertStringContainsString('-4', $html);
        $this->assertStringContainsString('-300.000,00', $html);
        // The JOB heads the sheet, not the shed. This opname counts a SITE
        // warehouse and header.project borrows that warehouse's project for the
        // PROYEK box; a warehouse heading displaced it. GUDANG is still an
        // identity line, so the shed is not lost.
        $this->assertStringContainsString(
            '<div class="judul">PENGEMBANGAN BANDAR UDARA SULTAN HASANUDIN - MAKASSAR</div>',
            $html,
        );
    }

    /**
     * A CENTRAL warehouse belongs to no project, and that is the hole the
     * title fills: without it the centred heading came out as an empty bold
     * line. It is the only branch the warehouse name heads.
     */
    public function test_an_opname_of_a_central_warehouse_heads_itself_with_the_warehouse(): void
    {
        $adjustment = $this->makeAdjustment($this->gudangPusat, [[$this->semen, 5]], '2026-03-26');

        $html = $this->forms->html('berita-acara-opname', ['id' => $adjustment->id]);

        $this->assertStringContainsString('<div class="judul">GUDANG PUSAT CAKUNG</div>', $html);
    }

    /**
     * inv_stock_adjustment_items.unit_cost is 0 until postAdjustment values
     * the count against the warehouse average. An unposted berita acara that
     * printed "0,00" under NILAI SELISIH would tell the two people signing it
     * that a shortfall of four zak costs nothing.
     */
    public function test_an_unposted_opname_rules_the_value_of_the_difference(): void
    {
        $this->receipt();
        $adjustment = $this->makeAdjustment($this->gudangProyek, [[$this->semen, 196]], '2026-03-26', approve: false);

        $html = $this->forms->html('berita-acara-opname', ['id' => $adjustment->id]);

        $this->assertStringContainsString('-4', $html);
        $this->assertStringNotContainsString('0,00', $html);
        // HPP, nilai selisih and the ruled alasan on the line, plus the total.
        $this->assertSame(4, $this->fills($html));
    }

    // ---------------------------------------------------------- saldo stok

    public function test_the_stock_sheet_lists_the_warehouse_balances_and_their_value(): void
    {
        $this->receipt();
        $this->postedIssue();

        $html = $this->forms->html('saldo-stok', ['id' => $this->gudangProyek->id]);

        $this->assertStringContainsString('DAFTAR SALDO STOK', $html);
        $this->assertStringContainsString('Form F/SS', $html);
        $this->assertStringContainsString('<body class="landscape">', $html);
        $this->assertStringContainsString('WH-MKS', $html);
        $this->assertStringContainsString('Jl. Bandara Baru, Mandai, Maros', $html);
        $this->assertStringContainsString('Sugeng Riyadi', $html);
        // 160 zak at 75.000 and 100 btg at 140.000 = 26.000.000.
        $this->assertStringContainsString('12.000.000,00', $html);
        $this->assertStringContainsString('14.000.000,00', $html);
        $this->assertStringContainsString('26.000.000,00', $html);
    }

    /**
     * T28/T29's figure, on the paper this time. Goods on the road are in
     * NEITHER warehouse balance, so a stock take that does not name them
     * reports a shortfall at the sending end and a surplus at the receiving
     * one — and the two are never reconciled because nobody knows they are the
     * same twenty zak.
     */
    public function test_the_stock_sheet_names_the_goods_in_transit_at_both_ends(): void
    {
        $this->receipt();
        $this->sentTransfer();

        $masuk = $this->forms->html('saldo-stok', ['id' => $this->gudangProyek->id]);
        $keluar = $this->forms->html('saldo-stok', ['id' => $this->gudangPusat->id]);

        $this->assertStringContainsString('BARANG DALAM PERJALANAN', $masuk);
        $this->assertStringContainsString('Masuk', $masuk);
        $this->assertStringContainsString('300.000,00', $masuk);

        $this->assertStringContainsString('Keluar', $keluar);
        $this->assertStringContainsString('300.000,00', $keluar);
    }

    public function test_a_warehouse_with_nothing_on_the_road_says_so(): void
    {
        $this->receipt();

        $html = $this->forms->html('saldo-stok', ['id' => $this->gudangProyek->id]);

        $this->assertStringContainsString('Tidak ada barang dalam perjalanan', $html);
    }

    /**
     * inv_stock_balances keeps no history: qty and avg_cost are today's, and
     * nothing in this ERP can reconstruct what they were in June. A ?tanggal=
     * in the URL must therefore NOT reach the sheet — it would turn a live
     * listing into a dated claim about a month whose figures nobody has.
     */
    public function test_the_stock_sheet_is_dated_by_the_printer_not_by_the_url(): void
    {
        $this->receipt();

        $html = $this->forms->html('saldo-stok', [
            'id' => $this->gudangProyek->id,
            'date' => '2026-01-31',
        ]);

        $this->assertStringNotContainsString('31 Januari 2026', $html);
        // Not merely "not January": the sheet states the day it was printed,
        // which is the only day its figures belong to.
        $this->assertStringContainsString('SALDO PER TANGGAL', $html);
        $this->assertStringContainsString($this->todayLabel(), $html);
    }

    // ------------------------------------------------------ retur pembelian

    public function test_the_purchase_return_sheet_mirrors_the_receipt_it_sends_back(): void
    {
        $grn = $this->receipt();
        $return = $this->stock()->postPurchaseReturn($this->draftPurchaseReturn($grn));

        $html = $this->forms->html('retur-pembelian', ['id' => $return->id]);

        $this->assertStringContainsString('RETUR PEMBELIAN', $html);
        $this->assertStringContainsString('Form F/RPB', $html);
        $this->assertStringContainsString($return->code, $html);
        $this->assertStringContainsString($grn->code, $html);
        $this->assertStringContainsString('SJ/BNS/2026/03/0912', $html);
        $this->assertStringContainsString('PT Semen Distribusi Utama', $html);
        $this->assertStringContainsString('Besi beton D16 panjang 12 m', $html);
        // Ten of the hundred received, at the 140.000 they came in at.
        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('140.000,00', $html);
        $this->assertStringContainsString('1.400.000,00', $html);
        $this->assertStringContainsString('besi bengkok dan berkarat', $html);
    }

    public function test_an_unposted_purchase_return_rules_its_prices(): void
    {
        $return = $this->draftPurchaseReturn($this->receipt());

        $html = $this->forms->html('retur-pembelian', ['id' => $return->id]);

        $this->assertStringNotContainsString('0,00', $html);
        // Harga satuan and jumlah on the line, plus the ruled total.
        $this->assertSame(3, $this->fills($html));
    }

    // ------------------------------------------------------- retur material

    public function test_the_material_return_sheet_mirrors_the_bon_it_comes_back_on(): void
    {
        $issue = $this->postedIssue();
        $return = $this->stock()->postIssueReturn($this->draftIssueReturn($issue));

        $html = $this->forms->html('retur-material', ['id' => $return->id]);

        $this->assertStringContainsString('RETUR MATERIAL', $html);
        $this->assertStringContainsString('Form F/RTM', $html);
        $this->assertStringContainsString($return->code, $html);
        $this->assertStringContainsString($issue->code, $html);
        $this->assertStringContainsString('Pengembangan Bandar Udara Sultan Hasanudin', $html);
        // 8 of the 40 zak back at the 75.000 they left at = 600.000.
        $this->assertStringContainsString('75.000,00', $html);
        $this->assertStringContainsString('600.000,00', $html);
        $this->assertStringContainsString('Sisa material pengecoran', $html);
    }

    // ------------------------------------------------------------ endpoint

    public function test_every_inventory_document_is_catalogued_for_its_resource(): void
    {
        $catalogue = collect(
            $this->actingAs($this->adminUser())
                ->getJson('/api/core/print/forms')
                ->assertOk()
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('inventory/goods-receipts', $catalogue['penerimaan-barang']['resource']);
        $this->assertSame('inventory/issues', $catalogue['bon-material']['resource']);
        $this->assertSame('inventory/transfers', $catalogue['surat-jalan-transfer']['resource']);
        $this->assertSame('inventory/stock-adjustments', $catalogue['berita-acara-opname']['resource']);
        $this->assertSame('inventory/warehouses', $catalogue['saldo-stok']['resource']);
        $this->assertSame('inventory/purchase-returns', $catalogue['retur-pembelian']['resource']);
        $this->assertSame('inventory/issue-returns', $catalogue['retur-material']['resource']);
    }

    public function test_printing_an_inventory_document_needs_the_modules_view(): void
    {
        $grn = $this->receipt();
        $user = $this->adminUser();
        $user->roles->first()->revokePermissionTo('inv.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->refresh())
            ->get("/api/core/print/forms/penerimaan-barang/{$grn->id}")
            ->assertForbidden();
    }

    // ------------------------------------------- baris yang sumbernya hilang

    /**
     * An item deleted since the document was written keeps its name on the
     * paper.
     *
     * An item may be deleted once its stock reaches zero
     * (DeletedItemGuardsTest), and every historical receipt, bon, surat jalan,
     * opname and retur that named it stays in the database. Loaded plainly the
     * relation came back null and the sheet ruled KODE, URAIAN BARANG and SAT
     * beside a real 200, a real 75.000,00 and a real 15.000.000,00 — a signed
     * receipt for goods it cannot name, with a dotted blank next to money.
     * Nothing was fabricated; what was lost was a name the database still
     * holds.
     */
    public function test_a_deleted_item_keeps_its_name_on_the_receipt_it_arrived_on(): void
    {
        $grn = $this->receipt();

        $this->semen->delete();

        $html = $this->forms->html('penerimaan-barang', ['id' => $grn->id]);

        $this->assertStringContainsString($this->semen->code, $html);
        $this->assertStringContainsString($this->semen->name, $html);
        $this->assertStringContainsString('15.000.000,00', $html);
    }

    /** The same on the bon it went out on. */
    public function test_a_deleted_item_keeps_its_name_on_the_bon(): void
    {
        $issue = $this->postedIssue();

        $this->semen->delete();

        $html = $this->forms->html('bon-material', ['id' => $issue->id]);

        $this->assertStringContainsString($this->semen->code, $html);
        $this->assertStringContainsString($this->semen->name, $html);
    }

    /**
     * And on the other four documents that name items, because the eager load
     * is declared per document and one missing constraint is one sheet.
     *
     * A surat jalan is the paper the driver carries and the paper an insurer is
     * shown; KODE and URAIAN BARANG ruled beside a real 20 zak and a real
     * 300.000,00 is a delivery note for goods it cannot name.
     */
    public function test_a_deleted_item_keeps_its_name_on_the_transfer_note(): void
    {
        $transfer = $this->sentTransfer();

        $this->semen->delete();

        $html = $this->forms->html('surat-jalan-transfer', ['id' => $transfer->id]);

        $this->assertStringContainsString($this->semen->code, $html);
        $this->assertStringContainsString($this->semen->name, $html);
        $this->assertStringContainsString('300.000,00', $html);
    }

    /** The opname: two signatures against a counted difference. */
    public function test_a_deleted_item_keeps_its_name_on_the_opname(): void
    {
        $this->receipt();
        $adjustment = $this->makeAdjustment($this->gudangProyek, [[$this->semen, 196]], '2026-03-26');
        $this->stock()->postAdjustment($adjustment);

        $this->semen->delete();

        $html = $this->forms->html('berita-acara-opname', ['id' => $adjustment->refresh()->id]);

        $this->assertStringContainsString($this->semen->code, $html);
        $this->assertStringContainsString($this->semen->name, $html);
        $this->assertStringContainsString('-300.000,00', $html);
    }

    /** The retur pembelian: the sheet the vendor's driver argues about. */
    public function test_a_deleted_item_keeps_its_name_on_the_purchase_return(): void
    {
        $grn = $this->receipt();
        $return = $this->stock()->postPurchaseReturn($this->draftPurchaseReturn($grn));

        $this->besi->delete();

        $html = $this->forms->html('retur-pembelian', ['id' => $return->id]);

        $this->assertStringContainsString($this->besi->code, $html);
        $this->assertStringContainsString($this->besi->name, $html);
        $this->assertStringContainsString('1.400.000,00', $html);
    }

    /** And the retur material, which credits a job for what came back. */
    public function test_a_deleted_item_keeps_its_name_on_the_material_return(): void
    {
        $issue = $this->postedIssue();
        $return = $this->stock()->postIssueReturn($this->draftIssueReturn($issue));

        $this->semen->delete();

        $html = $this->forms->html('retur-material', ['id' => $return->id]);

        $this->assertStringContainsString($this->semen->code, $html);
        $this->assertStringContainsString($this->semen->name, $html);
        $this->assertStringContainsString('600.000,00', $html);
    }

    /**
     * A shed closed while the lorry was still on the road keeps its name in
     * the BARANG DALAM PERJALANAN table — at BOTH ends.
     *
     * inv_warehouses soft-deletes (a site finishes and its shed is retired)
     * and a transfer stays in transit until somebody receives it, so this is
     * exactly the row that outlives one of its two warehouses. It is also the
     * one figure a counting team uses to reconcile a shortfall at one end
     * against a surplus at the other: GUDANG LAWAN ruled beside a real 20 zak
     * and a real 300.000,00 leaves the count with nothing to reconcile
     * against, and the twenty zak are written off at one shed and found at the
     * other. Both directions are asserted because they are two declarations —
     * fromWarehouse and toWarehouse — and one dropped constraint is one sheet.
     */
    public function test_a_retired_warehouse_is_still_named_on_the_goods_in_transit(): void
    {
        $this->receipt();
        $this->sentTransfer();

        // The receiving shed is closed while the goods are still on the road.
        $this->gudangProyek->delete();

        $keluar = $this->forms->html('saldo-stok', ['id' => $this->gudangPusat->id]);

        $this->assertStringContainsString('Gudang Proyek Makassar', $keluar);
        $this->assertStringContainsString('300.000,00', $keluar);

        // And the other way round, from the receiving shed's own sheet.
        $this->gudangProyek->restore();
        $this->gudangPusat->delete();

        $masuk = $this->forms->html('saldo-stok', ['id' => $this->gudangProyek->id]);

        $this->assertStringContainsString('Gudang Pusat Cakung', $masuk);
        $this->assertStringContainsString('300.000,00', $masuk);
    }

    /**
     * A bon whose project has since been soft-deleted is still a SITE bon.
     *
     * Without withTrashed on Issue::project it demoted silently to an office
     * bon: no PROYEK box, no centred project name, and the whole house
     * identity block — waktu pelaksanaan, hari ke, minggu ke, the lines that
     * are the reason a bon carries the house block at all — gone from a sheet
     * filed as a site document. FormPrintService::laporanHarian already takes
     * this position for the daily report, in those words.
     */
    public function test_a_bon_for_a_deleted_project_is_still_a_site_bon(): void
    {
        $issue = $this->postedIssue();

        $this->project->delete();

        $html = $this->forms->html('bon-material', ['id' => $issue->id]);

        $this->assertStringContainsString($this->project->name, $html);
        $this->assertStringContainsString('HARI KE', $html);
    }

    /**
     * The same question asked of the WAREHOUSE relation, which is where five
     * documents get their job from.
     *
     * Asserted on Warehouse::project() rather than on a rendered sheet, and
     * that is deliberate: every registry entry that reaches this relation
     * (penerimaan-barang, berita-acara-opname, retur-pembelian,
     * surat-jalan-transfer through from/toWarehouse, saldo-stok through the
     * warehouse itself) declares `withTrashed` on its own eager load, so all
     * five sheets already print the job and a sheet-level assertion here would
     * pass with the relation reverted. The relation is what was wrong: loaded
     * plainly it answered NULL, so the guarantee rested on five declarations in
     * a file none of these documents owns, and a sixth caller — or one dropped
     * constraint — turns a site document into an office one with nothing
     * failing. Issue::project() settled this for the bon; this is the same
     * decision for the shed.
     */
    public function test_a_site_warehouse_still_names_a_project_that_has_been_deleted(): void
    {
        $this->project->delete();

        $warehouse = Warehouse::query()->findOrFail($this->gudangProyek->id);

        $this->assertSame($this->project->name, $warehouse->project?->name);
    }

    /**
     * And the distinction the relation exists to draw is still drawn: a CENTRAL
     * warehouse belongs to NO job, and withTrashed must not turn that into one.
     */
    public function test_a_central_warehouse_still_belongs_to_no_project(): void
    {
        $warehouse = Warehouse::query()->findOrFail($this->gudangPusat->id);

        $this->assertNull($warehouse->project);
        $this->assertFalse($warehouse->isSiteWarehouse());
    }
}
