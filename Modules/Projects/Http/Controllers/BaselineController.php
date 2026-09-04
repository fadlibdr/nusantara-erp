<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\BaselineStoreRequest;
use Modules\Projects\Http\Requests\BaselineUpdateRequest;
use Modules\Projects\Http\Resources\ProjectBaselineResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBaseline;
use Modules\Projects\Services\BaselineService;

class BaselineController extends ApiController
{
    public function __construct(private readonly BaselineService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProjectBaseline::query()
            ->with('project')
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->boolean('current'), fn ($query) => $query->whereNull('superseded_at')->where('status', 'approved'))
            ->orderByDesc('project_id')
            ->orderByDesc('revision_no');

        return $this->listing($request, $query, ProjectBaselineResource::class,
            sortable: ['code', 'revision_no', 'effective_date', 'bac', 'planned_finish', 'status']);
    }

    public function store(BaselineStoreRequest $request): JsonResponse
    {
        $project = Project::query()->findOrFail($request->integer('project_id'));

        try {
            $baseline = $this->service->snapshot($project, $request->validated(), $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(ProjectBaselineResource::make($baseline->load(['points', 'tasks'])));
    }

    public function show(ProjectBaseline $baseline): JsonResponse
    {
        // approvals.user: jejak persetujuan — 4 Sep 2026 hanya 5 dari 28 show()
        // memuatnya; kartu Riwayat Persetujuan dan nama/tanggal pada strip status
        // hilang di dokumen lainnya (HASIL-UJI P-4, T3.3).
        return $this->ok(ProjectBaselineResource::make(
            $baseline->load(['project', 'points', 'tasks.liveTask', 'approvals.user'])
        ));
    }

    public function update(BaselineUpdateRequest $request, ProjectBaseline $baseline): JsonResponse
    {
        try {
            $baseline = $this->service->update($baseline, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProjectBaselineResource::make($baseline));
    }

    public function destroy(ProjectBaseline $baseline): JsonResponse
    {
        try {
            $this->service->delete($baseline);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Baseline dihapus.');
    }

    public function resnapshot(ProjectBaseline $baseline): JsonResponse
    {
        try {
            $baseline = $this->service->resnapshot($baseline);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProjectBaselineResource::make($baseline->load(['points', 'tasks'])), 'Baseline diambil ulang dari WBS dan RAP terkini.');
    }

    public function submit(Request $request, ProjectBaseline $baseline): JsonResponse
    {
        try {
            $baseline = $this->service->submit($baseline, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProjectBaselineResource::make($baseline), 'Baseline diajukan untuk persetujuan.');
    }

    /**
     * SelfApprovalException extends LogicException on purpose, so the catch
     * below carries a maker-checker refusal to the operator's screen verbatim
     * and in Indonesian — the same path the other thirteen approve controllers
     * already use. Nothing extra is needed here.
     */
    public function approve(Request $request, ProjectBaseline $baseline): JsonResponse
    {
        try {
            $baseline = $this->service->approve($baseline, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProjectBaselineResource::make($baseline), 'Baseline dibekukan.');
    }

    public function reject(Request $request, ProjectBaseline $baseline): JsonResponse
    {
        try {
            $baseline = $this->service->reject($baseline, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(ProjectBaselineResource::make($baseline), 'Baseline ditolak.');
    }
}
