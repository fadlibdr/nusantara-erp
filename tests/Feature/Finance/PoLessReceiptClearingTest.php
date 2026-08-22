<?php

namespace Tests\Feature\Finance;

use LogicException;
use Modules\Finance\Models\Journal;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\ProjectCost;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;
use Tests\Unit\Inventory\InventoryFixtures;

/**
 * THE PO-LESS RECEIPT (audit A5), tested as one invariant rather than as a list
 * of journal shapes:
 *
 *   EVERY CREDIT THIS ENGINE RAISES HAS A DEBIT PATH THAT EXISTS IN THE
 *   PRODUCT, AND THE FULL CYCLE LEAVES NO PERMANENTLY STRANDED BALANCE.
 *
 * H2's first repair only relabelled the problem: a PO-less receipt credited
 * 2-1600 instead of 2-1150, and accounting.receipt_accrual_account had exactly
 * one consumer (StockService) and no debit side anywhere. The docblock claimed a
 * manual bill settled it; a manual bill debited 6-4100 and booked the goods a
 * SECOND time, leaving 2-1600 credited for ever. And for opening stock there is
 * no vendor and no liability at all — the credit did not belong in a liability
 * account in the first place.
 *
 * The resolution now in the code (read from StockService::receiptCreditLeg and
 * ApBillService::createFromGoodsReceipt) splits the case by whether a
 * counterparty exists, and gives each half a real closing document:
 *
 *   vendor, no PO   Cr 2-1600 penerimaan accrual, RECORDED on the receipt. A
 *                   bill referencing that receipt (fin_ap_bills.goods_receipt_id)
 *                   debits exactly the recorded amount back out, through the
 *                   same machinery a PO bill uses.
 *   no vendor       Cr 3-3100 saldo awal (EQUITY), RECORDING NOTHING. There is
 *                   no counterparty, so there is no liability to invent, and no
 *                   trading event, so nothing may reach the P&L either — an
 *                   expense credit reports the go-live inventory as income. The
 *                   credit is closed where it is raised and no bill can ever
 *                   pretend to clear it.
 *
 * The shipped installation's own defect — GRN/2026/VII/0001, Rp 332.510.000 of
 * opening stock with NO journal at all, so GL 1-1400 = 0,00 against a
 * Rp 332.510.000 sub-ledger — is repaired by the data migration, and that repair
 * is exercised here too.
 */
class PoLessReceiptClearingTest extends ErpTestCase
{
    use FinanceFixtures;
    use InventoryFixtures;

    /**
     * A delivery from a known vendor with no PO:
     *   100 zak * 62.000    = 6.200.000  dpp
     *   6.200.000 * 11%     =   682.000  ppn
     *   6.200.000 + 682.000 = 6.882.000  total
     */
    private const DELIVERY_QTY = 100.0;

    private const DELIVERY_UNIT_COST = 62000.0;

    private const DELIVERY_VALUE = 6200000.0;

    private const DELIVERY_PPN = 682000.0;

    private const DELIVERY_TOTAL = 6882000.0;

    /**
     * The shipped demo's opening stock, to the rupiah:
     *   5.000 zak * 66.502 = 332.510.000
     */
    private const OPENING_QTY = 5000.0;

    private const OPENING_UNIT_COST = 66502.0;

    private const OPENING_VALUE = 332510000.0;

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

    // ---------------------------------------------------------------- vendor, no PO: a real debit path

