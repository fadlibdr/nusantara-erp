<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\OverheadBudgetStoreRequest;
use Modules\Finance\Http\Requests\OverheadBudgetUpdateRequest;
use Modules\Finance\Http\Resources\OverheadBudgetResource;
use Modules\Finance\Models\OverheadBudget;
use Modules\Finance\Services\OverheadBudgetService;

/**
 * OVB — anggaran overhead per tahun buku (F-2 / T2.4).
 *
 * submit/approve/reject melewati LAYANAN, tidak pernah memanggil trait
 * langsung: aturan "satu anggaran disetujui per tahun" hidup di sana, dan
 * sebuah pintu yang melewatinya akan menyetujui yang kedua tanpa sepatah kata.
 */
class OverheadBudgetController extends ApiController
{
    public function __construct(private readonly OverheadBudgetService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = OverheadBudget::query()
            ->with('lines')
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('period_year'), fn ($q) => $q->where('period_year', $request->integer('period_year')))
            ->latest('id');

        return $this->listing($request, $query, OverheadBudgetResource::class,
            sortable: ['code', 'period_year', 'total_amount', 'status']);
    }

    public function store(OverheadBudgetStoreRequest $request): JsonResponse
    {
        $budget = $this->service->create($request->validated(), $request->user());

        return $this->created(new OverheadBudgetResource($budget->load('lines.account')));
    }

    public function show(OverheadBudget $overheadBudget): JsonResponse
    {
        return $this->ok(new OverheadBudgetResource($overheadBudget->load(['lines.account', 'approvals.user'])));
    }

    public function update(OverheadBudgetUpdateRequest $request, OverheadBudget $overheadBudget): JsonResponse
    {
        try {
            $overheadBudget = $this->service->update($overheadBudget, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new OverheadBudgetResource($overheadBudget->load('lines.account')));
    }

    public function destroy(OverheadBudget $overheadBudget): JsonResponse
    {
        if (! $overheadBudget->status->isEditable()) {
            return $this->error("OVB {$overheadBudget->code} tidak dapat dihapus selama statusnya {$overheadBudget->status->value}.", 422);
        }

        $overheadBudget->delete();

        return $this->ok(null, 'Deleted');
    }

    public function submit(Request $request, OverheadBudget $overheadBudget): JsonResponse
    {
        try {
            $this->service->submit($overheadBudget, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new OverheadBudgetResource($overheadBudget), 'OVB diajukan');
    }

    public function approve(Request $request, OverheadBudget $overheadBudget): JsonResponse
    {
        try {
            $this->service->approve($overheadBudget, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new OverheadBudgetResource($overheadBudget), 'OVB disetujui');
    }

    public function reject(Request $request, OverheadBudget $overheadBudget): JsonResponse
    {
        try {
            $this->service->reject($overheadBudget, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new OverheadBudgetResource($overheadBudget), 'OVB ditolak');
    }

    /** Anggaran vs realisasi overhead satu tahun buku. */
    public function realisation(Request $request): JsonResponse
    {
        $year = $request->filled('year') ? $request->integer('year') : (int) now()->year;

        return $this->ok($this->service->realisation($year));
    }
}
