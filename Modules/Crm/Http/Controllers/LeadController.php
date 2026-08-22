<?php

namespace Modules\Crm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Crm\Http\Requests\LeadStoreRequest;
use Modules\Crm\Http\Requests\LeadUpdateRequest;
use Modules\Crm\Http\Resources\CustomerResource;
use Modules\Crm\Http\Resources\LeadResource;
use Modules\Crm\Models\Lead;
use Modules\Crm\Services\LeadService;

class LeadController extends ApiController
{
    public function __construct(private readonly LeadService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Lead::query()
            ->with('owner')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('company_name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, LeadResource::class,
            sortable: ['code', 'name', 'source', 'estimated_value', 'status', 'next_follow_up_at']);
    }

    public function store(LeadStoreRequest $request): JsonResponse
    {
        $lead = Lead::query()->create($request->validated());

        return $this->created(LeadResource::make($lead->load('owner')));
    }

    public function show(Lead $lead): JsonResponse
    {
        return $this->ok(LeadResource::make($lead->load('owner')));
    }

    public function update(LeadUpdateRequest $request, Lead $lead): JsonResponse
    {
        $lead->update($request->validated());

        return $this->ok(LeadResource::make($lead->load('owner')));
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();

        return $this->ok(null, 'Lead deleted.');
    }

    /**
     * "Jadikan pelanggan" — copy the lead's fields into a customer master row.
     *
     * NOT gated on status won here: a quotation requires customer_id, so a
     * qualified lead legitimately needs its customer BEFORE any quotation can
     * exist. The SPA surfaces the button on won leads (the resep's case); the
     * API allows the earlier conversion the quotation form forces anyway.
     */
    public function convertToCustomer(Lead $lead): JsonResponse
    {
        $alreadyLinked = $lead->customer_id !== null;
        $customer = $this->service->convertToCustomer($lead);

        return $alreadyLinked
            ? $this->ok(CustomerResource::make($customer), "Lead {$lead->code} sudah menjadi pelanggan {$customer->code}.")
            : $this->created(CustomerResource::make($customer), "Pelanggan {$customer->code} dibuat dari lead {$lead->code}.");
    }
}
