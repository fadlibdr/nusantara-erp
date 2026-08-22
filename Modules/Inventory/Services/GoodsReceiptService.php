<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Inventory\Enums\StockDocumentStatus;
use Modules\Inventory\Models\GoodsReceipt;

class GoodsReceiptService
{
    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $items = Arr::pull($data, 'items', []);

            $grn = new GoodsReceipt(Arr::except($data, ['code', 'status']));
            $grn->status = StockDocumentStatus::Draft;
            $grn->save(); // HasDocumentNumber fills the GRN code

            $this->syncItems($grn, $items);

            return $grn->load('items.item', 'warehouse');
        });
    }

    public function update(GoodsReceipt $grn, array $data): GoodsReceipt
    {
        $this->assertEditable($grn);

        return DB::transaction(function () use ($grn, $data): GoodsReceipt {
            $items = Arr::pull($data, 'items');

            $grn->fill(Arr::except($data, ['code', 'status']));
            $grn->save();

            if (is_array($items)) {
                $this->syncItems($grn, $items); // lines are replaced wholesale
            }

            return $grn->load('items.item', 'warehouse');
        });
    }

    public function delete(GoodsReceipt $grn): void
    {
        $this->assertEditable($grn);

        DB::transaction(function () use ($grn): void {
            $grn->items()->delete();
            $grn->delete();
        });
    }

    private function syncItems(GoodsReceipt $grn, array $items): void
    {
        $grn->items()->delete();

        foreach ($items as $item) {
            $qty = round((float) ($item['qty'] ?? 0), 3);
            $unitCost = round((float) ($item['unit_cost'] ?? 0), 2);

            $grn->items()->create([
                'item_id' => $item['item_id'],
                'po_item_id' => $item['po_item_id'] ?? null,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'amount' => round($qty * $unitCost, 2),
            ]);
        }
    }

    private function assertEditable(GoodsReceipt $grn): void
    {
        if (! $grn->status->isEditable()) {
            throw new LogicException("GRN {$grn->code} is {$grn->status->value} and can no longer be modified.");
        }
    }
}
