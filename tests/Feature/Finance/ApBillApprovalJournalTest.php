<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\StockService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Approving an AP bill books
 *
 *   Dr <biaya / persediaan>           dpp
 *   Dr 1-1600 PPN Masukan             ppn_amount
 *   Cr 2-1100 Hutang Usaha            total_payable
 *   Cr 2-12x0 Hutang PPh              pph_amount
 *
 * which balances because dpp + ppn = total_payable + pph, and feeds the project
 * cost ledger with the DPP.
 *
 * A bill against a GOODS purchase order (one with a deliver-to warehouse) under
 * perpetual inventory follows the three-way match instead: the goods must be in
 * before the invoice can be approved, the debit clears GR/IR for exactly the
 * value received, and the invoice/receipt difference goes to 6-4500. The goods
 * themselves book no project cost — that happens when the material is issued —
 * but the difference does, because the GL charges it to the project.
 */
class ApBillApprovalJournalTest extends ErpTestCase
{
    use FinanceFixtures;

    private Vendor $vendor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->vendor = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    public function test_a_project_bill_books_a_balanced_journal_and_a_project_cost(): void
    {
        $pph = $this->makePph23Tax(2.0);

        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'bill_date' => '2026-03-10',
            'description' => 'Sewa alat berat Maret 2026',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
            'pph_tax_id' => $pph->id,
        ]));

        // 100.000.000 * 2% = 2.000.000 => payable 100.000.000 + 11.000.000 - 2.000.000 = 109.000.000
        $this->assertSame(2000000.0, (float) $bill->pph_amount);
        $this->assertSame(109000000.0, (float) $bill->total_payable);

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Tanpa PO dan tanpa opname subkon => kategori overhead => 5-1500.
        $this->assertSame(100000000.0, $lines['5-1500']['debit']);
        $this->assertSame(11000000.0, $lines['1-1600']['debit']);
        $this->assertSame(109000000.0, $lines['2-1100']['credit']);
        $this->assertSame(2000000.0, $lines['2-1220']['credit']); // akun dari tax row PPh 23
        $this->assertSame((int) $this->project->id, $lines['5-1500']['project_id']);

        // 100.000.000 + 11.000.000 = 109.000.000 + 2.000.000 = 111.000.000
        $this->assertSame(111000000.0, $journal->totalDebit());
        $this->assertSame(111000000.0, $journal->totalCredit());

        $cost = ProjectCost::query()->where('reference_type', 'ap_bill')->sole();

        // Realisasi memakai DPP: PPN masukan bisa dikreditkan, jadi bukan biaya.
        $this->assertSame(100000000.0, (float) $cost->amount);
        $this->assertSame('overhead', $cost->cost_category->value);
        $this->assertSame((int) $this->project->id, (int) $cost->project_id);
        $this->assertSame('2026-03-10', $cost->cost_date->toDateString());
    }

    public function test_a_bill_without_a_project_hits_general_opex_and_no_cost_ledger(): void
    {
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Langganan internet kantor',
            'dpp' => 5000000,
            'ppn_amount' => 550000,
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(5000000.0, $lines['6-4100']['debit']);
        $this->assertSame(550000.0, $lines['1-1600']['debit']);
        $this->assertSame(5550000.0, $lines['2-1100']['credit']);
        $this->assertSame(0, ProjectCost::query()->count());
    }

    /**
     * The PPh liability account is READ, never guessed.
     *
     * This used to fall back to 2-1220 Hutang PPh 23 for any withholding with
     * no tax row behind it, which is how PPh final Pasal 4(2) ended up in the
     * PPh 23 liability. The pairing is now enforced at the source instead —
     * see BillTaxFieldsTest for the refusal — so the only thing left to pin
     * here is that the account comes from the named tax.
     */
    public function test_the_withholding_is_credited_to_the_account_its_own_tax_row_names(): void
    {
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Jasa konsultan',
            'dpp' => 50000000,
            'pph_tax_id' => $this->makePph23Tax(2.0)->id,   // 2-1220 Hutang PPh 23
            'pph_amount' => 1000000,
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // 50.000.000 - 1.000.000 = 49.000.000
        $this->assertSame(49000000.0, $lines['2-1100']['credit']);
        $this->assertSame(1000000.0, $lines['2-1220']['credit']);
    }

    /**
     * The same amount under a PPh final row lands in 2-1230, not 2-1220 — the
     * distinction the fallback erased. Two SSPs are filed on the 10th and each
     * has to carry its own money.
     */
    public function test_pph_final_konstruksi_is_credited_to_its_own_liability_not_to_pph_23(): void
    {
        $bill = $this->approveBill($this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Opname subkon struktur',
            'dpp' => 50000000,
            'pph_tax_id' => $this->makePphFinalTax()->id,   // 2-1230 Hutang PPh Final 4(2)
            'pph_amount' => 1325000,                        // 50jt x 2,65%
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(1325000.0, $lines['2-1230']['credit']);
        $this->assertArrayNotHasKey('2-1220', $lines);
    }

    // ------------------------------------------------------------ services PO (classic)

    public function test_a_services_po_bill_keeps_the_classic_treatment(): void
    {
        // No warehouse_id => jasa/sewa, tidak ada barang yang masuk gudang, jadi
        // tidak ada GR/IR untuk dicocokkan.
        $po = $this->makePurchaseOrder($this->vendor, ['project_id' => $this->project->id]);

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // PO => kategori material => 5-1100 karena tagihan membawa proyek.
        $this->assertSame(100000000.0, $lines['5-1100']['debit']);
        $this->assertSame(11000000.0, $lines['1-1600']['debit']);
        $this->assertSame(111000000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);

        $cost = ProjectCost::query()->where('reference_type', 'ap_bill')->sole();
        $this->assertSame(100000000.0, (float) $cost->amount);
        $this->assertSame('material', $cost->cost_category->value);
    }

    public function test_a_non_project_services_po_bill_is_an_expense_and_never_inventory(): void
    {
        // "Sewa genset kantor": a PO with no stock line at all. It used to debit
        // 1-1400 Persediaan Material purely because a purchase order was named,
        // which parked the rental in an asset account against a stock
        // sub-ledger of 0,00 — permanently, since no issue can ever relieve
        // stock that does not exist — and never expensed it.
        $po = $this->makePurchaseOrder($this->vendor); // PO jasa tanpa proyek

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(100000000.0, $lines['6-4100']['debit']);
        $this->assertArrayNotHasKey('1-1400', $lines);
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_non_project_stock_po_bill_capitalises_only_under_periodic_inventory(): void
    {
        // Periodic inventory: the goods receipt posts nothing, so this bill is
        // the only entry that can put the goods on the balance sheet.
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makePurchaseOrder($this->vendor, ['warehouse_id' => $this->warehouse()->id]);
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id, // a real STOCK line
            'description' => 'Semen Gresik 40kg',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 1000,
        ]);

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(100000000.0, $lines['1-1400']['debit']);
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_a_non_project_stock_po_bill_under_perpetual_inventory_is_an_expense(): void
    {
        // Perpetual, but nothing was ever received — the bill reaches the
        // classic path with no clearing to debit, which is only possible on a
        // CLOSED po (a short shipment the buyer accepted). Goods that never
        // arrived are not inventory: there is no sub-ledger row behind them.
        $po = $this->makePurchaseOrder($this->vendor, [
            'warehouse_id' => $this->warehouse()->id,
            'status' => DocumentStatus::Closed,
        ]);
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 0,
        ]);

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(100000000.0, $lines['6-4100']['debit']);
        $this->assertArrayNotHasKey('1-1400', $lines);
        $this->assertArrayNotHasKey('2-1150', $lines);

        // And the GL still agrees with the stock sub-ledger: both are empty.
        $this->assertSame(0.0, $this->accountNet('1-1400'));
    }

    // ------------------------------------------------------------ goods PO (three-way match)

    public function test_a_goods_po_bill_clears_gr_ir_for_the_value_received_and_books_no_cost(): void
    {
        // Perpetual: GRN sudah membukukan Dr 1-1400 / Cr 2-1150 saat barang masuk.
        $po = $this->makeGoodsPurchaseOrder();
        $this->receiveGoods($po, 1000, 100000); // 1.000 * 100.000 = 100.000.000

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Tagihan hanya mengubah kewajiban GR/IR menjadi hutang usaha.
        $this->assertSame(100000000.0, $lines['2-1150']['debit']);
        $this->assertSame(11000000.0, $lines['1-1600']['debit']);
        $this->assertSame(111000000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('5-1100', $lines);
        $this->assertArrayNotHasKey('1-1400', $lines);
        $this->assertArrayNotHasKey('6-4500', $lines); // harga sama: tidak ada selisih

        // GRN kredit 100.000.000, tagihan debit 100.000.000 => GR/IR nol.
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // Biaya material diakui saat pemakaian, bukan saat penagihan.
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_billing_more_than_the_goods_cost_debits_the_purchase_variance(): void
    {
        // Barang diterima 40 dari 1.000, PO lalu ditutup (short shipment
        // diterima), tetapi vendor tetap menagih nilai PO penuh. Barang harus
        // diterima SELAGI PO masih disetujui — penerimaan atas PO tertutup
        // ditolak, karena kreditnya tidak akan pernah bisa ditagihkan.
        $po = $this->makeGoodsPurchaseOrder();
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 40,
        ]);
        $this->receiveGoods($po, 40, 100000); // 40 * 100.000 = 4.000.000

        $po->forceFill(['status' => DocumentStatus::Closed, 'closed_at' => now()])->save();

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // GR/IR hanya sebesar yang diterima; sisanya 100.000.000 - 4.000.000
        // = 96.000.000 masuk selisih harga pembelian sebagai beban.
        $this->assertSame(4000000.0, $lines['2-1150']['debit']);
        $this->assertSame(96000000.0, $lines['6-4500']['debit']);
        $this->assertSame(11000000.0, $lines['1-1600']['debit']);
        $this->assertSame(111000000.0, $lines['2-1100']['credit']);

        // 4.000.000 + 96.000.000 + 11.000.000 = 111.000.000
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // Selisihnya dibebankan ke proyek di buku besar (baris 6-4500 membawa
        // project_id), jadi realisasi proyek harus mencatat rupiah yang sama —
        // kalau tidak, laba-rugi proyek dan buku besar berbeda 96.000.000.
        $this->assertSame((int) $this->project->id, $lines['6-4500']['project_id']);

        $cost = ProjectCost::query()->where('reference_type', 'ap_bill')->sole();

        $this->assertSame(96000000.0, (float) $cost->amount);
        $this->assertSame('material', $cost->cost_category->value);
        $this->assertSame((int) $bill->id, (int) $cost->reference_id);
        $this->assertSame((int) $this->project->id, (int) $cost->project_id);
    }

    public function test_a_variance_in_the_companys_favour_reduces_the_project_realisation(): void
    {
        // Diterima 1.000 * 103.000 = 103.000.000, ditagih 100.000.000: vendor
        // menagih 3.000.000 lebih murah dari nilai barang yang datang. Buku
        // besar mengkredit 6-4500, jadi realisasi proyek harus turun sebesar
        // itu juga — nilainya negatif, bukan diabaikan.
        $po = $this->makeGoodsPurchaseOrder();
        $this->receiveGoods($po, 1000, 103000);

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));
        $this->assertSame(3000000.0, $lines['6-4500']['credit']);

        $cost = ProjectCost::query()->where('reference_type', 'ap_bill')->sole();

        $this->assertSame(-3000000.0, (float) $cost->amount);
        $this->assertSame('material', $cost->cost_category->value);
    }

    public function test_a_perfectly_matched_bill_books_no_project_cost_at_all(): void
    {
        // Tidak ada selisih => tidak ada biaya proyek dari tagihan; biaya
        // material diakui saat barang dipakai.
        $po = $this->makeGoodsPurchaseOrder();
        $this->receiveGoods($po, 1000, 100000);

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $this->assertArrayNotHasKey(
            '6-4500',
            $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id))
        );
        $this->assertSame(0, ProjectCost::query()->count());
    }

    public function test_billing_less_than_the_goods_cost_credits_the_purchase_variance(): void
    {
        // Barang diterima dengan harga 103.000/zak, vendor menagih harga PO 100.000.
        $po = $this->makeGoodsPurchaseOrder();
        $this->receiveGoods($po, 1000, 103000); // 1.000 * 103.000 = 103.000.000

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // 100.000.000 - 103.000.000 = -3.000.000 => selisih dikredit.
        $this->assertSame(103000000.0, $lines['2-1150']['debit']);
        $this->assertSame(3000000.0, $lines['6-4500']['credit']);
        $this->assertSame(0.0, $lines['6-4500']['debit']);
        $this->assertSame(11000000.0, $lines['1-1600']['debit']);
        $this->assertSame(111000000.0, $lines['2-1100']['credit']);

        // Debit 103.000.000 + 11.000.000 = kredit 3.000.000 + 111.000.000
        $this->assertSame(0.0, $this->accountNet('2-1150'));
    }

    public function test_a_goods_po_bill_is_refused_before_the_goods_receipt_is_posted(): void
    {
        // Invoice-first: inilah yang dulu menyebabkan biaya tercatat dua kali.
        // Baris PO menyebut item persediaan (item_id) — itulah definisi baris
        // barang menurut skema, dan itulah yang menahan tagihan sampai barang
        // benar-benar diterima. Gudang tujuan tidak lagi dipakai sebagai penanda.
        $po = $this->makeGoodsPurchaseOrder();
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 0,
        ]);
        $this->makeGoodsReceipt($po, 1000, 100000); // masih draft, belum diposting

        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $bill->submit($this->financeUser());

        $this->expectExceptionMessage("Tagihan atas {$po->code} hanya dapat disetujui setelah barang diterima");

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
        } finally {
            $this->assertSame(DocumentStatus::Submitted, $bill->fresh()->status);
            $this->assertSame(0, Journal::query()->count());
            $this->assertSame(0, ProjectCost::query()->count());
        }
    }

    public function test_a_goods_po_bill_is_refused_while_the_delivery_is_still_partial(): void
    {
        $po = $this->makeGoodsPurchaseOrder();
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 400,
        ]);
        $this->receiveGoods($po, 400, 100000);

        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $bill->submit($this->financeUser());

        $this->expectExceptionMessage("Tagihan atas {$po->code} hanya dapat disetujui setelah barang diterima seluruhnya");

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
        } finally {
            $this->assertSame(DocumentStatus::Submitted, $bill->fresh()->status);
            $this->assertNoJournalForBill((int) $bill->id);
            $this->assertSame(0, ProjectCost::query()->count());
        }
    }

    /**
     * T44. Closing the order is not a delivery.
     *
     * The audit's scenario, on the demo's own shape: PO/2026/III/0002 is late,
     * the buyer closes it — the one prc.update click the refusal above offers —
     * and Finance approves the vendor invoice in full. The project was charged
     * Rp 115.600.000 of material that had not arrived; when it arrived it was
     * booked against the vendor with no PO (what StockService's own refusal
     * instructs) and issuing it charged the project again: realisasi
     * 231.200.000 for one Rp 115,6 juta purchase.
     */
    public function test_a_project_bill_for_a_closed_stock_order_that_received_nothing_is_refused(): void
    {
        $po = $this->makeGoodsPurchaseOrder();
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 0,
        ]);

        // The buyer gives up on the delivery and closes the order.
        $po->forceFill(['status' => DocumentStatus::Closed, 'closed_at' => now()])->save();

        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $bill->submit($this->financeUser());

        $this->expectExceptionMessage(
            "Tagihan atas {$po->code} hanya dapat disetujui setelah barang diterima: belum ada penerimaan barang"
        );

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
        } finally {
            $this->assertSame(DocumentStatus::Submitted, $bill->fresh()->status);
            $this->assertNoJournalForBill((int) $bill->id);
            $this->assertSame(0, ProjectCost::query()->count());
            $this->assertSame(0.0, $this->accountNet('5-1100'));
        }
    }

    /**
     * The works-pair for the shape the guard must NOT catch: goods the order
     * never itemised. The vendor substitutes a different article, so the
     * receipt line matches no PO line and qty_received stays 0 on every one of
     * them — but the goods are in the warehouse and GR/IR carries their value.
     * A gate written on qty_received would refuse this delivery for ever; one
     * written on the receipt cannot.
     */
    public function test_a_delivery_the_order_never_itemised_can_still_be_billed_once_the_order_closes(): void
    {
        $po = $this->makeGoodsPurchaseOrder();
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 0,
        ]);

        // 300 zak of a DIFFERENT brand at 100.000: Rp 30.000.000 into stock and
        // into GR/IR, against a PO line that stays untouched at 0 received.
        $this->receiveGoods($po, 300, 100000, $this->substituteItem());

        $po->forceFill(['status' => DocumentStatus::Closed, 'closed_at' => now()])->save();

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Three-way match: GR/IR for what arrived, the rest is a purchase
        // difference — 100.000.000 - 30.000.000 = 70.000.000.
        $this->assertSame(30000000.0, $lines['2-1150']['debit']);
        $this->assertSame(70000000.0, $lines['6-4500']['debit']);
        $this->assertArrayNotHasKey('5-1100', $lines);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, (float) $po->items()->value('qty_received'));

        // Only the difference is a project cost; the goods are costed on issue.
        $this->assertSame(70000000.0, (float) ProjectCost::query()->sole()->amount);
    }

    /**
     * The other works-pair: material delivered straight to site. The order
     * names no deliver-to warehouse, so no goods receipt can ever exist and no
     * issue can ever charge it a second time — this bill IS the cost, and it
     * still reaches 5-1100 and the project ledger in full.
     */
    public function test_a_stock_order_delivered_straight_to_site_is_still_billed_as_project_cost(): void
    {
        $po = $this->makeGoodsPurchaseOrder(['warehouse_id' => null]);
        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->item()->id,
            'description' => 'Semen Gresik 40kg (kirim langsung ke lokasi)',
            'qty' => 1000,
            'unit' => 'zak',
            'unit_price' => 100000,
            'amount' => 100000000,
            'qty_received' => 0,
        ]);

        // Nothing will ever be "received", so the buyer closes the order — the
        // gate on an OPEN order is untouched by this fix.
        $po->forceFill(['status' => DocumentStatus::Closed, 'closed_at' => now()])->save();

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(100000000.0, $lines['5-1100']['debit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertSame(100000000.0, (float) ProjectCost::query()->sole()->amount);

        // And nothing entered persediaan, so the GL still says what the
        // warehouses say.
        $this->assertSame(0.0, $this->accountNet('1-1400'));
    }

    public function test_switching_to_periodic_inventory_restores_the_expense_debit(): void
    {
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPurchaseOrder();
        $this->receiveGoods($po, 1000, 100000); // periodik: GRN tidak menjurnal

        $bill = $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(100000000.0, $lines['5-1100']['debit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertArrayNotHasKey('6-4500', $lines);
        $this->assertSame(1, ProjectCost::query()->count());
    }

    // ------------------------------------------------------------ subcon path

    public function test_a_subcon_claim_bill_copies_the_opname_math_and_books_subcon_cost(): void
    {
        $this->makePphFinalTax('pelaksanaan_bersertifikat', 2.65);
        $subcontractor = $this->makeVendor(['name' => 'CV Karya Sipil Sejahtera', 'is_subcontractor' => true, 'classification' => 'sipil']);
        $spk = $this->makeSubcontract($subcontractor, ['project_id' => $this->project->id]);
        $claim = $this->makeProgressClaim($spk);

        $bill = $this->approveBill($this->apBills()->create([
            'subcontract_claim_id' => $claim->id,
            'bill_date' => '2026-03-10',
        ]));

        // Opname: bruto 100.000.000, PPN 11.000.000, PPh final 2,65% = 2.650.000,
        // retensi 5% = 5.000.000.
        //
        // The DPP stays GROSS: PPN and PPh final are charged on the work done,
        // not on what is paid this month. The retention only reduces the
        // payable, and becomes a liability back to the subcontractor.
        $this->assertSame(100000000.0, (float) $bill->dpp);
        $this->assertSame(11000000.0, (float) $bill->ppn_amount);
        $this->assertSame(2650000.0, (float) $bill->pph_amount);
        $this->assertSame(5000000.0, (float) $bill->retention_amount);
        // 100.000.000 + 11.000.000 - 2.650.000 - 5.000.000 = 103.350.000
        $this->assertSame(103350000.0, (float) $bill->total_payable);

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(100000000.0, $lines['5-1300']['debit']); // Beban Subkontraktor
        $this->assertSame(11000000.0, $lines['1-1600']['debit']);
        $this->assertSame(103350000.0, $lines['2-1100']['credit']);
        $this->assertSame(2650000.0, $lines['2-1230']['credit']); // Hutang PPh Final 4(2)
        // The retention the claim said it was holding is now actually held.
        $this->assertSame(5000000.0, $lines['2-1500']['credit']);

        $cost = ProjectCost::query()->where('reference_type', 'ap_bill')->sole();
        $this->assertSame(100000000.0, (float) $cost->amount);
        $this->assertSame('subcon', $cost->cost_category->value);
    }

    // ------------------------------------------------------------ guards

    public function test_billing_the_same_purchase_order_twice_is_refused(): void
    {
        $po = $this->makePurchaseOrder($this->vendor);
        $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);

        $this->expectExceptionMessage("A bill already exists for PO {$po->code}.");

        try {
            $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-20']);
        } finally {
            $this->assertSame(1, ApBill::query()->where('purchase_order_id', $po->id)->count());
        }
    }

    public function test_billing_the_same_subcon_claim_twice_is_refused(): void
    {
        $this->makePphFinalTax();
        $subcontractor = $this->makeVendor(['name' => 'CV Karya Sipil Sejahtera', 'is_subcontractor' => true, 'classification' => 'sipil']);
        $claim = $this->makeProgressClaim($this->makeSubcontract($subcontractor, ['project_id' => $this->project->id]));

        $this->apBills()->create(['subcontract_claim_id' => $claim->id, 'bill_date' => '2026-03-10']);

        $this->expectExceptionMessage("A bill already exists for opname {$claim->code}.");

        try {
            $this->apBills()->create(['subcontract_claim_id' => $claim->id, 'bill_date' => '2026-03-20']);
        } finally {
            $this->assertSame(1, ApBill::query()->where('subcontract_claim_id', $claim->id)->count());
        }
    }

    public function test_billing_a_purchase_order_that_is_not_approved_is_refused(): void
    {
        $po = $this->makePurchaseOrder($this->vendor, ['status' => DocumentStatus::Submitted]);

        $this->expectExceptionMessage('only approved POs can be billed');

        try {
            $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        } finally {
            $this->assertDatabaseCount('fin_ap_bills', 0);
        }
    }

    public function test_billing_a_closed_purchase_order_is_still_allowed(): void
    {
        $po = $this->makePurchaseOrder($this->vendor, ['status' => DocumentStatus::Closed]);

        $bill = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);

        $this->assertSame(100000000.0, (float) $bill->dpp);
    }

    public function test_billing_a_subcon_claim_that_is_not_approved_is_refused(): void
    {
        $subcontractor = $this->makeVendor(['name' => 'CV Karya Sipil Sejahtera', 'is_subcontractor' => true, 'classification' => 'sipil']);
        $claim = $this->makeProgressClaim(
            $this->makeSubcontract($subcontractor, ['project_id' => $this->project->id]),
            ['status' => DocumentStatus::Submitted],
        );

        $this->expectExceptionMessage('only approved claims can be billed');

        try {
            $this->apBills()->create(['subcontract_claim_id' => $claim->id, 'bill_date' => '2026-03-10']);
        } finally {
            $this->assertDatabaseCount('fin_ap_bills', 0);
        }
    }

    public function test_a_closed_period_rolls_the_whole_bill_approval_back(): void
    {
        FiscalPeriod::query()->where('year', 2026)->where('month', 3)->update(['status' => 'closed']);

        $bill = $this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'bill_date' => '2026-03-10',
            'description' => 'Sewa alat berat Maret 2026',
            'dpp' => 100000000,
            'ppn_amount' => 11000000,
        ]);
        $bill->submit($this->financeUser());

        $this->expectExceptionMessage('Periode fiskal 2026-03 sudah ditutup');

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
        } finally {
            $this->assertSame(DocumentStatus::Submitted, $bill->fresh()->status);
            $this->assertSame(0, Journal::query()->count());
            $this->assertSame(0, ProjectCost::query()->count());
        }
    }

    public function test_a_draft_bill_cannot_be_approved(): void
    {
        $bill = $this->apBills()->create([
            'vendor_id' => $this->vendor->id,
            'bill_date' => '2026-03-10',
            'description' => 'Tagihan belum diajukan',
            'dpp' => 1000000,
        ]);

        $this->expectException(LogicException::class);

        try {
            $this->apBills()->approve($bill, $this->financeApprover());
        } finally {
            $this->assertSame(DocumentStatus::Draft, $bill->fresh()->status);
            $this->assertSame(0, Journal::query()->count());
        }
    }

    // ------------------------------------------------------------ fixtures

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->firstOrCreate(
            ['code' => 'WH-PUSAT'],
            ['name' => 'Gudang Pusat', 'is_active' => true],
        );
    }

    private function item(): Item
    {
        $category = ItemCategory::query()->firstOrCreate(
            ['code' => 'CAT-UMUM'],
            ['name' => 'Material Umum'],
        );

        return Item::query()->firstOrCreate(
            ['name' => 'Semen Gresik 40kg'],
            [
                'category_id' => $category->id,
                'unit' => 'zak',
                'item_type' => ItemType::Material,
                'min_stock' => 0,
                'avg_cost' => 0,
                'last_price' => 0,
                'is_active' => true,
            ],
        );
    }

    /**
     * A GOODS purchase order: it names a deliver-to warehouse, which is what
     * makes the bill subject to three-way matching. Same commercial terms as
     * makePurchaseOrder() — dpp 100.000.000, PPN 11.000.000.
     */
    private function makeGoodsPurchaseOrder(array $attributes = []): PurchaseOrder
    {
        return $this->makePurchaseOrder($this->vendor, array_merge([
            'project_id' => $this->project->id,
            'warehouse_id' => $this->warehouse()->id,
        ], $attributes));
    }

    /**
     * A second stock article, the one the vendor substitutes for what the order
     * asked for. No PO line names it, so receiving it moves no qty_received.
     */
    private function substituteItem(): Item
    {
        $category = ItemCategory::query()->firstOrCreate(
            ['code' => 'CAT-UMUM'],
            ['name' => 'Material Umum'],
        );

        return Item::query()->firstOrCreate(
            ['name' => 'Semen Tiga Roda 40kg'],
            [
                'category_id' => $category->id,
                'unit' => 'zak',
                'item_type' => ItemType::Material,
                'min_stock' => 0,
                'avg_cost' => 0,
                'last_price' => 0,
                'is_active' => true,
            ],
        );
    }

    /**
     * A DRAFT goods receipt for the PO, valued at qty * unitCost.
     */
    private function makeGoodsReceipt(PurchaseOrder $po, float $qty, float $unitCost, ?Item $item = null): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $po->warehouse_id ?? $this->warehouse()->id,
            'purchase_order_id' => $po->id,
            'receipt_date' => '2026-03-05',
            'received_by' => $this->financeUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => ($item ?? $this->item())->id,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => round($qty * $unitCost, 2),
        ]);

        return $grn->refresh();
    }

    /**
     * Receive the goods for real, through StockService, so the GR/IR credit the
     * bill has to clear actually exists in the ledger.
     */
    private function receiveGoods(PurchaseOrder $po, float $qty, float $unitCost, ?Item $item = null): GoodsReceipt
    {
        return app(StockService::class)->postReceipt($this->makeGoodsReceipt($po, $qty, $unitCost, $item));
    }

    /**
     * Signed movement of a COA account over every journal line: debit - credit.
     * Zero means the account has been cleared.
     */
    private function accountNet(string $code): float
    {
        $sums = JournalLine::query()
            ->where('account_id', $this->accountId($code))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return round((float) $sums->debit - (float) $sums->credit, 2);
    }

    private function assertNoJournalForBill(int $billId): void
    {
        $this->assertDatabaseMissing('fin_journals', [
            'reference_type' => 'ap_bill',
            'reference_id' => $billId,
        ]);
    }
}
