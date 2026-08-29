<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\ProcurementPlanStoreRequest;
use Modules\Procurement\Http\Requests\ProcurementPlanUpdateRequest;
use Modules\Procurement\Http\Resources\ProcurementPlanResource;
use Modules\Procurement\Models\ProcurementPlan;
use Modules\Procurement\Services\ProcurementPlanService;

class ProcurementPlanController extends ApiController
{
    public function __construct(private readonly ProcurementPlanService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProcurementPlan::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%"));
            })
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, ProcurementPlanResource::class,
            sortable: ['code', 'title', 'status']);
    }

    public function store(ProcurementPlanStoreRequest $request): JsonResponse
    {
        $plan = $this->service->create($request->validated());

        return $this->created(ProcurementPlanResource::make($plan));
    }

    public function show(ProcurementPlan $procurementPlan): JsonResponse
    {
        return $this->ok(ProcurementPlanResource::make($procurementPlan->load('items')));
    }

    public function update(ProcurementPlanUpdateRequest $request, ProcurementPlan $procurementPlan): JsonResponse
    {
        $plan = $this->service->update($procurementPlan, $request->validated());

        return $this->ok(ProcurementPlanResource::make($plan));
    }

    public function destroy(ProcurementPlan $procurementPlan): JsonResponse
    {
        $this->service->delete($procurementPlan);

        return $this->ok(null, 'Rencana pengadaan dihapus.');
    }
}
