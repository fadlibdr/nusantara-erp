<?php

namespace Modules\ServiceDesk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\ServiceDesk\Http\Requests\ContractStoreRequest;
use Modules\ServiceDesk\Http\Requests\ContractUpdateRequest;
use Modules\ServiceDesk\Http\Resources\ServiceContractResource;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Services\ContractService;

class ServiceContractController extends ApiController
{
    public function __construct(private readonly ContractService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ServiceContract::query()
            ->with('customer', 'sites')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->orderByDesc('id');

        // period_end, not period_start: renewal — "kontrak yang habis bulan
        // ini" — is the date question this list is opened for.
        return $this->listing($request, $query, ServiceContractResource::class,
            sortable: ['code', 'name', 'period_start', 'period_end', 'contract_value', 'sla_response_hours', 'status'],
            dateColumn: 'period_end');
    }

    public function store(ContractStoreRequest $request): JsonResponse
    {
        try {
            $contract = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ServiceContractResource::make($contract));
    }

    public function show(ServiceContract $contract): JsonResponse
    {
        return $this->ok(ServiceContractResource::make(
            $contract->load('customer', 'crmContract', 'sites', 'preventiveSchedules')
        ));
    }

    public function update(ContractUpdateRequest $request, ServiceContract $contract): JsonResponse
    {
        try {
            $contract = $this->service->update($contract, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ServiceContractResource::make($contract));
    }

    public function destroy(ServiceContract $contract): JsonResponse
    {
        try {
            $this->service->delete($contract);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Service contract deleted.');
    }
}
