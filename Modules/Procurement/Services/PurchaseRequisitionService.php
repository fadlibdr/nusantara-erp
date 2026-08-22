<?php

namespace Modules\Procurement\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Procurement\Models\PurchaseRequisition;

class PurchaseRequisitionService
{
    public function create(array $data): PurchaseRequisition
    {
        return DB::transaction(function () use ($data): PurchaseRequisition {
            $items = Arr::pull($data, 'items', []);

            $pr = new PurchaseRequisition(Arr::except($data, ['code', 'status']));
            $pr->status = DocumentStatus::Draft;
            $pr->save(); // HasDocumentNumber fills the PR code

            $this->syncItems($pr, $items);

            return $pr->load('items');
        });
    }

    public function update(PurchaseRequisition $pr, array $data): PurchaseRequisition
    {
        $this->assertEditable($pr);

        return DB::transaction(function () use ($pr, $data): PurchaseRequisition {
            $items = Arr::pull($data, 'items');

            $pr->fill(Arr::except($data, ['code', 'status']));
            $pr->save();

            if (is_array($items)) {
                $this->syncItems($pr, $items); // lines are replaced wholesale
            }

            return $pr->load('items');
        });
    }

    public function delete(PurchaseRequisition $pr): void
    {
        $this->assertEditable($pr);

        if ($pr->purchaseOrders()->exists()) {
            throw new LogicException("PR {$pr->code} already has purchase orders and cannot be deleted.");
        }

        $pr->delete();
    }

    private function syncItems(PurchaseRequisition $pr, array $items): void
    {
        $pr->items()->delete();

        $lineNo = 0;

        foreach ($items as $item) {
            $pr->items()->create([
                'line_no' => ++$lineNo,
                'item_id' => $item['item_id'] ?? null,
                'description' => $item['description'] ?? null,
                'qty' => round((float) ($item['qty'] ?? 1), 3),
                'unit' => $item['unit'] ?? null,
                'estimated_price' => round((float) ($item['estimated_price'] ?? 0), 2),
                'boq_item_id' => $item['boq_item_id'] ?? null,
            ]);
        }
    }

    private function assertEditable(PurchaseRequisition $pr): void
    {
        if (! $pr->status->isEditable()) {
            throw new LogicException("PR {$pr->code} is {$pr->status->value} and can no longer be edited.");
        }
    }
}
