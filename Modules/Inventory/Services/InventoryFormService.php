<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\IssueReturn;
use Modules\Inventory\Models\PurchaseReturn;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\Warehouse;

/**
 * The body of the seven Persediaan house forms, in the taste of
 * Modules\Subcontract\Services\SubcontractFormService.
 *
 * ============================================================================
 * THE ONE DECISION THIS CLASS EXISTS FOR — an unvalued line is not a free one.
 *
 * Five of the seven stock documents are written with unit_cost = 0 and valued
 * only later, each with the same comment beside the zero in its own service:
 *
 *   inv_transfer_items          0 until sendTransfer() freezes the source avg
 *   inv_issue_items             0 until postIssue() freezes the warehouse avg
 *   inv_stock_adjustment_items  0 until postAdjustment() values the difference
 *   inv_purchase_return_items   0 until postPurchaseReturn() copies the receipt
 *   inv_issue_return_items      0 until postIssueReturn() copies the bon
 *
 * That zero means "nobody has worked out what this is worth yet". Printed as
 * "0,00" under a driver's or a storeman's signature it becomes a valuation:
 * the surat jalan is the paper an insurer is shown when a truck is robbed, and
 * the berita acara opname is the paper two people sign to say a shortfall cost
 * the company nothing. So every money cell on those five sheets — and the
 * document total with it — is RULED until the document itself says it has been
 * valued, and the test that fixes this is InventoryPrintTest.
 *
 * THE GRN IS THE DELIBERATE EXCEPTION. inv_goods_receipt_items.unit_cost is
 * typed by the receiving clerk when the receipt is raised, and a zero-cost line
 * has to be confirmed explicitly before the API accepts it (temuan #72). A zero
 * there is an assertion somebody made and prints as 0,00.
 * ============================================================================
 *
 * Everything else on these sheets is a straight read off a stored column, and
 * the registry entry reads it directly. What lands here is only what needs a
 * decision: the valuation gate above, the two figures the T28/T29 work exposed
 * (goods in transit belong to NEITHER warehouse balance), and the two notes
 * blocks that have something to say beyond what was typed into a notes column.
 */
class InventoryFormService
{
    /**
     * The as-at date of a sheet the database cannot date itself.
     *
     * inv_stock_balances keeps no history: qty and avg_cost are today's, and
     * nothing in this ERP can reconstruct what they were last month. So the
     * saldo sheet is dated by the PRINTER, and — because the registry's own
     * date wins over ?tanggal= — a URL cannot re-date it into a claim about a
     * month whose figures nobody kept.
     */
    public function printedOn(): Carbon
    {
        return Carbon::now()->startOfDay();
    }

    // ------------------------------------------------- penerimaan barang

    /**
     * What the delivery was worth, from the line amounts the receipt stored.
     *
     * Not gl_clearing_amount, which is a different question with a different
     * answer: that column is what the POSTING credited, and it is null under
     * periodic inventory, zero on a zero-value receipt and short of the
     * receipt value on a receipt already partly returned.
     */
    public function receiptTotal(GoodsReceipt $grn): float
    {
        return round((float) $grn->items->sum('amount'), 2);
    }

    /**
     * The receipt's own notes, plus the standing sentence that explains the
     * one column the ERP cannot answer.
     *
     * There is no qty_rejected on inv_goods_receipt_items and no rejection
     * document at all — a partial rejection is handled afterwards as a retur
     * pembelian — so the KONDISI column is ruled on every line and this says
     * why. Without it the column reads as an oversight and gets left empty.
     */
    public function receiptNotes(GoodsReceipt $grn): string
    {
        return $this->paragraphs([
            $grn->notes,
            $this->labelled('Pembatalan', $grn->cancellation_reason),
            'Kolom KONDISI / KETERANGAN diisi tangan: aplikasi ini tidak menyimpan jumlah barang ditolak, '
                .'sehingga penolakan ditulis pada barisnya dan diparaf penerima. Barang yang benar-benar '
                .'dikembalikan ke pemasok dicatat sebagai retur pembelian.',
        ]);
    }

    // ------------------------------------------------------- bon material

    /**
     * One row per bon line, with the HPP ruled until the bon has been posted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function issueLines(Issue $issue): array
    {
        $valued = $issue->status !== StockDocumentStatus::Draft;

        return $issue->items->values()->map(fn ($line, int $index): array => [
            'no' => $index + 1,
            'kode' => $line->item?->code,
            'uraian' => $line->item?->name,
            'wbs' => $line->wbsTask?->wbs_code,
            'qty' => $line->qty,
            'satuan' => $line->item?->unit,
            'hpp' => $valued ? (float) $line->unit_cost : null,
            'jumlah' => $valued ? (float) $line->amount : null,
        ])->all();
    }

    /** Ruled while the bon is a draft; see the class docblock. */
    public function issueTotal(Issue $issue): ?float
    {
        if ($issue->status === StockDocumentStatus::Draft) {
            return null;
        }

        return round((float) $issue->items->sum('amount'), 2);
    }

