<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Models\Transfer;

class TransferService
{
    public function create(array $data): Transfer
    {
        return DB::transaction(function () use ($data): Transfer {
            $items = Arr::pull($data, 'items', []);

            $transfer = new Transfer(Arr::except($data, ['code', 'status']));
            $transfer->status = TransferStatus::Draft;
            $transfer->save(); // HasDocumentNumber fills the TRF code

            $this->syncItems($transfer, $items);

            return $transfer->load('items.item', 'fromWarehouse', 'toWarehouse');
        });
    }

    public function update(Transfer $transfer, array $data): Transfer
    {
        $this->assertEditable($transfer);

        return DB::transaction(function () use ($transfer, $data): Transfer {
            $items = Arr::pull($data, 'items');

            $transfer->fill(Arr::except($data, ['code', 'status']));
            $transfer->save();

            if (is_array($items)) {
                $this->syncItems($transfer, $items); // lines are replaced wholesale
            }

            return $transfer->load('items.item', 'fromWarehouse', 'toWarehouse');
        });
    }

    public function delete(Transfer $transfer): void
    {
        $this->assertEditable($transfer);

        DB::transaction(function () use ($transfer): void {
            $transfer->items()->delete();
            $transfer->delete();
        });
    }

    private function syncItems(Transfer $transfer, array $items): void
    {
        $transfer->items()->delete();

        foreach ($items as $item) {
            $transfer->items()->create([
                'item_id' => $item['item_id'],
                'qty' => round((float) ($item['qty'] ?? 0), 3),
                'unit_cost' => 0, // frozen at the source avg cost when sent
            ]);
        }
    }

    private function assertEditable(Transfer $transfer): void
    {
        if (! $transfer->status->isEditable()) {
            throw new LogicException("Transfer {$transfer->code} is {$transfer->status->value} and can no longer be modified.");
        }
    }
}
