<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Enums\PostingStatus;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\PurchaseReturnService;
use Modules\Inventory\Services\StockService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * THE WAY BACK for the expensive third of T37: a posted GRN used to be
 * permanent, and it is the stock document where the consequences concentrate —
 * its value sits in 1-1400 with a clearing credit a vendor bill can still
 * settle, and PoService::registerReceipt() only ever ADDED to qty_received, so
 * a bogus receipt left the order reading delivered for ever. Correcting one
 * meant an opname (6-4400 Selisih Persediaan — shrinkage, for a purchase that
 * never happened) plus a manual JV, with the PO wrong for good.
 *
 * Cancellation is a NEW document event, never an edit: the original posting
 * stands, a mirror stock-out leaves at the stored average (costing rule 2), a
 * reversing journal sits beside the original, the receipt's recorded clearing
 * is cleared so no bill can sweep it, and the PO takes its quantities back
 * through the same PoService::unregisterReceipt() the purchase return uses —
 * including reopening an auto-closed order.
 *
 * Same fixture as PurchaseReturnTest, on the audit's own quantities:
 * 100 zak semen @ 62.000 = 6.200.000.
 */
class GoodsReceiptCancellationTest extends ErpTestCase
{
    // FinanceFixtures already carries the journal readers (linesByAccount,
    // singleJournalFor, assertPostedAndBalanced), so AssertsJournals would
    // collide — the same reason PurchaseReturnTest leaves it off.
    use FinanceFixtures;
    use InventoryFixtures;

    private const REASON = 'GRN keliru: surat jalan vendor ini milik gudang site, bukan pusat.';

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

    /** The PurchaseReturnTest PO: one line, 100 zak @ 62.000, approved. */
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

    // ------------------------------------------------------------------- works

