<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\DocumentStatus;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockBalance;

class StockAdjustmentService
{
    public function __construct(private readonly StockService $stockService) {}

    public function create(array $data): StockAdjustment
    {
        return DB::transaction(function () use ($data): StockAdjustment {
            $items = Arr::pull($data, 'items', []);

            $adjustment = new StockAdjustment(Arr::except($data, ['code', 'status', 'posted_at']));
            $adjustment->status = DocumentStatus::Draft;
            $adjustment->save(); // HasDocumentNumber fills the ADJ code

            $this->syncItems($adjustment, $items);

            return $adjustment->load('items.item', 'warehouse');
        });
    }

    public function update(StockAdjustment $adjustment, array $data): StockAdjustment
    {
        $this->assertEditable($adjustment);

        return DB::transaction(function () use ($adjustment, $data): StockAdjustment {
            $items = Arr::pull($data, 'items');

            $adjustment->fill(Arr::except($data, ['code', 'status', 'posted_at']));
            $adjustment->save();

            if (is_array($items)) {
                $this->syncItems($adjustment, $items); // lines are replaced wholesale
            }

            return $adjustment->load('items.item', 'warehouse');
        });
    }

    public function delete(StockAdjustment $adjustment): void
    {
        $this->assertEditable($adjustment);

        DB::transaction(function () use ($adjustment): void {
            $adjustment->items()->delete();
            $adjustment->delete();
        });
    }

    /**
     * Approval and ledger posting are one atomic step: if the posting fails
     * (e.g. stock already consumed since the count), the approval rolls back
     * and the document stays submitted for correction.
     */
    public function approveAndPost(StockAdjustment $adjustment, User $by, ?string $note = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $by, $note): StockAdjustment {
            $adjustment->approve($by, $note);

            return $this->stockService->postAdjustment($adjustment);
        });
    }

    /**
     * Lines snapshot the current system quantity so the opname sheet shows
     * exactly what the system believed at counting time.
     */
    private function syncItems(StockAdjustment $adjustment, array $items): void
    {
        $adjustment->items()->delete();

        foreach ($items as $item) {
            $countedQty = round((float) ($item['counted_qty'] ?? 0), 3);

            $systemQty = round((float) StockBalance::query()
                ->where('warehouse_id', $adjustment->warehouse_id)
                ->where('item_id', $item['item_id'])
                ->value('qty'), 3);

            $adjustment->items()->create([
                'item_id' => $item['item_id'],
                'system_qty' => $systemQty,
                'counted_qty' => $countedQty,
                'diff_qty' => round($countedQty - $systemQty, 3),
                'unit_cost' => 0, // valued at warehouse avg cost when posted
            ]);
        }
    }

    private function assertEditable(StockAdjustment $adjustment): void
    {
        if (! $adjustment->status->isEditable()) {
            throw new LogicException("Adjustment {$adjustment->code} is {$adjustment->status->value} and can no longer be modified.");
        }
    }
}
