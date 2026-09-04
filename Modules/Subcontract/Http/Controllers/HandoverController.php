<?php

namespace Modules\Subcontract\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Subcontract\Http\Requests\HandoverStoreRequest;
use Modules\Subcontract\Http\Requests\HandoverUpdateRequest;
use Modules\Subcontract\Http\Resources\HandoverResource;
use Modules\Subcontract\Models\Handover;
use Modules\Subcontract\Services\HandoverService;

class HandoverController extends ApiController
{
    public function __construct(private readonly HandoverService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Handover::query()
            ->with('subcontract')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('scope_notes', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('subcontract_id'), fn ($query) => $query->where('subcontract_id', $request->integer('subcontract_id')))
            ->when($request->filled('handover_type'), fn ($query) => $query->where('handover_type', $request->string('handover_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, HandoverResource::class,
            sortable: ['code', 'handover_type', 'handover_date', 'retention_release_due', 'status'],
            dateColumn: 'handover_date');
    }

    public function store(HandoverStoreRequest $request): JsonResponse
    {
        try {
            $handover = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(HandoverResource::make($handover));
    }

    public function show(Handover $handover): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(HandoverResource::make($handover->load('subcontract', 'approvals.user')));
    }

    public function update(HandoverUpdateRequest $request, Handover $handover): JsonResponse
    {
        try {
            $updated = $this->service->update($handover, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(HandoverResource::make($updated));
    }

    public function destroy(Handover $handover): JsonResponse
    {
        try {
            $this->service->delete($handover);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Berita acara serah terima dihapus.');
    }

    public function submit(Request $request, Handover $handover): JsonResponse
    {
        try {
            $handover->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(HandoverResource::make($handover), 'Berita acara diajukan.');
    }

    /** Through the SERVICE — the two prerequisites run there (roadmap §7). */
    public function approve(Request $request, Handover $handover): JsonResponse
    {
        try {
            $approved = $this->service->approve($handover, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(HandoverResource::make($approved), 'Berita acara disetujui.');
    }

    public function reject(Request $request, Handover $handover): JsonResponse
    {
        try {
            $rejected = $this->service->reject($handover, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(HandoverResource::make($rejected), 'Berita acara ditolak.');
    }

    /** What approving this BAST will require — read before anybody clicks. */
    public function prerequisites(Handover $handover): JsonResponse
    {
        return $this->ok($this->service->evaluate($handover));
    }
}
