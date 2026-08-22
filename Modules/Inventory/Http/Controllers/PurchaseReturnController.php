<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\DocumentReturnRequest;
use Modules\Inventory\Http\Requests\PurchaseReturnStoreRequest;
use Modules\Inventory\Http\Requests\PurchaseReturnUpdateRequest;
use Modules\Inventory\Http\Resources\PurchaseReturnResource;
use Modules\Inventory\Models\GoodsReceipt;
use Modules\Inventory\Models\PurchaseReturn;
use Modules\Inventory\Services\PurchaseReturnService;
use Modules\Inventory\Services\StockService;

class PurchaseReturnController extends ApiController
{
    public function __construct(
        private readonly PurchaseReturnService $service,
        private readonly StockService $stockService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseReturn::query()
            ->with(['warehouse', 'goodsReceipt'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('reason', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('goods_receipt_id'), fn ($query) => $query->where('goods_receipt_id', $request->integer('goods_receipt_id')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, PurchaseReturnResource::class,
            sortable: ['code', 'return_date', 'status'], dateColumn: 'return_date');
    }

    public function store(PurchaseReturnStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['returned_by'] = $request->user()?->id;

        try {
            $return = $this->service->create($data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(PurchaseReturnResource::make($return));
    }

    /**
     * "Buat Retur" on the GRN's detail screen: a draft covering every line's
     * remaining returnable quantity, for the operator to trim and post.
     */
    public function storeFromReceipt(DocumentReturnRequest $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $data = $request->validated();
        $data['returned_by'] = $request->user()?->id;

        try {
            $return = $this->service->createFromReceipt($goodsReceipt, $data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(PurchaseReturnResource::make($return));
    }

    public function show(PurchaseReturn $purchaseReturn): JsonResponse
    {
        return $this->ok(PurchaseReturnResource::make(
            $purchaseReturn->load('items.item', 'goodsReceipt.items.item', 'warehouse')
        ));
    }

    public function update(PurchaseReturnUpdateRequest $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        try {
            $purchaseReturn = $this->service->update($purchaseReturn, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseReturnResource::make($purchaseReturn));
    }

    public function destroy(PurchaseReturn $purchaseReturn): JsonResponse
    {
        try {
            $this->service->delete($purchaseReturn);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Retur dihapus.');
    }

    public function post(PurchaseReturn $purchaseReturn): JsonResponse
    {
        try {
            $purchaseReturn = $this->stockService->postPurchaseReturn($purchaseReturn);
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            PurchaseReturnResource::make($purchaseReturn),
            'Retur diposting; stok keluar, sisa tagihan vendor berkurang, dan PO menerima kembali kuantitasnya.',
        );
    }
}
