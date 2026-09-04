<?php

namespace Modules\Subcontract\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Subcontract\Http\Requests\LaborClaimStoreRequest;
use Modules\Subcontract\Http\Requests\LaborClaimUpdateRequest;
use Modules\Subcontract\Http\Resources\LaborClaimResource;
use Modules\Subcontract\Models\LaborClaim;
use Modules\Subcontract\Services\LaborClaimService;

class LaborClaimController extends ApiController
{
    public function __construct(private readonly LaborClaimService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = LaborClaim::query()
            ->with('laborContract', 'kasbon')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhereHas('laborContract', fn ($sp3) => $sp3->where('code', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('labor_contract_id'), fn ($query) => $query->where('labor_contract_id', $request->integer('labor_contract_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, LaborClaimResource::class,
            sortable: ['code', 'claim_no', 'period_end', 'gross_amount', 'net_payable', 'status'],
            dateColumn: 'period_end');
    }

    public function store(LaborClaimStoreRequest $request): JsonResponse
    {
        try {
            $claim = $this->service->createClaim($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(LaborClaimResource::make($claim));
    }

    public function show(LaborClaim $laborClaim): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(LaborClaimResource::make(
            $laborClaim->load('items.laborContractItem', 'laborContract', 'kasbon', 'approvals.user')
        ));
    }

    public function update(LaborClaimUpdateRequest $request, LaborClaim $laborClaim): JsonResponse
    {
        try {
            $claim = $this->service->updateClaim($laborClaim, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborClaimResource::make($claim));
    }

    public function destroy(LaborClaim $laborClaim): JsonResponse
    {
        try {
            $this->service->delete($laborClaim);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Opname mandor dihapus.');
    }

    public function submit(Request $request, LaborClaim $laborClaim): JsonResponse
    {
        try {
            $laborClaim->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborClaimResource::make($laborClaim), 'Opname mandor diajukan.');
    }

    public function approve(Request $request, LaborClaim $laborClaim): JsonResponse
    {
        try {
            $claim = $this->service->approve($laborClaim, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborClaimResource::make($claim), 'Opname mandor disetujui.');
    }

    public function reject(Request $request, LaborClaim $laborClaim): JsonResponse
    {
        try {
            $laborClaim->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LaborClaimResource::make($laborClaim), 'Opname mandor ditolak.');
    }
}
