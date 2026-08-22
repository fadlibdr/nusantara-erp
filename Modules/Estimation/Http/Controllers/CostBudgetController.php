<?php

namespace Modules\Estimation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Estimation\Http\Requests\CostBudgetGenerateRequest;
use Modules\Estimation\Http\Requests\CostBudgetStoreRequest;
use Modules\Estimation\Http\Requests\CostBudgetUpdateRequest;
use Modules\Estimation\Http\Resources\CostBudgetResource;
use Modules\Estimation\Models\CostBudget;
use Modules\Estimation\Services\RapService;

class CostBudgetController extends ApiController
{
    public function __construct(private readonly RapService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = CostBudget::query()
            ->with('boq')
            ->when($request->filled('q'), fn ($q) => $q->where('code', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('boq_id'), fn ($q) => $q->where('boq_id', $request->integer('boq_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->latest('id');

        return $this->listing($request, $query, CostBudgetResource::class,
            sortable: ['code', 'target_margin_pct', 'total_budget', 'status']);
    }

    public function store(CostBudgetStoreRequest $request): JsonResponse
    {
        $budget = $this->service->create($request->validated());

        return $this->created(new CostBudgetResource($budget->load('boq')));
    }

    public function show(CostBudget $costBudget): JsonResponse
    {
        return $this->ok(new CostBudgetResource($costBudget->load(['boq', 'items.boqItem'])));
    }

    /**
     * The editability rule lives in RapService, not here.
     *
     * It used to live in both: an inline check in this method and
     * RapService::assertEditable behind the importer, wording the same refusal
     * twice. Two copies of a status guard drift, and the copy that drifts is the
     * one nobody is looking at — so the form now goes through the same door the
     * importer does. The 422 body is unchanged (assertEditable('edited') builds
     * the identical sentence); what also comes with it is the project_id rule,
     * which reads a cleared Proyek field as "the BOQ's project" instead of
     * nulling the link every baseline and EVM report resolves the RAP by.
     */
    public function update(CostBudgetUpdateRequest $request, CostBudget $costBudget): JsonResponse
    {
        try {
            $costBudget = $this->service->update($costBudget, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new CostBudgetResource($costBudget->load('boq')));
    }

    public function destroy(CostBudget $costBudget): JsonResponse
    {
        if (! $costBudget->status->isEditable()) {
            return $this->error("RAP {$costBudget->code} cannot be deleted while status is {$costBudget->status->value}.", 422);
        }

        $costBudget->delete();

        return $this->ok(null, 'Deleted');
    }

    public function generateFromBoq(CostBudgetGenerateRequest $request, CostBudget $costBudget): JsonResponse
    {
        $margin = $request->validated('target_margin_pct');

        try {
            $budget = $this->service->generateFromBoq($costBudget, $margin !== null ? (float) $margin : null);
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(
            new CostBudgetResource($budget->load(['boq', 'items.boqItem'])),
            'RAP lines generated from BOQ',
        );
    }

    public function submit(Request $request, CostBudget $costBudget): JsonResponse
    {
        try {
            $costBudget->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new CostBudgetResource($costBudget), 'RAP submitted');
    }

    public function approve(Request $request, CostBudget $costBudget): JsonResponse
    {
        try {
            $costBudget->approve($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new CostBudgetResource($costBudget), 'RAP approved');
    }

    public function reject(Request $request, CostBudget $costBudget): JsonResponse
    {
        try {
            $costBudget->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(new CostBudgetResource($costBudget), 'RAP rejected');
    }
}
