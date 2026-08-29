<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\WorkOrderBillingStoreRequest;
use Modules\Procurement\Http\Resources\WorkOrderBillingResource;
use Modules\Procurement\Models\WorkOrder;
use Modules\Procurement\Models\WorkOrderBilling;
use Modules\Procurement\Services\WorkOrderBillingService;

class WorkOrderBillingController extends ApiController
{
    public function __construct(private readonly WorkOrderBillingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = WorkOrderBilling::query()
            ->with('workOrder.vendor')
            ->when($request->filled('work_order_id'), fn ($query) => $query->where('work_order_id', $request->integer('work_order_id')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhereHas('workOrder', fn ($wo) => $wo->where('code', 'like', "%{$q}%"));
                });
            })
            ->orderByDesc('id');

        return $this->listing($request, $query, WorkOrderBillingResource::class,
            sortable: ['code', 'period_start', 'period_end', 'total_amount'],
            dateColumn: 'period_start');
    }

    public function store(WorkOrderBillingStoreRequest $request): JsonResponse
    {
        $workOrder = WorkOrder::query()->findOrFail($request->integer('work_order_id'));

        try {
            $billing = $this->service->create($workOrder, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(WorkOrderBillingResource::make($billing));
    }

    public function show(WorkOrderBilling $workOrderBilling): JsonResponse
    {
        return $this->ok(WorkOrderBillingResource::make(
            $workOrderBilling->load('lines.workOrderItem', 'workOrder.vendor')
        ));
    }

    public function destroy(WorkOrderBilling $workOrderBilling): JsonResponse
    {
        try {
            $this->service->delete($workOrderBilling);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Tagihan periode dihapus.');
    }

    /**
     * GET work-orders/reports/billing-recap?from=&to=&vendor_id=&project_id=
     * Rekap Tagihan Alat (deviasi 3.10): billing per periode per vendor,
     * dengan tagihan AP-nya bila ada.
     */
    public function recap(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'vendor_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
        ]);

        return $this->ok($this->service->recap(
            $request->string('from')->toString(),
            $request->string('to')->toString(),
            $request->filled('vendor_id') ? $request->integer('vendor_id') : null,
            $request->filled('project_id') ? $request->integer('project_id') : null,
        ));
    }
}
