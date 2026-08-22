<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\StockAdjustmentStoreRequest;
use Modules\Inventory\Http\Requests\StockAdjustmentUpdateRequest;
use Modules\Inventory\Http\Resources\StockAdjustmentResource;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\StockAdjustmentService;

class StockAdjustmentController extends ApiController
{
    public function __construct(private readonly StockAdjustmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockAdjustment::query()
            ->with('warehouse')
            ->when($request->filled('q'), fn ($query) => $query->where('code', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('reason'), fn ($query) => $query->where('reason', $request->string('reason')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, StockAdjustmentResource::class,
            sortable: ['code', 'adjustment_date', 'reason', 'posted_at', 'status'], dateColumn: 'adjustment_date');
    }

    public function store(StockAdjustmentStoreRequest $request): JsonResponse
    {
        $adjustment = $this->service->create($request->validated());

        return $this->created(StockAdjustmentResource::make($adjustment));
    }

    public function show(StockAdjustment $stockAdjustment): JsonResponse
    {
        return $this->ok(StockAdjustmentResource::make($stockAdjustment->load('items.item', 'warehouse', 'approvals')));
    }

    public function update(StockAdjustmentUpdateRequest $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        try {
            $adjustment = $this->service->update($stockAdjustment, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(StockAdjustmentResource::make($adjustment));
    }

    public function destroy(StockAdjustment $stockAdjustment): JsonResponse
    {
        try {
            $this->service->delete($stockAdjustment);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Adjustment deleted.');
    }

    public function submit(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        try {
            $stockAdjustment->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(StockAdjustmentResource::make($stockAdjustment), 'Adjustment submitted.');
    }

    public function approve(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        try {
            $adjustment = $this->service->approveAndPost($stockAdjustment, $request->user(), $request->input('note'));
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(StockAdjustmentResource::make($adjustment), 'Adjustment approved and posted to the stock ledger.');
    }

    public function reject(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        try {
            $stockAdjustment->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(StockAdjustmentResource::make($stockAdjustment), 'Adjustment rejected.');
    }
}
