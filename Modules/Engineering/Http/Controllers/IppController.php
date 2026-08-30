<?php

namespace Modules\Engineering\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Engineering\Http\Requests\IppStoreRequest;
use Modules\Engineering\Http\Requests\IppUpdateRequest;
use Modules\Engineering\Http\Resources\IppResource;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\Engineering\Services\IppService;

/**
 * IPP endpoints. submit goes through IppService (the gate lives there);
 * approve/reject call the trait directly, exactly as the P0-C permit
 * controllers do — an IPP approval has no side-effects beyond its own status,
 * and inventing a pass-through service method would put a second name on the
 * same rule.
 */
class IppController extends ApiController
{
    public function __construct(private readonly IppService $service) {}

    private const DETAIL = [
        'project', 'location', 'wbsTask',
        // P8 (D9): superseded_by_code pada Resource hanya terisi bila relasi
        // ini termuat — banner "digantikan" di SPA menyebut nomor penggantinya.
        'supersededBy',
        'materials', 'equipment',
        'drawings.drawingSubmittal.drawing', 'materialApprovals.materialSubmittal',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = WorkPermitIpp::query()
            ->with('project')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('scope'), fn ($query) => $query->where('scope', $request->string('scope')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, IppResource::class,
            sortable: ['code', 'planned_start', 'scope', 'status'],
            dateColumn: 'planned_start');
    }

    public function store(IppStoreRequest $request): JsonResponse
    {
        $ipp = $this->service->create($request->validated(), $request->user());

        return $this->created(IppResource::make($ipp->load(self::DETAIL)));
    }

    public function show(WorkPermitIpp $ipp): JsonResponse
    {
        return $this->ok(IppResource::make($ipp->load(self::DETAIL)));
    }

    public function update(IppUpdateRequest $request, WorkPermitIpp $ipp): JsonResponse
    {
        $updated = $this->service->update($ipp, $request->validated());

        return $this->ok(IppResource::make($updated->load(self::DETAIL)));
    }

    public function destroy(WorkPermitIpp $ipp): JsonResponse
    {
        $ipp->assertRevisiBerlaku('dihapus');

        if (! $ipp->status->isEditable()) {
            return $this->error("IPP {$ipp->code} berstatus {$ipp->status->value} dan tidak dapat dihapus lagi.");
        }

        $ipp->delete();

        return $this->ok(null, 'IPP dihapus.');
    }

    public function submit(Request $request, WorkPermitIpp $ipp): JsonResponse
    {
        try {
            $this->service->submit($ipp, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(IppResource::make($ipp->load(self::DETAIL)), 'IPP diajukan.');
    }

    public function approve(Request $request, WorkPermitIpp $ipp): JsonResponse
    {
        $ipp->assertRevisiBerlaku('disetujui');

        try {
            $ipp->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(IppResource::make($ipp->load(self::DETAIL)), 'IPP disetujui.');
    }

    public function reject(Request $request, WorkPermitIpp $ipp): JsonResponse
    {
        $ipp->assertRevisiBerlaku('ditolak');

        try {
            $ipp->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(IppResource::make($ipp->load(self::DETAIL)), 'IPP ditolak.');
    }

    /**
     * P8 — revisi generik (D9): baris BARU bernomor baru lewat service, baris
     * material/alat/gambar ikut tersalin; pendahulu distempel dan tinggal
     * sebagai arsip yang tetap bisa dicetak.
     */
    public function revise(WorkPermitIpp $ipp): JsonResponse
    {
        $successor = $this->service->revise($ipp);

        return $this->created(IppResource::make($successor->load(self::DETAIL)));
    }
}
