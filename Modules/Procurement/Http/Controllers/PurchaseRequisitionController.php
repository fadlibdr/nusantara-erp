<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\PurchaseOrderFromPrRequest;
use Modules\Procurement\Http\Requests\PurchaseRequisitionStoreRequest;
use Modules\Procurement\Http\Requests\PurchaseRequisitionUpdateRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Http\Resources\PurchaseRequisitionResource;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Services\PoService;
use Modules\Procurement\Services\PurchaseRequisitionService;

class PurchaseRequisitionController extends ApiController
{
    public function __construct(
        private readonly PurchaseRequisitionService $service,
        private readonly PoService $poService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseRequisition::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('purpose', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('requested_by'), fn ($query) => $query->where('requested_by', $request->integer('requested_by')))
            ->orderByDesc('id');

        // needed_date is the date a buyer means on a PR — "barang untuk
        // November", not "PR yang diketik November".
        return $this->listing($request, $query, PurchaseRequisitionResource::class,
            sortable: ['code', 'purpose', 'needed_date', 'status'], dateColumn: 'needed_date');
    }

    public function store(PurchaseRequisitionStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['requested_by'] = $data['requested_by'] ?? $request->user()?->id;

        $pr = $this->service->create($data);

        return $this->created(PurchaseRequisitionResource::make($pr));
    }

    public function show(PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        // approvals.user — jejak persetujuan. Diukur 4 Sep 2026 (HASIL-UJI P-4):
        // hanya 5 dari 28 show() dokumen Approvable memuat approvals; halaman PR
        // menggambar Informasi · Item · Lampiran · Metadata tanpa kartu Riwayat
        // Persetujuan, dan strip status jatuh ke "Diajukan · menunggu persetujuan."
        // tanpa nama pengaju dan tanggalnya — padahal barisnya ada di core_approvals.
        // Resource-nya memancarkan bentuk PaymentResource; whenLoaded() menjaga
        // index() tetap tanpa kueri tambahan (T3.3).
        return $this->ok(PurchaseRequisitionResource::make(
            $purchaseRequisition->load('items', 'requester', 'purchaseOrders', 'approvals.user')
        ));
    }

    public function update(PurchaseRequisitionUpdateRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        try {
            $pr = $this->service->update($purchaseRequisition, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseRequisitionResource::make($pr));
    }

    public function destroy(PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        try {
            $this->service->delete($purchaseRequisition);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'PR deleted.');
    }

    public function submit(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        try {
            $purchaseRequisition->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseRequisitionResource::make($purchaseRequisition), 'PR diajukan.');
    }

    public function approve(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        try {
            $purchaseRequisition->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseRequisitionResource::make($purchaseRequisition), 'PR disetujui.');
    }

    public function reject(Request $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        try {
            $purchaseRequisition->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(PurchaseRequisitionResource::make($purchaseRequisition), 'PR ditolak.');
    }

    public function createPo(PurchaseOrderFromPrRequest $request, PurchaseRequisition $purchaseRequisition): JsonResponse
    {
        try {
            $po = $this->poService->createFromPr($purchaseRequisition, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(PurchaseOrderResource::make($po), "Draft PO {$po->code} created from {$purchaseRequisition->code}.");
    }
}
