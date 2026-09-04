<?php

namespace Modules\Quality\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Quality\Http\Requests\InspectionStoreRequest;
use Modules\Quality\Http\Requests\InspectionUpdateRequest;
use Modules\Quality\Http\Resources\InspectionResource;
use Modules\Quality\Models\Inspection;
use Modules\Quality\Services\InspectionService;

/**
 * QCI endpoints. submit goes through InspectionService (the NCR block lives
 * there); approve/reject call the Approvable trait directly — an inspection
 * approval has no side-effects beyond its own status, exactly like the IPP.
 */
class InspectionController extends ApiController
{
    public function __construct(private readonly InspectionService $service) {}

    private const DETAIL = [
        // supersededBy: P8 (D9) — superseded_by_code pada Resource hanya
        // terisi bila relasi ini termuat; banner SPA menyebut penggantinya.
        'project', 'ipp', 'location', 'template', 'inspector', 'supersededBy', 'results.templateItem',
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya (HASIL-UJI P-4, T3.3).
        'approvals.user',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Inspection::query()
            ->with(['project', 'template'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where('code', 'like', "%{$q}%");
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, InspectionResource::class,
            sortable: ['code', 'inspected_at', 'status'],
            dateColumn: 'inspected_at');
    }

    public function store(InspectionStoreRequest $request): JsonResponse
    {
        $inspection = $this->service->create($request->validated(), $request->user());

        return $this->created(InspectionResource::make($inspection->load(self::DETAIL)));
    }

    public function show(Inspection $inspection): JsonResponse
    {
        return $this->ok(InspectionResource::make($inspection->load(self::DETAIL)));
    }

    public function update(InspectionUpdateRequest $request, Inspection $inspection): JsonResponse
    {
        $updated = $this->service->update($inspection, $request->validated());

        return $this->ok(InspectionResource::make($updated->load(self::DETAIL)));
    }

    public function destroy(Inspection $inspection): JsonResponse
    {
        $inspection->assertRevisiBerlaku('dihapus');

        if (! $inspection->status->isEditable()) {
            return $this->error("Inspeksi {$inspection->code} berstatus {$inspection->status->value} dan tidak dapat dihapus lagi.");
        }

        $inspection->delete();

        return $this->ok(null, 'Inspeksi dihapus.');
    }

    public function submit(Request $request, Inspection $inspection): JsonResponse
    {
        try {
            $this->service->submit($inspection, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(InspectionResource::make($inspection->load(self::DETAIL)), 'Inspeksi diajukan.');
    }

    public function approve(Request $request, Inspection $inspection): JsonResponse
    {
        $inspection->assertRevisiBerlaku('disetujui');

        try {
            $inspection->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(InspectionResource::make($inspection->load(self::DETAIL)), 'Inspeksi disetujui.');
    }

    public function reject(Request $request, Inspection $inspection): JsonResponse
    {
        $inspection->assertRevisiBerlaku('ditolak');

        try {
            $inspection->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(InspectionResource::make($inspection->load(self::DETAIL)), 'Inspeksi ditolak.');
    }

    /**
     * P8 — revisi generik (D9): baris BARU bernomor QCI baru lewat service,
     * butir hasil ikut tersalin; pendahulu distempel dan tinggal sebagai arsip
     * yang tetap bisa dicetak.
     */
    public function revise(Inspection $inspection): JsonResponse
    {
        $successor = $this->service->revise($inspection);

        return $this->created(InspectionResource::make($successor->load(self::DETAIL)));
    }
}
