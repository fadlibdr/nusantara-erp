<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Inventory\Http\Requests\DocumentReturnRequest;
use Modules\Inventory\Http\Requests\IssueReturnStoreRequest;
use Modules\Inventory\Http\Requests\IssueReturnUpdateRequest;
use Modules\Inventory\Http\Resources\IssueReturnResource;
use Modules\Inventory\Models\Issue;
use Modules\Inventory\Models\IssueReturn;
use Modules\Inventory\Services\IssueReturnService;
use Modules\Inventory\Services\StockService;

class IssueReturnController extends ApiController
{
    public function __construct(
        private readonly IssueReturnService $service,
        private readonly StockService $stockService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = IssueReturn::query()
            ->with(['warehouse', 'issue'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('reason', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('issue_id'), fn ($query) => $query->where('issue_id', $request->integer('issue_id')))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('id');

        return $this->listing($request, $query, IssueReturnResource::class,
            sortable: ['code', 'return_date', 'status'], dateColumn: 'return_date');
    }

    public function store(IssueReturnStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['returned_by'] = $request->user()?->id;

        try {
            $return = $this->service->create($data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(IssueReturnResource::make($return));
    }

    /**
     * "Buat Retur" on the bon's detail screen: a draft covering every line's
     * remaining returnable quantity, for the operator to trim and post. The
     * dialog only asks for the reason — the lines are facts the server knows.
     */
    public function storeFromIssue(DocumentReturnRequest $request, Issue $issue): JsonResponse
    {
        $data = $request->validated();
        $data['returned_by'] = $request->user()?->id;

        try {
            $return = $this->service->createFromIssue($issue, $data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(IssueReturnResource::make($return));
    }

    public function show(IssueReturn $issueReturn): JsonResponse
    {
        return $this->ok(IssueReturnResource::make($issueReturn->load('items.item', 'issue.items.item', 'warehouse')));
    }

    public function update(IssueReturnUpdateRequest $request, IssueReturn $issueReturn): JsonResponse
    {
        try {
            $issueReturn = $this->service->update($issueReturn, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(IssueReturnResource::make($issueReturn));
    }

    public function destroy(IssueReturn $issueReturn): JsonResponse
    {
        try {
            $this->service->delete($issueReturn);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Retur dihapus.');
    }

    public function post(IssueReturn $issueReturn): JsonResponse
    {
        try {
            $issueReturn = $this->stockService->postIssueReturn($issueReturn);
        } catch (DomainException|LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            IssueReturnResource::make($issueReturn),
            'Retur diposting; stok kembali pada harga keluarnya dan biaya proyek berkurang.',
        );
    }
}