    public function test_cancelling_a_posted_grn_unwinds_stock_gl_and_po_to_the_rupiah(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        // The full delivery auto-closed the order and recorded the clearing.
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
        $this->assertSame(100.0, (float) $po->items()->sole()->qty_received);
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));

        $cancelled = $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);

        $this->assertSame(StockDocumentStatus::Cancelled, $cancelled->status);
        $this->assertSame(self::REASON, $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($this->warehouseUser()->id, (int) $cancelled->cancelled_by);

        // Stock: the 100 zak left again, at the average they were carried at.
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));

        // Kartu stok stays append-only: receipt in, mirror out — nothing rewritten.
        $rows = $this->ledgerFor($this->pusat, $this->semen);
        $this->assertSame(['in', 'out'], $rows->pluck('direction')->all());
        $this->assertSame(0.0, (float) $rows->last()->balance_qty_after);

        // GL to the rupiah: the reversal mirrors the original journal, so
        // 1-1400 and 2-1150 both net to exactly zero, dated the receipt's own
        // date while that is still the last word for this stock.
        $reversal = $this->singleJournalFor('goods_receipt_cancellation', (int) $grn->id);
        $this->assertPostedAndBalanced($reversal, '2026-03-05');
        $lines = $this->linesByAccount($reversal);
        $this->assertSame(6200000.0, $lines['1-1400']['credit']);
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertStringContainsString(self::REASON, $reversal->description);
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // The original posting is immutable and still says what it said.
        $original = $this->singleJournalFor('goods_receipt', (int) $grn->id);
        $this->assertSame(PostingStatus::Posted, $original->status);
        $this->assertSame(6200000.0, $this->linesByAccount($original)['1-1400']['debit']);

        // No bill can ever sweep a cancelled receipt: the record is cleared.
        $this->assertFalse($grn->fresh()->hasRecordedClearing());
        $this->assertNull($grn->fresh()->gl_clearing_amount);

        // PO: qty_received handed back in full and the auto-close reopened, so
        // the real delivery can still be received.
        $po = $po->fresh();
        $this->assertSame(DocumentStatus::Approved, $po->status);
        $this->assertNull($po->closed_at);
        $this->assertSame(0.0, (float) $po->items()->sole()->qty_received);

        $replacement = $this->postedGrnFor($po, self::PO_QTY, '2026-03-20');
        $this->assertSame(StockDocumentStatus::Posted, $replacement->status);
        $this->assertSame(100.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
    }

    public function test_the_gl_moves_by_exactly_what_the_stored_balance_moved(): void
    {
        // Costing rule 2. A cheaper delivery re-averaged the balance after the
        // receipt (100 @ 62.000 + 100 @ 50.000 = 56.000), so the mirror-out
        // releases 5.600.000 while the reversal credits the original 6.200.000
        // — the 600.000 the stock had already absorbed into its average is a
        // valuation difference and lands in 6-4400 with an opname's shape,
        // never left in 1-1400 to break the tie-out.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->receiveStock($this->pusat, $this->semen, 100, 50000, '2026-03-07', ['vendor_id' => $this->vendor->id]);
        $this->assertSame(56000.0, $this->balanceAvg($this->pusat, $this->semen));

        $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);

        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(56000.0, $this->balanceAvg($this->pusat, $this->semen));

        // The identity the CLI health check asserts: GL 1-1400 == sub-ledger.
        $this->assertSame(5600000.0, $this->accountNet('1-1400'));
        $this->assertSame(
            round((float) DB::table('inv_stock_balances')->selectRaw('COALESCE(SUM(qty * avg_cost), 0) AS v')->value('v'), 2),
            $this->accountNet('1-1400'),
        );
        $this->assertSame(-600000.0, $this->accountNet('6-4400'));

        // Two journals under the cancellation reference: the mirror and the gap.
        $this->assertSame(2, DB::table('fin_journals')->where('reference_type', 'goods_receipt_cancellation')->count());
    }

    public function test_a_perpetual_receipt_cancelled_under_periodic_still_reverses_what_it_recorded(): void
    {
        // cancelIssue()'s rule from the receipt side: a reversal unwinds what
        // the ORIGINAL posting actually recorded, and the parameter never
        // re-decides what an earlier posting already did. Deciding on today's
        // parameter would leave the receipt holding its full clearing credit —
        // exactly the money a final bill then sweeps for goods whose receipt
        // was cancelled.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());

        $this->setSetting('accounting.perpetual_inventory', false);

        $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);

        $reversal = $this->singleJournalFor('goods_receipt_cancellation', (int) $grn->id);
        $lines = $this->linesByAccount($reversal);
        $this->assertSame(6200000.0, $lines['1-1400']['credit']);
        $this->assertSame(6200000.0, $lines['2-1150']['debit']);
        $this->assertNull($grn->fresh()->gl_clearing_amount);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_receipt_that_raised_no_journal_still_cancels_its_stock(): void
    {
        // Posted under periodic: no journal, no clearing — reverseFor would
        // rightly refuse "nothing to reverse", so nothing may ask it to.
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->assertFalse($grn->fresh()->hasRecordedClearing());

        $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);

        $this->assertSame(StockDocumentStatus::Cancelled, $grn->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertDatabaseMissing('fin_journals', [
            'reference_type' => 'goods_receipt_cancellation',
            'reference_id' => $grn->id,
        ]);
    }

    public function test_a_cancellation_after_the_stock_moved_again_is_dated_today(): void
    {
        // The mirror is a stock movement like any other: dating it back behind
        // rows already recorded would break the running balance the kartu stok
        // reads, so the cancellation is an event of TODAY — the cancelIssue
        // precedent, decided by the same one rule.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->receiveStock($this->pusat, $this->semen, 50, 62000, '2026-03-12');

        $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);

        $this->assertSame(50.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(
            now()->toDateString(),
            StockLedgerEntry::query()->orderByDesc('id')->firstOrFail()->trx_date->toDateString(),
        );
        $this->assertSame(
            now()->toDateString(),
            $this->singleJournalFor('goods_receipt_cancellation', (int) $grn->id)->journal_date->toDateString(),
        );
    }

    // ---------------------------------------------------------------- refusals

    public function test_a_receipt_whose_clearing_a_bill_swept_cannot_be_cancelled(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        // Fully received, auto-closed, billed: the clearing is spent and the
        // liability is a real Hutang Usaha somebody approved.
        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));

        try {
            $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a billed receipt to refuse its cancellation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tagihan vendor', $e->getMessage());
            $this->assertStringContainsString('nota kredit', $e->getMessage());
        }

        // Nothing moved: stock, clearing record, PO, status.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());
        $this->assertSame(100.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertSame(0, DB::table('fin_journals')->where('reference_type', 'goods_receipt_cancellation')->count());
    }

    public function test_a_receipt_whose_po_was_billed_classic_style_cannot_be_cancelled(): void
    {
        // Posted under periodic the receipt recorded nothing, so the PO's final
        // bill found no clearing to sweep and took the classic path — the goods
        // are already expensed on 5-1100. clearedByBills() reads zero for such
        // a bill, so without its own refusal the cancellation would reopen an
        // order whose delivery is already paid for as cost.
        $this->setSetting('accounting.perpetual_inventory', false);

        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->setSetting('accounting.perpetual_inventory', true);

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-08',
        ]));
        $this->assertSame(0.0, (float) $bill->fresh()->gl_cleared_amount);

        try {
            $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a classic-billed PO to refuse the cancellation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('pembebanan langsung', $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_receipt_partially_issued_since_cannot_be_cancelled(): void
    {
        // 70 of the 100 zak are on a site: the whole-document mirror would
        // drive the gudang negative. The honest remedies are named — retur
        // pembelian for the part still on the shelf, opname for shrinkage.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 70]], (int) $this->project->id, '2026-03-08'));

        try {
            $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a partially issued receipt to refuse its cancellation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('retur pembelian', $e->getMessage());
            $this->assertStringContainsString('opname', $e->getMessage());
        }

        // The refusal rolled everything back — including the PO quantities.
        $this->assertSame(30.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(100.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
    }

    public function test_a_receipt_partially_returned_cannot_be_cancelled_whole(): void
    {
        // A posted retur already handed 20 zak back: mirroring the whole
        // document on top restores quantities the vendor no longer owes and
        // reverses clearing the retur already reversed. Same guard shape as
        // cancelIssue() over posted retur material.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $return = app(PurchaseReturnService::class)->create([
            'goods_receipt_id' => $grn->id,
            'return_date' => '2026-03-10',
            'returned_by' => $this->warehouseUser()->id,
            'reason' => 'Semen menggumpal; 20 zak ditolak QC.',
            'items' => [
                ['grn_item_id' => (int) $grn->items()->sole()->id, 'qty' => 20],
            ],
        ]);
        $this->stock()->postPurchaseReturn($return);

        try {
            $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a partially returned receipt to refuse its cancellation.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('retur pembelian', $e->getMessage());
            $this->assertStringContainsString($return->code, $e->getMessage());
        }

        $this->assertSame(80.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(4960000.0, $grn->fresh()->recordedClearingAmount());
        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
    }

    public function test_a_cancellation_is_refused_while_a_movement_dated_ahead_holds_the_card_open(): void
    {
        // Today is the fallback date, and paperwork keyed into a future month
        // leaves no date that both keeps the card straight and stays out of a
        // month nobody may post into — assertMovementInOrder() runs on the
        // mirror with no exemption, exactly as it does for cancelIssue().
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->receiveStock($this->pusat, $this->semen, 20, 62000, '2026-12-20');

        try {
            $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a cancellation behind a later movement to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString("Pembatalan penerimaan {$grn->code}", $e->getMessage());
            $this->assertStringContainsString('2026-12-20', $e->getMessage());
        }

        $this->assertSame(120.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertDatabaseMissing('fin_journals', [
            'reference_type' => 'goods_receipt_cancellation',
            'reference_id' => $grn->id,
        ]);
    }

    public function test_a_draft_grn_cannot_be_cancelled(): void
    {
        // A draft is edited or deleted, not cancelled — there is no posting to
        // reverse and a cancellation would invent a stock movement.
        $po = $this->makeGoodsPo();
        $draft = $this->makeGrn($this->pusat, [[$this->semen, 10, self::PO_UNIT_PRICE]], '2026-03-05', [
            'purchase_order_id' => $po->id,
            'vendor_id' => $this->vendor->id,
        ]);

        try {
            $this->stock()->cancelReceipt($draft, self::REASON);
            $this->fail('Expected a draft GRN to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('hanya penerimaan yang sudah diposting yang dapat dibatalkan', $e->getMessage());
        }

        $this->assertSame(0, StockLedgerEntry::query()->count());
    }

    public function test_a_grn_cannot_be_cancelled_twice(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);

        try {
            $this->stock()->cancelReceipt($grn->fresh(), self::REASON, $this->warehouseUser()->id);
            $this->fail('Expected a second cancellation to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('berstatus cancelled', $e->getMessage());
        }

        // The 100 zak left exactly once, not 200, and the PO moved once.
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, (float) $po->items()->sole()->fresh()->qty_received);
        $this->assertSame(1, DB::table('fin_journals')->where('reference_type', 'goods_receipt_cancellation')->count());
    }

    public function test_a_cancellation_reason_is_required(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        try {
            $this->stock()->cancelReceipt($grn, '   ');
            $this->fail('Expected a blank reason to be refused.');
        } catch (LogicException $e) {
            $this->assertSame('Alasan pembatalan wajib diisi.', $e->getMessage());
        }

        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
    }

    public function test_a_cancelled_grn_cannot_take_a_purchase_return(): void
    {
        // The mirror already returned everything; a retur on top would send
        // back zak the vendor no longer owes. postPurchaseReturn's own status
        // gate answers, so the two ways back cannot stack.
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $return = app(PurchaseReturnService::class)->create([
            'goods_receipt_id' => $grn->id,
            'return_date' => '2026-03-10',
            'reason' => 'Semen menggumpal; ditolak QC.',
            'items' => [
                ['grn_item_id' => (int) $grn->items()->sole()->id, 'qty' => 20],
            ],
        ]);

        $this->stock()->cancelReceipt($grn, self::REASON, $this->warehouseUser()->id);

        try {
            $this->stock()->postPurchaseReturn($return->fresh());
            $this->fail('Expected a retur over a cancelled receipt to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('berstatus cancelled', $e->getMessage());
        }
    }

    // ------------------------------------------- negative control: T37's rest

    public function test_transfers_and_opnames_still_have_no_cancel_route(): void
    {
        // The fence deliberately leaves these two shut: a transfer is undone by
        // a second transfer the other way, an opname by a second opname — see
        // StockDocumentStatus's docblock for what that second opname does NOT
        // fix. The day either gains a cancellation, this is the reminder that
        // the service, the route and the screen learn about it together.
        $this->assertFalse(method_exists(StockService::class, 'cancelTransfer'));
        $this->assertFalse(method_exists(StockService::class, 'cancelAdjustment'));

        $admin = $this->adminUser();

        $transfer = $this->makeTransfer($this->pusat, $this->makeWarehouse('WH-SITE'), [[$this->semen, 1]]);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/transfers/{$transfer->id}/cancel", ['reason' => self::REASON])
            ->assertNotFound();

        $this->receiveStock($this->pusat, $this->semen, 10, 10000, '2026-03-01');
        $adjustment = $this->makeAdjustment($this->pusat, [[$this->semen, 10]], '2026-03-02', false);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/inventory/stock-adjustments/{$adjustment->id}/cancel", ['reason' => self::REASON])
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- endpoint

    public function test_the_endpoint_refuses_a_reason_too_short_to_tell_an_auditor_anything(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/inventory/goods-receipts/{$grn->id}/cancel", ['reason' => 'oops'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
    }

    public function test_the_endpoint_cancels_and_answers_with_the_cancelled_grn(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/inventory/goods-receipts/{$grn->id}/cancel", ['reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', self::REASON);

        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, (float) $po->items()->sole()->fresh()->qty_received);
    }

    public function test_the_endpoint_translates_a_refusal_into_a_422_not_a_500(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);
        $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 70]], (int) $this->project->id, '2026-03-08'));

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson("/api/inventory/goods-receipts/{$grn->id}/cancel", ['reason' => self::REASON])
            ->assertStatus(422);

        $this->assertSame(StockDocumentStatus::Posted, $grn->fresh()->status);
    }

    // ----------------------------------------------------------- layar (SPA)

    /**
     * REACHABILITY, not rendering — the same reading IssueCancellationTest does
     * for the bon screen, because this feature's twin first shipped exactly
     * once as an endpoint no button could call.
     */
    public function test_the_grn_screen_offers_the_cancellation_the_endpoint_serves(): void
    {
        $action = $this->schemaAction('inventory/goods-receipts', 'cancel');

        // Hak POSTING, bukan hak hapus — sama dengan pembatalan bon dan AR/AP,
        // dan sama dengan middleware rutenya.
        $this->assertStringContainsString("perm: 'inv.post'", $action);
        $this->assertStringContainsString("method: 'POST'", $action);
        $this->assertStringContainsString("variant: 'danger'", $action);

        // The states cancelReceipt() refuses outright are mirrored in the
        // predicate, so the operator is never handed a button whose only
        // possible answer is an error: only a posted GRN can be cancelled.
        $this->assertStringContainsString("row.status === 'posted'", $action);

        // A prompt that did not demand the reason would post a blank one and be
        // refused with a 422 the dialog cannot fix.
        $this->assertStringContainsString("key: 'reason'", $action);
        $this->assertStringContainsString('required: true', $action);
    }

    /**
     * The URL actions.js builds out of `api` + `path` — assembled here from the
     * file, not hand-written — reaches the route that actually cancels.
     */
    public function test_the_url_the_button_builds_is_the_route_that_cancels(): void
    {
        $po = $this->makeGoodsPo();
        $grn = $this->postedGrnFor($po);

        $url = '/api/'
            .$this->schemaValue($this->schemaResource('inventory/goods-receipts'), 'api').'/'
            .str_replace('{id}', (string) $grn->id, $this->schemaValue($this->schemaAction('inventory/goods-receipts', 'cancel'), 'path'));

        $this->assertSame("/api/inventory/goods-receipts/{$grn->id}/cancel", $url);

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson($url, ['reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
    }

    /** The whole RESOURCES entry for one screen, as schema.js declares it. */
    private function schemaResource(string $key): string
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));
        $start = strpos($schema, "\n  '{$key}': {");

        $this->assertNotFalse($start, "RESOURCES has no '{$key}' entry; this test can no longer read that screen.");

        $tail = substr($schema, $start + 1);

        $length = preg_match("/\n  '[a-z0-9\/-]+': \{/", $tail, $match, PREG_OFFSET_CAPTURE) === 1
            ? $match[0][1]
            : strlen($tail);

        return substr($tail, 0, $length);
    }

    /** One action object out of that entry's `actions` array. */
    private function schemaAction(string $resource, string $key): string
    {
        $block = $this->schemaResource($resource);
        $start = strpos($block, "key: '{$key}'");

        $this->assertNotFalse(
            $start,
            "RESOURCES['{$resource}'] declares no '{$key}' action, so no button in the SPA can reach "
            ."/api/{$resource}/{id}/{$key} — the endpoint ships as dead code.",
        );

        $end = strpos($block, "\n      },", $start);

        $this->assertNotFalse($end, "the '{$key}' action object could not be delimited; this test can no longer check it.");

        return substr($block, $start, $end - $start);
    }

    /** A `name: 'value'` string out of a schema.js fragment. */
    private function schemaValue(string $fragment, string $name): string
    {
        $this->assertSame(
            1,
            preg_match("/\\b{$name}: '([^']+)'/", $fragment, $match),
            "no {$name}: '...' in this schema.js fragment.",
        );

        return $match[1];
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
