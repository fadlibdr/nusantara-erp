<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\GatePassStoreRequest;
use Modules\Projects\Http\Requests\GatePassUpdateRequest;
use Modules\Projects\Http\Resources\GatePassResource;
use Modules\Projects\Models\GatePass;
use Modules\Projects\Services\GatePassService;

class GatePassController extends ApiController
{
    public function __construct(private readonly GatePassService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = GatePass::query()
            ->with(['project', 'vendor'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('vehicle_no', 'like', "%{$q}%")
                        ->orWhere('driver_name', 'like', "%{$q}%")
                        ->orWhere('counterparty', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', $request->string('direction')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, GatePassResource::class,
            sortable: ['code', 'pass_date', 'direction', 'status'], dateColumn: 'pass_date');
    }

    public function store(GatePassStoreRequest $request): JsonResponse
    {
        try {
            $pass = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(GatePassResource::make($pass->load(['project', 'vendor'])));
    }

    public function show(GatePass $gatePass): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(GatePassResource::make($gatePass->load(['project', 'vendor', 'items', 'checkedBy', 'approvals.user'])));
    }

    public function update(GatePassUpdateRequest $request, GatePass $gatePass): JsonResponse
    {
        if (! $gatePass->status->isEditable()) {
            return $this->error("Izin {$gatePass->code} berstatus {$gatePass->status->value} dan tidak dapat diubah lagi.");
        }

        try {
            $pass = $this->service->update($gatePass, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GatePassResource::make($pass->load(['project', 'vendor'])));
    }

    public function destroy(GatePass $gatePass): JsonResponse
    {
        if (! $gatePass->status->isEditable()) {
            return $this->error("Izin {$gatePass->code} berstatus {$gatePass->status->value} dan tidak dapat dihapus lagi.");
        }

        $gatePass->delete();

        return $this->ok(null, 'Izin masuk/keluar dihapus.');
    }

    public function submit(Request $request, GatePass $gatePass): JsonResponse
    {
        try {
            $gatePass->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GatePassResource::make($gatePass), 'Izin masuk/keluar diajukan.');
    }

    public function approve(Request $request, GatePass $gatePass): JsonResponse
    {
        try {
            $gatePass->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GatePassResource::make($gatePass), 'Izin masuk/keluar disetujui.');
    }

    public function reject(Request $request, GatePass $gatePass): JsonResponse
    {
        try {
            $gatePass->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GatePassResource::make($gatePass), 'Izin masuk/keluar ditolak.');
    }

    /** The gate's check — GatePassService enforces the approve-first order. */
    public function periksa(Request $request, GatePass $gatePass): JsonResponse
    {
        try {
            $pass = $this->service->periksa($gatePass, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(GatePassResource::make($pass->load('checkedBy')), 'Muatan diperiksa di gerbang.');
    }
}
