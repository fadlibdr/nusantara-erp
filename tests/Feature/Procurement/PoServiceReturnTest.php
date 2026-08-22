<?php

namespace Tests\Feature\Procurement;

use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderItem;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;
use Tests\ErpTestCase;

/**
 * PoService::unregisterReceipt() — the mirror registerReceipt() never had.
 *
 * The audit (temuan 38) named the one-way arithmetic exactly: qty_received
 * only ever grew, so an order whose goods went back to the vendor kept reading
 * fully delivered — the AP completeness gate (qty_received < qty) then let the
 * bill through for goods no longer in the gudang, and the auto-closed PO
 * refused the replacement delivery when it finally arrived.
 *
 * The delicate half is the REOPEN: only the AUTOMATIC close may be undone.
 * A manual close() forgives an undelivered remainder on purpose, and a return
 * must not silently revive an order the buyer has cancelled.
 */
class PoServiceReturnTest extends ErpTestCase
{
    private function service(): PoService
    {
        return app(PoService::class);
    }

    private function makeVendor(): Vendor
    {
        return Vendor::create([
            'code' => 'VND-0001',
            'name' => 'PT Semen Distribusi Utama',
            'is_pkp' => true,
            'is_subcontractor' => false,
            'classification' => 'material',
            'payment_term_days' => 30,
            'status' => 'active',
        ]);
    }

    /** An approved one-line order for 100 zak @ 62.000. */
    private function makeApprovedPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'vendor_id' => $this->makeVendor()->id,
            'order_date' => '2026-03-01',
            'payment_term_days' => 30,
            'subtotal' => 6200000,
            'discount_amount' => 0,
            'dpp' => 6200000,
            'ppn_rate' => 11.0,
            'ppn_amount' => 682000,
            'total' => 6882000,
            'status' => DocumentStatus::Approved,
        ]);

        $po->items()->create([
            'line_no' => 1,
            'description' => 'Semen Gresik 40kg',
            'qty' => 100,
            'unit' => 'zak',
            'unit_price' => 62000,
            'amount' => 6200000,
            'qty_received' => 0,
        ]);

        return $po->refresh();
    }

    private function line(PurchaseOrder $po): PurchaseOrderItem
    {
        return $po->items()->sole()->fresh();
    }

    // ------------------------------------------------------------------- works

    public function test_a_return_decrements_qty_received_and_reopens_the_auto_closed_po(): void
    {
        $po = $this->makeApprovedPo();

        // The full delivery closes the order automatically.
        $this->service()->registerReceipt($this->line($po), 100);
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
        $this->assertNotNull($po->fresh()->closed_at);

        $this->service()->unregisterReceipt($this->line($po), 20);

        // The premise of that close is now false: 20 zak are owed again.
        $po = $po->fresh();
        $this->assertSame(80.0, (float) $this->line($po)->qty_received);
        $this->assertSame(DocumentStatus::Approved, $po->status);
        $this->assertNull($po->closed_at);
    }

    public function test_the_reopened_order_can_receive_the_replacement_delivery(): void
    {
        $po = $this->makeApprovedPo();
        $this->service()->registerReceipt($this->line($po), 100);
        $this->service()->unregisterReceipt($this->line($po), 20);

        // Before the mirror existed this refusal was permanent: the order read
        // fully received for ever and the replacement had nowhere to land.
        $this->service()->registerReceipt($this->line($po), 20);

        $this->assertSame(100.0, (float) $this->line($po)->qty_received);
        $this->assertSame(DocumentStatus::Closed, $po->fresh()->status);
    }

    public function test_a_partial_return_on_a_still_open_order_only_moves_the_quantity(): void
    {
        $po = $this->makeApprovedPo();
        $this->service()->registerReceipt($this->line($po), 60);

        $this->service()->unregisterReceipt($this->line($po), 10);

        // Nothing to reopen: the order never closed.
        $this->assertSame(50.0, (float) $this->line($po)->qty_received);
        $this->assertSame(DocumentStatus::Approved, $po->fresh()->status);
    }

    public function test_a_manual_close_is_not_reopened_by_a_return(): void
    {
        // The buyer accepted 60 of 100 and cancelled the rest: close() means
        // "nothing more is coming". A 10-zak return does not change that —
        // reopening would quietly revive a commitment somebody ended.
        $po = $this->makeApprovedPo();
        $this->service()->registerReceipt($this->line($po), 60);
        $this->service()->close($po->fresh());

        $this->service()->unregisterReceipt($this->line($po), 10);

        $po = $po->fresh();
        $this->assertSame(50.0, (float) $this->line($po)->qty_received);
        $this->assertSame(DocumentStatus::Closed, $po->status);
        $this->assertNotNull($po->closed_at);
    }

    // ---------------------------------------------------------------- refusals

    public function test_a_return_cannot_exceed_the_received_quantity(): void
    {
        $po = $this->makeApprovedPo();
        $this->service()->registerReceipt($this->line($po), 60);

        try {
            $this->service()->unregisterReceipt($this->line($po), 70);
            $this->fail('Expected a return above qty_received to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('exceeds received quantity', $e->getMessage());
        }

        // qty_received may never go negative: the completeness gate and the
        // outstanding report both read it as a physical fact.
        $this->assertSame(60.0, (float) $this->line($po)->qty_received);
    }

    public function test_a_return_quantity_must_be_positive(): void
    {
        $po = $this->makeApprovedPo();
        $this->service()->registerReceipt($this->line($po), 60);

        try {
            $this->service()->unregisterReceipt($this->line($po), 0);
            $this->fail('Expected a zero return to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must be positive', $e->getMessage());
        }
    }

    public function test_a_draft_order_takes_no_return(): void
    {
        $po = $this->makeApprovedPo();
        $this->service()->registerReceipt($this->line($po), 60);
        $po->forceFill(['status' => DocumentStatus::Draft])->save();

        try {
            $this->service()->unregisterReceipt($this->line($po), 10);
            $this->fail('Expected a draft order to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('only an approved or closed PO', $e->getMessage());
        }

        $this->assertSame(60.0, (float) $this->line($po)->qty_received);
    }
}
