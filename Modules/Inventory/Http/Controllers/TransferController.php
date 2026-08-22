<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\TransferStoreRequest;
use Modules\Inventory\Http\Requests\TransferUpdateRequest;
use Modules\Inventory\Http\Resources\TransferResource;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Services\StockService;
use Modules\Inventory\Services\TransferService;

class TransferController extends ApiController
{
    public function __construct(
        private readonly TransferService $service,
        private readonly StockService $stockService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Transfer::query()
            ->with('fromWarehouse', 'toWarehouse')
            ->when($request->filled('q'), fn ($query) => $query->where('code', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('from_warehouse_id'), fn ($query) => $query->where('from_warehouse_id', $request->integer('from_warehouse_id')))
            ->when($request->filled('to_warehouse_id'), fn ($query) => $query->where('to_warehouse_id', $request->integer('to_warehouse_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, TransferResource::class,
            sortable: ['code', 'transfer_date', 'status'], dateColumn: 'transfer_date');
    }

    public function store(TransferStoreRequest $request): JsonResponse
    {
        $transfer = $this->service->create($request->validated());

        return $this->created(TransferResource::make($transfer));
    }

    public function show(Transfer $transfer): JsonResponse
    {
        return $this->ok(TransferResource::make($transfer->load('items.item', 'fromWarehouse', 'toWarehouse')));
    }

    public function update(TransferUpdateRequest $request, Transfer $transfer): JsonResponse
    {
        try {
            $transfer = $this->service->update($transfer, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TransferResource::make($transfer));
    }

    public function destroy(Transfer $transfer): JsonResponse
    {
        try {
            $this->service->delete($transfer);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Transfer deleted.');
    }

    public function send(Transfer $transfer): JsonResponse
    {
        try {
            $transfer = $this->stockService->sendTransfer($transfer);
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TransferResource::make($transfer), 'Transfer sent; stock left the source warehouse.');
    }

    public function receive(Transfer $transfer): JsonResponse
    {
        try {
            $transfer = $this->stockService->receiveTransfer($transfer);
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(TransferResource::make($transfer), 'Transfer received at the destination warehouse.');
    }
}
