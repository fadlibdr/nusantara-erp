<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Enums\TransferStatus;
use Modules\Inventory\Http\Requests\ItemStoreRequest;
use Modules\Inventory\Http\Requests\ItemUpdateRequest;
use Modules\Inventory\Http\Resources\ItemResource;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\TransferItem;

class ItemController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Item::query()
            ->with('category')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('barcode', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('item_type'), fn ($query) => $query->where('item_type', $request->string('item_type')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('code');

        return $this->listing($request, $query, ItemResource::class,
            sortable: ['code', 'name', 'item_type', 'min_stock', 'avg_cost', 'is_active']);
    }

    public function store(ItemStoreRequest $request): JsonResponse
    {
        $item = Item::query()->create($request->validated());

        return $this->created(ItemResource::make($item->load('category')));
    }

    public function show(Item $item): JsonResponse
    {
        return $this->ok(ItemResource::make($item->load('category', 'balances.warehouse')));
    }

    public function update(ItemUpdateRequest $request, Item $item): JsonResponse
    {
        $item->update($request->validated());

        return $this->ok(ItemResource::make($item->load('category')));
    }

    /**
     * Goods on the road are stock the company owns, and the balance guard cannot
     * see them: TransferStatus's own docblock says stock leaves the source on
     * send and arrives at the destination on receive, "so goods on the road are
     * visible in neither balance". A fully-transferred item therefore reads qty 0
     * everywhere and used to pass — after which receiveTransfer() dereferenced a
     * null relation, the transfer was wedged in_transit for ever with no restore
     * endpoint and no way to cancel it, and its value (Rp 11.500.000 of Kabel UTP
     * Cat6 in the probe) sat in 1-1400 with nothing in the sub-ledger behind it.
     */
    public function destroy(Item $item): JsonResponse
    {
        if ($item->balances()->where('qty', '>', 0)->exists()) {
            return $this->error('Item masih memiliki stok dan tidak dapat dihapus.');
        }

        $inTransit = TransferItem::query()
            ->where('item_id', $item->id)
            ->whereHas('transfer', fn ($transfer) => $transfer->where('status', TransferStatus::InTransit))
            ->exists();

        if ($inTransit) {
            return $this->error(
                'Item ini sedang dalam perjalanan antar gudang dan tidak dapat dihapus. '
                .'Terima dulu transfernya di gudang tujuan, baru hapus itemnya.'
            );
        }

        $item->delete();

        return $this->ok(null, 'Item deleted.');
    }
}
