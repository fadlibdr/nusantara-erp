<?php

namespace Tests\Feature\Inventory;

use LogicException;
use Modules\Finance\Models\FiscalPeriod;
use Modules\Finance\Models\Journal;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Warehouse;
use Tests\ErpTestCase;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * A CLOSED PERIOD SEALS STOCK, not just journals.
 *
 * FiscalPeriodRollbackTest already proves the value-bearing documents roll back
 * when the GL refuses them. It could only prove that because those documents
 * reach JournalService at all — and three of them return BEFORE they do whenever
 * the value rounds to zero (postReceiptJournal, postIssueJournal and
 * postAdjustmentJournal each check `=== 0.0` and return), while transfers never
 * call it under any circumstances.
 *
 * So the seal had exactly the holes this file measures. The audit's two probes:
 * a zero-cost receipt followed by an issue of 60, accepted into a fully closed
 * 2026 with the balance walking 100 -> 40; and a net-zero opname (110 semen
 * counted against 100, 90 besi against 100, both at Rp 15.000) that moved
 * Rp 150.000 of inventory value from one item to another inside a closed month,
 * leaving the company total unchanged so GL and sub-ledger still tied while the
 * PER-ITEM stock valuation of a signed-off period was retroactively different.
 * Plus a back-dated TRF walking 200 zak Semen Portland (Rp 12.400.000) out of a
 * warehouse inside a closed and reported January.
 *
 * The rule now: a fiscal period governs WHEN a movement may be recorded, not
 * whether the document in hand happens to raise a journal.
 */
class StockPeriodSealTest extends ErpTestCase
{
    use AssertsJournals;
    use InventoryFixtures;

    private Warehouse $pusat;

    private Warehouse $site;

    private Item $semen;

    private Item $besi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedger(2026);

