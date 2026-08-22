<?php

namespace Tests\Feature\Inventory;

use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\PurchaseReturn;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\PurchaseReturnService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * Retur pembelian (temuan 38): rejected goods go back on the vendor's truck,
 * and three books have to move together — stock out of the gudang, the
 * receipt's recorded clearing debited back BY THE SLICE so no vendor bill can
 * ever settle the returned goods, and the PO's qty_received handed back so the
 * replacement delivery can still be received.
 *
 * The emergency path this replaces was an opname: stock out at 6-4400 Selisih
 * Persediaan — an operating EXPENSE for goods the company is not keeping —
 * while the vendor's bill stayed billable in FULL and the auto-closed PO
 * refused the replacement for ever.
 *
 * Same fixture as GrIrMatchingTest, on the audit's own quantities:
 * 100 zak semen @ 62.000 = 6.200.000.
 */
class PurchaseReturnTest extends ErpTestCase
{
    // FinanceFixtures already carries the journal readers (linesByAccount,
    // singleJournalFor, assertPostedAndBalanced), so AssertsJournals would
    // collide — the same reason GrIrMatchingTest leaves it off.
    use FinanceFixtures;
    use InventoryFixtures;

    private const REASON = 'Semen menggumpal saat diterima; ditolak QC dan dikembalikan ke vendor.';

    private const PO_QTY = 100.0;

    private const PO_UNIT_PRICE = 62000.0;

    private const PO_DPP = 6200000.0;

    private Warehouse $pusat;

    private Item $semen;

    private Vendor $vendor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->vendor = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    private function returns(): PurchaseReturnService
    {
        return app(PurchaseReturnService::class);
    }

