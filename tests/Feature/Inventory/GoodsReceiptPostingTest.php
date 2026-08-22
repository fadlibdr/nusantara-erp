<?php

namespace Tests\Feature\Inventory;

use Modules\Finance\Models\Journal;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * GR/IR step 1 — posting a goods receipt books the goods onto the balance sheet
 * against a liability. Nothing may reach the P&L yet: the cost waits for the
 * material issue.
 *
 * Which account carries the credit follows which document can remove it again —
 * the invariant is that every credit posted here has a debit path that exists in
 * the product:
 *   with a billable PO  => 2-1150 GR/IR, debited by that PO's bill;
 *   otherwise a vendor  => 2-1600 accrual, debited by a manual bill that
 *                          references this receipt (fin_ap_bills.goods_receipt_id);
 *   neither             => 3-3100 Saldo Awal, EQUITY. Stock that arrives with no
 *                          counterparty is an opening balance: nobody is owed
 *                          anything and nothing was earned, so neither a
 *                          liability nor a P&L account may be touched. Crediting
 *                          6-4400 Selisih Persediaan here — which is what this
 *                          branch used to do — reports the company's entire
 *                          go-live inventory as operating income.
 *
 * The first two record what they credited on the receipt row
 * (gl_clearing_account / gl_clearing_amount); the third records nothing, so no
 * bill can ever try to clear it.
 */
class GoodsReceiptPostingTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    private Warehouse $pusat;

    private Item $semen;

    private Item $besi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->besi = $this->makeItem('Besi Beton D13', ['unit' => 'btg']);
    }

    public function test_posting_a_receipt_against_a_po_debits_inventory_and_credits_the_clearing_account(): void
    {
        $po = $this->makeGoodsPurchaseOrder($this->pusat);

        $grn = $this->stock()->postReceipt($this->makeGrn($this->pusat, [
            [$this->semen, 100, 15000],  // 100 * 15.000 = 1.500.000
            [$this->besi, 5, 200000],    //   5 * 200.000 = 1.000.000
        ], '2026-03-10', ['purchase_order_id' => $po->id]));

        // Total received value = 1.500.000 + 1.000.000 = 2.500.000
        $journal = $this->singleJournalFor('goods_receipt', (int) $grn->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        $this->assertSame(['1-1400', '2-1150'], array_keys($lines));
        $this->assertSame(2500000.0, $lines['1-1400']['debit']);
        $this->assertSame(0.0, $lines['1-1400']['credit']);
        $this->assertSame(2500000.0, $lines['2-1150']['credit']);
        $this->assertSame(0.0, $lines['2-1150']['debit']);

        // The decision is recorded on the receipt, not re-derived at billing
        // time: this is the exact pair ApBillService is allowed to clear.
        $this->assertSame('2-1150', $grn->fresh()->gl_clearing_account);
        $this->assertSame(2500000.0, $grn->fresh()->recordedClearingAmount());
    }

    public function test_a_receipt_from_a_vendor_without_a_purchase_order_credits_the_accrual_account(): void
    {
        // Barang datang duluan: a real delivery from a known vendor whose
        // invoice has not been raised through procurement. No PO will ever carry
        // it, so parking the credit in 2-1150 would strand it there; it is
        // accrued in 2-1600, which a manual bill referencing this receipt
        // debits back out.
        $grn = $this->stock()->postReceipt($this->makeGrn($this->pusat, [
            [$this->semen, 100, 15000],  // 100 * 15.000 = 1.500.000
            [$this->besi, 5, 200000],    //   5 * 200.000 = 1.000.000
        ], '2026-03-10', ['vendor_id' => $this->vendor()->id]));

        $this->assertNull($grn->purchase_order_id);

        $journal = $this->singleJournalFor('goods_receipt', (int) $grn->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        $this->assertSame(['1-1400', '2-1600'], array_keys($lines));
        $this->assertSame(2500000.0, $lines['1-1400']['debit']);
        $this->assertSame(2500000.0, $lines['2-1600']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);

        // Recorded, therefore clearable — see ReceiptAccrualBillingTest for the
        // bill that empties it.
        $this->assertSame('2-1600', $grn->fresh()->gl_clearing_account);
        $this->assertSame(2500000.0, $grn->fresh()->recordedClearingAmount());
    }

    public function test_a_receipt_with_neither_po_nor_vendor_credits_equity_and_records_no_clearing(): void
    {
        // Opening stock: the goods are on hand, nobody is owed anything for
        // them, and no sale or purchase happened. Booking a liability would
        // create a balance no document in the product could ever remove;
        // booking the credit in 6-4400 Selisih Persediaan — an EXPENSE account —
        // would report the whole opening inventory as operating income, because
        // a negative expense is income. The counter-entry of an asset that
        // simply exists is equity.
        $grn = $this->stock()->postReceipt($this->makeGrn($this->pusat, [
            [$this->semen, 100, 15000],  // 100 * 15.000 = 1.500.000
            [$this->besi, 5, 200000],    //   5 * 200.000 = 1.000.000
        ], '2026-03-10'));

        $this->assertNull($grn->purchase_order_id);
        $this->assertNull($grn->vendor_id);

        $journal = $this->singleJournalFor('goods_receipt', (int) $grn->id);
        $lines = $this->linesByAccount($journal);

        $this->assertSame(['1-1400', '3-3100'], array_keys($lines));
        $this->assertSame(2500000.0, $lines['1-1400']['debit']);
        $this->assertSame(2500000.0, $lines['3-3100']['credit']);
        $this->assertArrayNotHasKey('2-1150', $lines);
        $this->assertArrayNotHasKey('2-1600', $lines);
        $this->assertArrayNotHasKey('6-4400', $lines);

        // Balance sheet only: an asset against equity, nothing in the P&L.
        $types = $journal->lines()->with('account')->get()
            ->map(fn ($line): string => $line->account->account_type->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['asset', 'equity'], $types);

        // Nothing recorded: there is no outstanding balance, so no bill may
        // clear this receipt.
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertNull($grn->fresh()->gl_clearing_amount);
        $this->assertFalse($grn->fresh()->hasRecordedClearing());
    }

    public function test_the_receipt_journal_never_touches_the_profit_and_loss(): void
    {
        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10', [
                'vendor_id' => $this->vendor()->id,
            ])
        );

        $journal = $this->singleJournalFor('goods_receipt', (int) $grn->id);

        $types = $journal->lines()->with('account')->get()
            ->map(fn ($line): string => $line->account->account_type->value)
            ->sort()
            ->values()
            ->all();

        // Balance-sheet only: one asset debit, one liability credit.
        $this->assertSame(['asset', 'liability'], $types);
    }

    public function test_the_journal_carries_the_receiving_user_and_the_document_reference(): void
    {
        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );

        $journal = $this->singleJournalFor('goods_receipt', (int) $grn->id);

        $this->assertSame((int) $grn->received_by, (int) $journal->posted_by);
        $this->assertStringContainsString($grn->code, $journal->description);
    }

    public function test_the_journal_value_sums_line_amounts_rounded_per_line(): void
    {
        $grn = $this->stock()->postReceipt($this->makeGrn($this->pusat, [
            [$this->semen, 3.333, 1500.55], // 3,333 * 1.500,55 = 5.001,33315 -> 5.001,33
            [$this->besi, 7.777, 999.99],   // 7,777 *   999,99 = 7.776,92223 -> 7.776,92
        ], '2026-03-10', ['vendor_id' => $this->vendor()->id]));

        // 5.001,33 + 7.776,92 = 12.778,25
        // Vendor but no PO, so the credit is the 2-1600 accrual.
        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        $this->assertSame(12778.25, $lines['1-1400']['debit']);
        $this->assertSame(12778.25, $lines['2-1600']['credit']);

        // The recorded amount is the journal amount, to the cent — that identity
        // is what stops a residue when the bill clears it.
        $this->assertSame(12778.25, $grn->fresh()->recordedClearingAmount());
    }

    public function test_a_zero_value_receipt_moves_stock_without_a_journal(): void
    {
        // Free issue from a vendor: quantity arrives, value is nil.
        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 10, 0]], '2026-03-10')
        );

        $this->assertSame(10.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(0.0, $this->balanceAvg($this->pusat, $this->semen));
        $this->assertNoJournalFor('goods_receipt', (int) $grn->id);
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_each_receipt_raises_its_own_journal(): void
    {
        $first = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );
        $second = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 50, 18000]], '2026-04-10')
        );

        $this->assertSame(2, Journal::query()->count());

        // 100 * 15.000 = 1.500.000 and 50 * 18.000 = 900.000
        $this->assertSame(
            1500000.0,
            $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $first->id))['1-1400']['debit']
        );
        $this->assertSame(
            900000.0,
            $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $second->id))['1-1400']['debit']
        );

        // Warehouse average after both: 2.400.000 / 150 = 16.000
        $this->assertSame(16000.0, $this->balanceAvg($this->pusat, $this->semen));
    }

    public function test_a_refused_second_posting_raises_no_second_journal(): void
    {
        $grn = $this->stock()->postReceipt(
            $this->makeGrn($this->pusat, [[$this->semen, 100, 15000]], '2026-03-10')
        );

        try {
            $this->stock()->postReceipt($grn->fresh());
        } catch (\LogicException) {
            // expected — the guard is asserted in the unit suite
        }

        $this->assertSame(1, Journal::query()->count());
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
    }
}
