<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\OvertimePermitStoreRequest;
use Modules\Projects\Http\Requests\OvertimePermitUpdateRequest;
use Modules\Projects\Http\Resources\OvertimePermitResource;
use Modules\Projects\Models\OvertimePermit;
use Modules\Projects\Services\OvertimePermitService;

class OvertimePermitController extends ApiController
{
    public function __construct(private readonly OvertimePermitService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = OvertimePermit::query()
            ->with(['project', 'workers.employee'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('reason', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, OvertimePermitResource::class,
            sortable: ['code', 'overtime_date', 'status'], dateColumn: 'overtime_date');
    }

    public function store(OvertimePermitStoreRequest $request): JsonResponse
    {
        try {
            $permit = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(OvertimePermitResource::make($permit->load(['project', 'workers.employee'])));
    }

    public function show(OvertimePermit $overtimePermit): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(OvertimePermitResource::make($overtimePermit->load(['project', 'workers.employee', 'approvals.user'])));
    }

    public function update(OvertimePermitUpdateRequest $request, OvertimePermit $overtimePermit): JsonResponse
    {
        if (! $overtimePermit->status->isEditable()) {
            return $this->error("Izin {$overtimePermit->code} berstatus {$overtimePermit->status->value} dan tidak dapat diubah lagi.");
        }

        try {
            $permit = $this->service->update($overtimePermit, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(OvertimePermitResource::make($permit->load(['project', 'workers.employee'])));
    }

    public function destroy(OvertimePermit $overtimePermit): JsonResponse
    {
        if (! $overtimePermit->status->isEditable()) {
            return $this->error("Izin {$overtimePermit->code} berstatus {$overtimePermit->status->value} dan tidak dapat dihapus lagi.");
        }

        $overtimePermit->delete();

        return $this->ok(null, 'Izin lembur dihapus.');
    }

    public function submit(Request $request, OvertimePermit $overtimePermit): JsonResponse
    {
        try {
            $overtimePermit->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(OvertimePermitResource::make($overtimePermit), 'Izin lembur diajukan.');
    }

    /**
     * Approve feeds the recap; a skipped posted period is REPORTED in the
     * message (the LeaveRequestController shape) — the approver is the one
     * person who can still fix that recap by other means, so tell them it was
     * left alone rather than let them assume it moved.
     */
    public function approve(Request $request, OvertimePermit $overtimePermit): JsonResponse
    {
        try {
            $result = $this->service->approve($overtimePermit, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        $message = 'Izin lembur disetujui.';

        if ($result['skipped_periods'] !== []) {
            $message .= ' Rekap '.implode(', ', $result['skipped_periods'])
                .' tidak diubah — payroll periode itu sudah diposting.';
        }

        return $this->ok(OvertimePermitResource::make($result['permit']), $message);
    }

    public function reject(Request $request, OvertimePermit $overtimePermit): JsonResponse
    {
        try {
            $overtimePermit->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(OvertimePermitResource::make($overtimePermit), 'Izin lembur ditolak.');
    }
}