    /**
     * A cancelled bon has to SAY it is cancelled where the storeman is
     * already looking. The reversal lives in the ledger, which is not a place
     * anybody at the gudang will check before handing over material.
     */
    public function issueNotes(Issue $issue): ?string
    {
        return $this->paragraphs([
            $this->labelled('Pembatalan', $issue->cancellation_reason),
        ]) ?: null;
    }

    // ----------------------------------------------- surat jalan transfer

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transferLines(Transfer $transfer): array
    {
        // A draft has moved nothing and frozen nothing: TransferService writes
        // unit_cost 0 and sendTransfer replaces it with the source warehouse's
        // average at the moment the goods left.
        $valued = $transfer->status !== TransferStatus::Draft;

        return $transfer->items->values()->map(fn ($line, int $index): array => [
            'no' => $index + 1,
            'kode' => $line->item?->code,
            'uraian' => $line->item?->name,
            'qty' => $line->qty,
            'satuan' => $line->item?->unit,
            'harga' => $valued ? (float) $line->unit_cost : null,
            'jumlah' => $valued ? $this->lineValue($line->qty, $line->unit_cost) : null,
        ])->all();
    }

    /**
     * The value riding on the truck — the same qty x unit_cost
     * StockService::inTransitValue() sums company-wide, so this document's
     * slice and the Saldo Stok screen's total cannot describe it differently.
     */
    public function transferTotal(Transfer $transfer): ?float
    {
        if ($transfer->status === TransferStatus::Draft) {
            return null;
        }

        return round((float) $transfer->items->sum(
            fn ($line): float => $this->lineValue($line->qty, $line->unit_cost)
        ), 2);
    }

    // -------------------------------------------------- berita acara opname

    /**
     * @return array<int, array<string, mixed>>
     */
    public function opnameLines(StockAdjustment $adjustment): array
    {
        $valued = $adjustment->isPosted();

        return $adjustment->items->values()->map(fn ($line, int $index): array => [
            'no' => $index + 1,
            'kode' => $line->item?->code,
            'uraian' => $line->item?->name,
            'satuan' => $line->item?->unit,
            // system_qty and diff_qty are SNAPSHOTS taken when the sheet was
            // raised (StockAdjustmentService::syncItems), never recomputed
            // here: the count was made against what the system believed that
            // morning, and re-asking today would print a difference nobody
            // counted.
            'sistem' => $line->system_qty,
            'fisik' => $line->counted_qty,
            'selisih' => $line->diff_qty,
            'hpp' => $valued ? (float) $line->unit_cost : null,
            'nilai' => $valued ? $this->lineValue($line->diff_qty, $line->unit_cost) : null,
        ])->all();
    }

    /** The net write-off, and null until posting works out what it is. */
    public function opnameTotal(StockAdjustment $adjustment): ?float
    {
        if (! $adjustment->isPosted()) {
            return null;
        }

        return round((float) $adjustment->items->sum(
            fn ($line): float => $this->lineValue($line->diff_qty, $line->unit_cost)
        ), 2);
    }

    // ---------------------------------------------------------- saldo stok

    /**
     * Every balance row this warehouse holds, by item code.
     *
     * INCLUDING THE ZEROS, on purpose. A row of 0 means the item has been in
     * this warehouse and is now out — which is exactly the line a counting
     * team needs in front of them when they find three of it on a shelf. Only
     * items that have never moved here have no row at all.
     *
     * withTrashed on the item: an item soft-deleted since it last moved still
     * has stock and still has value, and a sheet that dropped its name would
     * hide the stock rather than the item.
     *
     * @return array<int, StockBalance>
     */
    public function warehouseBalances(Warehouse $warehouse): array
    {
        return StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            // withTrashed one level DOWN as well: inv_item_categories soft-deletes,
            // so an item whose category was retired printed its name, qty, HPP
            // and value with the KATEGORI column ruled beside them.
            ->with(['item' => fn ($query) => $query->withTrashed()
                ->with(['category' => fn ($inner) => $inner->withTrashed()])])
            ->get()
            ->sortBy(fn (StockBalance $row): string => (string) ($row->item?->code ?? ''))
            ->values()
            ->all();
    }

    /** What one balance row is worth, and the unit the total is built from. */
    public function balanceValue(StockBalance $balance): float
    {
        return $this->lineValue($balance->qty, $balance->avg_cost);
    }

    /** qty x avg_cost across the warehouse, the figure the sheet foots to. */
    public function warehouseStockValue(Warehouse $warehouse): float
    {
        return round((float) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->get()
            ->sum(fn (StockBalance $row): float => $this->lineValue($row->qty, $row->avg_cost)), 2);
    }