    public function test_a_vendor_delivery_without_a_po_is_cleared_paid_and_leaves_nothing_behind(): void
    {
        $bank = $this->makeBankAccount('1-1210');

        // Material delivered against a phone order: a vendor, no PO.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::DELIVERY_QTY, self::DELIVERY_UNIT_COST]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        $receiptLines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        // 100 * 62.000 = 6.200.000: Dr 1-1400 / Cr 2-1600, recorded on the GRN.
        $this->assertSame(6200000.0, $receiptLines['1-1400']['debit']);
        $this->assertSame(6200000.0, $receiptLines['2-1600']['credit']);
        $this->assertSame('2-1600', $grn->fresh()->gl_clearing_account);
        $this->assertSame(6200000.0, $grn->fresh()->recordedClearingAmount());
        $this->assertTrue($grn->fresh()->hasRecordedClearing());
        // No GR/IR: that account belongs to POs and nothing here can clear it.
        $this->assertSame(0, $this->lineCountFor('2-1150'));

        // The debit path the accrual never had: a bill that names the receipt.
        $bill = $this->apBills()->create([
            'goods_receipt_id' => $grn->id,
            'ppn_amount' => self::DELIVERY_PPN,
            'bill_date' => '2026-03-10',
        ]);

        // The bill defaults to the outstanding accrual, so the two ends cannot
        // drift apart: 6.200.000 credited, 6.200.000 to be billed.
        $this->assertSame(6200000.0, (float) $bill->dpp);
        $this->assertSame(682000.0, (float) $bill->ppn_amount);
        $this->assertSame(6882000.0, (float) $bill->total_payable);
        $this->assertSame((int) $this->supplier->id, (int) $bill->vendor_id);

        $bill = $this->approveBill($bill);

        $journal = $this->singleJournalFor('ap_bill', (int) $bill->id);
        $this->assertPostedAndBalanced($journal, '2026-03-10');

        $lines = $this->linesByAccount($journal);

        // Dr 2-1600 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        // The old manual bill debited 6-4100 here and booked the goods twice.
        $this->assertSame(6200000.0, $lines['2-1600']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);
        $this->assertArrayNotHasKey('6-4100', $lines);
        $this->assertArrayNotHasKey('5-1100', $lines);
        $this->assertSame(6200000.0, (float) $bill->gl_cleared_amount);

        // 6.200.000 credited by the receipt - 6.200.000 debited by the bill = 0.
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(0, ProjectCost::query()->count());

        // Consumption is still the only step that creates cost.
        $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, self::DELIVERY_QTY]],
            (int) $this->project->id,
            '2026-03-20',
        ));

        // And the vendor is paid: Dr 2-1100 6.882.000 / Cr 1-1210 6.882.000.
        $this->approvedOutgoingPayment(
            [
                'payment_date' => '2026-03-25',
                'bank_account_id' => $bank->id,
                'amount' => self::DELIVERY_TOTAL,
            ],
            [['payable_type' => 'ap_bill', 'payable_id' => $bill->id, 'amount' => self::DELIVERY_TOTAL]],
        );

        // THE invariant, at the end of the full cycle. Every account that only
        // ever holds value in transit is empty:
        $this->assertSame(0.0, $this->accountNet('2-1600')); // accrual cleared
        $this->assertSame(0.0, $this->accountNet('2-1150')); // never touched
        $this->assertSame(0.0, $this->accountNet('1-1400')); // in 6.200.000, out 6.200.000
        $this->assertSame(0.0, $this->accountNet('2-1100')); // billed 6.882.000, paid 6.882.000
        $this->assertSame(0.0, $this->accountNet('6-4400')); // no counterparty-less credit
        $this->assertSame(0.0, $this->accountNet('6-4100')); // no second expensing

        // …and the only balances left are the true economics of the purchase:
        //   5-1100  6.200.000 material consumed (once)
        //   1-1600    682.000 recoverable PPN Masukan
        //   1-1210 -6.882.000 cash paid
        //   6.200.000 + 682.000 - 6.882.000 = 0
        $this->assertSame(6200000.0, $this->accountNet('5-1100'));
        $this->assertSame(682000.0, $this->accountNet('1-1600'));
        $this->assertSame(-6882000.0, $this->accountNet('1-1210'));
        $this->assertSame(0.0, round(
            $this->accountNet('5-1100') + $this->accountNet('1-1600') + $this->accountNet('1-1210'),
            2,
        ));

        $this->assertSame(6200000.0, (float) ProjectCost::query()->sole()->amount);
        // Per-line issue reference — see StockService's per-item cost rows.
        $this->assertSame('inventory_issue_item', ProjectCost::query()->sole()->reference_type);
    }

    public function test_the_accrual_of_one_receipt_can_only_be_cleared_once(): void
    {
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::DELIVERY_QTY, self::DELIVERY_UNIT_COST]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $grn->id,
            'bill_date' => '2026-03-10',
        ]));

        // 6.200.000 credited, 6.200.000 already cleared => nothing outstanding.
        $this->assertSame(0.0, $this->accountNet('2-1600'));

        try {
            $this->apBills()->create(['goods_receipt_id' => $grn->id, 'bill_date' => '2026-03-20']);
            $this->fail('Expected the second bill against the same receipt to be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString($grn->code, $e->getMessage());
        }

        // The refusal left the ledger exactly where it was: one receipt journal
        // and one bill journal, and the accrual still at zero.
        $this->assertSame(2, Journal::query()->count());
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(-6200000.0, $this->accountNet('2-1100')); // still unpaid, correctly
    }

    // ---------------------------------------------------------------- no vendor: no liability at all

    public function test_opening_stock_raises_no_liability_and_nets_to_zero_over_the_full_cycle(): void
    {
        // The shipped demo's GRN: opening stock, no PO and no vendor. There is
        // no counterparty, so booking a liability would create a balance only a
        // hand-written JV could ever remove.
        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        $lines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));

        // 5.000 * 66.502 = 332.510.000: Dr 1-1400 / Cr 3-3100 Saldo Awal.
        $this->assertSame(332510000.0, $lines['1-1400']['debit']);
        $this->assertSame(332510000.0, $lines['3-3100']['credit']);
        $this->assertSame(0, $this->lineCountFor('6-4400'));

        // Nothing clearable is recorded, and no liability account is touched.
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertFalse($grn->fresh()->hasRecordedClearing());
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));
        $this->assertSame(0, $this->lineCountFor('2-1100'));

        // And the engine refuses to pretend otherwise when asked to bill it.
        try {
            $this->apBills()->create(['goods_receipt_id' => $grn->id, 'bill_date' => '2026-07-05']);
            $this->fail('Expected a receipt with no counterparty to be unbillable.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tidak memiliki akrual penerimaan', $e->getMessage());
        }

        // Consume the lot: 5.000 * 66.502 = 332.510.000 out of stock.
        $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY]],
            (int) $this->project->id,
            '2026-07-10',
        ));

        // The invariant for the counterparty-less half: the asset is emptied,
        // the cost of the stock the company started with is recognised exactly
        // once when it is consumed, and its counter-entry stays in equity —
        // opening stock was never a purchase and never income.
        //   5-1100  +332.510.000 consumed
        //   3-3100  -332.510.000 raised at source, in EQUITY
        //   6-4400             0 the P&L is never touched by the receipt
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(332510000.0, $this->accountNet('5-1100'));
        $this->assertSame(-332510000.0, $this->accountNet('3-3100'));
        $this->assertSame(0, $this->lineCountFor('6-4400'));

        // No liability anywhere at any point in the cycle.
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));
        $this->assertSame(0, $this->lineCountFor('2-1100'));
    }

    // ---------------------------------------------------------------- the shipped installation

    public function test_the_data_migration_puts_a_journal_less_stock_ledger_back_on_the_general_ledger(): void
    {
        // Reproduce the shipped installation exactly: Inventory seeds before
        // Finance, so the chart of accounts was still empty when the opening
        // GRN and the first issue posted and StockService wrote no journal.
        // (Turning the perpetual switch off produces the identical state: a
        // posted stock document with no GL entry.)
        $this->setSetting('accounting.perpetual_inventory', false);

        $grn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::OPENING_QTY, self::OPENING_UNIT_COST]],
            '2026-07-01',
        ));

        // 1.000 zak issued to the project at the 66.502 average = 66.502.000.
        $issue = $this->stock()->postIssue($this->makeIssue(
            $this->pusat,
            [[$this->semen, 1000]],
            (int) $this->project->id,
            '2026-07-10',
        ));

        // The measured defect: Rp 332.510.000 of stock received, 4.000 zak
        // (266.008.000) still on hand, and GL 1-1400 = 0,00.
        $this->assertSame(0, Journal::query()->count());
        $this->assertSame(0.0, $this->accountNet('1-1400'));
        $this->assertSame(266008000.0, $this->balanceValue($this->pusat, $this->semen));

        // Back on the shipped default (perpetual), then run the repair.
        $this->setSetting('accounting.perpetual_inventory', null);
        $this->runClearingBackfillMigration();

        $receiptLines = $this->linesByAccount($this->singleJournalFor('goods_receipt', (int) $grn->id));
        $issueLines = $this->linesByAccount($this->singleJournalFor('inventory_issue', (int) $issue->id));

        // Dr 1-1400 332.510.000 / Cr 6-4400 332.510.000 (no counterparty), then
        // Dr 5-1100  66.502.000 / Cr 1-1400  66.502.000 for the consumption.
        $this->assertSame(332510000.0, $receiptLines['1-1400']['debit']);
        $this->assertSame(332510000.0, $receiptLines['6-4400']['credit']);
        $this->assertSame(66502000.0, $issueLines['5-1100']['debit']);
        $this->assertSame(66502000.0, $issueLines['1-1400']['credit']);

        // 332.510.000 - 66.502.000 = 266.008.000 = 4.000 zak * 66.502, which is
        // exactly what the stock sub-ledger says. GL and sub-ledger agree again.
        $this->assertSame(266008000.0, $this->accountNet('1-1400'));
        $this->assertSame($this->balanceValue($this->pusat, $this->semen), $this->accountNet('1-1400'));
        $this->assertSame(66502000.0, (float) ProjectCost::query()->sole()->amount);

        // The repair invents no liability and nothing clearable: the credit went
        // where it is closed at source.
        $this->assertNull($grn->fresh()->gl_clearing_account);
        $this->assertSame(0, $this->lineCountFor('2-1150'));
        $this->assertSame(0, $this->lineCountFor('2-1600'));

        // Idempotent: a redeploy must not book the same value twice.
        $this->runClearingBackfillMigration();

        $this->assertSame(2, Journal::query()->count());
        $this->assertSame(266008000.0, $this->accountNet('1-1400'));
        $this->assertSame(66502000.0, (float) ProjectCost::query()->sum('amount'));
    }

    public function test_the_migration_records_only_a_liability_credit_as_clearable(): void
    {
        // Two receipts posted by an EARLIER build: both have a journal, neither
        // carries the clearing record this build writes.
        $vendorGrn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, self::DELIVERY_QTY, self::DELIVERY_UNIT_COST]],
            '2026-03-05',
            ['vendor_id' => $this->supplier->id],
        ));

        $openingGrn = $this->stock()->postReceipt($this->makeGrn(
            $this->pusat,
            [[$this->semen, 50, self::DELIVERY_UNIT_COST]],
            '2026-03-06',
        ));

        GoodsReceipt::query()->update(['gl_clearing_account' => null, 'gl_clearing_amount' => null]);

        $this->runClearingBackfillMigration();

        // Copied FROM THE JOURNAL, never re-derived: the vendor receipt credited
        // 2-1600 with 100 * 62.000 = 6.200.000, so that is what a bill may clear.
        $this->assertSame('2-1600', $vendorGrn->fresh()->gl_clearing_account);
        $this->assertSame(6200000.0, $vendorGrn->fresh()->recordedClearingAmount());

        // The opening receipt credited 3-3100 (50 * 62.000 = 3.100.000), an
        // equity account and not a balance anyone owes, so it stays
        // unclearable — recording it would hand a bill a phantom to debit.
        $this->assertNull($openingGrn->fresh()->gl_clearing_account);
        $this->assertFalse($openingGrn->fresh()->hasRecordedClearing());

        // The recorded one bills and clears to zero…
        $bill = $this->approveBill($this->apBills()->create([
            'goods_receipt_id' => $vendorGrn->id,
            'ppn_amount' => self::DELIVERY_PPN,
            'bill_date' => '2026-03-10',
        ]));

        $lines = $this->linesByAccount($this->singleJournalFor('ap_bill', (int) $bill->id));

        // Dr 2-1600 6.200.000 + Dr 1-1600 682.000 = Cr 2-1100 6.882.000.
        $this->assertSame(6200000.0, $lines['2-1600']['debit']);
        $this->assertSame(682000.0, $lines['1-1600']['debit']);
        $this->assertSame(6882000.0, $lines['2-1100']['credit']);

        // 2-1600 carried 6.200.000 from the vendor receipt only; the opening
        // receipt's 3.100.000 went to equity and never near a liability or the
        // profit and loss.
        $this->assertSame(0.0, $this->accountNet('2-1600'));
        $this->assertSame(-3100000.0, $this->accountNet('3-3100'));
        $this->assertSame(0, $this->lineCountFor('6-4400'));

        // …and the unrecorded one still cannot be billed.
        $this->expectExceptionMessage('tidak memiliki akrual penerimaan');

        $this->apBills()->create(['goods_receipt_id' => $openingGrn->id, 'bill_date' => '2026-03-11']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run the Inventory data migration that records what already-posted receipts
     * credited and back-posts the journals a journal-less installation never
     * got. `require` (not require_once) so each call returns a fresh instance,
     * which is what makes the idempotency check above meaningful.
     */
    private function runClearingBackfillMigration(): void
    {
        $migration = require base_path(
            'Modules/Inventory/Database/Migrations/2026_07_25_000495_backfill_goods_receipt_gl_clearing.php'
        );

        $migration->up();
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

    private function lineCountFor(string $code): int
    {
        return JournalLine::query()->where('account_id', $this->accountId($code))->count();
    }
}
