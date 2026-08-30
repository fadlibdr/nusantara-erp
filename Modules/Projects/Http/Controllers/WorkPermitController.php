<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\WorkPermitStoreRequest;
use Modules\Projects\Http\Requests\WorkPermitUpdateRequest;
use Modules\Projects\Http\Resources\WorkPermitResource;
use Modules\Projects\Models\WorkPermit;
use Modules\Projects\Services\WorkPermitService;

class WorkPermitController extends ApiController
{
    public function __construct(private readonly WorkPermitService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = WorkPermit::query()
            ->with(['project', 'requestedBy'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('work_description', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('shift'), fn ($query) => $query->where('shift', $request->string('shift')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, WorkPermitResource::class,
            sortable: ['code', 'permit_date', 'shift', 'status'], dateColumn: 'permit_date');
    }

    public function store(WorkPermitStoreRequest $request): JsonResponse
    {
        try {
            $permit = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(WorkPermitResource::make($permit->load(['project', 'requestedBy', 'safetyOfficer'])));
    }

    public function show(WorkPermit $workPermit): JsonResponse
    {
        // supersededBy: P8 (D9) — superseded_by_code pada Resource hanya
        // terisi bila relasi ini termuat; banner SPA menyebut penggantinya.
        return $this->ok(WorkPermitResource::make($workPermit->load(['project', 'requestedBy', 'safetyOfficer', 'supersededBy'])));
    }

    public function update(WorkPermitUpdateRequest $request, WorkPermit $workPermit): JsonResponse
    {
        if (! $workPermit->status->isEditable()) {
            return $this->error("Izin {$workPermit->code} berstatus {$workPermit->status->value} dan tidak dapat diubah lagi.");
        }

        try {
            $permit = $this->service->update($workPermit, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkPermitResource::make($permit->load(['project', 'requestedBy', 'safetyOfficer'])));
    }

    public function destroy(WorkPermit $workPermit): JsonResponse
    {
        $workPermit->assertRevisiBerlaku('dihapus');

        if (! $workPermit->status->isEditable()) {
            return $this->error("Izin {$workPermit->code} berstatus {$workPermit->status->value} dan tidak dapat dihapus lagi.");
        }

        $workPermit->delete();

        return $this->ok(null, 'Izin kerja dihapus.');
    }

    public function submit(Request $request, WorkPermit $workPermit): JsonResponse
    {
        $workPermit->assertRevisiBerlaku('diajukan');

        try {
            $workPermit->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkPermitResource::make($workPermit), 'Izin kerja diajukan.');
    }

    public function approve(Request $request, WorkPermit $workPermit): JsonResponse
    {
        $workPermit->assertRevisiBerlaku('disetujui');

        try {
            $workPermit->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkPermitResource::make($workPermit), 'Izin kerja disetujui.');
    }

    public function reject(Request $request, WorkPermit $workPermit): JsonResponse
    {
        $workPermit->assertRevisiBerlaku('ditolak');

        try {
            $workPermit->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(WorkPermitResource::make($workPermit), 'Izin kerja ditolak.');
    }

    /**
     * P8 — revisi generik (D9): baris BARU bernomor baru lewat service;
     * pendahulu distempel dan tinggal sebagai arsip yang tetap bisa dicetak.
     */
    public function revise(WorkPermit $workPermit): JsonResponse
    {
        $successor = $this->service->revise($workPermit);

        return $this->created(WorkPermitResource::make($successor->load(['project', 'requestedBy', 'safetyOfficer'])));
    }
}