        $this->pusat = $this->makeWarehouse('WH-PUSAT');
        $this->site = $this->makeWarehouse('WH-SITE');
        $this->semen = $this->makeItem('Semen Gresik 40kg');
        $this->besi = $this->makeItem('Besi Beton D13', ['unit' => 'btg']);
    }

    private function closePeriod(int $year, int $month): void
    {
        FiscalPeriod::query()
            ->where('year', $year)
            ->where('month', $month)
            ->update(['status' => 'closed']);
    }

    public function test_a_zero_valued_issue_cannot_be_posted_into_a_closed_period(): void
    {
        // Free-issue stock: unit_cost 0 is what GoodsReceiptStoreRequest permits
        // (min:0), so every movement of it values at zero and raises no journal.
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 100, 0]], '2026-03-10'));

        $this->closePeriod(2026, 3);

        $issue = $this->makeIssue($this->pusat, [[$this->semen, 60]], null, '2026-03-15');

        try {
            $this->stock()->postIssue($issue);
            $this->fail('Expected a closed period to refuse a zero-valued issue.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-03 sudah ditutup', $e->getMessage());
        }

        // The audit measured 100 -> 40 here with zero journals to explain it.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(StockDocumentStatus::Draft, $issue->fresh()->status);
        $this->assertSame(1, StockLedgerEntry::query()->count());
    }

    public function test_the_same_zero_valued_issue_posts_while_its_period_is_open(): void
    {
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 100, 0]], '2026-03-10'));

        $issue = $this->stock()->postIssue($this->makeIssue($this->pusat, [[$this->semen, 60]], null, '2026-03-15'));

        // Still no journal — there is genuinely no value to book — but the stock
        // moved, which is the whole point of the seal being about the movement.
        $this->assertSame(StockDocumentStatus::Posted, $issue->fresh()->status);
        $this->assertSame(40.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertNoJournalFor('inventory_issue', (int) $issue->id);
    }

    public function test_a_net_zero_opname_cannot_move_value_between_items_inside_a_closed_period(): void
    {
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [
            [$this->semen, 100, 15000],
            [$this->besi, 100, 15000],
        ], '2026-03-10'));

        $this->closePeriod(2026, 3);

        // +10 semen and -10 besi at the same 15.000: netValue = 0, so no journal
        // would be raised and nothing downstream would ever notice.
        $adjustment = $this->makeAdjustment($this->pusat, [
            [$this->semen, 110],
            [$this->besi, 90],
        ], '2026-03-25');

        try {
            $this->stock()->postAdjustment($adjustment);
            $this->fail('Expected a closed period to refuse a net-zero opname.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-03 sudah ditutup', $e->getMessage());
        }

        // Rp 150.000 stayed where the signed-off valuation said it was.
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(100.0, $this->balanceQty($this->pusat, $this->besi));
        $this->assertNull($adjustment->fresh()->posted_at);
    }

    public function test_a_transfer_cannot_be_sent_into_a_closed_period(): void
    {
        // Transfers are the one movement that posts NO journal at all, so before
        // this guard they were never period-checked in any circumstance.
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 200, 62000]], '2026-01-05'));

        $this->closePeriod(2026, 1);

        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 200]], '2026-01-15');

        try {
            $this->stock()->sendTransfer($transfer);
            $this->fail('Expected a closed period to refuse a transfer.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Periode fiskal 2026-01 sudah ditutup', $e->getMessage());
        }

        // 200 zak @ 62.000 = Rp 12.400.000 stayed in the warehouse the January
        // stock card the accountant signed says they were in.
        $this->assertSame(TransferStatus::Draft, $transfer->fresh()->status);
        $this->assertSame(200.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(1, StockLedgerEntry::query()->count());
    }

    public function test_goods_closed_over_while_in_transit_arrive_on_the_day_they_arrive(): void
    {
        // Both ends are sealed, but they are sealed differently: the SEND is
        // pinned to the transfer's date and a closed January must refuse it,
        // while the ARRIVAL is an event of the day the truck reaches the site.
        // Gating the receipt on the send date instead stranded the goods for
        // ever — an in-transit transfer cannot be edited, deleted or cancelled,
        // so Rp 12.400.000 sat in 1-1400 with nothing in either warehouse
        // balance behind it and no document able to bring it back.
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 200, 62000]], '2026-01-05'));

        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 200]], '2026-01-15');
        $this->stock()->sendTransfer($transfer);

        $this->closePeriod(2026, 1);

        $received = $this->stock()->receiveTransfer($transfer->fresh());

        $this->assertSame(TransferStatus::Received, $received->status);
        $this->assertSame(200.0, $this->balanceQty($this->site, $this->semen));

        // Dated today, and the transfer says so, so an operator reading a
        // January transfer against an arrival row months later is told why.
        $this->assertSame(now()->toDateString(), $received->received_date->toDateString());
        $this->assertSame(
            now()->toDateString(),
            StockLedgerEntry::query()->orderByDesc('id')->firstOrFail()->trx_date->toDateString(),
        );
        // Nothing was written into the month that was signed off.
        $this->assertSame(1, StockLedgerEntry::query()->whereBetween('trx_date', ['2026-01-01', '2026-01-31'])->where('direction', 'out')->count());
    }

    public function test_an_arrival_is_refused_when_there_is_no_open_day_to_record_it_on(): void
    {
        // The escape hatch is today, so it closes when today is shut too: the
        // honest answer is then a refusal naming the month that has to be
        // reopened, not a movement dropped into a reported period.
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 200, 62000]], '2026-01-05'));

        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 200]], '2026-01-15');
        $this->stock()->sendTransfer($transfer);

        $this->closePeriod(2026, 1);
        $this->closePeriod((int) now()->format('Y'), (int) now()->format('n'));

        try {
            $this->stock()->receiveTransfer($transfer->fresh());
            $this->fail('Expected a receipt with nowhere to land to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('sudah ditutup', $e->getMessage());
            $this->assertStringContainsString(now()->format('Y-m'), $e->getMessage());
        }

        $this->assertSame(TransferStatus::InTransit, $transfer->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->site, $this->semen));
    }

    public function test_a_transfer_moves_normally_while_its_period_is_open(): void
    {
        $this->stock()->postReceipt($this->makeGrn($this->pusat, [[$this->semen, 200, 62000]], '2026-01-05'));

        $transfer = $this->makeTransfer($this->pusat, $this->site, [[$this->semen, 200]], '2026-01-15');

        $this->stock()->sendTransfer($transfer);
        $this->stock()->receiveTransfer($transfer->fresh());

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(0.0, $this->balanceQty($this->pusat, $this->semen));
        $this->assertSame(200.0, $this->balanceQty($this->site, $this->semen));
        // Still no journal: value moved between two warehouses of one company,
        // so 1-1400 is untouched by design.
        $this->assertSame(1, Journal::query()->count());
    }
}
