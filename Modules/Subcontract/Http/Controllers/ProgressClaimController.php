<?php

namespace Modules\Subcontract\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Subcontract\Http\Requests\ProgressClaimStoreRequest;
use Modules\Subcontract\Http\Requests\ProgressClaimUpdateRequest;
use Modules\Subcontract\Http\Resources\ProgressClaimResource;
use Modules\Subcontract\Models\ProgressClaim;
use Modules\Subcontract\Services\ClaimService;

class ProgressClaimController extends ApiController
{
    public function __construct(private readonly ClaimService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProgressClaim::query()
            ->with('subcontract')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhereHas('subcontract', fn ($spk) => $spk->where('code', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('subcontract_id'), fn ($query) => $query->where('subcontract_id', $request->integer('subcontract_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, ProgressClaimResource::class,
            sortable: ['code', 'claim_no', 'period_end', 'gross_amount', 'net_payable', 'status'],
            dateColumn: 'period_end');
    }

    public function store(ProgressClaimStoreRequest $request): JsonResponse
    {
        try {
            $claim = $this->service->createClaim($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ProgressClaimResource::make($claim));
    }

    public function show(ProgressClaim $progressClaim): JsonResponse
    {
        return $this->ok(ProgressClaimResource::make(
            $progressClaim->load('items.subcontractItem', 'subcontract')
        ));
    }

    public function update(ProgressClaimUpdateRequest $request, ProgressClaim $progressClaim): JsonResponse
    {
        try {
            $claim = $this->service->updateClaim($progressClaim, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressClaimResource::make($claim));
    }

    public function destroy(ProgressClaim $progressClaim): JsonResponse
    {
        try {
            $this->service->delete($progressClaim);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Opname deleted.');
    }

    public function submit(Request $request, ProgressClaim $progressClaim): JsonResponse
    {
        try {
            $progressClaim->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressClaimResource::make($progressClaim), 'Opname submitted.');
    }

    public function approve(Request $request, ProgressClaim $progressClaim): JsonResponse
    {
        try {
            $claim = $this->service->approve($progressClaim, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressClaimResource::make($claim), 'Opname approved; SPK progress updated.');
    }

    public function reject(Request $request, ProgressClaim $progressClaim): JsonResponse
    {
        try {
            $progressClaim->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProgressClaimResource::make($progressClaim), 'Opname rejected.');
    }
}
