<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\IssueCancelRequest;
use Modules\Inventory\Http\Requests\IssueStoreRequest;
use Modules\Inventory\Http\Requests\IssueUpdateRequest;
use Modules\Inventory\Http\Resources\IssueResource;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Services\IssueService;
use Modules\Inventory\Services\StockService;

class IssueController extends ApiController
{
    public function __construct(
        private readonly IssueService $service,
        private readonly StockService $stockService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Issue::query()
            ->with('warehouse')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('purpose', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, IssueResource::class,
            sortable: ['code', 'issue_date', 'purpose', 'status'], dateColumn: 'issue_date');
    }

    public function store(IssueStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['issued_by'] = $request->user()?->id;

        $issue = $this->service->create($data);

        return $this->created(IssueResource::make($issue));
    }

    public function show(Issue $issue): JsonResponse
    {
        return $this->ok(IssueResource::make($issue->load('items.item', 'warehouse')));
    }

    public function update(IssueUpdateRequest $request, Issue $issue): JsonResponse
    {
        try {
            $issue = $this->service->update($issue, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(IssueResource::make($issue));
    }

    public function destroy(Issue $issue): JsonResponse
    {
        try {
            $this->service->delete($issue);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Issue deleted.');
    }

    public function post(Issue $issue): JsonResponse
    {
        try {
            $issue = $this->stockService->postIssue($issue);
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(IssueResource::make($issue), 'Issue posted; stock deducted at average cost.');
    }

    /**
     * The way back for a bon posted against the wrong project or the wrong
     * warehouse. Gated on inv.post, not inv.delete: this raises a mirror stock
     * movement and a reversing journal, so it is a posting act.
     */
    public function cancel(IssueCancelRequest $request, Issue $issue): JsonResponse
    {
        try {
            $issue = $this->stockService->cancelIssue(
                $issue,
                (string) $request->validated('reason'),
                $request->user()?->id,
            );
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(IssueResource::make($issue), 'Bon dibatalkan; stok dikembalikan dan jurnalnya dibalik.');
    }
}