    /** The GrIrMatchingTest PO: one line, 100 zak @ 62.000, approved. */
    private function makeGoodsPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->vendor->id,
            'project_id' => $this->project->id,
            'warehouse_id' => $this->pusat->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::PO_DPP,
            'discount_amount' => 0,
            'dpp' => self::PO_DPP,
            'ppn_rate' => 11.0,
            'ppn_amount' => 682000.0,
            'total' => 6882000.0,
            'status' => DocumentStatus::Approved,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'item_id' => $this->semen->id,
            'description' => 'Semen Gresik 40kg',
            'qty' => self::PO_QTY,
            'unit' => 'zak',
            'unit_price' => self::PO_UNIT_PRICE,
            'amount' => self::PO_DPP,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    /** A POSTED receipt of $qty zak against the PO line. */
    private function postedGrnFor(?PurchaseOrder $po, float $qty = self::PO_QTY, string $date = '2026-03-05'): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $po?->id,
            'vendor_id' => $this->vendor->id,
            'receipt_date' => $date,
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => $this->semen->id,
            'po_item_id' => $po?->items()->value('id'),
            'qty' => $qty,
            'unit_cost' => self::PO_UNIT_PRICE,
            'amount' => round($qty * self::PO_UNIT_PRICE, 2),
        ]);

        return $this->stock()->postReceipt($grn->refresh());
    }

    /** A draft retur of $qty zak against the receipt's single line. */
    private function draftReturn(GoodsReceipt $grn, float $qty, string $date = '2026-03-10'): PurchaseReturn
    {
        return $this->returns()->create([
            'goods_receipt_id' => $grn->id,
            'return_date' => $date,
            'returned_by' => $this->warehouseUser()->id,
            'reason' => self::REASON,
            'items' => [
                ['grn_item_id' => (int) $grn->items()->sole()->id, 'qty' => $qty],
            ],
        ]);
    }

    // ------------------------------------------------------------------- works

    public function test_posting_a_return_reverses_the_receipt_slice_end_to_end(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());

        $return = $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));

        $this->assertSame(StockDocumentStatus::Posted, $return->status);

        // Stock: 20 of the 100 zak gone again.
        $this->assertSame(80.0, $this->balanceQty($this->pusat, $this->semen));

        // GL: the slice debited back off the EXACT account the receipt credited,
        // 20 zak * 62.000 = 1.240.000 — no 6-4400, nothing re-averaged since.
        $journal = $this->singleJournalFor('inventory_purchase_return', (int) $return->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');
        $lines = $this->linesByAccount($journal);
        $this->assertSame(1240000.0, $lines['2-1150']['debit']);
        $this->assertSame(1240000.0, $lines['1-1400']['credit']);
        $this->assertArrayNotHasKey('6-4400', $lines);

        // The receipt's record — the ONLY figure a bill may sweep — shrank by
        // the same slice.
        $this->assertSame(4960000.0, $grn->fresh()->recordedClearingAmount());

        // The line froze the receipt price, not some current average.
        $line = $return->items()->sole();
        $this->assertSame(62000.0, (float) $line->unit_cost);
        $this->assertSame(1240000.0, (float) $line->amount);

        // Vendor copied from the receipt: the counterparty taking the goods.
        $this->assertSame((int) $this->vendor->id, (int) $return->vendor_id);
    }

    public function test_the_returned_slice_can_never_be_billed(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));

        // The buyer accepts the short delivery: close the reopened order so
        // its final bill may be approved (the completeness gate).
        app(PoService::class)->close($po->fresh());

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-15',
        ]));

        // The bill swept what remained after the return — 4.960.000, never the
        // original 6.200.000 — so GR/IR nets to exactly zero: receipt credit,
        // return debit, bill debit.
        $this->assertSame(4960000.0, (float) $bill->fresh()->gl_cleared_amount);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
    }

    /**
     * Penagihan parsial per-GRN: plafon retur harus membaca kliring GRN ITU
     * SENDIRI, bukan gabungan seluruh tagihan PO — dulu retur atas GRN-B
     * dinilai dari keadaan tagihan GRN-A.
     */
    public function test_a_return_judges_the_receipts_own_clearing_under_partial_billing(): void
    {
        $po = $this->makeGoodsPo();
        $grnA = $this->postedGrnFor($po, 50, '2026-03-05');
        $grnB = $this->postedGrnFor($po, 30, '2026-03-06');

        // GRN-A ditagih parsial; GRN-B belum tersentuh tagihan mana pun.
        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grnA->id],
            'bill_date' => '2026-03-08',
        ]));

        // Arah 1: retur atas GRN-B BOLEH — kliring miliknya belum ditagih,
        // walau pool PO sudah memuat tagihan approved.
        $return = $this->stock()->postPurchaseReturn($this->draftReturn($grnB, 10));
        $this->assertSame(StockDocumentStatus::Posted, $return->status);

        // Arah 2: retur atas GRN-A DITOLAK — kliring miliknya sudah disapu,
        // walau GRN-B masih menyisakan kliring di pool.
        try {
            $this->stock()->postPurchaseReturn($this->draftReturn($grnA, 10, '2026-03-11'));
            $this->fail('Expected the billed receipt to refuse its return.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum ditagih', $e->getMessage());
        }
    }

    /** Setelah tagihan parsial, GRN baru pada PO yang sama harus tetap bisa diterima DAN mengkliring. */
    public function test_a_new_receipt_still_clears_after_a_partial_bill(): void
    {
        $po = $this->makeGoodsPo();
        $grnA = $this->postedGrnFor($po, 50, '2026-03-05');

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$grnA->id],
            'bill_date' => '2026-03-08',
        ]));

        // Dulu: satu tagihan approved non-uang-muka mematikan rute kliring PO,
        // dan GRN berikutnya diterima TANPA kredit GR/IR — nilai truk kedua
        // lenyap dari hutang akrual.
        $grnB = $this->postedGrnFor($po, 30, '2026-03-12');

        $this->assertSame(6200000.0 * 30 / 100, (float) $grnB->fresh()->recordedClearingAmount(),
            'GRN kedua harus tetap mengkliring ke GR/IR setelah tagihan parsial GRN pertama.');
    }

    public function test_a_receipt_already_swept_by_a_bill_cannot_be_returned(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        // Fully received, auto-closed, billed: the clearing is spent.
        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));

        try {
            $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));
            $this->fail('Expected a return against a billed receipt to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('belum ditagih', $e->getMessage());
        }

        // Nothing moved: the settled liability stays settled, the stock stays.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());
        $this->assertSame(0, DB::table('fin_journals')->where('reference_type', 'inventory_purchase_return')->count());
    }

    public function test_a_return_reopens_the_auto_closed_po_and_hands_back_the_quantity(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        // The full delivery auto-closed the order.
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
        $this->assertSame(100.0, (float) $po->items()->sole()->qty_received);

        $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));

        // registerReceipt() only ever added — the audit's exact gap: the order
        // now awaits the replacement 20 zak again, and can receive them.
        $po = $po->fresh();
        $this->assertSame(DocumentStatus::Approved, $po->status);
        $this->assertNull($po->closed_at);
        $this->assertSame(80.0, (float) $po->items()->sole()->qty_received);

        $replacement = $this->postedGrnFor($po, 20, '2026-03-20');
        $this->assertSame(StockDocumentStatus::Posted, $replacement->status);
        $this->assertSame(100.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
    }

    public function test_a_manual_close_survives_a_return(): void
    {
        // 60 of 100 delivered, buyer forgives the rest: close() means "nothing
        // more is coming", and a 10-zak return must not silently reopen an
        // order whose remainder was deliberately cancelled.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po, 60);

        app(PoService::class)->close($po->fresh());

        $this->stock()->postPurchaseReturn($this->draftReturn($grn, 10));

        $po = $po->fresh();
        $this->assertSame(DocumentStatus::Closed, $po->status);
        $this->assertSame(50.0, (float) $po->items()->sole()->qty_received);
    }

    public function test_returns_are_capped_cumulatively_at_the_received_quantity(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->stock()->postPurchaseReturn($this->draftReturn($grn, 60));

        $second = $this->draftReturn($grn, 50, '2026-03-12');

        try {
            $this->stock()->postPurchaseReturn($second);
            $this->fail('Expected the cumulative ceiling to refuse the second return.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah kembali ke vendor', $e->getMessage());
        }

        // The refusal rolled everything back: stock, clearing, PO.
        $this->assertSame(40.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(2480000.0, $grn->fresh()->recordedClearingAmount());
        $this->assertSame(40.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertSame(StockDocumentStatus::Draft, $second->fresh()->status);
    }

    public function test_a_draft_cannot_reference_the_same_receipt_line_twice(): void
    {
        // The duplicate-line bypass on grn_item_id: qtyReturned() counts
        // POSTED documents only, so two 60-zak lines of ONE draft each fit
        // alone under a 100-zak receipt line — together they hand the vendor
        // back 120 zak he only ever delivered 100 of.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $lineId = (int) $grn->items()->sole()->id;

        try {
            $this->returns()->create([
                'goods_receipt_id' => $grn->id,
                'return_date' => '2026-03-10',
                'reason' => self::REASON,
                'items' => [
                    ['grn_item_id' => $lineId, 'qty' => 60],
                    ['grn_item_id' => $lineId, 'qty' => 60],
                ],
            ]);
            $this->fail('Expected a duplicate grn_item_id to be refused at drafting.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('dua kali', $e->getMessage());
        }

        $this->assertSame(0, PurchaseReturn::query()->count());

        // The wire door says the same thing before the service is asked.
        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/inventory/purchase-returns', [
                'goods_receipt_id' => $grn->id,
                'return_date' => '2026-03-10',
                'reason' => self::REASON,
                'items' => [
                    ['grn_item_id' => $lineId, 'qty' => 60],
                    ['grn_item_id' => $lineId, 'qty' => 60],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.grn_item_id']);
    }

    public function test_the_posting_ceiling_counts_sibling_lines_of_one_document(): void
    {
        // The naked shape of the bypass: a vendor delivery with NO purchase
        // order (no unregisterReceipt floor) posted under periodic (no
        // clearing ceiling), with enough unrelated stock on the shelf to
        // cover both lines. Nothing else refuses it — the loop must.
        $this->setSetting('accounting.perpetual_inventory', false);

        $grn = $this->receiveStock($this->pusat, $this->semen, 100, self::PO_UNIT_PRICE, '2026-03-05', [
            'vendor_id' => $this->vendor->id,
        ]);
        // Unrelated opening stock so applyOut covers 120 zak without a fight.
        $this->receiveStock($this->pusat, $this->semen, 100, self::PO_UNIT_PRICE, '2026-03-06');

        $return = $this->draftReturn($grn, 60);

        // The sibling line injected past syncItems, as a pre-guard draft.
        $return->items()->create([
            'grn_item_id' => (int) $grn->items()->sole()->id,
            'item_id' => $this->semen->id,
            'qty' => 60,
            'unit_cost' => 0,
            'amount' => 0,
        ]);

        try {
            $this->stock()->postPurchaseReturn($return);
            $this->fail('Expected the ceiling to count the sibling line of the same document.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah kembali ke vendor', $e->getMessage());
        }

        // The refusal rolled the whole posting back: all 200 zak still there,
        // the draft untouched.
        $this->assertSame(200.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(StockDocumentStatus::Draft, $return->fresh()->status);
    }

    public function test_goods_already_issued_to_a_project_cannot_go_back_to_the_vendor(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        // 70 zak are on a site; only 30 remain in the gudang.
        $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 70]], (int) $this->project->id, '2026-03-08'));

        try {
            $this->stock()->postPurchaseReturn($this->draftReturn($grn, 50));
            $this->fail('Expected a return of goods no longer in the warehouse to be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Stok tidak mencukupi', $e->getMessage());
        }

        $this->assertSame(30.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());
    }

    public function test_the_average_drift_since_receipt_lands_in_the_variance_account(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        // A cheaper delivery mixes in: 100 @ 62.000 + 100 @ 50.000 = 56.000.
        $this->receiveStock($this->pusat, $this->semen, 100, 50000, '2026-03-07', ['vendor_id' => $this->vendor->id]);
        $this->assertSame(56000.0, $this->balanceAvg($this->pusat, $this->semen));

        $return = $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));

        $lines = $this->linesByAccount($this->singleJournalFor('inventory_purchase_return', (int) $return->id));

        // Costing rule 2 on the stock leg: 1-1400 gives up what the balance
        // actually lost (20 * 56.000), the vendor is short-paid the RECEIPT
        // price (20 * 62.000), and the 120.000 the stock had already absorbed
        // into its average is a valuation difference — 6-4400, the account
        // every other one lands in.
        $this->assertSame(1240000.0, $lines['2-1150']['debit']);
        $this->assertSame(1120000.0, $lines['1-1400']['credit']);
        $this->assertSame(120000.0, $lines['6-4400']['credit']);

        // The identity the CLI health check asserts: GL 1-1400 == sub-ledger.
        $this->assertSame(
            round((float) DB::table('inv_stock_balances')->selectRaw('COALESCE(SUM(qty * avg_cost), 0) AS v')->value('v'), 2),
            $this->accountNet('1-1400'),
        );
    }

    public function test_an_opening_stock_receipt_has_nobody_to_return_to(): void
    {
        // No PO, no vendor: the receipt credited EQUITY (saldo awal), nobody is
        // owed a refund, and the only honest exit for the goods is an opname.
        $grn = $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 50, 10000]], '2026-03-01'));

        try {
            $this->draftReturn($grn, 10);
            $this->fail('Expected an opening-stock receipt to be refused at drafting.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak ada pihak', $e->getMessage());
        }

        $this->assertSame(0, PurchaseReturn::query()->count());
    }

    public function test_only_a_posted_receipt_can_take_a_return(): void
    {
        $po = $this->makeGoodsPo();

        $draft = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $this->vendor->id,
            'receipt_date' => '2026-03-05',
            'status' => StockDocumentStatus::Draft,
        ]);
        $draft->items()->create([
            'item_id' => $this->semen->id,
            'qty' => 10,
            'unit_cost' => self::PO_UNIT_PRICE,
            'amount' => 620000,
        ]);

        try {
            $this->draftReturn($draft->refresh(), 5);
            $this->fail('Expected a draft receipt to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('penerimaan yang sudah diposting', $e->getMessage());
        }
    }

    public function test_a_return_cannot_be_posted_twice(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $return = $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));

        try {
            $this->stock()->postPurchaseReturn($return->fresh());
            $this->fail('Expected a second posting to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('berstatus posted', $e->getMessage());
        }

        // 20 zak left exactly once: stock, clearing and PO all moved once.
        $this->assertSame(80.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(4960000.0, $grn->fresh()->recordedClearingAmount());
        $this->assertSame(80.0, (float) $po->items()->sole()->fresh()->qty_received);
    }

    public function test_under_periodic_inventory_the_stock_and_the_po_still_move_but_no_journal_posts(): void
    {
        // The receipt recorded no clearing and raised no journal; the return
        // must not invent one — the parameter never re-decides what an earlier
        // posting already did. Quantity flow is method-independent: the shelf
        // and the PO move all the same.
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->assertFalse($grn->fresh()->hasRecordedClearing());

        $return = $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));

        $this->assertSame(StockDocumentStatus::Posted, $return->fresh()->status);
        $this->assertSame(80.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(80.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertDatabaseMissing('fin_journals', [
            'reference_type' => 'inventory_purchase_return',
            'reference_id' => $return->id,
        ]);
        $this->assertNull($grn->fresh()->gl_clearing_amount);
    }

    public function test_a_perpetual_receipt_returned_under_periodic_still_reverses_its_recorded_clearing(): void
    {
        // The method-flip hole: the receipt recorded Cr 2-1150 6.200.000, the
        // installation then switches to periodic, and the return decided its
        // money side on TODAY's parameter — the record kept its full
        // 6.200.000, so the final bill swept the slice of 20 zak that had
        // gone back on the vendor's truck. The rule is cancelIssue()'s: a
        // reversal unwinds what the ORIGINAL posting actually recorded, and
        // the parameter never re-decides what an earlier posting already did.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());

        $this->setSetting('accounting.perpetual_inventory', false);

        $return = $this->stock()->postPurchaseReturn($this->draftReturn($grn, 20));

        // The record — the ONLY figure a bill may sweep — shrank by the slice…
        $this->assertSame(4960000.0, $grn->fresh()->recordedClearingAmount());

        // …and the reversal journal was posted: what the receipt put into
        // 2-1150 and 1-1400 comes back out, whatever the parameter says today.
        $journal = $this->singleJournalFor('inventory_purchase_return', (int) $return->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');
        $lines = $this->linesByAccount($journal);
        $this->assertSame(1240000.0, $lines['2-1150']['debit']);
        $this->assertSame(1240000.0, $lines['1-1400']['credit']);

        // End to end: switched back to perpetual, the final bill sweeps what
        // remained — never the returned slice — and GR/IR nets to zero.
        $this->setSetting('accounting.perpetual_inventory', true);
        app(PoService::class)->close($po->fresh());

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-15',
        ]));

        $this->assertSame(4960000.0, (float) $bill->fresh()->gl_cleared_amount);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
    }

    // ---------------------------------------------------------------- endpoint

    public function test_the_detail_action_drafts_the_remaining_returnable_quantities(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->stock()->postPurchaseReturn($this->draftReturn($grn, 60));

        $admin = $this->adminUser();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/goods-receipts/{$grn->id}/returns", ['reason' => self::REASON])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.goods_receipt_id', $grn->id);

        $draft = PurchaseReturn::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(40.0, (float) $draft->items()->sole()->qty);

        // Posting through the endpoint completes the loop.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/purchase-returns/{$draft->id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, $grn->fresh()->recordedClearingAmount());
    }

    // ------------------------------------------------------------------ helper

    /**
     * Posted debit minus credit on one COA code — how the GL is read back.
     */
    private function accountNet(string $code): float
    {
        $row = DB::table('fin_journal_lines')
            ->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_accounts.code', $code)
            ->whereNull('fin_journals.deleted_at')
            ->where('fin_journals.status', 'posted')
            ->selectRaw('COALESCE(SUM(fin_journal_lines.debit), 0) - COALESCE(SUM(fin_journal_lines.credit), 0) AS net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }
}
