<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Resources\StockBalanceResource;
use Modules\Inventory\Http\Resources\StockLedgerResource;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLedgerEntry;
use Modules\Inventory\Services\StockService;

class StockController extends ApiController
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * Balance rows plus meta.totals the page reduce cannot compute (audit
     * T28/T29). The screen used to sum stock_value over the ONE page it loaded,
     * so past per_page rows "Nilai persediaan" was silently short — on_hand_value
     * is therefore summed in SQL over the WHOLE filtered set. The in-transit
     * figure is StockService::inTransitValue(), the same method
     * erp:inventory-method-check quotes, so screen and CLI cannot drift; it is
     * always company-wide, because goods on the road sit in NEITHER warehouse
     * balance and no warehouse_id filter can claim them. owned_value
     * (on hand + in transit) is what ties to GL 1-1400 — as a reconciliation
     * identity that only holds on the unfiltered set, which is why the SPA
     * shows the transit tiles only there.
     */
    public function balances(Request $request): JsonResponse
    {
        $query = StockBalance::query()
            ->with('item.category', 'warehouse')
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('item_id'), fn ($query) => $query->where('item_id', $request->integer('item_id')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->whereHas('item', function ($item) use ($q): void {
                    $item->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->boolean('nonzero'), fn ($query) => $query->where('qty', '!=', 0))
            ->orderBy('warehouse_id')
            ->orderBy('item_id');

        // Summed BEFORE pagination, over a clone of the very same filters, so
        // the total and the rows can never describe two different sets. Rows
        // with qty = 0 contribute nothing, so the nonzero toggle cannot change
        // the figure — only which rows are listed.
        $onHand = round((float) (clone $query)->reorder()->sum(DB::raw('qty * avg_cost')), 2);
        $inTransit = $this->stockService->inTransitValue();

        return $this->listing($request, $query, StockBalanceResource::class, meta: [
            'totals' => [
                'on_hand_value' => $onHand,
                'in_transit_value' => $inTransit,
                'in_transit_transfers' => $this->stockService->inTransitTransferCount(),
                'owned_value' => round($onHand + $inTransit, 2),
            ],
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $query = StockLedgerEntry::query()
            ->with('item', 'warehouse')
            ->when($request->filled('item_id'), fn ($query) => $query->where('item_id', $request->integer('item_id')))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', $request->string('direction')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('trx_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('trx_date', '<=', $request->date('date_to')))
            ->orderBy('trx_date')
            ->orderBy('id');

        return $this->ok(StockLedgerResource::collection($query->paginate($request->integer('per_page', 20))));
    }

    public function lowStock(Request $request): JsonResponse
    {
        $warehouseId = $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null;

        return $this->ok($this->stockService->lowStockAlerts($warehouseId));
    }
}
