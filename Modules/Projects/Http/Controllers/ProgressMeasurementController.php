<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\ProgressMeasurementStoreRequest;
use Modules\Projects\Http\Requests\ProgressMeasurementUpdateRequest;
use Modules\Projects\Http\Resources\ProgressMeasurementResource;
use Modules\Projects\Models\ProgressMeasurement;
use Modules\Projects\Services\MeasurementService;

class ProgressMeasurementController extends ApiController
{
    public function __construct(private readonly MeasurementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProgressMeasurement::query()
            ->with(['project', 'contract'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('notes', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('contract_id'), fn ($query) => $query->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, ProgressMeasurementResource::class,
            sortable: ['code', 'measurement_no', 'period_end', 'period_amount', 'status'], dateColumn: 'period_end');
    }

    public function store(ProgressMeasurementStoreRequest $request): JsonResponse
    {
        try {
            $measurement = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ProgressMeasurementResource::make($this->loaded($measurement)));
    }

    public function show(ProgressMeasurement $progressMeasurement): JsonResponse
    {
        return $this->ok(ProgressMeasurementResource::make($this->loaded($progressMeasurement)));
    }

    public function update(ProgressMeasurementUpdateRequest $request, ProgressMeasurement $progressMeasurement): JsonResponse
    {
        try {
            $measurement = $this->service->update($progressMeasurement, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressMeasurementResource::make($this->loaded($measurement)));
    }

    public function destroy(ProgressMeasurement $progressMeasurement): JsonResponse
    {
        try {
            $this->service->delete($progressMeasurement);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Opname dihapus.');
    }

    public function submit(Request $request, ProgressMeasurement $progressMeasurement): JsonResponse
    {
        try {
            $progressMeasurement->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressMeasurementResource::make($progressMeasurement), 'Opname diajukan.');
    }

    /**
     * Through the SERVICE, never the trait: approval re-checks the ceiling
     * against live rows and re-derives the weekly curve (roadmap §7 — never
     * call ->approve() directly where a service exists).
     */
    public function approve(Request $request, ProgressMeasurement $progressMeasurement): JsonResponse
    {
        try {
            $measurement = $this->service->approve($progressMeasurement, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressMeasurementResource::make($this->loaded($measurement)), 'Opname disetujui.');
    }

    public function reject(Request $request, ProgressMeasurement $progressMeasurement): JsonResponse
    {
        try {
            $measurement = $this->service->reject($progressMeasurement, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressMeasurementResource::make($measurement), 'Opname ditolak.');
    }

    private function loaded(ProgressMeasurement $measurement): ProgressMeasurement
    {
        return $measurement->load(['project', 'contract', 'items.boqItem', 'items.location']);
    }
}
