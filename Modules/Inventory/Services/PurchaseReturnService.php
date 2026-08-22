<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\GoodsReceiptItem;
use Modules\Inventory\Models\PurchaseReturn;

/**
 * CRUD for the retur pembelian draft. The stock, the clearing reversal and the
 * PO hand-back all live in StockService::postPurchaseReturn(); this assembles
 * rows and refuses at the door the receipts no return can be posted against —
 * an unposted GRN, and one with no counterparty to take the goods back.
 */
class PurchaseReturnService
{
    public function create(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $items = Arr::pull($data, 'items', []);

            /** @var GoodsReceipt $grn */
            $grn = GoodsReceipt::query()->findOrFail($data['goods_receipt_id']);

            $this->assertReturnable($grn);

            $return = new PurchaseReturn(Arr::except($data, ['code', 'status', 'warehouse_id', 'vendor_id']));
            // Copied, never chosen: the goods leave the warehouse that received
            // them, towards the counterparty the receipt named.
            $return->warehouse_id = $grn->warehouse_id;
            $return->vendor_id = $this->counterpartyVendorId($grn);
            $return->status = StockDocumentStatus::Draft;
            $return->save(); // HasDocumentNumber fills the RPB code

            $this->syncItems($return, $grn, $items);

            return $return->load('items.item', 'goodsReceipt', 'warehouse');
        });
    }

    /**
     * One-click draft off the GRN's detail screen: every line's remaining
     * returnable quantity (received minus already returned through posted
     * returns), for the operator to trim down and post.
     */
    public function createFromReceipt(GoodsReceipt $grn, array $data): PurchaseReturn
    {
        $this->assertReturnable($grn);

        $items = [];

        foreach ($grn->items as $line) {
            $remaining = round((float) $line->qty - $line->qtyReturned(), 3);

            if ($remaining > 0) {
                $items[] = ['grn_item_id' => (int) $line->id, 'qty' => $remaining];
            }
        }

        if ($items === []) {
            throw new LogicException(
                "Seluruh barang penerimaan {$grn->code} sudah kembali ke vendor lewat retur sebelumnya; tidak ada sisa untuk diretur."
            );
        }

        return $this->create([
            'goods_receipt_id' => $grn->id,
            'return_date' => $data['return_date'] ?? now()->toDateString(),
            'returned_by' => $data['returned_by'] ?? null,
            'reason' => $data['reason'],
            'items' => $items,
        ]);
    }

    public function update(PurchaseReturn $return, array $data): PurchaseReturn
    {
        $this->assertEditable($return);

        return DB::transaction(function () use ($return, $data): PurchaseReturn {
            $items = Arr::pull($data, 'items');

            // goods_receipt_id, warehouse_id and vendor_id are immovable — the
            // requests never validate them, so validated() cannot carry them
            // here; a return re-pointed at another receipt would reverse a
            // clearing that receipt never recorded. Wrong receipt: delete the
            // draft and raise it again.
            $return->fill(Arr::except($data, ['code', 'status', 'goods_receipt_id', 'warehouse_id', 'vendor_id']));
            $return->save();

            if (is_array($items)) {
                $this->syncItems($return, $return->goodsReceipt()->firstOrFail(), $items); // lines are replaced wholesale
            }

            return $return->load('items.item', 'goodsReceipt', 'warehouse');
        });
    }

    public function delete(PurchaseReturn $return): void
    {
        $this->assertEditable($return);

        DB::transaction(function () use ($return): void {
            $return->items()->delete();
            $return->delete();
        });
    }

    /**
     * Replace the lines of a draft return. Every line must reference a line of
     * THE receipt this return names — that line's unit_cost is the slice of
     * receipt value the posting reverses, and a foreign line would reverse
     * some other document's price. item_id is copied from the receipt line:
     * the vendor cannot be handed back an article this receipt never brought.
     */
    private function syncItems(PurchaseReturn $return, GoodsReceipt $grn, array $items): void
    {
        $return->items()->delete();

        /** @var array<int, true> $seen receipt line ids already used by an earlier line of this payload */
        $seen = [];

        foreach ($items as $item) {
            /** @var GoodsReceiptItem $grnLine */
            $grnLine = GoodsReceiptItem::query()->findOrFail($item['grn_item_id']);

            // One receipt line, one retur line. The posting ceiling reads
            // qtyReturned() — posted documents only — per line, so two lines
            // naming the SAME receipt line each pass alone: 60 + 60 against a
            // 100-zak line hands the vendor back 120 zak he delivered 100 of.
            // postPurchaseReturn() counts siblings too; this refusal keeps the
            // honest operator out while the draft is still cheap.
            if (isset($seen[(int) $grnLine->id])) {
                throw new LogicException(
                    "Baris retur menunjuk baris penerimaan #{$grnLine->id} dua kali; satu baris penerimaan "
                    ."hanya boleh muncul sekali per retur {$return->code} — gabungkan kuantitasnya dalam satu baris."
                );
            }

            $seen[(int) $grnLine->id] = true;

            if ((int) $grnLine->goods_receipt_id !== (int) $grn->id) {
                throw new LogicException(
                    "Baris retur menunjuk baris penerimaan lain (#{$grnLine->id} milik GRN #{$grnLine->goods_receipt_id}); "
                    ."retur {$return->code} hanya boleh mengembalikan baris penerimaan {$grn->code}."
                );
            }

            $return->items()->create([
                'grn_item_id' => $grnLine->id,
                'item_id' => $grnLine->item_id,
                'qty' => round((float) ($item['qty'] ?? 0), 3),
                'unit_cost' => 0, // frozen from the receipt line at posting
                'amount' => 0,
            ]);
        }
    }

    /**
     * Only a POSTED receipt from a counterparty can take a return: a draft is
     * edited or deleted instead, and opening/found stock (no PO, no vendor)
     * has nobody to hand the goods back to — that exit is an opname.
     */
    private function assertReturnable(GoodsReceipt $grn): void
    {
        if ($grn->status !== StockDocumentStatus::Posted) {
            throw new LogicException(
                "Penerimaan {$grn->code} berstatus {$grn->status->value}; retur pembelian hanya dapat dibuat "
                .'atas penerimaan yang sudah diposting.'
            );
        }

        if ($grn->purchase_order_id === null && $grn->vendor_id === null) {
            throw new LogicException(
                "Penerimaan {$grn->code} tidak menyebut PO maupun vendor (stok awal); tidak ada pihak yang "
                .'bisa menerima retur. Keluarkan lewat opname bila barangnya memang harus keluar.'
            );
        }
    }

    /**
     * Who takes the goods back: the receipt's own vendor, else its purchase
     * order's. Read through the table, guarded, because Procurement owns it —
     * and stored on the return so the counterparty survives a later PO
     * soft-delete.
     */
    private function counterpartyVendorId(GoodsReceipt $grn): ?int
    {
        if ($grn->vendor_id !== null) {
            return (int) $grn->vendor_id;
        }

        if ($grn->purchase_order_id === null || ! Schema::hasTable('prc_purchase_orders')) {
            return null;
        }

        $vendorId = DB::table('prc_purchase_orders')->where('id', $grn->purchase_order_id)->value('vendor_id');

        return $vendorId !== null ? (int) $vendorId : null;
    }

    private function assertEditable(PurchaseReturn $return): void
    {
        if (! $return->status->isEditable()) {
            throw new LogicException("Retur {$return->code} berstatus {$return->status->value} dan tidak dapat diubah lagi.");
        }
    }
}
