<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\ContractStoreRequest;
use Modules\Crm\Http\Requests\ContractUpdateRequest;
use Modules\Crm\Http\Resources\ContractResource;
use Modules\Crm\Http\Resources\ContractTerminResource;
use Modules\Crm\Models\Contract;
use Modules\Crm\Services\ContractService;

class ContractController extends ApiController
{
    public function __construct(private readonly ContractService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Contract::query()
            ->with('customer')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhere('contract_number_customer', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('scope_type'), fn ($query) => $query->where('scope_type', $request->string('scope_type')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, ContractResource::class,
            sortable: ['code', 'title', 'sign_date', 'value', 'status'], dateColumn: 'sign_date');
    }

    public function store(ContractStoreRequest $request): JsonResponse
    {
        try {
            $contract = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ContractResource::make($contract));
    }

    public function show(Contract $contract): JsonResponse
    {
        return $this->ok(ContractResource::make($contract->load('termins', 'customer', 'quotation')));
    }

    public function update(ContractUpdateRequest $request, Contract $contract): JsonResponse
    {
        try {
            $contract = $this->service->update($contract, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ContractResource::make($contract));
    }

    public function destroy(Contract $contract): JsonResponse
    {
        try {
            $this->service->delete($contract);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Contract deleted.');
    }

    public function activate(Contract $contract): JsonResponse
    {
        try {
            $contract = $this->service->activate($contract);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ContractResource::make($contract->load('termins')), 'Contract activated.');
    }

    public function termins(Contract $contract): JsonResponse
    {
        return $this->ok(ContractTerminResource::collection($contract->termins()->get()));
    }
}
