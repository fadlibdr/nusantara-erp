<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Finance\Models\ApBill;
use Modules\Finance\Models\JournalLine;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Http\Requests\GoodsReceiptStoreRequest;
use Modules\Inventory\Http\Requests\GoodsReceiptUpdateRequest;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * NO CREDIT WITHOUT A DEBIT PATH — the goods-receipt end (audit N1, N2).
 *
 * StockService used to read "purchase_order_id is not null" as proof that a
 * vendor bill would one day clear the GR/IR credit it raised. Two things break
 * that inference, and both were reproduced with numbers by the audit:
 *
 *   the id may point at nothing. A receipt naming purchase_order_id 999999
 *       credited 2-1150 for a purchase order that does not exist, and no
 *       document in the product could ever debit it. The same held for
 *       vendor_id and 2-1600;
 *   the order may exist and still be unable to bill. Finance allows exactly ONE
 *       non-advance bill per PO, so once that bill is approved the GR/IR route
 *       is spent: goods delivered afterwards (an over-delivery, or a delivery
 *       that follows the invoice) raised a credit with nowhere to go. Measured:
 *       2-1150 stranded at -1.240.000 with both billing routes refusing.
 *
 * The repair has two halves, and this test exercises both:
 *
 *   the header check moved out of the per-line loop, so a receipt against a PO
 *       that is not approved is refused whatever po_item_id says (it used to be
 *       skipped entirely for an unlinked line);
 *   the credit leg RESOLVES the purchase order and the vendor and asks whether
 *       a bill can still clear them. When it cannot, the credit goes to the
 *       penerimaan accrual, which a bill against the receipt itself clears —
 *       and that bill is now creatable, because the "GRN terkait PO" refusal
 *       was narrowed to the receipts a PO bill can genuinely still reach.
 *
 * Figures throughout: 100 zak @ 62.000 = 6.200.000 ordered, plus a 20 zak
 * over-delivery worth 1.240.000.
 */
class ReceiptClearingDocumentTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    private const PO_QTY = 100.0;

    private const PO_UNIT_PRICE = 62000.0;

    private const PO_DPP = 6200000.0;

    private const PO_PPN = 682000.0;

    private const EXTRA_QTY = 20.0;

    private const EXTRA_VALUE = 1240000.0;

    private Warehouse $pusat;

    private Item $semen;

    private Vendor $supplier;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->supplier = $this->makeVendor();
        $this->project = $this->makeProject();
    }

    // ---------------------------------------------------------------- N1: the header check

    public function test_a_receipt_against_a_closed_purchase_order_is_refused_even_on_an_unlinked_line(): void
    {
        // The audit's over-delivery: the order is complete (Procurement closed
        // it) and invoiced, then the vendor sends 20 zak more and the clerk
        // records them against the same PO with no po_item_id.
        $po = $this->makePo();
        $this->receive($po, self::PO_QTY);

        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);

        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertSame(0.0, $this->accountNet('2-1150'));

        $extra = $this->makeGrn($this->pusat, [[$this->semen, self::EXTRA_QTY, self::PO_UNIT_PRICE]], '2026-03-12', [
            'purchase_order_id' => $po->id,
            'vendor_id' => $this->supplier->id,
        ]); // po_item_id deliberately NULL, exactly as the request permits

        try {
            $this->stock()->postReceipt($extra);
            $this->fail('Expected a receipt against a closed PO to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($po->code, $e->getMessage());
            $this->assertStringContainsString('closed', $e->getMessage());
        }

        // Refused means refused: no journal, no stock, no half-posted document.
        $this->assertSame(StockDocumentStatus::Draft, $extra->fresh()->status);
        $this->assertNoJournalFor('goods_receipt', (int) $extra->id);
        $this->assertSame(self::PO_QTY, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // And the goods are still recordable, and billable, without the PO.
        $vendorReceipt = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::EXTRA_QTY, self::PO_UNIT_PRICE]],
            '2026-03-12',
            ['vendor_id' => $this->supplier->id],
        ));

        $this->assertSame('2-1600', $vendorReceipt->fresh()->gl_clearing_account);

        $bill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $vendorReceipt->id,
            'bill_date' => '2026-03-15',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        $this->assertSame(self::EXTRA_VALUE, $lines['2-1600']['debit']);
        $this->assertSame(self::EXTRA_VALUE, $lines['2-1100']['credit']);

        // Nothing stranded anywhere: 6.200.000 + 1.240.000 = 7.440.000 of stock,
        // both clearing accounts empty, and the P&L untouched until issue.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(7440000.0, $this->accountNet('1-1400'));
        $this->assertSame(0.0, $this->accountNet('5-1100'));
    }

    public function test_goods_that_arrive_after_a_classic_bill_cannot_be_received_against_that_order(): void
    {
        // Nothing was ever delivered, the buyer closed the order and the vendor
        // invoiced it anyway: the bill took the classic treatment and expensed
        // 6.200.000 to the project. If the goods could then be received against
        // the same PO, the credit would strand and the material would be
        // recognised a second time on issue.
        //
        // NO deliver-to warehouse, because that is now the only shape of this
        // premise Finance still produces: material ordered INTO a warehouse and
        // never received can no longer be billed to a project at all (T44,
        // ApBillService::assertOrderedStockWasReceived). Material shipped
        // straight to site still reaches 5-1100 on the invoice, and it is
        // exactly the case where a later receipt against the order would be the
        // second charge this guard exists to refuse.
        $po = $this->makePo(['warehouse_id' => null]);
        app(PoService::class)->close($po->fresh());

        $bill = $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertSame(self::PO_DPP, $this->linesByAccount(
            $this->singleJournalFor('ap_bill', (int) $bill->id)
        )['5-1100']['debit']);

        foreach ([true, false] as $linkTheLine) {
            $late = $this->makeGrn($this->pusat, [[$this->semen, self::PO_QTY, self::PO_UNIT_PRICE]], '2026-03-12', [
                'purchase_order_id' => $po->id,
                'vendor_id' => $this->supplier->id,
            ]);

            if ($linkTheLine) {
                $late->items()->update(['po_item_id' => $po->items()->value('id')]);
            }

            try {
                $this->stock()->postReceipt($late->fresh());
                $this->fail('Expected a receipt against a closed, already billed PO to be refused.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('only an approved purchase order can receive goods', $e->getMessage());
            }
        }

        // 5-1100 carries the purchase exactly once — the audit measured
        // 12.400.000 here — and no clearing account was touched at all.
        $this->assertSame(self::PO_DPP, $this->accountNet('5-1100'));
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(0.0, $this->accountNet('1-1400'));
    }

    // ---------------------------------------------------------------- N1: the credit leg

    public function test_a_delivery_on_an_order_whose_bill_is_already_approved_accrues_and_is_billable(): void
    {
        // A mixed order: one stock line, one service line. Receiving the goods
        // in full does not close it (the service line is still open), so the PO
        // stays approved — and its one final bill has already been approved,
        // which is what makes the GR/IR route unusable for anything that
        // arrives later.
        $po = $this->makePo();
        $po->items()->create([
            'line_no' => 2,
            'description' => 'Ongkos angkut',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 0,
            'amount' => 0,
            'qty_received' => 0,
        ]);

        $this->receive($po, self::PO_QTY);
        $this->approveBill($this->apBills()->create([
            'purchase_order_id' => $po->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertSame(DocumentStatus::Approved, $po->fresh()->status);
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // 20 zak more arrive against that still-approved order.
        $extra = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::EXTRA_QTY, self::PO_UNIT_PRICE]],
            '2026-03-12',
            ['purchase_order_id' => $po->id, 'vendor_id' => $this->supplier->id],
        ));

        // Not GR/IR: no second bill for this PO can ever exist, so that credit
        // would sit on the balance sheet for ever. The accrual has a document.
        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $extra->id));

        $this->assertSame(self::EXTRA_VALUE, $lines['1-1400']['debit']);
        $this->assertSame(self::EXTRA_VALUE, $lines['2-1600']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertSame('2-1600', $extra->fresh()->gl_clearing_account);
        $this->assertSame(self::EXTRA_VALUE, $extra->fresh()->recordedClearingAmount());

        // Procurement's own refusal still stands for a second PO bill…
        try {
            $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-15']);
            $this->fail('Expected a second bill for the same PO to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString("A bill already exists for PO {$po->code}", $e->getMessage());
        }

        // …and the receipt bill — the document that CAN clear it — is allowed
        // through, even though the receipt names a purchase order.
        $extraBill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $extra->id,
            'bill_date' => '2026-03-15',
        ]));

        $billLines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $extraBill->id));

        $this->assertSame(self::EXTRA_VALUE, $billLines['2-1600']['debit']);
        $this->assertSame(self::EXTRA_VALUE, $billLines['2-1100']['credit']);
        $this->assertSame(self::EXTRA_VALUE, (float) $extraBill->gl_cleared_amount);

        // Both clearing accounts empty; stock carries 120 zak at 62.000.
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(7440000.0, $this->accountNet('1-1400'));
        $this->assertSame(7440000.0, $this->balanceValue($this->pusat, $this->semen));
    }

    public function test_a_delivery_while_the_po_bill_is_still_a_draft_keeps_the_gr_ir_credit(): void
    {
        // The two clearing routes must stay disjoint, or the same credit gets
        // cleared twice. A bill that exists but has not been approved has not
        // run its sweep yet: it WILL debit this receipt's credit, so the credit
        // belongs in GR/IR and the receipt may not be billed on its own.
        $po = $this->makePo();
        $po->items()->create([
            'line_no' => 2,
            'description' => 'Ongkos angkut',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 0,
            'amount' => 0,
            'qty_received' => 0,
        ]);

        $this->receive($po, self::PO_QTY);

        $draft = $this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']);
        $this->assertSame(DocumentStatus::Draft, $draft->status);

        $extra = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::EXTRA_QTY, self::PO_UNIT_PRICE]],
            '2026-03-12',
            ['purchase_order_id' => $po->id, 'vendor_id' => $this->supplier->id],
        ));

        $this->assertSame('2-1150', $extra->fresh()->gl_clearing_account);

        try {
            $this->apBills()->create(['goods_receipt_id' => $extra->id, 'bill_date' => '2026-03-13']);
            $this->fail('Expected the receipt to be billable only through its PO.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tagihkan melalui pesanan pembeliannya', $e->getMessage());
        }

        $bill = $this->approveBill($draft->fresh());
        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // One sweep, both receipts: 6.200.000 + 1.240.000 = 7.440.000, and the
        // 1.240.000 the vendor never invoiced is a purchase gain.
        $this->assertSame(7440000.0, $lines['2-1150']['debit']);
        $this->assertSame(self::EXTRA_VALUE, $lines['6-4500']['credit']);
        $this->assertSame(7440000.0, (float) $bill->gl_cleared_amount);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0, ApBill::query()->whereNotNull('goods_receipt_id')->count());
    }

    // ---------------------------------------------------------------- N2: ids are not documents

    public function test_a_purchase_order_id_that_resolves_to_nothing_falls_through_to_the_vendor(): void
    {
        // Neither the API (Rule::exists) nor an import should produce this, but
        // the engine must not credit GR/IR on the strength of an integer.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::PO_QTY, self::PO_UNIT_PRICE]],
            '2026-03-05',
            ['purchase_order_id' => 999999, 'vendor_id' => $this->supplier->id],
        ));

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        $this->assertSame(['1-1400', '2-1600'], array_keys($lines));
        $this->assertSame('2-1600', $grn->fresh()->gl_clearing_account);
        $this->assertSame(0.0, $this->accountNet('2-1150'));

        // The branch it fell into has a real debit path, and it empties.
        $bill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $grn->id,
            'bill_date' => '2026-03-10',
        ]));

        $this->assertSame(self::PO_DPP, $this->linesByAccount(
            $this->singleJournalFor('ap_bill', (int) $bill->id)
        )['2-1600']['debit']);
        $this->assertSame(0.0, $this->accountNet('2-1600'));
    }

    public function test_a_vendor_id_that_resolves_to_nothing_raises_no_liability_at_all(): void
    {
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::PO_QTY, self::PO_UNIT_PRICE]],
            '2026-03-05',
            ['purchase_order_id' => 999999, 'vendor_id' => 999999],
        ));

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        // No counterparty could be resolved, so no liability is invented: the
        // credit is the opening-balance equity leg and records nothing.
        $this->assertSame(['1-1400', '3-3100'], array_keys($lines));
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertSame(0.0, $this->accountNet('2-1150'));
        $this->assertSame(0.0, $this->accountNet('2-1600'));
    }

    public function test_a_receipt_naming_only_a_purchase_order_accrues_towards_that_orders_vendor(): void
    {
        // The receipt itself names no vendor. The order does, and that is who
        // delivered — so the accrual has a counterparty and the operator can
        // raise the bill for it.
        $po = $this->makePo();
        $po->items()->create([
            'line_no' => 2,
            'description' => 'Ongkos angkut',
            'qty' => 1,
            'unit' => 'ls',
            'unit_price' => 0,
            'amount' => 0,
            'qty_received' => 0,
        ]);

        $this->receive($po, self::PO_QTY);
        $this->approveBill($this->apBills()->create(['purchase_order_id' => $po->id, 'bill_date' => '2026-03-10']));

        $extra = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::EXTRA_QTY, self::PO_UNIT_PRICE]],
            '2026-03-12',
            ['purchase_order_id' => $po->id], // vendor_id NULL on the receipt
        ));

        $this->assertSame('2-1600', $extra->fresh()->gl_clearing_account);

        $bill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $extra->id,
            'vendor_id' => $this->supplier->id,
            'bill_date' => '2026-03-15',
        ]));

        $this->assertSame((int) $this->supplier->id, (int) $bill->vendor_id);
        $this->assertSame(0.0, $this->accountNet('2-1600'));
    }

    // ---------------------------------------------------------------- N2: the request layer

    public function test_the_goods_receipt_requests_reject_ids_that_point_at_nothing(): void
    {
        $payload = [
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => 999999,
            'vendor_id' => 999999,
            'receipt_date' => '2026-03-05',
            'items' => [['item_id' => $this->semen->id, 'qty' => 10, 'unit_cost' => 62000]],
        ];

        foreach ([new GoodsReceiptStoreRequest, new GoodsReceiptUpdateRequest] as $request) {
            $rules = $request->rules();

            $rejected = validator($payload, $rules);

            $this->assertTrue($rejected->fails(), get_class($request).' accepted ids that resolve to nothing.');
            $this->assertArrayHasKey('purchase_order_id', $rejected->errors()->toArray());
            $this->assertArrayHasKey('vendor_id', $rejected->errors()->toArray());

            // Real ids still pass…
            $po = $this->makePo();
            $accepted = validator(array_merge($payload, [
                'purchase_order_id' => $po->id,
                'vendor_id' => $this->supplier->id,
            ]), $rules);

            $this->assertFalse($accepted->fails(), implode('; ', $accepted->errors()->all()));

            // …and a soft-deleted order does not, because no bill can name it.
            $po->delete();
            $deleted = validator(array_merge($payload, [
                'purchase_order_id' => $po->id,
                'vendor_id' => $this->supplier->id,
            ]), $rules);

            $this->assertTrue($deleted->fails());
            $this->assertArrayHasKey('purchase_order_id', $deleted->errors()->toArray());
        }
    }

    public function test_the_goods_receipt_requests_still_validate_without_procurement(): void
    {
        // Inventory has to keep working on an installation that does not have
        // Procurement at all: the cross-module rule is added only when the
        // table it points at exists.
        Schema::rename('prc_purchase_orders', 'gone_purchase_orders');
        Schema::rename('prc_vendors', 'gone_vendors');

        $rules = (new GoodsReceiptStoreRequest)->rules();

        $this->assertSame(['nullable', 'integer'], $rules['purchase_order_id']);
        $this->assertSame(['nullable', 'integer'], $rules['vendor_id']);

        $validator = validator([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => 999999,
            'vendor_id' => 999999,
            'receipt_date' => '2026-03-05',
            'items' => [['item_id' => $this->semen->id, 'qty' => 10, 'unit_cost' => 62000]],
        ], $rules);

        $this->assertFalse($validator->fails(), implode('; ', $validator->errors()->all()));

        // And the engine, which cannot resolve either id, books no liability.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::PO_QTY, self::PO_UNIT_PRICE]],
            '2026-03-05',
            ['purchase_order_id' => 999999, 'vendor_id' => 999999],
        ));

        $this->assertSame(
            ['1-1400', '3-3100'],
            array_keys($this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id)))
        );
        $this->assertNull($grn->fresh()->gl_clearing_account);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * An approved purchase order for 100 zak semen with a real STOCK line
     * (item_id set — the schema's own definition of a goods line).
     */
    private function makePo(array $attributes = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'vendor_id' => $this->supplier->id,
            'project_id' => $this->project->id,
            'warehouse_id' => $this->pusat->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => self::PO_DPP,
            'discount_amount' => 0,
            'dpp' => self::PO_DPP,
            'ppn_rate' => 11.0,
            'ppn_amount' => self::PO_PPN,
            'total' => self::PO_DPP + self::PO_PPN,
            'status' => DocumentStatus::Approved,
        ], $attributes));

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

    /**
     * Receive against the PO's stock line for real, so Procurement's received
     * quantity moves and the credit a bill has to clear actually exists.
     */
    private function receive(PurchaseOrder $po, float $qty, string $date = '2026-03-05'): GoodsReceipt
    {
        $grn = GoodsReceipt::create([
            'warehouse_id' => $this->pusat->id,
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'receipt_date' => $date,
            'received_by' => $this->warehouseUser()->id,
            'status' => StockDocumentStatus::Draft,
        ]);

        $grn->items()->create([
            'item_id' => $this->semen->id,
            'po_item_id' => $po->items()->whereNotNull('item_id')->value('id'),
            'qty' => $qty,
            'unit_cost' => self::PO_UNIT_PRICE,
            'amount' => round($qty * self::PO_UNIT_PRICE, 2),
        ]);

        return $this->stock()->postReceipt($grn->refresh());
    }

    private function assertNoJournalFor(string $referenceType, int $referenceId): void
    {
        $this->assertDatabaseMissing('fin_journals', [
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * Signed movement of a COA account across every posted journal line:
     * debit - credit. Zero means the account has been fully cleared.
     */
    private function accountNet(string $code): float
    {
        $sums = JournalLine::query()
            ->where('account_id', $this->accountId($code))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return round((float) $sums->debit - (float) $sums->credit, 2);
    }
}