    /**
     * Goods that have left one warehouse and not arrived at the other, listed
     * from THIS warehouse's point of view — the figure T28/T29 found missing.
     *
     * Stock leaves the source balance on send and joins the destination
     * balance on receive, so for the whole transit window it is in NEITHER.
     * A stock take that does not know this reports a shortfall at one end and
     * a surplus at the other, and the two are never reconciled because nobody
     * knows they are the same twenty zak. Both directions are listed because
     * both are wrong in a different way: what went out is already off this
     * sheet's saldo, and what is coming in is not on it yet — and may well be
     * standing in the yard when the counters walk past it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inTransitLines(Warehouse $warehouse): array
    {
        $transfers = Transfer::query()
            ->where('status', TransferStatus::InTransit)
            ->where(function ($query) use ($warehouse): void {
                $query->where('from_warehouse_id', $warehouse->id)
                    ->orWhere('to_warehouse_id', $warehouse->id);
            })
            // withTrashed on both ends for the same reason the item carries it:
            // inv_warehouses soft-deletes, and goods still on the road between
            // two sheds must name where they left and where they are going.
            ->with([
                'items.item' => fn ($query) => $query->withTrashed(),
                'fromWarehouse' => fn ($query) => $query->withTrashed(),
                'toWarehouse' => fn ($query) => $query->withTrashed(),
            ])
            ->orderBy('transfer_date')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($transfers as $transfer) {
            $outbound = (int) $transfer->from_warehouse_id === (int) $warehouse->id;

            foreach ($transfer->items as $line) {
                $rows[] = [
                    'arah' => $outbound ? 'Keluar' : 'Masuk',
                    'lawan' => $outbound ? $transfer->toWarehouse?->name : $transfer->fromWarehouse?->name,
                    'transfer' => $transfer->code,
                    'tanggal' => $transfer->transfer_date,
                    'kode' => $line->item?->code,
                    'uraian' => $line->item?->name,
                    'qty' => $line->qty,
                    'satuan' => $line->item?->unit,
                    // Frozen on send, so every line here is valued — an
                    // in-transit transfer cannot still be a draft.
                    'harga' => (float) $line->unit_cost,
                    'nilai' => $this->lineValue($line->qty, $line->unit_cost),
                ];
            }
        }

        return $rows;
    }

    /** What has left this warehouse and not arrived anywhere yet. */
    public function inTransitOutValue(Warehouse $warehouse): float
    {
        return $this->transitTotal($warehouse, 'Keluar');
    }

    /** What is on the road towards this warehouse and not yet in its saldo. */
    public function inTransitInValue(Warehouse $warehouse): float
    {
        return $this->transitTotal($warehouse, 'Masuk');
    }

    // ------------------------------------------------------------- returns

    /**
     * @return array<int, array<string, mixed>>
     */
    public function purchaseReturnLines(PurchaseReturn $return): array
    {
        $valued = $return->status !== StockDocumentStatus::Draft;

        return $return->items->values()->map(fn ($line, int $index): array => [
            'no' => $index + 1,
            'kode' => $line->item?->code,
            'uraian' => $line->item?->name,
            // The mirror: what the receipt line brought in, beside what is
            // going back. A retur read without it says nothing about whether
            // the whole delivery was rejected or one crate of it.
            'qty_asal' => $line->receiptItem?->qty,
            'qty' => $line->qty,
            'satuan' => $line->item?->unit,
            'harga' => $valued ? (float) $line->unit_cost : null,
            'jumlah' => $valued ? (float) $line->amount : null,
        ])->all();
    }

    public function purchaseReturnTotal(PurchaseReturn $return): ?float
    {
        if ($return->status === StockDocumentStatus::Draft) {
            return null;
        }

        return round((float) $return->items->sum('amount'), 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function issueReturnLines(IssueReturn $return): array
    {
        $valued = $return->status !== StockDocumentStatus::Draft;

        return $return->items->values()->map(fn ($line, int $index): array => [
            'no' => $index + 1,
            'kode' => $line->item?->code,
            'uraian' => $line->item?->name,
            'qty_asal' => $line->issueItem?->qty,
            'qty' => $line->qty,
            'satuan' => $line->item?->unit,
            // The price the material LEFT at, which is the price it comes
            // back at (StockService::postIssueReturn) — never today's average.
            'harga' => $valued ? (float) $line->unit_cost : null,
            'jumlah' => $valued ? (float) $line->amount : null,
        ])->all();
    }

    public function issueReturnTotal(IssueReturn $return): ?float
    {
        if ($return->status === StockDocumentStatus::Draft) {
            return null;
        }

        return round((float) $return->items->sum('amount'), 2);
    }

    // ------------------------------------------------------------ internals

    private function transitTotal(Warehouse $warehouse, string $direction): float
    {
        return round(array_sum(array_map(
            fn (array $row): float => (float) $row['nilai'],
            array_filter($this->inTransitLines($warehouse), fn (array $row): bool => $row['arah'] === $direction),
        )), 2);
    }

    private function lineValue(mixed $qty, mixed $unitCost): float
    {
        return round((float) $qty * (float) $unitCost, 2);
    }

    /** "Pembatalan : ..." — or nothing at all when there is nothing to say. */
    private function labelled(string $label, ?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $label.' : '.$value;
    }

    /**
     * @param  array<int, ?string>  $blocks
     */
    private function paragraphs(array $blocks): string
    {
        return implode("\n", array_filter(
            array_map(fn (?string $block): string => trim((string) $block), $blocks),
            fn (string $block): bool => $block !== '',
        ));
    }
}
