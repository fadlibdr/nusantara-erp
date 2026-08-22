<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\GoodsReceiptCancelRequest;
use Modules\Inventory\Http\Requests\GoodsReceiptStoreRequest;
use Modules\Inventory\Http\Requests\GoodsReceiptUpdateRequest;
use Modules\Inventory\Http\Resources\GoodsReceiptResource;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Services\GoodsReceiptService;
use Modules\Inventory\Services\StockService;

class GoodsReceiptController extends ApiController
{
    public function __construct(
        private readonly GoodsReceiptService $service,
        private readonly StockService $stockService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = GoodsReceipt::query()
            ->with('warehouse')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('delivery_note_no', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, GoodsReceiptResource::class,
            sortable: ['code', 'receipt_date', 'delivery_note_no', 'status'], dateColumn: 'receipt_date');
    }

    public function store(GoodsReceiptStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['received_by'] = $request->user()?->id;

        $grn = $this->service->create($data);

        return $this->created(GoodsReceiptResource::make($grn));
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        return $this->ok(GoodsReceiptResource::make($goodsReceipt->load('items.item', 'warehouse')));
    }

    public function update(GoodsReceiptUpdateRequest $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        try {
            $grn = $this->service->update($goodsReceipt, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GoodsReceiptResource::make($grn));
    }

    public function destroy(GoodsReceipt $goodsReceipt): JsonResponse
    {
        try {
            $this->service->delete($goodsReceipt);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'GRN deleted.');
    }

    public function post(GoodsReceipt $goodsReceipt): JsonResponse
    {
        try {
            $grn = $this->stockService->postReceipt($goodsReceipt);
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GoodsReceiptResource::make($grn), 'GRN posted; stock and moving average updated.');
    }

    /**
     * The whole-document way back for a GRN posted in error (audit T37), where
     * the retur pembelian is the partial one. Gated on inv.post, not
     * inv.delete: this raises a mirror stock movement, a reversing journal and
     * a PO reopening, so it is a posting act — the same reasoning as
     * IssueController::cancel.
     */
    public function cancel(GoodsReceiptCancelRequest $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        try {
            $grn = $this->stockService->cancelReceipt(
                $goodsReceipt,
                (string) $request->validated('reason'),
                $request->user()?->id,
            );
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GoodsReceiptResource::make($grn), 'Penerimaan dibatalkan; stok ditarik kembali, jurnalnya dibalik, dan kuantitas PO dikembalikan.');
    }
}
