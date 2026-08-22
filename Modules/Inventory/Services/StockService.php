<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Support\Erp;
use Modules\Finance\Enums\CostCategory;
use Modules\Finance\Models\Account;
use Modules\Finance\Services\JournalService;
use Modules\Finance\Services\ProjectCostService;
use Modules\Inventory\Enums\ItemType;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\GoodsReceiptItem;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\IssueItem;
use Modules\Inventory\Models\IssueReturn;
use Modules\Inventory\Models\IssueReturnItem;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\PurchaseReturn;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderItem;
use Modules\Procurement\Models\Vendor;
use Modules\Procurement\Services\PoService;

/**
 * Perpetual moving-average stock engine. Every mutation goes through here so
 * inv_stock_balances and inv_stock_ledger always agree.
 *
 * GL integration (perpetual persediaan), end to end for a purchased material.
 * Follow where the value sits at each step:
 *
 *   0. AP advance approved (optional)  Dr Uang Muka Proyek / Cr Hutang Usaha
 *      A down payment (uang muka) against the PO, booked by Finance before any
 *      goods exist. It is a prepaid ASSET, carries no goods and no project
 *      cost, and is netted off when the final bill lands. Nothing here touches
 *      stock — it is listed so the whole chain reads in one place.
 *   1. GRN posted      Dr Persediaan          / Cr clearing liability
 *      Goods are on hand but the vendor invoice has not arrived: the credit
 *      parks the liability out of Hutang Usaha until the invoice is booked.
 *      Value now sits on the balance sheet (asset + matching liability).
 *      WHICH account carried that credit, and how much, is WRITTEN ONTO THE
 *      RECEIPT (gl_clearing_account / gl_clearing_amount) — see
 *      postReceiptJournal(). That record, not the PO's shape and not today's
 *      value of accounting.perpetual_inventory, is what step 2 clears.
 *   2. AP bill approved Dr clearing liability / Cr Hutang Usaha  (Finance)
 *      ApBillService debits back EXACTLY what the receipts recorded here and
 *      routes any vendor/receipt price difference to the purchase variance
 *      account, so the clearing account nets to zero. Any approved advance is
 *      credited back out of Uang Muka Proyek and reduces Hutang Usaha by the
 *      same amount. No project cost is recognised at billing time. Value has
 *      moved from clearing to a real payable; the asset is untouched.
 *   3. Issue posted    Dr Beban proyek 5-xxxx / Cr Persediaan
 *      Cost hits the P&L (and fin_project_costs) only when material is
 *      actually consumed, so project profitability follows real usage. This
 *      is the ONLY step that turns the asset into cost.
 *
 * A receipt with no purchase order ABLE TO BILL IT has no step 2 document by
 * that route, so the credit is placed where a document can still reach it:
 *
 *   vendor known         Cr penerimaan accrual (2-1600). A manual AP bill
 *       referencing this receipt debits it back out — the same clearing
 *       machinery as a PO bill, driven by the same recorded amount. This is
 *       also where an over-delivery lands: goods arriving against a PO whose
 *       invoice has already been approved cannot be cleared by that invoice
 *       (Finance refuses a second final bill for one PO), so parking them in
 *       GR/IR would strand the credit for good.
 *   no counterparty      Cr saldo awal (3-3100, EQUITY). Opening stock or
 *       found stock: nobody is owed anything, and no trading event happened,
 *       so neither a liability nor a P&L line is honest. Such a receipt
 *       records NO clearing, so no bill can ever try to clear it.
 *
 * Stock opname differences bypass that chain and land in the stock variance
 * account (6-4400) — shrinkage and breakage ARE operating expenses, unlike an
 * opening balance. Everything is gated on the accounting.perpetual_inventory
 * parameter: switch it off and stock movements never touch the ledger
 * (periodic inventory, cost recognised on the vendor bill). The parameter is
 * read only to decide what a NEW posting does; it never re-decides what an
 * earlier posting already did.
 *
 * Ids are never trusted: the purchase order and the vendor are RESOLVED to
 * rows before either can steer a credit. An id pointing at nothing (or at a
 * module that is not installed) is treated as absent, because a document that
 * does not exist cannot clear anything.
 *
 * THE COSTING RULE, stated once because four paths have to obey the same one.
 *
 *   1. Stock is costed FORWARD, in the order movements are recorded. The
 *      average is read off inv_stock_balances as it stands and never re-derived
 *      by date, so a movement dated before the last one already recorded for
 *      that (warehouse, item) is REFUSED — see assertMovementInOrder(). This
 *      engine does not re-cost history, and the honest response to paperwork
 *      that arrives late is to say so rather than to mis-value it silently.
 *   2. The stored balance (qty, avg_cost) is the AUTHORITY, and every GL leg is
 *      the change in that stored balance — not a value re-derived from the
 *      document. applyIn/applyOut therefore return the signed rupiah they moved,
 *      and the journals are built from those figures, so GL 1-1400 and
 *      sum(qty * avg_cost) agree by construction instead of approximately.
 *      A REVERSAL OBEYS IT TOO: cancelIssue() mirrors the original journal (the
 *      only way to unwind a shape it did not choose) and then books the gap
 *      between that and what the balance actually gave back — see
 *      postCancellationRoundingJournal().
 *   3. A fiscal period governs WHEN a movement may be recorded, not whether the
 *      document in hand happens to raise a journal — assertStockPeriodOpen()
 *      runs up front on every path, so transfers (which never post one) and
 *      zero-value movements (which return before posting one) obey the close
 *      like everything else. Perpetual only; see that method for why periodic
 *      is deliberately exempt.
 */
