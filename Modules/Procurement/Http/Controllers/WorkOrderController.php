<?php

namespace Modules\Procurement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Procurement\Http\Requests\WorkOrderStoreRequest;
use Modules\Procurement\Http\Requests\WorkOrderUpdateRequest;
use Modules\Procurement\Http\Resources\WorkOrderResource;
use Modules\Procurement\Models\WorkOrder;
use Modules\Procurement\Services\VendorQualificationService;
use Modules\Procurement\Services\WorkOrderService;

class WorkOrderController extends ApiController
{
    public function __construct(
        private readonly WorkOrderService $service,
        private readonly VendorQualificationService $qualification,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WorkOrder::query()
            ->with('vendor', 'project')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('vendor_id'), fn ($query) => $query->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, WorkOrderResource::class,
            sortable: ['code', 'title', 'value', 'start_date', 'status'],
            dateColumn: 'start_date');
    }

    public function store(WorkOrderStoreRequest $request): JsonResponse
    {
        try {
            // VendorNotQualifiedException extends LogicException by design,
            // so the one catch answers both refusals as a 422.
            $workOrder = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(WorkOrderResource::make($workOrder));
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(WorkOrderResource::make(
            $workOrder->load('items.asset', 'billings.lines', 'vendor', 'project', 'approvals.user')
        ));
    }

    public function update(WorkOrderUpdateRequest $request, WorkOrder $workOrder): JsonResponse
    {
        try {
            $workOrder = $this->service->update($workOrder, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkOrderResource::make($workOrder));
    }

    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        try {
            $this->service->delete($workOrder);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'PPK dihapus.');
    }

    public function submit(Request $request, WorkOrder $workOrder): JsonResponse
    {
        try {
            // Gate prakualifikasi berdiri saat MENGAJUKAN, bukan hanya saat
            // membuat draf — cermin LaborContractController::submit: dokumen
            // wajib vendor bisa kedaluwarsa (dan vendornya dinonaktifkan) di
            // antara draf dan pengajuan, dan update() bebas menukar vendor_id.
            $vendor = $workOrder->vendor;

            if ($vendor === null) {
                return $this->error("Vendor PPK {$workOrder->code} sudah dihapus; pilih vendor lain sebelum mengajukan.");
            }

            $reason = trim((string) $request->input('qualification_override_reason', ''));
            $overridden = $this->qualification->assertQualified($vendor, $reason === '' ? null : $reason);

            $workOrder = DB::transaction(function () use ($workOrder, $request, $reason, $overridden): WorkOrder {
                /** @var WorkOrder $fresh */
                $fresh = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

                // submit() DULU, alasan sesudahnya, satu transaksi — pengajuan
                // yang ditolak Approvable tidak boleh meninggalkan jejak
                // override palsu (pola SPK/SP3).
                $fresh->submit($request->user());

                if ($overridden !== []) {
                    $fresh->forceFill(['qualification_override_reason' => $reason])->save();
                }

                return $fresh;
            });
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkOrderResource::make($workOrder), 'PPK diajukan.');
    }

    public function approve(Request $request, WorkOrder $workOrder): JsonResponse
    {
        try {
            $workOrder = $this->service->approve($workOrder, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkOrderResource::make($workOrder), 'PPK disetujui.');
    }

    public function reject(Request $request, WorkOrder $workOrder): JsonResponse
    {
        try {
            $workOrder->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkOrderResource::make($workOrder), 'PPK ditolak.');
    }
}
