<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\CustomerStoreRequest;
use Modules\Crm\Http\Requests\CustomerUpdateRequest;
use Modules\Crm\Http\Resources\CustomerResource;
use Modules\Crm\Models\Customer;

class CustomerController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('legal_name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('city'), fn ($query) => $query->where('city', $request->string('city')))
            ->orderBy('code');

        return $this->listing($request, $query, CustomerResource::class,
            sortable: ['code', 'name', 'city', 'payment_term_days', 'status']);
    }

    public function store(CustomerStoreRequest $request): JsonResponse
    {
        $customer = Customer::query()->create($request->validated());

        return $this->created(CustomerResource::make($customer));
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->ok(CustomerResource::make($customer));
    }

    public function update(CustomerUpdateRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return $this->ok(CustomerResource::make($customer));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return $this->ok(null, 'Customer deleted.');
    }
}