class StockService
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /**
     * COA fallbacks, used when neither a DB override nor config/erp.php answers.
     * Non-project issues expense to general opex rather than a 5-xxxx HPP account.
     */
    private const DEFAULT_INVENTORY_ACCOUNT = '1-1400';

    private const DEFAULT_CLEARING_ACCOUNT = '2-1150';

    private const DEFAULT_ACCRUAL_ACCOUNT = '2-1600';

    private const DEFAULT_VARIANCE_ACCOUNT = '6-4400';

    private const DEFAULT_ISSUE_EXPENSE_ACCOUNT = '6-4100';

    /**
     * Saldo Awal — the equity account that carries the counter-entry of stock
     * that was already on hand when the system went live.
     */
    private const DEFAULT_OPENING_BALANCE_ACCOUNT = '3-3100';

    /**
     * EVERY reference type under which a posted bon's cost can be sitting in the
     * general ledger, because cancelIssue() has to reverse all of them.
     *
     * The second one is not hypothetical: on the live demo ISS/2026/VII/0001's
     * Rp 18.740.000 reached 5-1100 with project 1 through a SEPARATE journal,
     * JV/2026/07/0008 under 'inventory_issue_cost_reclass', written by migration
     * 000496 because the bon was posted while prj_projects was still empty.
     * Reversing only 'inventory_issue' left that reclass standing: project 1's
     * GL P&L stayed at Rp 228.240.000 while fin_project_costs fell back to
     * Rp 209.500.000, and 6-4100 was left carrying a project-less CREDIT of
     * Rp 18.740.000 — a negative operating expense nobody booked. The company
     * trial balance still balanced, which is what made it easy to miss.
     */
    private const ISSUE_JOURNAL_REFERENCE_TYPES = [
        'inventory_issue',
        'inventory_issue_cost_reclass',
    ];

    /** Reference type every journal a cancellation posts is filed under. */
    private const CANCELLATION_REFERENCE_TYPE = 'inventory_issue_cancellation';

    /**
     * EVERY reference type under which a posted receipt's value can be sitting
     * in the general ledger, because cancelReceipt() has to reverse all of them.
     *
     * The second is migration 001196's: an opening-stock receipt whose original
     * journal credited a P&L account carries a SECOND reclassifying journal
     * moving that credit to 3-3100 equity, keyed on the receipt's own id.
     * Reversing only 'goods_receipt' would leave that reclass standing — the
     * exact one-legged unwind ISSUE_JOURNAL_REFERENCE_TYPES exists to prevent
     * on the bon side.
     */
    private const RECEIPT_JOURNAL_REFERENCE_TYPES = [
        'goods_receipt',
        'opening_stock_reclass',
    ];

    /** Reference type every journal a receipt cancellation posts is filed under. */
    private const RECEIPT_CANCELLATION_REFERENCE_TYPE = 'goods_receipt_cancellation';

    /**
     * Post a draft GRN: per line apply the moving average
     * new_avg = (bal_qty * bal_avg + qty * unit_cost) / (bal_qty + qty),
     * write the ledger, refresh the item's global weighted average and
     * last purchase price, and notify Procurement when the GRN covers a PO.
     *
     * The purchase order named on the HEADER is resolved once, before any stock
     * moves: a receipt may only be booked against a PO that is still approved,
     * whatever the lines say. That check used to live inside the line loop and
     * was skipped entirely for a line with no po_item_id, which is how goods
     * kept arriving against closed and already-invoiced orders.
     *
     * The received value is booked to the GL as Dr Persediaan / Cr clearing
     * liability, and the receipt row keeps a record of that credit so the vendor
     * bill can clear precisely it. That value is the rupiah the stock sub-ledger
     * actually gained (rule 2 above), which is not always qty * unit_cost: a
     * receipt of 3 @ 1.000 followed by 4 @ 1.001 leaves an average of 1.000,57
     * and a sub-ledger of 7.003,99, so debiting the invoice's 7.004,00 left one
     * centavo of persediaan behind that no issue could ever relieve.
     */
    public function postReceipt(GoodsReceipt $grn): GoodsReceipt
    {
        if ($grn->status !== StockDocumentStatus::Draft) {
            throw new LogicException("GRN {$grn->code} is {$grn->status->value}; only draft GRNs can be posted.");
        }

        if ($grn->items()->doesntExist()) {
            throw new LogicException("GRN {$grn->code} has no lines to post.");
        }

        return DB::transaction(function () use ($grn): GoodsReceipt {
            $this->assertStockPeriodOpen($grn->receipt_date->toDateString());

            // Resolved once and reused: the guard below and the credit leg must
            // read the SAME purchase order row, and neither may trust the bare
            // id on the receipt. Deliberately taken BEFORE the loop, because
            // registerPoReceipt() may close the order on the way through — this
            // delivery was made against an approved PO and its credit belongs
            // in GR/IR, where that PO's bill will clear it. Re-reading the row
            // afterwards would misfile the credit of every complete delivery.
            $purchaseOrder = $this->resolvePurchaseOrder($grn);

            $this->assertPurchaseOrderCanReceive($grn, $purchaseOrder);
            $this->assertPoQuantitiesAreBounded($grn, $purchaseOrder);

            $postToLedger = $this->ledgerPostingEnabled();
            $receiptValue = 0.0;

            foreach ($grn->items()->with('item')->get() as $line) {
                $qty = round((float) $line->qty, 3);
                $unitCost = round((float) $line->unit_cost, 2);

                // The item can be soft-deleted while the GRN is still a draft
                // (ItemController::destroy only blocks items holding stock), and
                // the SoftDeletes scope then resolves the relation to null.
                // Refuse the posting as a business-rule violation (422) instead
                // of dereferencing null and returning a 500.
                $item = $line->item;

                if ($item === null) {
                    throw new LogicException(
                        "GRN {$grn->code} references item #{$line->item_id}, which has been deleted; "
                        .'restore the item or remove the line before posting.'
                    );
                }

                $this->assertMovementInOrder($grn->warehouse_id, (int) $line->item_id, $grn->receipt_date->toDateString(), $grn->code);

                $moved = $this->applyIn($line->item_id, $grn->warehouse_id, $qty, $unitCost, $grn->receipt_date->toDateString(), $grn);

                $item->forceFill(['last_price' => $unitCost])->save();
                $this->refreshGlobalAvgCost($item);

                $this->registerPoReceipt($grn, $line);

                $receiptValue = round($receiptValue + $moved, 2);
            }

            // The clearing record is reset here and written back only by
            // postReceiptJournal(), so a receipt posted under periodic inventory
            // — or one whose draft carried a stale value — provably records
            // nothing for a bill to clear.
            $grn->forceFill([
                'status' => StockDocumentStatus::Posted,
                'gl_clearing_account' => null,
                'gl_clearing_amount' => null,
            ])->save();

            if ($postToLedger) {
                $this->postReceiptJournal($grn, $receiptValue, $purchaseOrder);
            }

            return $grn->load('items.item', 'warehouse');
        });
    }

    /**
     * Membatalkan penerimaan barang yang terlanjur diposting — the expensive
     * third of audit T37 (docs/ASSESSMENT-LANJUTAN.md), and the WHOLE-DOCUMENT
     * case of machinery that already exists: postPurchaseReturn() reverses a
     * SLICE of one receipt, this unwinds the document entire.
     *
     * A posted GRN could not be edited, deleted or reversed; its value sat in
     * 1-1400 with a clearing credit a vendor bill could still settle, and
     * PoService::registerReceipt() only ever ADDED to qty_received. Correcting
     * a bogus receipt therefore meant an opname — 6-4400 Selisih Persediaan,
     * shrinkage, for a purchase that never happened — plus a manual JV, with
     * the order reading delivered for ever and its bill still approvable.
     *
     * The shape is cancelIssue()'s wherever the books allow: mandatory reason,
     * locked re-read + status re-check, ONE DATE for the mirror and every
     * journal (receiptCancellationDate() — the receipt's own date while it is
     * still the last word for every (warehouse, item) it touched, else today),
     * no exemption from the chronology guard, and the GL moving by what the
     * stored balance moved (costing rule 2): the mirror stock-out leaves at the
     * stored average, reverseReceiptJournals() mirrors what was ACTUALLY posted
     * — both reference types, whatever today's parameter says — and the
     * re-averaging gap between the two lands in 6-4400.
     *
     * What is REFUSED, because a whole-document unwind cannot say "partially":
     *
     *   stock partially gone   the mirror would drive the gudang negative —
     *                          retur pembelian gives back the part still on
     *                          the shelf, opname records shrinkage; the
     *                          refusal names both remedies;
     *   posted retur exists    that retur already reversed a slice of stock,
     *                          clearing and PO quantity; the whole-document
     *                          mirror on top would unwind the slice twice;
     *   clearing swept         the bill made the liability a real, approved
     *                          Hutang Usaha; the money side of unwinding it is
     *                          a vendor credit note through Keuangan, never a
     *                          stock document — the purchase-return rule,
     *                          asked about the whole document;
     *   PO billed classic      the order's one final bill expensed the goods
     *                          straight to 5-1100 with NOTHING swept, so
     *                          clearedByBills() reads zero — cancelling would
     *                          reopen an order whose delivery that bill
     *                          already paid for as cost.
     *
     * The PO takes its quantities back through PoService::unregisterReceipt()
     * (via registerPoReturn() — same guards, same silences), which also reopens
     * an order the delivery auto-closed, so the real goods can still arrive.
     */
    public function cancelReceipt(GoodsReceipt $grn, string $reason, ?int $userId = null): GoodsReceipt
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new LogicException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($grn, $reason, $userId): GoodsReceipt {
            /** @var GoodsReceipt $grn */
            $grn = GoodsReceipt::query()->whereKey($grn->id)->lockForUpdate()->firstOrFail();

            // lockForUpdate() is a no-op on SQLite; the RE-READ above plus this
            // re-check inside the transaction is the real protection against
            // two cancellations mirroring the same stock twice.
            if ($grn->status !== StockDocumentStatus::Posted) {
                throw new LogicException(
                    "Penerimaan {$grn->code} berstatus {$grn->status->value}; hanya penerimaan yang sudah diposting yang dapat dibatalkan."
                );
            }

            // A receipt partially unwound by a posted retur pembelian has LESS
            // than its full delivery still standing: mirroring the whole
            // document on top of a 20-zak retur walks out 100 zak of which only
            // 80 remain accounted to the vendor, and reverses clearing the
            // retur already reversed. Draft returns are not counted — they have
            // moved nothing, and once the receipt is cancelled
            // postPurchaseReturn() refuses them by status.
            $returned = PurchaseReturn::query()
                ->where('goods_receipt_id', $grn->id)
                ->where('status', StockDocumentStatus::Posted)
                ->orderBy('code')
                ->pluck('code');

            if ($returned->isNotEmpty()) {
                throw new LogicException(
                    "Penerimaan {$grn->code} sudah dikembalikan sebagian lewat retur pembelian ({$returned->implode(', ')}); "
                    .'membatalkan utuh di atasnya akan mengeluarkan stok dan membalik kliring melebihi yang tersisa. '
                    .'Kembalikan sisanya lewat retur pembelian juga.'
                );
            }

            // Once a bill has swept ANY of the receipt's clearing — pivot slice
            // or classic, the same double-entry the purchase-return ceiling
            // reads — the liability is a real Hutang Usaha somebody approved,
            // and a stock document must not rewrite it.
            $swept = $this->clearedByBills($grn);

            if ($swept > 0.0) {
                throw new LogicException(sprintf(
                    'Kliring penerimaan %s sudah disapu tagihan vendor sebesar %s; hutang yang telah disetujui '
                    .'tidak boleh ditulis ulang dokumen stok. Mintakan nota kredit vendor dan bukukan lewat '
                    .'Keuangan, dan keluarkan barangnya lewat opname bila memang harus keluar.',
                    $grn->code,
                    number_format($swept, 2, ',', '.'),
                ));
            }

            // The classic path leaves nothing for clearedByBills() to see: the
            // PO's one final bill was approved with nothing received on record
            // (a receipt posted under periodic among them) and expensed the
            // goods straight to 5-1100 with gl_cleared_amount 0. Cancelling the
            // receipt would reopen an order whose delivery that bill already
            // paid for as cost.
            $po = $this->resolvePurchaseOrder($grn);

            if ($po !== null && $this->purchaseOrderWasBilledWithoutMatching($po)) {
                throw new LogicException(
                    "Penerimaan {$grn->code} menyebut PO {$po->code} yang tagihannya sudah disetujui dengan "
                    .'pembebanan langsung (tanpa menyapu kliring penerimaan), sehingga nilai barangnya sudah '
                    .'menjadi beban lewat tagihan itu. Selesaikan lewat nota kredit vendor di Keuangan.'
                );
            }

            $reversalDate = $this->receiptCancellationDate($grn);

            $lines = $grn->items()->with('item')->get();

            // The friendly half of applyOut()'s balance check, asked up front
            // and CUMULATIVELY per item (two lines of one item drain the same
            // balance): a whole-document mirror either takes everything back or
            // nothing, and the operator is told which documents do "partially".
            /** @var array<int, float> $required item id => qty the mirror must take out */
            $required = [];

            foreach ($lines as $line) {
                $qty = round((float) $line->qty, 3);

                if ($qty > 0) {
                    $required[(int) $line->item_id] = round(($required[(int) $line->item_id] ?? 0.0) + $qty, 3);
                }
            }

            foreach ($required as $itemId => $qty) {
                $available = round((float) StockBalance::query()
                    ->where('warehouse_id', $grn->warehouse_id)
                    ->where('item_id', $itemId)
                    ->value('qty'), 3);

                // 0.0005 tolerance absorbs decimal(15,3) rounding artifacts.
                if ($available + 0.0005 >= $qty) {
                    continue;
                }

                throw new LogicException(sprintf(
                    'Stok %s di %s tinggal %s, kurang dari %s yang harus ditarik pembatalan utuh %s — '
                    .'sebagian sudah keluar lewat bon, transfer, atau retur sejak diterima. Gunakan retur '
                    .'pembelian untuk mengembalikan bagian yang masih di gudang, atau opname bila barangnya susut.',
                    Item::query()->withTrashed()->find($itemId)?->name ?? "item #{$itemId}",
                    Warehouse::query()->find($grn->warehouse_id)?->name ?? "gudang #{$grn->warehouse_id}",
                    number_format($available, 3, ',', '.'),
                    number_format($qty, 3, ',', '.'),
                    $grn->code,
                ));
            }

            // What the STORED BALANCE gives up (negative), summed from
            // applyOut()'s own report of it — never re-derived from the
            // document. Costing rule 2.
            $released = 0.0;

            foreach ($lines as $line) {
                $qty = round((float) $line->qty, 3);

                if ($qty <= 0) {
                    continue;
                }

                $item = $line->item;

                if ($item === null) {
                    throw new LogicException(
                        "Penerimaan {$grn->code} memuat item #{$line->item_id} yang sudah dihapus; "
                        .'pulihkan itemnya lebih dulu agar stoknya dapat dikeluarkan.'
                    );
                }

                $this->assertMovementInOrder(
                    (int) $grn->warehouse_id,
                    (int) $line->item_id,
                    $reversalDate,
                    "Pembatalan penerimaan {$grn->code}",
                );

                [, $moved] = $this->applyOut(
                    (int) $line->item_id,
                    (int) $grn->warehouse_id,
                    $qty,
                    $reversalDate,
                    $grn,
                );

                $released = round($released + $moved, 2);

                $this->refreshGlobalAvgCost($item);

                // The PO mirror of the whole document: qty_received handed back
                // in full, an auto-closed order reopened for the real delivery.
                $this->registerPoReturn($grn, $line, $qty);
            }

            $grn->forceFill([
                'status' => StockDocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $this->postingUserId($userId),
                'cancellation_reason' => $reason,
                // The ONLY figure a vendor bill may sweep, cleared in the same
                // transaction that moves the stock: a cancelled receipt cannot
                // be billed at all.
                'gl_clearing_amount' => null,
            ])->save();

            $this->reverseReceiptJournals($grn, $reason, $reversalDate, $released, $userId);

            return $grn->load('items.item', 'warehouse');
        });
    }

    /**
     * Post a draft material issue. Cost is NOT taken from user input: each line
     * is valued at the warehouse's current moving-average cost at posting time.
     *
     * That value is the moment cost leaves the balance sheet: the GL entry is
     * Dr project cost (or general opex without a project) / Cr Persediaan, and
     * a project issue additionally feeds fin_project_costs so realisasi can be
     * compared against the RAP.
     */
    public function postIssue(Issue $issue): Issue
    {
        if ($issue->status !== StockDocumentStatus::Draft) {
            throw new LogicException("Issue {$issue->code} is {$issue->status->value}; only draft issues can be posted.");
        }

        if ($issue->items()->doesntExist()) {
            throw new LogicException("Issue {$issue->code} has no lines to post.");
        }

        return DB::transaction(function () use ($issue): Issue {
            $this->assertStockPeriodOpen($issue->issue_date->toDateString());

            $postToLedger = $this->ledgerPostingEnabled();

            /** @var array<string, float> $byAccount debit account code => value issued */
            $byAccount = [];
            /** @var list<array{line: IssueItem, category: CostCategory}> $costLines valued lines for the project cost ledger */
            $costLines = [];

            foreach ($issue->items()->with('item')->get() as $line) {
                $qty = round((float) $line->qty, 3);

                $this->assertMovementInOrder($issue->warehouse_id, (int) $line->item_id, $issue->issue_date->toDateString(), $issue->code);

                [$unitCost, $moved] = $this->applyOut($line->item_id, $issue->warehouse_id, $qty, $issue->issue_date->toDateString(), $issue);
                $amount = round(abs($moved), 2);

                $line->forceFill([
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                ])->save();

                // Consumption changes the mix of stock still on hand, so the
                // item-level weighted average has to follow it — exactly like
                // postReceipt(), receiveTransfer() and postAdjustment() do.
                // Null-tolerant on purpose: this path already survives a
                // soft-deleted item (see issueCostCategory below).
                if ($line->item !== null) {
                    $this->refreshGlobalAvgCost($line->item);
                }

                if (! $postToLedger) {
                    continue;
                }

                // The item type picks both the cost bucket and, on a project
                // issue, the 5-xxxx HPP account behind it. Lines are summed per
                // account so one issue yields one balanced journal.
                $category = $this->issueCostCategory($line->item);

                $accountCode = $issue->project_id !== null
                    ? $category->cogsAccountCode()
                    : self::DEFAULT_ISSUE_EXPENSE_ACCOUNT;

                $byAccount[$accountCode] = round(($byAccount[$accountCode] ?? 0.0) + $amount, 2);
                $costLines[] = ['line' => $line, 'category' => $category];
            }

            $issue->forceFill(['status' => StockDocumentStatus::Posted])->save();

            if ($postToLedger) {
                $this->postIssueJournal($issue, $byAccount);
                $this->recordIssueProjectCost($issue, $costLines);
            }

            return $issue->load('items.item', 'warehouse');
        });
    }

    /**
     * Membatalkan bon pemakaian yang terlanjur diposting.
     *
     * A posted bon used to be permanent, and it is the one stock document that
     * lands on PROJECT COST: ISS/2026/VII/0001 issues Rp 18.740.000 of semen and
     * besi, and if it was posted against PRJ-2026-001 when the material went to
     * PRJ-2026-002 there was no way back at all. An opname restores the quantity
     * but books the value to 6-4400 Selisih Persediaan — shrinkage, not a
     * project transfer — and a manual JV moves the GL while leaving the stock
     * ledger and fin_project_costs saying the opposite. Both projects' realisasi,
     * CPI and PSAK 115 cost base stayed wrong for good.
     *
     * Modelled on ApBillService::cancel(), because the shape is the same and the
     * two must not drift: a reason is mandatory, the ORIGINAL posting is never
     * touched (posted journals are immutable), and everything the document
     * derived is released — the mirror stock movement, the reversing journals,
     * and the project cost rows, which would otherwise keep the project P&L above
     * the general ledger by exactly the amount just reversed.
     *
     * ONE DATE FOR THE MIRROR AND THE JOURNALS, AND NO EXEMPTION FROM THE
     * CHRONOLOGY GUARD. issueCancellationDate() picks it: the bon's own date
     * while that is still the last word for every (warehouse, item) the bon
     * touched, otherwise today. The mirror is a stock movement like any other, so
     * assertMovementInOrder() runs on it. Dating it back behind movements already
     * recorded is exactly the failure that guard exists to stop: 100 in (10 Mar),
     * 30 out (15 Mar), 50 in (18 Mar), 40 out (20 Mar), 10 out (25 Mar) gives a
     * kartu stok of 100/70/120/80/70 agreeing with the stored balance; putting
     * the 20 March bon back onto 20 March makes it read 100/70/120/80/110/70 in
     * date order, every row from that date on wrong by the cancelled 40 zak and
     * the card ending 40 below the shelf.
     *
     * What the mirror cannot restore is the average as it stood for movements in
     * between, which is the same limitation costing rule 1 states everywhere:
     * this engine costs forward and does not re-cost history.
     *
     * EVERY JOURNAL THE BON POSTED IS REVERSED, not only its own — see
     * ISSUE_JOURNAL_REFERENCE_TYPES.
     *
     * AND THE GL MOVES BY WHAT THE BALANCE MOVED, costing rule 2: applyIn()
     * returns the rupiah the stored balance actually gained, the mirrors give
     * 1-1400 back what the originals took, and postCancellationRoundingJournal()
     * books the difference between the two rather than leaving it in 1-1400.
     */
    public function cancelIssue(Issue $issue, string $reason, ?int $userId = null): Issue
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new LogicException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($issue, $reason, $userId): Issue {
            /** @var Issue $issue */
            $issue = Issue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();

            // lockForUpdate() is a no-op on SQLite; the RE-READ above plus this
            // re-check inside the transaction is the real protection against two
            // cancellations mirroring the same stock twice.
            if ($issue->status !== StockDocumentStatus::Posted) {
                throw new LogicException(
                    "Bon {$issue->code} berstatus {$issue->status->value}; hanya bon yang sudah diposting yang dapat dibatalkan."
                );
            }

            // A bon raised by a field-report acknowledgement is not the operator's
            // document to unwind: svc_field_reports would still read "disahkan
            // pelanggan" over a visit whose parts had come back onto the shelf,
            // and inv_issues.field_report_id is UNIQUE so the report could never
            // issue them again. The report is the thing to correct.
            if ($issue->field_report_id !== null) {
                throw new LogicException(
                    "Bon {$issue->code} dibuat otomatis dari pengesahan laporan lapangan dan tidak dapat "
                    .'dibatalkan sendiri — koreksi laporan lapangannya, karena pengesahan dan pengeluaran '
                    .'suku cadang adalah satu peristiwa yang sama.'
                );
            }

            // A bon partially unwound by a posted retur material has LESS than
            // its full quantity still out: mirroring the whole document on top
            // of RTM 30 zak restores 70 for a bon that only ever took 40, and
            // the reversing journal credits 5-1100 by cost the project no
            // longer carries. Draft returns are not counted — they have moved
            // nothing, and once the bon is cancelled they can only be deleted
            // (postIssueReturn refuses a non-posted bon).
            $posted = IssueReturn::query()
                ->where('issue_id', $issue->id)
                ->where('status', StockDocumentStatus::Posted)
                ->orderBy('code')
                ->pluck('code');

            if ($posted->isNotEmpty()) {
                throw new LogicException(
                    "Bon {$issue->code} sudah dikembalikan sebagian lewat retur material ({$posted->implode(', ')}); "
                    .'membatalkan utuh di atasnya akan mengembalikan stok melebihi yang pernah keluar. '
                    .'Kembalikan sisanya lewat retur material juga.'
                );
            }

            $reversalDate = $this->issueCancellationDate($issue);

            // What the STORED BALANCE gives back, summed from applyIn()'s own
            // report of it — never re-derived from the document. Costing rule 2.
            $restored = 0.0;

            foreach ($issue->items()->with('item')->get() as $line) {
                $qty = round((float) $line->qty, 3);
                $unitCost = round((float) $line->unit_cost, 2);

                if ($qty <= 0) {
                    continue;
                }

                $item = $line->item;

                if ($item === null) {
                    throw new LogicException(
                        "Bon {$issue->code} memuat item #{$line->item_id} yang sudah dihapus; "
                        .'pulihkan itemnya lebih dulu agar stoknya dapat dikembalikan.'
                    );
                }

                $this->assertMovementInOrder(
                    $issue->warehouse_id,
                    (int) $line->item_id,
                    $reversalDate,
                    "Pembatalan bon {$issue->code}",
                );

                $restored = round($restored + $this->applyIn(
                    $line->item_id,
                    $issue->warehouse_id,
                    $qty,
                    $unitCost,
                    $reversalDate,
                    $issue,
                ), 2);

                $this->refreshGlobalAvgCost($item);
            }

            $issue->forceFill([
                'status' => StockDocumentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $this->postingUserId($userId),
                'cancellation_reason' => $reason,
            ])->save();

            $this->reverseIssueJournals($issue, $reason, $reversalDate, $restored, $userId);

            $this->removeIssueProjectCost($issue);

            return $issue->load('items.item', 'warehouse');
        });
    }

    /**
     * Memposting retur material dari proyek — the PARTIAL mirror of one posted
     * bon (temuan 37), where cancelIssue() is the whole-document one.
     *
     * The everyday case is smaller than a cancellation: 150 zak issued, the
     * pekerjaan finishes, 30 come back. The two documents that could receive
     * them were both lies — a vendor-less GRN credits EQUITY 3-3100 (stock
     * nobody bought), an opname credits EXPENSE 6-4400 (stock that was lost) —
     * and neither touched the fin_project_costs rows the bon wrote, so the
     * project P&L kept carrying material that was back on the shelf and the
     * next opname booked the "surplus" as negative operating expense.
     *
     * THE PRICE IS THE ISSUE LINE'S, NEVER TODAY'S AVERAGE. Each return line
     * references the issue line it reverses and comes back at that line's
     * frozen unit_cost — the same rule cancelIssue() applies to its mirror,
     * because the slice of cost leaving the project must be the slice once
     * booked, not a value the warehouse mix has drifted to since.
     *
     * The mirror mechanics are cancelIssue()'s, scaled to a slice:
     *
     *   - never more than was issued, CUMULATIVELY: the ceiling is asked per
     *     issue line against every return already POSTED for it, inside the
     *     transaction, so two partial returns cannot both fit under one line —
     *     AND against the sibling lines of this same document, which no posted
     *     count can see, so two lines naming one bon line cannot both fit
     *     under it either;
     *   - a field-report bon is refused for cancelIssue()'s exact reason — the
     *     pengesahan and the parts leaving are one event, and the report is
     *     the document to correct;
     *   - the stock-in is a movement like any other: assertMovementInOrder()
     *     and the period gate run on the return's own date (costing rules 1
     *     and 3) — the date is the operator's, this only refuses a dishonest
     *     one;
     *   - costing rule 2: the 1-1400 debit is what the stored balance actually
     *     gained (applyIn's own report), the credit legs are the cost slice at
     *     issue price, and the re-averaging gap between the two lands in
     *     6-4400 exactly as postCancellationRoundingJournal() books it.
     *
     * The credit account is derived the way postIssue() chose its debit —
     * category HPP account for a project bon, general opex without one — read
     * off the issue's CURRENT project_id. That also serves the one reclassified
     * legacy bon (migration 000496): its cost sits on 5-1100 under the project
     * that was backfilled onto the row, which is precisely where this derivation
     * points.
     *
     * fin_project_costs gets NEGATIVE rows keyed ('inventory_issue_return_item',
     * line id) rather than edits of the bon's rows: forward-only, per-line so
     * the WBS attribution survives, and the idempotency key cannot collide with
     * the issue's own rows.
     */
    public function postIssueReturn(IssueReturn $return): IssueReturn
    {
        return DB::transaction(function () use ($return): IssueReturn {
            /** @var IssueReturn $return */
            $return = IssueReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            // lockForUpdate() is a no-op on SQLite; the RE-READ above plus this
            // re-check inside the transaction is the real protection against
            // two posts walking the same stock back in twice.
            if ($return->status !== StockDocumentStatus::Draft) {
                throw new LogicException(
                    "Retur {$return->code} berstatus {$return->status->value}; hanya retur draf yang dapat diposting."
                );
            }

            if ($return->items()->doesntExist()) {
                throw new LogicException("Retur {$return->code} tidak memiliki baris untuk diposting.");
            }

            /** @var Issue $issue */
            $issue = Issue::query()->whereKey($return->issue_id)->lockForUpdate()->firstOrFail();

            // Only a POSTED bon holds cost a slice can come off: a draft moved
            // no stock, and a cancelled bon was already returned in full by its
            // own mirror — a retur on top would restore the same zak twice.
            if ($issue->status !== StockDocumentStatus::Posted) {
                throw new LogicException(
                    "Bon {$issue->code} berstatus {$issue->status->value}; retur material hanya dapat "
                    .'diposting atas bon yang sudah diposting.'
                );
            }

            if ($issue->field_report_id !== null) {
                throw new LogicException(
                    "Bon {$issue->code} dibuat otomatis dari pengesahan laporan lapangan; suku cadangnya "
                    .'tidak dapat diretur sendiri — koreksi laporan lapangannya, karena pengesahan dan '
                    .'pengeluaran suku cadang adalah satu peristiwa yang sama.'
                );
            }

            $this->assertStockPeriodOpen($return->return_date->toDateString());

            $postToLedger = $this->ledgerPostingEnabled();

            // What the STORED BALANCE gains, from applyIn()'s own report of it —
            // never re-derived from the document. Costing rule 2.
            $restored = 0.0;
            /** @var array<string, float> $byAccount credit account code => cost slice coming back */
            $byAccount = [];
            /** @var list<array{line: IssueReturnItem, issueLine: IssueItem, category: CostCategory}> $costLines */
            $costLines = [];
            /** @var array<int, float> $withinThisReturn issue line id => qty earlier lines of THIS document already claimed */
            $withinThisReturn = [];

            foreach ($return->items()->with('issueItem.item')->get() as $line) {
                $issueLine = $line->issueItem;

                if ($issueLine === null || (int) $issueLine->issue_id !== (int) $issue->id) {
                    throw new LogicException(
                        "Baris pada retur {$return->code} menunjuk baris bon yang bukan milik {$issue->code}; "
                        .'perbaiki dokumennya sebelum diposting.'
                    );
                }

                $qty = round((float) $line->qty, 3);

                if ($qty <= 0) {
                    continue;
                }

                // CUMULATIVE ceiling, read from posted documents inside this
                // transaction: 150 issued, 100 already back, a retur of 60 is
                // 10 zak the project never held. Per-document arithmetic would
                // let two returns of 100 each walk past a 150-zak bon.
                //
                // PLUS the sibling lines of THIS document: qtyReturned() only
                // counts POSTED returns, and this one is still draft while its
                // own loop runs, so two 30-zak lines naming one 40-zak bon
                // line each passed alone and posted 60 — phantom stock on the
                // shelf, 5-1100 and fin_project_costs driven negative.
                // syncItems() refuses the duplicate at drafting; this term is
                // the guard that holds for a document crafted before it did.
                $issued = round((float) $issueLine->qty, 3);
                $alreadyReturned = round(
                    $issueLine->qtyReturned() + ($withinThisReturn[(int) $issueLine->id] ?? 0.0),
                    3,
                );

                // 0.0005 tolerance, the same one applyOut() uses, absorbs
                // decimal(15,3) rounding artifacts.
                if (round($alreadyReturned + $qty, 3) > $issued + 0.0005) {
                    throw new LogicException(sprintf(
                        'Retur %s mengembalikan %s sebanyak %s padahal bon %s hanya mengeluarkan %s dan %s '
                        .'sudah kembali lewat retur sebelumnya atau baris lain retur ini. Kurangi kuantitasnya.',
                        $return->code,
                        $issueLine->item?->name ?? "item #{$issueLine->item_id}",
                        number_format($qty, 3, ',', '.'),
                        $issue->code,
                        number_format($issued, 3, ',', '.'),
                        number_format($alreadyReturned, 3, ',', '.'),
                    ));
                }

                $withinThisReturn[(int) $issueLine->id] = round(
                    ($withinThisReturn[(int) $issueLine->id] ?? 0.0) + $qty,
                    3,
                );

                $item = $issueLine->item;

                if ($item === null) {
                    throw new LogicException(
                        "Retur {$return->code} memuat item #{$issueLine->item_id} yang sudah dihapus; "
                        .'pulihkan itemnya lebih dulu agar stoknya dapat dikembalikan.'
                    );
                }

                // The price the goods LEFT at, frozen on the issue line — the
                // resep of temuan 37, and cancelIssue()'s mirror rule.
                $unitCost = round((float) $issueLine->unit_cost, 2);

                $this->assertMovementInOrder(
                    (int) $issue->warehouse_id,
                    (int) $issueLine->item_id,
                    $return->return_date->toDateString(),
                    $return->code,
                );

                $restored = round($restored + $this->applyIn(
                    (int) $issueLine->item_id,
                    (int) $issue->warehouse_id,
                    $qty,
                    $unitCost,
                    $return->return_date->toDateString(),
                    $return,
                ), 2);

                $amount = round($qty * $unitCost, 2);

                $line->forceFill([
                    'item_id' => $issueLine->item_id,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                ])->save();

                $this->refreshGlobalAvgCost($item);

                if (! $postToLedger || $amount === 0.0) {
                    continue;
                }

                $category = $this->issueCostCategory($item);

                $accountCode = $issue->project_id !== null
                    ? $category->cogsAccountCode()
                    : self::DEFAULT_ISSUE_EXPENSE_ACCOUNT;

                $byAccount[$accountCode] = round(($byAccount[$accountCode] ?? 0.0) + $amount, 2);
                $costLines[] = ['line' => $line, 'issueLine' => $issueLine, 'category' => $category];
            }

            $return->forceFill(['status' => StockDocumentStatus::Posted])->save();

            if ($postToLedger) {
                $this->postIssueReturnJournal($return, $issue, $byAccount, $restored);
                $this->recordIssueReturnProjectCost($return, $issue, $costLines);
            }

            return $return->load('items.item', 'issue', 'warehouse');
        });
    }

    /**
     * Memposting retur pembelian — goods going back to the vendor against the
     * receipt that brought them in (temuan 38).
     *
     * The emergency path this replaces booked the exit as an opname: stock out
     * at 6-4400 Selisih Persediaan — an operating EXPENSE for goods the company
     * is not even keeping — while the vendor's bill stayed billable in FULL,
     * because nothing reduced the receipt's recorded clearing and nothing ever
     * subtracted from prc_purchase_order_items.qty_received.
     *
     * Three books move together here, in one transaction:
     *
     *   STOCK   out at the warehouse's current average (applyOut, costing rule
     *           2 — the 1-1400 credit is what the balance actually lost), on the
     *           return's own date under the chronology guard and the period gate
     *           (rules 1 and 3). applyOut's balance check IS the "unissued at
     *           that warehouse" refusal: goods already sent to site are not in
     *           the gudang to give back.
     *   GL      Dr the EXACT account the receipt recorded (gl_clearing_account
     *           — GR/IR for a billable PO, the 2-1600 accrual for a vendor
     *           delivery), by the slice of receipt value going back at the
     *           RECEIPT LINE's unit_cost; the gap between that slice and what
     *           the balance gave up is a stock valuation difference and lands
     *           in 6-4400 with postAdjustmentJournal()'s debit/credit shape.
     *           WHETHER this leg runs follows the receipt's own record, not
     *           today's accounting.perpetual_inventory — the same rule that
     *           header states for postings generally, and cancelIssue()'s rule
     *           for reversals: unwind what was ACTUALLY recorded. Deciding on
     *           ledgerPostingEnabled() at return time left a receipt posted
     *           under perpetual, returned after a flip to periodic, holding
     *           its FULL gl_clearing_amount — and that column is the ONLY
     *           figure ApBillService may sweep, so the final bill settled the
     *           returned slice and the vendor was paid for goods he had taken
     *           back. Now the slice is decremented and the reversal posted
     *           whenever the receipt carries a recorded clearing, whatever the
     *           parameter says today.
     *   PO      qty_received hands the quantity back through
     *           PoService::unregisterReceipt(), which also reopens an
     *           auto-closed order so the replacement delivery can be received —
     *           the exact one-way arithmetic the audit named under temuan 38.
     *
     * CEILINGS. Cumulative per receipt line (received minus already returned,
     * from POSTED returns read inside the transaction, plus the sibling lines
     * of this same document, which no posted count can see), and — whenever
     * the receipt recorded a clearing, or perpetual is on today — the slice
     * may not exceed what remains UNBILLED of the receipt's recorded clearing:
     * once a bill has swept the credit, the money side is a vendor credit note
     * through Keuangan, not a stock document rewriting a settled liability.
     */
    public function postPurchaseReturn(PurchaseReturn $return): PurchaseReturn
    {
        return DB::transaction(function () use ($return): PurchaseReturn {
            /** @var PurchaseReturn $return */
            $return = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            // Re-read + re-check inside the transaction, as everywhere: two
            // posts of one retur must not send the goods back twice.
            if ($return->status !== StockDocumentStatus::Draft) {
                throw new LogicException(
                    "Retur {$return->code} berstatus {$return->status->value}; hanya retur draf yang dapat diposting."
                );
            }

            if ($return->items()->doesntExist()) {
                throw new LogicException("Retur {$return->code} tidak memiliki baris untuk diposting.");
            }

            /** @var GoodsReceipt $grn */
            $grn = GoodsReceipt::query()->whereKey($return->goods_receipt_id)->lockForUpdate()->firstOrFail();

            // Only a POSTED receipt put anything on the shelf or in the ledger;
            // a draft GRN with wrong goods is edited or deleted, not returned.
            if ($grn->status !== StockDocumentStatus::Posted) {
                throw new LogicException(
                    "Penerimaan {$grn->code} berstatus {$grn->status->value}; retur pembelian hanya dapat "
                    .'diposting atas penerimaan yang sudah diposting.'
                );
            }

            $this->assertStockPeriodOpen($return->return_date->toDateString());

            $postToLedger = $this->ledgerPostingEnabled();

            // What the sub-ledger actually gave up (positive rupiah) versus the
            // slice of the receipt's recorded value being reversed — the two
            // sides postPurchaseReturnJournal() reconciles through 6-4400.
            $released = 0.0;
            $slice = 0.0;
            /** @var array<int, float> $withinThisReturn receipt line id => qty earlier lines of THIS document already claimed */
            $withinThisReturn = [];

            foreach ($return->items()->with('receiptItem.item')->get() as $line) {
                $grnLine = $line->receiptItem;

                if ($grnLine === null || (int) $grnLine->goods_receipt_id !== (int) $grn->id) {
                    throw new LogicException(
                        "Baris pada retur {$return->code} menunjuk baris penerimaan yang bukan milik {$grn->code}; "
                        .'perbaiki dokumennya sebelum diposting.'
                    );
                }

                $qty = round((float) $line->qty, 3);

                if ($qty <= 0) {
                    continue;
                }

                // Sibling lines of THIS document count against the ceiling
                // exactly as in postIssueReturn(): qtyReturned() sees POSTED
                // documents only, so a pre-guard draft carrying one receipt
                // line twice (60 + 60 against a 100-zak line) walked 120 zak
                // onto the vendor's truck with nothing left to refuse it —
                // no PO floor on a vendor-only receipt, no clearing ceiling
                // under periodic. syncItems() refuses the duplicate at
                // drafting; this term is the guard that holds regardless.
                $received = round((float) $grnLine->qty, 3);
                $alreadyReturned = round(
                    $grnLine->qtyReturned() + ($withinThisReturn[(int) $grnLine->id] ?? 0.0),
                    3,
                );

                if (round($alreadyReturned + $qty, 3) > $received + 0.0005) {
                    throw new LogicException(sprintf(
                        'Retur %s mengembalikan %s sebanyak %s padahal penerimaan %s hanya mencatat %s dan %s '
                        .'sudah kembali ke vendor lewat retur sebelumnya atau baris lain retur ini. Kurangi kuantitasnya.',
                        $return->code,
                        $grnLine->item?->name ?? "item #{$grnLine->item_id}",
                        number_format($qty, 3, ',', '.'),
                        $grn->code,
                        number_format($received, 3, ',', '.'),
                        number_format($alreadyReturned, 3, ',', '.'),
                    ));
                }

                $withinThisReturn[(int) $grnLine->id] = round(
                    ($withinThisReturn[(int) $grnLine->id] ?? 0.0) + $qty,
                    3,
                );

                $item = $grnLine->item;

                if ($item === null) {
                    throw new LogicException(
                        "Retur {$return->code} memuat item #{$grnLine->item_id} yang sudah dihapus; "
                        .'pulihkan itemnya lebih dulu agar stoknya dapat dikeluarkan.'
                    );
                }

                $this->assertMovementInOrder(
                    (int) $grn->warehouse_id,
                    (int) $grnLine->item_id,
                    $return->return_date->toDateString(),
                    $return->code,
                );

                // Refuses when the gudang no longer holds the quantity — the
                // goods have been issued or transferred, and stock that is on a
                // site cannot go back on the vendor's truck.
                [, $moved] = $this->applyOut(
                    (int) $grnLine->item_id,
                    (int) $grn->warehouse_id,
                    $qty,
                    $return->return_date->toDateString(),
                    $return,
                );

                $released = round($released + abs($moved), 2);

                // The price the goods ARRIVED at, frozen on the receipt line —
                // the slice of the receipt's booked value this return reverses.
                $unitCost = round((float) $grnLine->unit_cost, 2);
                $amount = round($qty * $unitCost, 2);
                $slice = round($slice + $amount, 2);

                $line->forceFill([
                    'item_id' => $grnLine->item_id,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                ])->save();

                $this->refreshGlobalAvgCost($item);

                $this->registerPoReturn($grn, $grnLine, $qty);
            }

            // Decided on what the RECEIPT recorded, never on today's parameter
            // alone — cancelIssue()'s rule, from the other direction. A receipt
            // posted under perpetual carries its clearing credit even after the
            // installation flips to periodic; gating this block on
            // ledgerPostingEnabled() left that credit at its FULL amount, so
            // the final bill swept the returned slice and paid the vendor for
            // goods back on his own truck. The $postToLedger arm keeps the
            // perpetual-day refusal alive: a receipt with NOTHING recorded
            // (posted under periodic, or its credit already spent) has no
            // liability a stock document may reverse, and the assert below
            // says so instead of posting a journal against thin air.
            if ($slice > 0.0 && ($grn->hasRecordedClearing() || $postToLedger)) {
                $this->assertReturnStaysWithinUnbilledClearing($return, $grn, $slice);

                // The ONLY figure a vendor bill may sweep, reduced inside the
                // same transaction that moves the stock: from here on the
                // returned slice cannot be billed.
                $grn->forceFill([
                    'gl_clearing_amount' => round($grn->recordedClearingAmount() - $slice, 2),
                ])->save();

                $this->postPurchaseReturnJournal($return, $grn, $slice, $released);
            }

            $return->forceFill(['status' => StockDocumentStatus::Posted])->save();

            return $return->load('items.item', 'goodsReceipt', 'warehouse');
        });
    }

    /**
     * Send a draft transfer: goods leave the source warehouse at its current
     * average cost, which is frozen on each line so the destination receives
     * at exactly the same cost (transfers never create or destroy value).
     *
     * NO JOURNAL, DELIBERATELY, AND WHAT THAT COSTS. A transfer moves value
     * between two warehouses of the same company: 1-1400 is debited and credited
     * the same rupiah, so the entry would be a no-op and there is no
     * goods-in-transit account to park it in. The price is that between send and
     * receive the goods sit in NEITHER warehouse balance while the general
     * ledger still carries them — measured on the demo: sub-ledger 320.110.000
     * against GL 1-1400 332.510.000 while 200 zak Semen Portland are on the road
     * (Rp 12.400.000). That difference is real, bounded by the transit window,
     * and closed by receiveTransfer(); what was wrong was that nothing NAMED it.
     * erp:inventory-method-check now prints it as the reconciling figure between
     * the two totals rather than leaving an operator to guess (see
     * InventoryMethodCheck::checkStockOnHand). Introducing a 1-14xx Persediaan
     * Dalam Perjalanan account would also work, but it is a chart-of-accounts
     * change on a live ledger to fix a reporting gap, and the reconciliation
     * answers the question the accountant actually asks.
     *
     * The period seal is NOT a consequence of the journal, so posting none does
     * not exempt a transfer from it: assertStockPeriodOpen() runs here too. It
     * used to be the one stock movement with no fiscal-period gate at all —
     * a back-dated TRF walked 200 zak out of a warehouse inside a January that
     * was closed and reported, writing an inv_stock_ledger row into it.
     */
    public function sendTransfer(Transfer $transfer): Transfer
    {
        if ($transfer->status !== TransferStatus::Draft) {
            throw new LogicException("Transfer {$transfer->code} is {$transfer->status->value}; only draft transfers can be sent.");
        }

        if ($transfer->items()->doesntExist()) {
            throw new LogicException("Transfer {$transfer->code} has no lines to send.");
        }

        return DB::transaction(function () use ($transfer): Transfer {
            $this->assertStockPeriodOpen($transfer->transfer_date->toDateString());

            foreach ($transfer->items()->with('item')->get() as $line) {
                $qty = round((float) $line->qty, 3);

                $this->assertMovementInOrder($transfer->from_warehouse_id, (int) $line->item_id, $transfer->transfer_date->toDateString(), $transfer->code);

                [$unitCost] = $this->applyOut($line->item_id, $transfer->from_warehouse_id, $qty, $transfer->transfer_date->toDateString(), $transfer);

                $line->forceFill(['unit_cost' => $unitCost])->save();

                // Sending is a stock-OUT like any other, so it changes the mix
                // of stock still on hand and the item-level weighted average has
                // to follow it — exactly like postIssue(), receiveTransfer() and
                // postAdjustment() do. Skipping it left inv_items.avg_cost stale
                // for the whole transit window: WH-PUSAT 100 @ 15.000 plus
                // WH-SITE 20 @ 21.000 reads 16.000, but once 40 zak leave PUSAT
                // the average over what is actually on hand is 16.500 — and
                // postAdjustment() values found stock at that stale field when
                // the counting warehouse has no history of its own.
                //
                // Null-tolerant on purpose: an item soft-deleted while the
                // transfer was a draft must not turn a send into a 500.
                $this->refreshGlobalAvgCost($line->item);
            }

            $transfer->forceFill(['status' => TransferStatus::InTransit])->save();

            return $transfer->load('items.item', 'fromWarehouse', 'toWarehouse');
        });
    }

    /**
     * Receive an in-transit transfer at the destination, line by line at the
     * cost frozen on send (moving average recomputed at the destination).
     *
     * THE ARRIVAL IS AN EVENT OF THE DAY IT HAPPENS, and that is not decoration:
     * the period gate used to be asked about the transfer's SEND date, which
     * turned a month-end close over a moving truck into a permanent trap. 200 zak
     * Semen Portland (Rp 12.400.000) leave WH-PUSAT on 28 July; July closes on
     * 4 August; the site storeman clicks Terima and gets "Periode fiskal 2026-07
     * sudah ditutup" — for ever, because TransferService::update and ::delete both
     * refuse an in-transit transfer, there is no cancelTransfer(), and reopening
     * July stops being possible the moment a posted PSAK 115 run measures it. The
     * value sat in 1-1400 with nothing in either warehouse balance behind it. So
     * the receipt lands on the send date while that period is still open and
     * unmeasured — the ordinary case, and what keeps a seeded July transfer
     * arriving in July — and on TODAY once it is not. stockEventDate() is the
     * same rule JournalService::reversalDate() applies to a journal.
     *
     * assertMovementInOrder() is deliberately NOT applied here. The date is not
     * a fresh decision — it is either the one already accepted when the goods
     * left, or today, which is at or after every movement already recorded — and
     * the cost is frozen, so there is nothing left to mis-value. Refusing at this
     * end would strand goods on the road again, which is the failure above.
     */
    public function receiveTransfer(Transfer $transfer): Transfer
    {
        if ($transfer->status !== TransferStatus::InTransit) {
            throw new LogicException("Transfer {$transfer->code} is {$transfer->status->value}; only in-transit transfers can be received.");
        }

        return DB::transaction(function () use ($transfer): Transfer {
            $receiptDate = $this->stockEventDate($transfer->transfer_date->toDateString());

            /*
             * The DESTINATION has its own chronology. The despatch only
             * established order at the SOURCE; while the truck was moving the
             * receiving warehouse may have received, issued or been opname'd,
             * and an arrival back-dated behind those rows leaves
             * balance_qty_after telling a story that never happened.
             *
             * Refusing here would strand the goods exactly as the period gate
             * did before it learned to fall forward, so this does the same
             * thing: the arrival is an event of the day it can honestly happen.
             */
            $receiptDate = $this->arrivalDateClearingDestination($transfer, $receiptDate);

            // Kept where every other path has it (costing rule 3): the date is
            // CHOSEN above, the gate is what refuses one nobody may post.
            $this->assertStockPeriodOpen($receiptDate);

            foreach ($transfer->items()->with('item')->get() as $line) {
                $qty = round((float) $line->qty, 3);
                $unitCost = round((float) $line->unit_cost, 2);

                // Same hazard postReceipt() guards, and the expensive one: while
                // the truck is moving both warehouse balances read 0, so
                // ItemController::destroy used to let the item be deleted and
                // this line then died on a TypeError (a 500 the controller's
                // DomainException|LogicException catch never saw). The transfer
                // was stuck in_transit for ever, Rp 11.500.000 of Kabel UTP
                // stranded in 1-1400 with no sub-ledger behind it and no
                // endpoint able to restore the item.
                $item = $line->item;

                if ($item === null) {
                    throw new LogicException(
                        "Transfer {$transfer->code} references item #{$line->item_id}, which has been deleted; "
                        .'restore the item before receiving the goods.'
                    );
                }

                $this->applyIn($line->item_id, $transfer->to_warehouse_id, $qty, $unitCost, $receiptDate, $transfer);

                $this->refreshGlobalAvgCost($item);
            }

            // Recorded, not inferred: once the arrival can fall on a different
            // day from the despatch, an operator reading TRF/2026/VII/0001 dated
            // 28 July against a stock card row dated 4 August has to be told why.
            $transfer->forceFill([
                'status' => TransferStatus::Received,
                'received_date' => $receiptDate,
            ])->save();

            return $transfer->load('items.item', 'fromWarehouse', 'toWarehouse');
        });
    }

    /**
     * Post an APPROVED stock adjustment exactly once: positive differences go
     * in, negative differences go out, both valued at the warehouse average
     * cost at posting time. The net value difference is booked against the
     * stock variance account (selisih persediaan).
     */
    public function postAdjustment(StockAdjustment $adjustment): StockAdjustment
    {
        if ($adjustment->status !== DocumentStatus::Approved) {
            throw new LogicException("Adjustment {$adjustment->code} is {$adjustment->status->value}; only approved adjustments can be posted.");
        }

        if ($adjustment->isPosted()) {
            throw new LogicException("Adjustment {$adjustment->code} has already been posted.");
        }

        return DB::transaction(function () use ($adjustment): StockAdjustment {
            $this->assertStockPeriodOpen($adjustment->adjustment_date->toDateString());

            $postToLedger = $this->ledgerPostingEnabled();
            $netValue = 0.0;

            foreach ($adjustment->items()->with('item')->get() as $line) {
                $diff = round((float) $line->diff_qty, 3);

                if ($diff === 0.0) {
                    continue; // counted equals system: nothing to book
                }

                // A found-stock line is precisely a line for an item the
                // warehouse does not hold, so the item can have been soft-
                // deleted between the count and the approval — and dereferencing
                // it gave the storeman a bare 500 ("Attempt to read property
                // avg_cost on null") where postReceipt() already raises an
                // actionable Indonesian refusal for the identical situation.
                $item = $line->item;

                if ($item === null) {
                    throw new LogicException(
                        "Opname {$adjustment->code} references item #{$line->item_id}, which has been deleted; "
                        .'restore the item or raise a fresh count sheet without it.'
                    );
                }

                $this->assertMovementInOrder($adjustment->warehouse_id, (int) $line->item_id, $adjustment->adjustment_date->toDateString(), $adjustment->code);

                if ($diff > 0) {
                    // Surplus found on opname: book it in at the warehouse
                    // average; fall back to the item's global average, then the
                    // last purchase price, when the warehouse has no history.
                    $unitCost = $this->currentAvgCost($adjustment->warehouse_id, $line->item_id);

                    if ($unitCost <= 0) {
                        $unitCost = (float) $item->avg_cost > 0
                            ? (float) $item->avg_cost
                            : (float) $item->last_price;
                    }

                    $unitCost = round($unitCost, 2);

                    $moved = $this->applyIn($line->item_id, $adjustment->warehouse_id, $diff, $unitCost, $adjustment->adjustment_date->toDateString(), $adjustment);
                } else {
                    [$unitCost, $moved] = $this->applyOut($line->item_id, $adjustment->warehouse_id, abs($diff), $adjustment->adjustment_date->toDateString(), $adjustment);
                }

                $line->forceFill(['unit_cost' => $unitCost])->save();
                $this->refreshGlobalAvgCost($item);

                // Signed by the balance itself: applyIn returns what the
                // sub-ledger gained, applyOut what it lost. Reading the movement
                // rather than recomputing diff * unit_cost is what keeps the
                // 6-4400 variance equal to the value that actually moved.
                $netValue = round($netValue + $moved, 2);
            }

            $adjustment->forceFill(['posted_at' => now()])->save();

            if ($postToLedger) {
                $this->postAdjustmentJournal($adjustment, $netValue);
            }

            return $adjustment->load('items.item', 'warehouse');
        });
    }

    /**
     * Per-warehouse balances that have fallen below the item's minimum stock.
     */
    public function lowStockAlerts(?int $warehouseId = null): Collection
    {
        return DB::table('inv_stock_balances as b')
            ->join('inv_items as i', 'i.id', '=', 'b.item_id')
            ->join('inv_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->whereNull('i.deleted_at')
            ->whereNull('w.deleted_at')
            ->where('i.is_active', true)
            ->where('i.min_stock', '>', 0)
            ->whereColumn('b.qty', '<', 'i.min_stock')
            ->when($warehouseId !== null, fn ($query) => $query->where('b.warehouse_id', $warehouseId))
            ->orderBy('w.code')
            ->orderBy('i.code')
            ->get([
                'w.id as warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'i.id as item_id',
                'i.code as item_code',
                'i.name as item_name',
                'i.unit',
                'b.qty',
                'i.min_stock',
            ])
            ->map(function (object $row): object {
                $row->shortage_qty = round((float) $row->min_stock - (float) $row->qty, 3);

                return $row;
            });
    }

    /**
     * Value of goods that have left one warehouse and not yet arrived at the
     * other. Read from the transfer lines, whose unit_cost was frozen at the
     * source average on send, because that is precisely the value the general
     * ledger still carries and neither warehouse balance does (see the
     * sendTransfer docblock for why no journal moves it).
     *
     * PUBLIC, AND SHARED, BECAUSE TWO READERS QUOTE THE SAME RUPIAH:
     * erp:inventory-method-check names it as the reconciling figure between
     * the stock sub-ledger and GL 1-1400, and StockController::balances()
     * serves it to the Saldo Stok screen. It used to live inside the command
     * alone, so the screen had no in-transit figure at all (audit T28/T29) —
     * and a second copy of the query would drift on its first edit, leaving
     * the screen and the CLI check quietly disagreeing about the same trucks.
     *
     * The Schema guards travel with the query: the command runs on installs
     * whose transfer tables may not be migrated, where the honest figure is
     * zero, not a QueryException.
     */
    public function inTransitValue(): float
    {
        if (! Schema::hasTable('inv_transfers') || ! Schema::hasTable('inv_transfer_items')) {
            return 0.0;
        }

        return round((float) DB::table('inv_transfer_items as ti')
            ->join('inv_transfers as t', 't.id', '=', 'ti.transfer_id')
            ->whereNull('t.deleted_at')
            ->where('t.status', TransferStatus::InTransit->value)
            ->sum(DB::raw('ti.qty * ti.unit_cost')), 2);
    }

    /**
     * How many transfer documents are on the road right now — shown beside the
     * rupiah on the Saldo Stok screen so a storeman knows how many documents
     * to chase to bring the figure back to zero, not just how much is riding.
     */
    public function inTransitTransferCount(): int
    {
        if (! Schema::hasTable('inv_transfers')) {
            return 0;
        }

        return DB::table('inv_transfers')
            ->whereNull('deleted_at')
            ->where('status', TransferStatus::InTransit->value)
            ->count();
    }

    /**
     * Recompute the item's global weighted average across all warehouses:
     * sum(qty * warehouse_avg) / sum(qty) over positive balances. When total
     * stock is zero the last known average is kept for valuation continuity.
     *
     * Null-tolerant: an item soft-deleted since the document was raised has no
     * average left to recompute, and a stock path that has already decided how
     * to handle that must not be turned into a TypeError on the way out.
     */
    public function refreshGlobalAvgCost(?Item $item): void
    {
        if ($item === null) {
            return;
        }

        $totals = StockBalance::query()
            ->where('item_id', $item->id)
            ->where('qty', '>', 0)
            ->selectRaw('COALESCE(SUM(qty), 0) as total_qty, COALESCE(SUM(qty * avg_cost), 0) as total_value')
            ->first();

        $totalQty = (float) ($totals->total_qty ?? 0);

        if ($totalQty > 0) {
            $item->forceFill([
                'avg_cost' => round((float) $totals->total_value / $totalQty, 2),
            ])->save();
        }
    }

    /**
     * Stock IN: moving average on the (warehouse, item) balance + ledger row.
     *
     * Returns the rupiah the sub-ledger GAINED — qty * avg is re-read off the
     * balance before and after, never re-derived from the document. That is
     * costing rule 2, and it is what stops the GL and the stock sub-ledger
     * drifting apart on prices that do not divide evenly: 3 @ 1.000 then
     * 4 @ 1.001 stores an average of 1.000,57, so the sub-ledger holds 7.003,99
     * while the invoice says 7.004,00. Debiting the invoice figure left Rp 0,01
     * in 1-1400 that survived the stock going to zero, and made "GL 1-1400 ==
     * sum(qty * avg_cost)" — which two tests assert outright — an approximation
     * that happened to hold because their fixtures divided evenly.
     */
    private function applyIn(int $itemId, int $warehouseId, float $qty, float $unitCost, string $trxDate, Model $reference): float
    {
        if ($qty <= 0) {
            throw new LogicException('Stock-in quantity must be positive.');
        }

        $balance = $this->lockBalance($warehouseId, $itemId);

        $oldQty = (float) $balance->qty;
        $oldAvg = (float) $balance->avg_cost;
        $newQty = round($oldQty + $qty, 3);

        // new_avg = (bal_qty * bal_avg + qty * unit_cost) / (bal_qty + qty)
        // A non-positive old balance contributes nothing, so the incoming cost wins.
        $newAvg = $oldQty > 0
            ? round((($oldQty * $oldAvg) + ($qty * $unitCost)) / ($oldQty + $qty), 2)
            : $unitCost;

        $balance->forceFill(['qty' => $newQty, 'avg_cost' => $newAvg])->save();

        $this->writeLedger($itemId, $warehouseId, $trxDate, $reference, self::DIRECTION_IN, $qty, $unitCost, $newQty);

        return round(round($newQty * $newAvg, 2) - round($oldQty * $oldAvg, 2), 2);
    }

    /**
     * Stock OUT at the warehouse's current average cost.
     *
     * Returns [that cost, the rupiah the sub-ledger LOST as a negative number].
     * The average never changes on the way out — only quantity drops — so the
     * movement is exactly qty * avg; it is still read off the balance rather
     * than recomputed, so both directions answer the same question and a caller
     * can sum them without knowing which way the stock went.
     *
     * @return array{0: float, 1: float}
     */
    private function applyOut(int $itemId, int $warehouseId, float $qty, string $trxDate, Model $reference): array
    {
        if ($qty <= 0) {
            throw new LogicException('Stock-out quantity must be positive.');
        }

        $balance = $this->lockBalance($warehouseId, $itemId);

        // 0.0005 tolerance absorbs decimal(15,3) rounding artifacts.
        if ((float) $balance->qty + 0.0005 < $qty) {
            throw new DomainException(sprintf(
                'Stok tidak mencukupi: %s di %s (tersedia %s, diminta %s).',
                Item::query()->find($itemId)?->name ?? "item #{$itemId}",
                Warehouse::query()->find($warehouseId)?->name ?? "gudang #{$warehouseId}",
                number_format((float) $balance->qty, 3, ',', '.'),
                number_format($qty, 3, ',', '.'),
            ));
        }

        $oldQty = (float) $balance->qty;
        $unitCost = round((float) $balance->avg_cost, 2);
        $newQty = round($oldQty - $qty, 3);

        $balance->forceFill(['qty' => $newQty])->save();

        $this->writeLedger($itemId, $warehouseId, $trxDate, $reference, self::DIRECTION_OUT, $qty, $unitCost, $newQty);

        return [$unitCost, round(round($newQty * $unitCost, 2) - round($oldQty * $unitCost, 2), 2)];
    }

    /**
     * A date for the arrival that does not land behind a movement the
     * destination has ALREADY recorded — looking backwards only.
     *
     * A destination row dated in the FUTURE is not cleared by this: the answer
     * is the send date or today, never max(candidate, last movement), so a
     * receipt booked ahead of the calendar still ends up in front of the
     * arrival on the kartu stok. That case is left where the module leaves it
     * everywhere else, to assertMovementInOrder() — which receiveTransfer()
     * deliberately does not call, because an arrival is not a fresh decision
     * about a date and refusing it would strand the goods it is trying to land.
     *
     * The despatch only established order at the SOURCE. While the truck was
     * rolling the receiving warehouse may have received, issued or been
     * opname'd, and an arrival dated behind those rows is written into
     * inv_stock_ledger in INSERTION order with a balance_qty_after computed off
     * today's balance — so the kartu stok, read in date order, shows the goods
     * arriving before movements that had already happened and a running balance
     * the warehouse never held.
     *
     * Falls forward to today rather than refusing, which is why this is not
     * simply assertMovementInOrder(): the goods are physically on the receiving
     * floor, and a refusal would strand them in transit with their value sitting
     * in 1-1400 behind neither warehouse balance — the same trap the send-date
     * period gate used to spring, described on receiveTransfer().
     */
    private function arrivalDateClearingDestination(Transfer $transfer, string $candidate): string
    {
        $latest = DB::table('inv_stock_ledger')
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->whereIn('item_id', $transfer->items()->pluck('item_id'))
            ->max('trx_date');

        if ($latest === null) {
            return $candidate;
        }

        return $candidate >= substr((string) $latest, 0, 10)
            ? $candidate
            : now()->toDateString();
    }

    /**
     * Refuse a movement dated before the last one already recorded for that
     * (warehouse, item) — costing rule 1.
     *
     * The moving average is computed forward from the balance as it stands:
     * applyIn/applyOut read inv_stock_balances and nothing else, and $trxDate
     * only ever reaches writeLedger(). So a delivery note for 100 zak semen at
     * Rp 62.000, delivered on 12 July but keyed on 20 July — after the 15 July
     * bon for the same 100 zak has already posted at the old Rp 55.000 average —
     * used to be accepted and valued at TODAY's mix. Measured end to end:
     * Beban Material for the project understated by Rp 700.000 (and the same
     * rupiah left overstated in 1-1400), fin_project_costs carrying the wrong
     * number, no warning and no refusal.
     *
     * The other honest answer is full retrospective re-costing — rewrite the
     * unit_cost and balance_qty_after of every ledger row after the inserted
     * date and post correcting journals. That is a much larger change than this
     * pass, and it is not even available once a fiscal period has closed over
     * the affected months, so the rule the engine actually obeys is the one
     * stated and enforced here. Late paperwork is booked on a date at or after
     * the last movement, or as an opname, which is the document that exists for
     * "the shelf disagrees with the system".
     *
     * A side effect worth having: with movements recorded in date order per
     * (warehouse, item), inv_stock_ledger.balance_qty_after — written in
     * INSERTION order — is finally a true running balance when the kartu stok is
     * read in date order. It previously ended a card at 0 for a warehouse that
     * really held 100.
     */
    private function assertMovementInOrder(int $warehouseId, int $itemId, string $trxDate, string $documentCode): void
    {
        $last = DB::table('inv_stock_ledger')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->max('trx_date');

        if ($last === null) {
            return;
        }

        $lastDate = substr((string) $last, 0, 10);

        if ($trxDate >= $lastDate) {
            return;
        }

        throw new LogicException(sprintf(
            'Dokumen %s bertanggal %s, lebih awal dari mutasi terakhir %s untuk %s di %s. '
            .'Harga rata-rata dihitung maju menurut urutan pencatatan, jadi mundurnya tanggal akan '
            .'menilai barang ini pada rata-rata hari ini dan membiarkan pengeluaran yang sudah terlanjur '
            .'diposting memakai harga lama. Ubah tanggalnya menjadi %s atau sesudahnya, atau catat '
            .'selisihnya lewat opname.',
            $documentCode,
            $trxDate,
            $lastDate,
            Item::query()->withTrashed()->find($itemId)?->name ?? "item #{$itemId}",
            Warehouse::query()->find($warehouseId)?->name ?? "gudang #{$warehouseId}",
            $lastDate,
        ));
    }

    /**
     * THE period seal on stock, asked independently of the journal — costing
     * rule 3.
     *
     * Until now the only thing that kept a movement out of a closed month was
     * JournalService::assertPeriodOpen, reached through autoPost. Every posting
     * method returns BEFORE that call when its value rounds to zero
     * (postReceiptJournal, postIssueJournal, postAdjustmentJournal all do), and
     * the two transfer paths never call it at all — so a net-zero opname
     * counting 110 semen against 100 and 90 besi against 100, both at Rp 15.000,
     * moved Rp 150.000 of value between two items inside a period whose stock
     * valuation had already been signed off, with no journal, no error and
     * nothing in the close checklist to notice it.
     *
     * A fiscal period governs WHEN a movement may be recorded, not whether a
     * journal happens to come out of THAT document — so the gate is asked up
     * front, on ledgerPostingEnabled() rather than on this document's value.
     *
     * It is deliberately NOT extended to periodic installations. Under periodic
     * no stock movement ever reaches the ledger, and
     * FiscalPeriodRollbackTest::test_a_closed_period_does_not_block_stock_when_perpetual_inventory_is_off
     * pins that as a decision, not an oversight: there is no journal for a close
     * to strand, so refusing the movement would be theatre. DanglingDocuments'
     * perpetual_only flag says the same thing from the other end.
     *
     * Degrades exactly like ledgerPostingEnabled(): with Finance absent, or with
     * a company that has not laid out a fiscal calendar yet, there is no period
     * to obey and Inventory keeps working standalone.
     */
    private function assertStockPeriodOpen(string $date): void
    {
        if (! $this->ledgerPostingEnabled() || ! Schema::hasTable('fin_fiscal_periods')) {
            return;
        }

        app(JournalService::class)->assertPeriodOpen($date);
    }

    private function currentAvgCost(int $warehouseId, int $itemId): float
    {
        return (float) StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->value('avg_cost');
    }

    private function lockBalance(int $warehouseId, int $itemId): StockBalance
    {
        $balance = StockBalance::query()->firstOrCreate(
            ['warehouse_id' => $warehouseId, 'item_id' => $itemId],
            ['qty' => 0, 'avg_cost' => 0],
        );

        // Re-fetch under a row lock so concurrent postings serialize per balance.
        return StockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
    }

    private function writeLedger(
        int $itemId,
        int $warehouseId,
        string $trxDate,
        Model $reference,
        string $direction,
        float $qty,
        float $unitCost,
        float $balanceQtyAfter,
    ): void {
        StockLedgerEntry::query()->create([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'trx_date' => $trxDate,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'direction' => $direction,
            'qty' => round($qty, 3),
            'unit_cost' => round($unitCost, 2),
            'balance_qty_after' => round($balanceQtyAfter, 3),
        ]);
    }

    /**
     * The purchase order the receipt HEADER names, as a row — never as an id.
     *
     * withTrashed on purpose: a soft-deleted PO still explains where the goods
     * came from (its vendor), while being unbillable, and the callers need to
     * tell "no such order" apart from "an order that can no longer be used".
     * Returns null when no PO is named, when the id resolves to nothing, or
     * when Procurement is not installed — all three mean "no purchase order I
     * can reason about".
     */
    private function resolvePurchaseOrder(GoodsReceipt $grn): ?PurchaseOrder
    {
        if ($grn->purchase_order_id === null) {
            return null;
        }

        if (! class_exists(PurchaseOrder::class) || ! Schema::hasTable('prc_purchase_orders')) {
            return null;
        }

        return PurchaseOrder::query()->withTrashed()->find($grn->purchase_order_id);
    }

    /**
     * Header-level receiving gate, hoisted out of the per-line loop: goods may
     * only be booked against a purchase order that is still APPROVED.
     *
     * PoService::registerReceipt enforces the same rule per PO LINE, but a
     * receipt line is allowed to carry po_item_id = NULL (an over-delivery, a
     * substituted article, a line the clerk could not match), and that skipped
     * the check completely. The order's own state is a property of the header,
     * so it is checked from the header.
     *
     * A closed order means "nothing more is coming"; an unapproved one means
     * "this order does not exist yet". Neither can be received against, and
     * neither can be made to balance afterwards — the receipt would raise a
     * clearing credit that no invoice for that PO will ever debit (Finance
     * allows exactly one final bill per PO). The delivery is still recordable:
     * drop the purchase order from the receipt and book it against the vendor,
     * which raises the penerimaan accrual a bill against the receipt clears.
     *
     * An unresolvable purchase_order_id is deliberately NOT fatal here — it is
     * a data error, not a procurement violation, and receiptCreditLeg() treats
     * it as "no purchase order", which routes the credit to a branch that has a
     * real debit path.
     */
    private function assertPurchaseOrderCanReceive(GoodsReceipt $grn, ?PurchaseOrder $po): void
    {
        if ($po === null) {
            return;
        }

        if ($po->trashed() || $po->status !== DocumentStatus::Approved) {
            $state = $po->trashed() ? 'deleted' : $po->status->value;

            throw new LogicException(
                "GRN {$grn->code} references PO {$po->code}, which is {$state}; only an approved "
                .'purchase order can receive goods. Record the delivery against the vendor without '
                .'the purchase order so it can be billed on the receipt.'
            );
        }

        if ($this->purchaseOrderWasBilledWithoutMatching($po)) {
            throw new LogicException(
                "GRN {$grn->code} menyebut PO {$po->code}, yang tagihannya sudah disetujui tanpa penerimaan "
                .'barang, sehingga pembeliannya langsung dibebankan ke 5-1100. Menerima barangnya sekarang '
                .'akan menaikkan persediaan dan memunculkan tagihan kedua untuk kiriman yang sama, jadi '
                .'biayanya terhitung dua kali. Catat pengiriman ini atas nama vendor tanpa nomor PO.'
            );
        }
    }

    /**
     * Did this purchase order's one final bill take the CLASSIC (expensing)
     * path — approved while nothing had been received?
     *
     * ApBillService::approve three-way-matches only when the receipts recorded a
     * clearing amount for it to sweep; with nothing received it finds
     * $clearing === [] and books Dr 5-1100 gross plus a fin_project_costs row
     * instead. The goods are then already expensed, and receiptCreditLeg() —
     * which correctly refuses to park a credit in GR/IR that no bill can ever
     * debit, because Finance allows exactly one final bill per PO — routes a
     * later delivery to the 2-1600 accrual, i.e. offers Finance a SECOND payable
     * for a delivery already invoiced.
     *
     * Measured against a copy of the live demo: PO/2026/II/0001 (Rp 209.500.000,
     * project 1) already carried an approved BIL/2026/III/0001 with
     * gl_cleared_amount 0,00; posting a GRN against it took 5-1100 from
     * Rp 228.240.000 to Rp 437.740.000 and doubled PRJ-2026-001's material
     * realisasi against an unchanged RAP.
     *
     * gl_cleared_amount > 0 means that bill DID match receipts, so goods
     * arriving after it are a genuine over-delivery and keep the accrual route.
     */
    private function purchaseOrderWasBilledWithoutMatching(PurchaseOrder $po): bool
    {
        if (! Schema::hasTable('fin_ap_bills') || ! Schema::hasColumn('fin_ap_bills', 'gl_cleared_amount')) {
            return false;
        }

        return DB::table('fin_ap_bills')
            ->whereNull('deleted_at')
            ->where('purchase_order_id', $po->getKey())
            ->where('is_advance', false)
            ->where('status', DocumentStatus::Approved->value)
            ->where(fn ($query) => $query
                ->whereNull('gl_cleared_amount')
                ->orWhere('gl_cleared_amount', '<=', 0))
            ->exists();
    }

    /**
     * The over-receipt ceiling, applied to receipt lines that name no PO line.
     *
     * po_item_id is the only thing that reaches PoService::registerReceipt(),
     * which holds the only quantity ceiling in the system
     * ($qty > $poItem->remainingQty()). registerPoReceipt() returns before it
     * whenever the line carries no reference, so a hand-added line — the storeman
     * who typed the row instead of using "Salin baris dari PO" — was uncapped
     * ENTIRELY: the audit received 1000 zak against a 100-zak order with no
     * refusal at all, qty_received stayed 0.000 so the order never closed,
     * Rp 15.000.000 went into 1-1400 and 2-1150 for 100 zak that arrived, and the
     * PO bill then swept the difference into 6-4500 as a Rp 13.500.000 purchase
     * "gain" plus a MINUS Rp 13.500.000 material row in fin_project_costs.
     *
     * So the ceiling is asked here instead of being skipped, and it is asked
     * CUMULATIVELY — ordered quantity for that article against everything this
     * purchase order has already taken delivery of, plus what this receipt adds.
     * Per-receipt arithmetic would let two unlinked deliveries of 60 each walk
     * past a 100-zak order, precisely because an unlinked line never moves
     * qty_received.
     *
     * IT APPLIES ONLY WHILE THE ORDER IS STILL AWAITING THAT ARTICLE, and that
     * boundary is the whole design. Two shapes deliberately live on the other
     * side of it, both named as legitimate in assertPurchaseOrderCanReceive's
     * docblock: a substituted article the order never mentioned (no ordered
     * quantity to measure against), and a genuine over-delivery arriving after
     * the ordered quantity is complete — which receiptCreditLeg() routes to the
     * 2-1600 accrual precisely because no PO bill can ever sweep it, and which
     * ReceiptClearingDocumentTest pins as intended behaviour. Neither is a
     * quantity error; both are bounded by their own machinery.
     *
     * A po_item_id belonging to some OTHER order is refused rather than skipped:
     * registerPoReceipt() dropped it silently, which is the same ceiling bypass
     * wearing a reference.
     */
    private function assertPoQuantitiesAreBounded(GoodsReceipt $grn, ?PurchaseOrder $po): void
    {
        if ($po === null || ! class_exists(PurchaseOrderItem::class) || ! Schema::hasTable('prc_purchase_order_items')) {
            return;
        }

        $poItems = PurchaseOrderItem::query()
            ->where('purchase_order_id', $po->getKey())
            ->get();

        $poItemIds = $poItems->pluck('id')->map(fn ($id): int => (int) $id)->all();

        /** @var array<int, float> $ordered item id => quantity this order is still awaiting a delivery of */
        $ordered = [];

        foreach ($poItems as $poItem) {
            if ($poItem->item_id === null || $poItem->remainingQty() <= 0) {
                continue;
            }

            $itemId = (int) $poItem->item_id;
            $ordered[$itemId] = round(($ordered[$itemId] ?? 0.0) + (float) $poItem->qty, 3);
        }

        /** @var array<int, float> $unlinked item id => quantity arriving on THIS receipt with no PO line */
        $unlinked = [];

        foreach ($grn->items as $line) {
            $poItemId = $line->po_item_id !== null ? (int) $line->po_item_id : null;

            if ($poItemId !== null && ! in_array($poItemId, $poItemIds, true)) {
                throw new LogicException(
                    "Baris pada GRN {$grn->code} menunjuk baris PO #{$poItemId} yang bukan milik PO {$po->code}. "
                    .'Perbaiki tautannya lewat "Salin baris dari PO" agar batas kuantitas PO ikut diperiksa.'
                );
            }

            $itemId = (int) $line->item_id;

            if ($poItemId === null && array_key_exists($itemId, $ordered)) {
                $unlinked[$itemId] = round(($unlinked[$itemId] ?? 0.0) + round((float) $line->qty, 3), 3);
            }
        }

        if ($unlinked === []) {
            return;
        }

        $alreadyReceived = $this->quantitiesReceivedAgainst($po, array_keys($unlinked));

        foreach ($unlinked as $itemId => $qty) {
            $total = round(($alreadyReceived[$itemId] ?? 0.0) + $qty, 3);

            // 0.0005 tolerance, the same one applyOut() uses, absorbs
            // decimal(15,3) rounding artifacts.
            if ($total <= $ordered[$itemId] + 0.0005) {
                continue;
            }

            $itemName = Item::query()->withTrashed()->find($itemId)?->name ?? "item #{$itemId}";

            throw new LogicException(sprintf(
                'GRN %s membuat total penerimaan %s atas PO %s menjadi %s, melebihi %s yang dipesan. '
                .'Baris ini tidak tertaut ke baris PO, sehingga batas kuantitas PO tidak diperiksa lewat jalur '
                .'biasa. Gunakan "Salin baris dari PO" untuk barang yang memang dipesan, atau perbaiki '
                .'kuantitasnya.',
                $grn->code,
                $itemName,
                $po->code,
                number_format($total, 3, ',', '.'),
                number_format($ordered[$itemId], 3, ',', '.'),
            ));
        }
    }

    /**
     * What this purchase order has already taken delivery of per item, counted
     * from POSTED receipts rather than from prc_purchase_order_items.qty_received
     * — because that column is exactly what an unlinked line fails to move, and
     * a ceiling that trusted it would be blind to the deliveries it is meant to
     * be counting.
     *
     * @param  list<int>  $itemIds
     * @return array<int, float>
     */
    private function quantitiesReceivedAgainst(PurchaseOrder $po, array $itemIds): array
    {
        $rows = DB::table('inv_goods_receipt_items as line')
            ->join('inv_goods_receipts as grn', 'grn.id', '=', 'line.goods_receipt_id')
            ->whereNull('grn.deleted_at')
            ->where('grn.purchase_order_id', $po->getKey())
            ->where('grn.status', StockDocumentStatus::Posted->value)
            ->whereIn('line.item_id', $itemIds)
            ->groupBy('line.item_id')
            ->selectRaw('line.item_id as item_id, COALESCE(SUM(line.qty), 0) as qty')
            ->get();

        $received = [];

        foreach ($rows as $row) {
            $received[(int) $row->item_id] = round((float) $row->qty, 3);
        }

        return $received;
    }

    /**
     * The vendor behind the delivery, as a row — never as an id. The receipt's
     * own vendor_id wins; a receipt that names only a purchase order inherits
     * that order's vendor, because that is who delivered.
     *
     * A vendor id that resolves to nothing (or to a soft-deleted vendor, whom
     * no bill can name) yields null: there is then no counterparty to accrue a
     * liability towards.
     */
    private function resolveVendorId(GoodsReceipt $grn, ?PurchaseOrder $po): ?int
    {
        if (! class_exists(Vendor::class) || ! Schema::hasTable('prc_vendors')) {
            return null;
        }

        foreach ([$grn->vendor_id, $po?->vendor_id] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (Vendor::query()->whereKey($candidate)->exists()) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * Can a vendor invoice for this purchase order still debit a GR/IR credit
     * raised against it?
     *
     * Only while the order is approved AND its final bill has not been approved
     * yet. ApBillService allows exactly one non-advance bill per PO
     * (finalBillExists), and that bill clears the receipts' recorded credits
     * once, at ITS approval. So:
     *
     *   no bill yet, or one still draft/submitted => that bill is the clearing
     *       document; it will sweep this receipt too when it is approved;
     *   final bill already approved               => its one chance is spent and
     *       no replacement can be created, so a GR/IR credit raised now would
     *       stay on the balance sheet for ever.
     *
     * Reading "approved" rather than "exists" is what keeps the two clearing
     * routes disjoint: a receipt only leaves the GR/IR route once no PO bill
     * can ever sweep it, so nothing can be cleared twice.
     *
     * Guarded for a partially migrated installation: with no bills table there
     * is no document of any kind that could clear GR/IR, so the conservative
     * branch is taken. (A journal is only posted at all when Finance's schema
     * is present — see ledgerPostingEnabled.)
     */
    private function purchaseOrderCanStillClear(PurchaseOrder $po): bool
    {
        if ($po->trashed() || $po->status !== DocumentStatus::Approved) {
            return false;
        }

        if (! Schema::hasTable('fin_ap_bills')) {
            return false;
        }

        return ! DB::table('fin_ap_bills')
            ->whereNull('deleted_at')
            ->where('purchase_order_id', $po->getKey())
            ->where('is_advance', false)
            ->where('status', DocumentStatus::Approved->value)
            ->exists();
    }

    /**
     * When the GRN line references a PO line, tell Procurement so it can track
     * received quantities and auto-close the PO. Guarded by class_exists so
     * Inventory still works if the Procurement module is absent.
     *
     * The order's status is NOT checked here — assertPurchaseOrderCanReceive()
     * does that once for the whole document, including the lines this method
     * skips.
     */
    private function registerPoReceipt(GoodsReceipt $grn, GoodsReceiptItem $line): void
    {
        if ($grn->purchase_order_id === null || $line->po_item_id === null) {
            return;
        }

        if (! class_exists(PoService::class)) {
            return;
        }

        $poItem = PurchaseOrderItem::query()->find($line->po_item_id);

        if ($poItem === null || (int) $poItem->purchase_order_id !== (int) $grn->purchase_order_id) {
            return; // dangling or mismatched PO line reference: skip, do not block the GRN
        }

        // Throws (and rolls back the posting) when the PO is not approved or
        // the receipt would exceed the remaining PO quantity.
        app(PoService::class)->registerReceipt($poItem, (float) $line->qty);
    }

    /**
     * The mirror of registerPoReceipt(), for a purchase return: when the GRN
     * line was registered against a PO line, hand the quantity back so the
     * order stops reading more delivered than it now holds. Same guards, same
     * silences — a dangling or mismatched reference is skipped, not fatal,
     * because it never incremented anything either.
     *
     * Throws (rolling the posting back) when the return would exceed what the
     * PO line has received — see PoService::unregisterReceipt(), which also
     * reopens an order the full delivery auto-closed.
     */
    private function registerPoReturn(GoodsReceipt $grn, GoodsReceiptItem $grnLine, float $qty): void
    {
        if ($grn->purchase_order_id === null || $grnLine->po_item_id === null) {
            return;
        }

        if (! class_exists(PoService::class)) {
            return;
        }

        $poItem = PurchaseOrderItem::query()->find($grnLine->po_item_id);

        if ($poItem === null || (int) $poItem->purchase_order_id !== (int) $grn->purchase_order_id) {
            return; // dangling or mismatched PO line reference: skip, do not block the return
        }

        app(PoService::class)->unregisterReceipt($poItem, $qty);
    }

    /**
     * GR/IR step 1 — goods on hand, vendor invoice not yet booked:
     *
     *   Dr 1-1400 Persediaan Material              nilai barang diterima
     *   Cr <akun kredit>                           idem
     *
     * THE GOVERNING RULE: every credit posted here must have a debit path that
     * exists in the product. Which account carries the credit therefore follows
     * which document is capable of removing it again — established by RESOLVING
     * the documents, never by testing an id for null:
     *
     *   PO that can still be billed  =>  2-1150 GR/IR clearing
     *       The bill for that PO debits it back out (ApBillService::approve).
     *
     *   otherwise, a known vendor    =>  2-1600 Beban Yang Masih Harus Dibayar
     *       A real delivery whose invoice has not been raised through
     *       procurement — no PO at all, a PO that no longer resolves, or a PO
     *       whose one final bill has already been approved (an over-delivery,
     *       or goods that arrive after the invoice). A manual AP bill that
     *       references THIS receipt (fin_ap_bills.goods_receipt_id) debits it
     *       back out — the accrual finally has a document, not just a docblock.
     *
     *   no counterparty at all       =>  3-3100 Saldo Awal (EQUITY)
     *       Opening stock or found stock. Nobody is owed anything, so no
     *       liability may be booked; and no trading event happened, so no P&L
     *       account may be touched either. Crediting an expense account
     *       (6-4400 Selisih Persediaan, which is what this branch used to do)
     *       posts the entire opening inventory as operating INCOME in the
     *       go-live year — a negative expense is income. The honest
     *       counter-entry to an asset that arrives with no transaction behind
     *       it is equity: 3-3100 collects those balances until an accountant
     *       closes it to Modal Disetor / Laba Ditahan. It needs no clearing
     *       document because it is closed where it is raised.
     *
     * The first two credits are recorded on the receipt row as
     * (gl_clearing_account, gl_clearing_amount). That record is the ONLY thing
     * ApBillService may clear, which is what stops the two ends of the chain
     * from disagreeing when the PO has no warehouse, or when the perpetual
     * switch is toggled between receipt and invoice. The third records nothing,
     * because there is nothing outstanding to clear.
     *
     * Nothing touches the P&L here — the material cost waits for the issue.
     */
    private function postReceiptJournal(GoodsReceipt $grn, float $value, ?PurchaseOrder $po): void
    {
        $value = round($value, 2);

        if ($value === 0.0) {
            return; // free-issue / zero-cost receipt: no value to book
        }

        [$creditCode, $creditDescription, $clearable] = $this->receiptCreditLeg($grn, $po);

        app(JournalService::class)->autoPost(
            'goods_receipt',
            (int) $grn->id,
            [
                [
                    'account_code' => $this->inventoryAccountCode(),
                    'debit' => $value,
                    'description' => "Penerimaan barang {$grn->code}",
                ],
                [
                    'account_code' => $creditCode,
                    'credit' => $value,
                    'description' => $creditDescription,
                ],
            ],
            $grn->receipt_date->toDateString(),
            "GRN {$grn->code} — penerimaan persediaan",
            $this->postingUserId($grn->received_by),
        );

        // Written after the posting, inside postReceipt()'s transaction: a
        // refused journal (missing or non-postable account, closed period) rolls
        // the record back with the stock movement, so the receipt can never
        // claim a credit the ledger does not carry.
        if ($clearable) {
            $grn->forceFill([
                'gl_clearing_account' => $creditCode,
                'gl_clearing_amount' => $value,
            ])->save();
        }
    }

    /**
     * Credit leg of the receipt journal: [account code, line description,
     * whether a later document is expected to clear it].
     *
     * Each branch is entered on a RESOLVED row. An id that points at nothing
     * proves nothing about what can clear a balance, so it falls through to the
     * next branch rather than steering the credit.
     *
     * @param  ?PurchaseOrder  $po  the resolved header PO, null when absent,
     *                              unresolvable or Procurement is not installed
     * @return array{0: string, 1: string, 2: bool}
     */
    private function receiptCreditLeg(GoodsReceipt $grn, ?PurchaseOrder $po): array
    {
        if ($po !== null && $this->purchaseOrderCanStillClear($po)) {
            return [
                $this->clearingAccountCode(),
                "Barang diterima belum ditagih {$grn->code}",
                true,
            ];
        }

        $vendorId = $this->resolveVendorId($grn, $po);

        if ($vendorId !== null) {
            return [
                $this->accrualAccountCode(),
                $po === null
                    ? "Penerimaan tanpa PO {$grn->code}"
                    : "Penerimaan di luar tagihan PO {$po->code} — {$grn->code}",
                true,
            ];
        }

        // No purchase order, no vendor: nothing was bought, so nothing is owed
        // and nothing was earned. See postReceiptJournal() for why this is
        // equity and not the stock variance expense.
        return [
            $this->openingBalanceAccountCode(),
            "Saldo awal persediaan {$grn->code}",
            false,
        ];
    }

    /**
     * GR/IR step 3 — consumption. One debit line per cost account (project
     * issues use the 5-xxxx HPP account of the line's category, non-project
     * issues general opex), one credit line emptying persediaan:
     *
     *   Dr 5-1100 / 5-1400 / 6-4100   nilai pemakaian
     *   Cr 1-1400 Persediaan Material total
     *
     * @param  array<string, float>  $byAccount  debit account code => value issued
     */
    private function postIssueJournal(Issue $issue, array $byAccount): void
    {
        $total = round(array_sum($byAccount), 2);

        if ($total === 0.0) {
            return; // nothing valued (zero moving average): no journal
        }

        $lines = [];

        foreach ($byAccount as $accountCode => $amount) {
            $lines[] = [
                'account_code' => $accountCode,
                'debit' => $amount,
                'description' => "Pemakaian material {$issue->code}",
                'project_id' => $issue->project_id,
            ];
        }

        $lines[] = [
            'account_code' => $this->inventoryAccountCode(),
            'credit' => $total,
            'description' => "Pengeluaran persediaan {$issue->code}",
            'project_id' => $issue->project_id,
        ];

        app(JournalService::class)->autoPost(
            'inventory_issue',
            (int) $issue->id,
            $lines,
            $issue->issue_date->toDateString(),
            "Issue {$issue->code} — pemakaian material",
            $this->postingUserId($issue->issued_by),
        );
    }

    /**
     * Mirror the issue into the project cost ledger so realisasi per category
     * lines up against the RAP — one row PER LINE, so the work package on the
     * line survives the trip.
     *
     * The reference is ('inventory_issue_item', line id), not the old
     * ('inventory_issue', issue id) per category. That is forced by
     * ProjectCostService::record()'s idempotency key (reference_type,
     * reference_id, cost_category): a per-issue reference collapses a bon
     * serving two work packages in one category into ONE row with ONE
     * wbs_task_id slot — ISS/2026/VII/0001 (150 zak semen for WBS C.1 + 80 btg
     * besi for WBS B.3, both `material`, Rp 18.740.000 together) would keep
     * whichever line recorded last and silently drop the other package.
     * Finance's own docblock on record() names per-line references as the
     * answer for exactly this; KasbonService::settle() already writes
     * ('kasbon_line', line id) the same way. Totals are unchanged — the lines
     * of a category still sum to what the single row used to carry.
     *
     * FORWARD-ONLY: rows written before this change keep reference_type
     * 'inventory_issue' with wbs_task_id null (the live ISS/2026/VII/0001 row
     * among them, repaired into existence by migration 000496). No runtime
     * path removes rows of either reference type today, and a posted issue can
     * never post again, so the two shapes never overlap for one document.
     *
     * @param  array<int, array{line: IssueItem, category: CostCategory}>  $costLines
     */
    private function recordIssueProjectCost(Issue $issue, array $costLines): void
    {
        if ($issue->project_id === null || $costLines === []) {
            return;
        }

        if (! class_exists(ProjectCostService::class) || ! Schema::hasTable('fin_project_costs')) {
            return;
        }

        $projectCosts = app(ProjectCostService::class);

        foreach ($costLines as $costLine) {
            $line = $costLine['line'];
            $amount = round((float) $line->amount, 2);

            if ($amount === 0.0) {
                continue; // zero moving average: no journal, no cost row
            }

            // The item name tells the two rows of one bon apart; a line whose
            // item was soft-deleted since falls back to the bon's purpose.
            $detail = $line->item?->name ?? $issue->purpose;

            $projectCosts->record(
                (int) $issue->project_id,
                $issue->issue_date->toDateString(),
                $costLine['category'],
                'inventory_issue_item',
                (int) $line->id,
                Str::limit("Pemakaian material {$issue->code} — {$detail}", 497),
                $amount,
                $line->wbs_task_id !== null ? (int) $line->wbs_task_id : null,
            );
        }
    }

    /**
     * Drop the project cost rows a cancelled bon wrote.
     *
     * BOTH reference shapes, because the ledger carries both: recordIssueProjectCost()
     * writes ('inventory_issue_item', line id) per line, while rows written before
     * that change — the live ISS/2026/VII/0001 row among them, repaired into
     * existence by migration 000496 — still carry ('inventory_issue', issue id).
     * Removing only one shape would leave a project realisasi above the general
     * ledger by exactly the amount the reversal just gave back.
     */
    private function removeIssueProjectCost(Issue $issue): void
    {
        if (! class_exists(ProjectCostService::class) || ! Schema::hasTable('fin_project_costs')) {
            return;
        }

        $projectCosts = app(ProjectCostService::class);

        $projectCosts->remove('inventory_issue', (int) $issue->id);

        foreach ($issue->items()->get(['id']) as $line) {
            $projectCosts->remove('inventory_issue_item', (int) $line->id);
        }
    }

    /**
     * Journal of a retur material, obeying costing rule 2 from both ends:
     *
     *   Dr 1-1400 Persediaan   what the stored balance actually gained
     *   Cr 5-xxxx / 6-4100     the cost slice at the issue line's price
     *   Dr/Cr 6-4400           the re-averaging gap between the two
     *
     * The gap leg is postCancellationRoundingJournal()'s, folded into the one
     * journal because — unlike a cancellation — this document builds its own
     * legs rather than mirroring an original through reverseFor(). Both P&L
     * legs keep the project, so the project ledger and the GL move together;
     * the 6-4400 leg carries none, exactly like an opname's.
     *
     * @param  array<string, float>  $byAccount  credit account code => slice
     */
    private function postIssueReturnJournal(IssueReturn $return, Issue $issue, array $byAccount, float $restored): void
    {
        $restored = round($restored, 2);
        $sliced = round(array_sum($byAccount), 2);

        if ($restored === 0.0 && $sliced === 0.0) {
            return; // free-issue slice (zero cost both ways): nothing to book
        }

        $lines = [];

        if ($restored !== 0.0) {
            $lines[] = [
                'account_code' => $this->inventoryAccountCode(),
                'debit' => $restored,
                'description' => "Retur material {$return->code} — {$issue->code}",
                'project_id' => $issue->project_id,
            ];
        }

        foreach ($byAccount as $accountCode => $amount) {
            $lines[] = [
                'account_code' => $accountCode,
                'credit' => $amount,
                'description' => "Retur material {$return->code} — {$issue->code}",
                'project_id' => $issue->project_id,
            ];
        }

        $difference = round($restored - $sliced, 2);

        if ($difference !== 0.0) {
            $lines[] = [
                'account_code' => $this->varianceAccountCode(),
                $difference > 0 ? 'credit' : 'debit' => round(abs($difference), 2),
                'description' => "Selisih pembulatan retur {$return->code}",
            ];
        }

        app(JournalService::class)->autoPost(
            'inventory_issue_return',
            (int) $return->id,
            $lines,
            $return->return_date->toDateString(),
            "Retur {$return->code} — pengembalian material bon {$issue->code}",
            $this->postingUserId($return->returned_by),
        );
    }

    /**
     * NEGATIVE fin_project_costs rows for the slice a retur takes back off the
     * project — the ledger's realisasi then agrees with the GL by the same
     * amount the journal credited 5-xxxx.
     *
     * New rows keyed ('inventory_issue_return_item', line id), never edits of
     * the bon's own rows: forward-only, per line so the WBS attribution of the
     * issue line survives (the variance report reads it back out), and the
     * idempotency key (reference, category) cannot collide with anything the
     * issue wrote.
     *
     * @param  array<int, array{line: IssueReturnItem, issueLine: IssueItem, category: CostCategory}>  $costLines
     */
    private function recordIssueReturnProjectCost(IssueReturn $return, Issue $issue, array $costLines): void
    {
        if ($issue->project_id === null || $costLines === []) {
            return;
        }

        if (! class_exists(ProjectCostService::class) || ! Schema::hasTable('fin_project_costs')) {
            return;
        }

        $projectCosts = app(ProjectCostService::class);

        foreach ($costLines as $costLine) {
            $line = $costLine['line'];
            $issueLine = $costLine['issueLine'];
            $amount = round((float) $line->amount, 2);

            if ($amount === 0.0) {
                continue; // zero-cost slice: no journal, no cost row
            }

            $detail = $issueLine->item?->name ?? $issue->purpose;

            $projectCosts->record(
                (int) $issue->project_id,
                $return->return_date->toDateString(),
                $costLine['category'],
                'inventory_issue_return_item',
                (int) $line->id,
                Str::limit("Retur material {$return->code} — {$detail}", 497),
                -$amount,
                $issueLine->wbs_task_id !== null ? (int) $issueLine->wbs_task_id : null,
            );
        }
    }

    /**
     * A returned slice may only reverse clearing that is still UNBILLED.
     *
     * The receipt's recorded credit is swept exactly once, by whichever bill
     * reaches it first (ApBillService reads gl_clearing_amount at approval).
     * Once swept, the liability is a real Hutang Usaha somebody approved:
     * a stock document must not rewrite it, so the money side of a post-bill
     * return is a vendor credit note through Keuangan. What remains unbilled
     * is the recorded amount minus what non-cancelled bills already cleared —
     * counted by BOTH routes a bill can take (the receipt itself, or the PO
     * of a GR/IR receipt), the same double-entry ApBillService::
     * clearedAgainstReceipts() does, so the two ends cannot disagree.
     *
     * A receipt with NO recorded clearing has nothing a return could reverse:
     * opening stock credited equity (nobody is owed a refund for it), and a
     * receipt posted under periodic raised no journal at all — the parameter
     * never re-decides what an earlier posting already did, in either
     * direction.
     */
    private function assertReturnStaysWithinUnbilledClearing(PurchaseReturn $return, GoodsReceipt $grn, float $slice): void
    {
        if (! $grn->hasRecordedClearing()) {
            throw new LogicException(
                "Penerimaan {$grn->code} tidak mencatat kewajiban vendor yang masih bisa dibalik — stok awal, "
                .'penerimaan yang diposting tanpa jurnal, atau kreditnya sudah habis ditagih/diretur. '
                ."Retur {$return->code} tidak punya kredit untuk dikembalikan; selesaikan lewat nota kredit "
                .'vendor di Keuangan, dan keluarkan barangnya lewat opname bila memang harus keluar.'
            );
        }

        $outstanding = round($grn->recordedClearingAmount() - $this->clearedByBills($grn), 2);

        // 0.005 absorbs decimal(18,2) rounding, the same order applyOut's
        // quantity tolerance covers on the other axis.
        if ($slice <= $outstanding + 0.005) {
            return;
        }

        throw new LogicException(sprintf(
            'Retur %s senilai %s melebihi sisa penerimaan %s yang belum ditagih (%s). Bagian yang sudah '
            .'disapu tagihan vendor adalah hutang yang telah disetujui — mintakan nota kredit vendor dan '
            .'bukukan lewat Keuangan, bukan lewat dokumen stok.',
            $return->code,
            number_format($slice, 2, ',', '.'),
            $grn->code,
            number_format(max($outstanding, 0.0), 2, ',', '.'),
        ));
    }

    /**
     * Clearing already swept off ONE receipt by vendor bills, by either route —
     * a bill keyed on the receipt itself, or on the purchase order when the
     * receipt credited GR/IR. The Inventory-side reading of ApBillService::
     * clearedAgainstReceipts(), guarded the same way every other Finance read
     * in this class is: no bills table, nothing swept.
     */
    private function clearedByBills(GoodsReceipt $grn): float
    {
        if (! Schema::hasTable('fin_ap_bills') || ! Schema::hasColumn('fin_ap_bills', 'gl_cleared_amount')) {
            return 0.0;
        }

        // Penagihan parsial per-GRN: kliring yang tersapu MILIK GRN INI ada di
        // baris pivot-nya sendiri (fin_ap_bill_goods_receipts.cleared_amount).
        // Menjumlah pool PO di sini membuat retur atas GRN-B dinilai dari
        // keadaan tagihan GRN-A — ditolak padahal kliringnya utuh, atau
        // sebaliknya. Pool hanya benar untuk PO pola lama: satu tagihan final
        // menyapu seluruh penerimaan tanpa baris pivot.
        if (Schema::hasTable('fin_ap_bill_goods_receipts')) {
            $ownSlice = DB::table('fin_ap_bill_goods_receipts as claim')
                ->join('fin_ap_bills as bill', 'bill.id', '=', 'claim.ap_bill_id')
                ->whereNull('bill.deleted_at')
                ->where('bill.status', '!=', DocumentStatus::Cancelled->value)
                ->where('claim.goods_receipt_id', $grn->getKey())
                ->sum('claim.cleared_amount');

            $poBilledPerReceipt = $grn->purchase_order_id !== null
                && DB::table('fin_ap_bill_goods_receipts as claim')
                    ->join('fin_ap_bills as bill', 'bill.id', '=', 'claim.ap_bill_id')
                    ->whereNull('bill.deleted_at')
                    ->where('bill.purchase_order_id', $grn->purchase_order_id)
                    ->exists();

            if ($poBilledPerReceipt) {
                $direct = DB::table('fin_ap_bills')
                    ->whereNull('deleted_at')
                    ->where('status', '!=', DocumentStatus::Cancelled->value)
                    ->where('goods_receipt_id', $grn->getKey())
                    ->sum('gl_cleared_amount');

                return round((float) $ownSlice + (float) $direct, 2);
            }
        }

        $cleared = DB::table('fin_ap_bills')
            ->whereNull('deleted_at')
            ->where('status', '!=', DocumentStatus::Cancelled->value)
            ->where(function ($query) use ($grn): void {
                $query->where('goods_receipt_id', $grn->getKey());

                if ($grn->purchase_order_id !== null
                    && (string) $grn->gl_clearing_account === $this->clearingAccountCode()) {
                    $query->orWhere('purchase_order_id', $grn->purchase_order_id);
                }
            })
            ->sum('gl_cleared_amount');

        return round((float) $cleared, 2);
    }

    /**
     * Journal of a retur pembelian — the receipt's slice reversed on the exact
     * account the receipt recorded, costing rule 2 on the stock leg:
     *
     *   Dr gl_clearing_account   slice at the receipt line's price
     *   Cr 1-1400 Persediaan     what the stored balance actually lost
     *   Dr/Cr 6-4400             the gap — the average has moved since receipt
     *
     * Debiting the recorded account (GR/IR or the 2-1600 accrual), never one
     * re-derived from the PO's shape or today's parameters, is the same rule
     * ApBillService clears bills by; the two documents therefore always fight
     * over the same credit and the account nets to zero when receipt, returns
     * and bill are done.
     */
    private function postPurchaseReturnJournal(PurchaseReturn $return, GoodsReceipt $grn, float $slice, float $released): void
    {
        $lines = [
            [
                'account_code' => (string) $grn->gl_clearing_account,
                'debit' => $slice,
                'description' => "Retur pembelian {$return->code} — batal tagih {$grn->code}",
            ],
        ];

        if ($released !== 0.0) {
            $lines[] = [
                'account_code' => $this->inventoryAccountCode(),
                'credit' => $released,
                'description' => "Retur pembelian {$return->code} — barang keluar gudang",
            ];
        }

        $difference = round($slice - $released, 2);

        if ($difference !== 0.0) {
            $lines[] = [
                'account_code' => $this->varianceAccountCode(),
                $difference > 0 ? 'credit' : 'debit' => round(abs($difference), 2),
                'description' => "Selisih nilai retur {$return->code}",
            ];
        }

        app(JournalService::class)->autoPost(
            'inventory_purchase_return',
            (int) $return->id,
            $lines,
            $return->return_date->toDateString(),
            "Retur {$return->code} — pengembalian barang ke vendor atas {$grn->code}",
            $this->postingUserId($return->returned_by),
        );
    }

    /**
     * Reverse every journal the bon posted, then true 1-1400 up to the rupiah
     * the stock sub-ledger actually gave back.
     *
     * The mirrors are read off the ORIGINAL LINES by JournalService::reverseFor,
     * whatever shape they had, so a bon whose cost was later reclassified is
     * unwound through BOTH entries and nothing is left tagged to the wrong
     * account or the wrong project. All of them carry the SAME date as the stock
     * mirror, so the two books never disagree about which month the cancellation
     * happened in.
     *
     * @param  float  $restored  rupiah the stock sub-ledger gained back
     */
    private function reverseIssueJournals(Issue $issue, string $reason, string $on, float $restored, ?int $userId): void
    {
        $reversed = false;

        foreach (self::ISSUE_JOURNAL_REFERENCE_TYPES as $referenceType) {
            // Only what exists: a bon posted under periodic inventory, or one
            // whose whole value rounded to zero, never raised a journal at all,
            // and reverseFor() refuses (rightly) when there is nothing to
            // reverse. The reclass exists on exactly the documents migration
            // 000496 repaired.
            if (! $this->hasPostedJournal($referenceType, (int) $issue->id)) {
                continue;
            }

            app(JournalService::class)->reverseFor(
                $referenceType,
                (int) $issue->id,
                self::CANCELLATION_REFERENCE_TYPE,
                "Pembatalan bon {$issue->code} — {$reason}",
                $this->postingUserId($userId),
                $on,
            );

            $reversed = true;
        }

        if (! $reversed) {
            return;
        }

        $this->postCancellationRoundingJournal(
            self::CANCELLATION_REFERENCE_TYPE,
            (int) $issue->id,
            $on,
            $restored,
            $userId,
            "Selisih pembulatan pembatalan bon {$issue->code}",
            "Issue {$issue->code} — selisih pembulatan harga rata-rata atas pembatalan",
        );
    }

    /**
     * reverseIssueJournals()'s twin for a cancelled receipt: reverse every
     * journal the receipt posted — its own AND migration 001196's opening-stock
     * reclass, see RECEIPT_JOURNAL_REFERENCE_TYPES — then true 1-1400 up to the
     * rupiah the stock sub-ledger actually gave up.
     *
     * Whether anything is reversed follows what EXISTS, never today's
     * accounting.perpetual_inventory: a receipt posted under perpetual carries
     * its journal after a flip to periodic, and skipping the reversal then
     * would leave 1-1400 and 2-1150 carrying a receipt whose stock is gone —
     * the same method-flip hole postPurchaseReturn() closes for the slice. A
     * receipt that never raised a journal (periodic, or zero value) reverses
     * nothing, and posting a gap journal against nothing would invent money.
     *
     * @param  float  $released  rupiah the stock sub-ledger lost (negative)
     */
    private function reverseReceiptJournals(GoodsReceipt $grn, string $reason, string $on, float $released, ?int $userId): void
    {
        $reversed = false;

        foreach (self::RECEIPT_JOURNAL_REFERENCE_TYPES as $referenceType) {
            if (! $this->hasPostedJournal($referenceType, (int) $grn->id)) {
                continue;
            }

            app(JournalService::class)->reverseFor(
                $referenceType,
                (int) $grn->id,
                self::RECEIPT_CANCELLATION_REFERENCE_TYPE,
                "Pembatalan penerimaan {$grn->code} — {$reason}",
                $this->postingUserId($userId),
                $on,
            );

            $reversed = true;
        }

        if (! $reversed) {
            return;
        }

        $this->postCancellationRoundingJournal(
            self::RECEIPT_CANCELLATION_REFERENCE_TYPE,
            (int) $grn->id,
            $on,
            $released,
            $userId,
            "Selisih pembulatan pembatalan penerimaan {$grn->code}",
            "GRN {$grn->code} — selisih pembulatan harga rata-rata atas pembatalan",
        );
    }

    /**
     * The difference between what the stock balance moved and what the mirrors
     * gave 1-1400, booked as what it is.
     *
     * Costing rule 2 says every GL leg is the change in the stored balance. The
     * mirrors cannot obey it on their own: they are the arithmetic inverse of
     * the ORIGINAL journal, while the mirror stock movement re-averages a
     * balance that may have moved since. Receive 1000 @ 33.333,33, issue 777,
     * receive 999 @ 77.777,77, then cancel the bon: the sub-ledger comes back to
     * Rp 111.033.335,56 while GL 1-1400 lands on Rp 111.033.328,03 — a Rp 7,53
     * residue that raises erp:inventory-method-check's tie-out blocker
     * ("Find the document behind it") with no document behind it, because the
     * cancellation IS the document. Its own tolerance, max(0,01, 0,01 x balance
     * rows), is far below the break.
     *
     * TWO CALLERS, ONE SIGN CONVENTION. $balanceMoved is SIGNED by the balance
     * itself, exactly as applyIn/applyOut report it: positive for a bon's
     * mirror (stock came back), negative for a receipt's (stock left). The
     * subtraction against postedNetOn() — itself signed debit-minus-credit —
     * then lands the gap on the correct side in both directions, so a receipt
     * cancelled after the average dropped DEBITS 1-1400 by what the mirrors
     * over-credited, and the identity "GL 1-1400 == sum(qty * avg_cost)" holds
     * for either document.
     *
     * It is a stock VALUATION difference, so it lands where every other one
     * does — 6-4400 Selisih Persediaan, the account postAdjustment() uses, with
     * the same debit/credit shape. Crediting the expense side instead would push
     * the residue into the project P&L and re-open the gap against
     * fin_project_costs, whose rows an issue cancellation removes in full.
     */
    private function postCancellationRoundingJournal(
        string $referenceType,
        int $referenceId,
        string $on,
        float $balanceMoved,
        ?int $userId,
        string $lineDescription,
        string $journalDescription,
    ): void {
        $inventory = $this->inventoryAccountCode();

        $difference = round($balanceMoved - $this->postedNetOn($inventory, $referenceType, $referenceId), 2);

        if ($difference === 0.0) {
            return; // the ordinary case: nothing re-averaged in between
        }

        $amount = round(abs($difference), 2);
        $variance = $this->varianceAccountCode();

        app(JournalService::class)->autoPost(
            $referenceType,
            $referenceId,
            [
                [
                    'account_code' => $difference > 0 ? $inventory : $variance,
                    'debit' => $amount,
                    'description' => $lineDescription,
                ],
                [
                    'account_code' => $difference > 0 ? $variance : $inventory,
                    'credit' => $amount,
                    'description' => $lineDescription,
                ],
            ],
            $on,
            $journalDescription,
            $this->postingUserId($userId),
        );
    }

    /**
     * Debit-minus-credit a document's posted journals left on one account.
     */
    private function postedNetOn(string $accountCode, string $referenceType, int $referenceId): float
    {
        if (! Schema::hasTable('fin_journal_lines') || ! Schema::hasTable('fin_accounts')) {
            return 0.0;
        }

        $row = DB::table('fin_journal_lines as l')
            ->join('fin_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('fin_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereNull('j.deleted_at')
            ->where('j.status', 'posted')
            ->where('j.reference_type', $referenceType)
            ->where('j.reference_id', $referenceId)
            ->where('a.code', $accountCode)
            ->selectRaw('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) as net')
            ->first();

        return round((float) ($row->net ?? 0), 2);
    }

    /**
     * The one date a cancellation happens on, for the stock mirror and every
     * reversing journal alike.
     *
     * Finance's rule decides whether the bon's own date is still available at
     * all. The stock ledger adds a second condition Finance cannot know about:
     * the mirror is a movement, and a movement dated behind one already recorded
     * for that (warehouse, item) makes inv_stock_ledger.balance_qty_after — a
     * running total written in INSERTION order — stop being a running total.
     * So if anything has moved that stock since the bon, the cancellation is an
     * event of TODAY, which is also the honest description of it.
     *
     * The remaining case, a movement dated in the FUTURE, is left to
     * assertMovementInOrder() in the loop: there is no date that both keeps the
     * card straight and stays out of a month nobody may post into, and inventing
     * one silently is what this pass is removing.
     */
    private function issueCancellationDate(Issue $issue): string
    {
        $onItsOwnDate = $this->stockEventDate($issue->issue_date->toDateString());

        $lastMovement = $this->lastMovementDate(
            (int) $issue->warehouse_id,
            $issue->items()->pluck('item_id')->map(fn ($id): int => (int) $id)->all(),
        );

        if ($lastMovement === null || $lastMovement <= $onItsOwnDate) {
            return $onItsOwnDate;
        }

        // Today has to be a day a movement may be recorded at all; if it is not,
        // the operator hears that rather than have the mirror fall silently into
        // a month the fiscal calendar has shut.
        $today = now()->toDateString();

        $this->assertStockPeriodOpen($today);

        return $today;
    }

    /**
     * issueCancellationDate()'s rule, second caller: the receipt's own date
     * while that is still the last word for every (warehouse, item) it touched
     * — Finance's reversalDate() deciding whether that date is even available —
     * otherwise today, and today itself must be a day a movement may be
     * recorded at all. The remaining case, a movement dated in the FUTURE, is
     * left to assertMovementInOrder() in cancelReceipt()'s loop, for the
     * reason stated there: no honest date exists, and inventing one silently
     * is what these cancellations refuse to do.
     */
    private function receiptCancellationDate(GoodsReceipt $grn): string
    {
        $onItsOwnDate = $this->stockEventDate($grn->receipt_date->toDateString());

        $lastMovement = $this->lastMovementDate(
            (int) $grn->warehouse_id,
            $grn->items()->pluck('item_id')->map(fn ($id): int => (int) $id)->all(),
        );

        if ($lastMovement === null || $lastMovement <= $onItsOwnDate) {
            return $onItsOwnDate;
        }

        $today = now()->toDateString();

        $this->assertStockPeriodOpen($today);

        return $today;
    }

    /**
     * Date of the last movement recorded for any of these items in a warehouse.
     *
     * @param  array<int, int>  $itemIds
     */
    private function lastMovementDate(int $warehouseId, array $itemIds): ?string
    {
        if ($itemIds === []) {
            return null;
        }

        $last = DB::table('inv_stock_ledger')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->max('trx_date');

        return $last === null ? null : substr((string) $last, 0, 10);
    }

    /**
     * Where a movement whose paperwork is older than the calendar lands, asked
     * through Finance's own rule so stock and the ledger can never disagree:
     * the document's own date while its period is open and no posted PSAK 115
     * run has measured it, otherwise today. Without a fiscal calendar there is
     * no rule to consult, and the event is one of today.
     *
     * Two callers, one rule: a cancellation (see issueCancellationDate) and the
     * receipt of an in-transit transfer (see receiveTransfer).
     */
    private function stockEventDate(string $documentDate): string
    {
        if (! $this->ledgerPostingEnabled() || ! Schema::hasTable('fin_fiscal_periods')) {
            return now()->toDateString();
        }

        return app(JournalService::class)->reversalDate($documentDate);
    }

    private function hasPostedJournal(string $referenceType, int $referenceId): bool
    {
        if (! Schema::hasTable('fin_journals')) {
            return false;
        }

        return DB::table('fin_journals')
            ->whereNull('deleted_at')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'posted')
            ->exists();
    }

    /**
     * Opname variance, valued at the warehouse average:
     *
     *   surplus  (net > 0)  Dr 1-1400 Persediaan / Cr 6-4400 Selisih Persediaan
     *   shortage (net < 0)  Dr 6-4400 Selisih Persediaan / Cr 1-1400 Persediaan
     *
     * Adjustments never carry a project, so the difference stays in operating
     * expense and out of the project cost ledger.
     */
    private function postAdjustmentJournal(StockAdjustment $adjustment, float $netValue): void
    {
        $netValue = round($netValue, 2);

        if ($netValue === 0.0) {
            return; // counted value equals system value: nothing to book
        }

        $amount = round(abs($netValue), 2);
        $inventory = $this->inventoryAccountCode();
        $variance = $this->varianceAccountCode();

        $debitCode = $netValue > 0 ? $inventory : $variance;
        $creditCode = $netValue > 0 ? $variance : $inventory;

        app(JournalService::class)->autoPost(
            'stock_adjustment',
            (int) $adjustment->id,
            [
                [
                    'account_code' => $debitCode,
                    'debit' => $amount,
                    'description' => "Selisih opname {$adjustment->code}",
                ],
                [
                    'account_code' => $creditCode,
                    'credit' => $amount,
                    'description' => "Selisih opname {$adjustment->code}",
                ],
            ],
            $adjustment->adjustment_date->toDateString(),
            "Adjustment {$adjustment->code} — selisih persediaan",
            $this->postingUserId(),
        );
    }

    /**
     * Perpetual GL postings run only when the parameter says so AND Finance is
     * actually usable. Guarded like registerPoReceipt(): Inventory must keep
     * working standalone, so a missing Finance module silently degrades to
     * periodic inventory instead of blocking the stock movement.
     */
    private function ledgerPostingEnabled(): bool
    {
        if (! Erp::setting('accounting.perpetual_inventory', true)) {
            return false;
        }

        if (! class_exists(JournalService::class)
            || ! Schema::hasTable('fin_journals')
            || ! Schema::hasTable('fin_accounts')) {
            return false;
        }

        // Bootstrap order: Inventory seeds before Finance, so the chart of
        // accounts can still be empty here. Nothing to post against yet.
        return Account::query()->exists();
    }

    /**
     * Alat bantu (tool) is booked as equipment cost; every other item type —
     * material, sparepart, merchandise — is material cost.
     */
    private function issueCostCategory(?Item $item): CostCategory
    {
        return $item?->item_type === ItemType::Tool
            ? CostCategory::Equipment
            : CostCategory::Material;
    }

    private function inventoryAccountCode(): string
    {
        return Erp::string('accounting.inventory_account', self::DEFAULT_INVENTORY_ACCOUNT);
    }

    private function clearingAccountCode(): string
    {
        return Erp::string('accounting.grn_clearing_account', self::DEFAULT_CLEARING_ACCOUNT);
    }

    /**
     * Liability for a delivery from a known vendor received without a PO. It is
     * cleared by a manual AP bill that references the receipt.
     */
    private function accrualAccountCode(): string
    {
        return Erp::string('accounting.receipt_accrual_account', self::DEFAULT_ACCRUAL_ACCOUNT);
    }

    /**
     * Stock variance (selisih persediaan) — an OPERATING EXPENSE, and rightly
     * so for the opname path: goods counted short really were lost. It is not
     * the account for stock that arrives without a counterparty; see
     * openingBalanceAccountCode().
     */
    private function varianceAccountCode(): string
    {
        return Erp::string('accounting.stock_variance_account', self::DEFAULT_VARIANCE_ACCOUNT);
    }

    /**
     * Equity account carrying the counter-entry of stock that simply exists —
     * the opening balance at go-live, or found stock booked in as a receipt.
     * Nothing is owed to anyone and no margin was earned, so the only truthful
     * counter-entry to the asset is equity; 3-3100 Saldo Awal holds it until an
     * accountant closes it to Modal Disetor / Laba Ditahan.
     */
    private function openingBalanceAccountCode(): string
    {
        return Erp::string('accounting.opening_balance_account', self::DEFAULT_OPENING_BALANCE_ACCOUNT);
    }

    /**
     * Who posted the journal: the actor recorded on the document when it has
     * one (received_by / issued_by), else the authenticated user.
     */
    private function postingUserId(mixed $documentUserId = null): ?int
    {
        $userId = $documentUserId ?? auth()->id();

        return $userId !== null ? (int) $userId : null;
    }
}
