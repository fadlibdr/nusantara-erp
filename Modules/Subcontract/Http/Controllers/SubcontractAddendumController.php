<?php

namespace Modules\Subcontract\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Subcontract\Http\Requests\AddendumStoreRequest;
use Modules\Subcontract\Http\Requests\AddendumUpdateRequest;
use Modules\Subcontract\Http\Resources\SubcontractAddendumResource;
use Modules\Subcontract\Models\SubcontractAddendum;
use Modules\Subcontract\Services\AddendumService;

/**
 * Addendum SPK. Same submit/approve/reject lifecycle as every other approvable
 * document; approval is what moves the SPK value (and the klaim plafon with
 * it), so it goes through the service, never the model.
 */
class SubcontractAddendumController extends ApiController
{
    public function __construct(private readonly AddendumService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = SubcontractAddendum::query()
            ->with('subcontract:id,code,title')
            ->when($request->filled('subcontract_id'), fn ($q) => $q->where('subcontract_id', $request->integer('subcontract_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('change_type'), fn ($q) => $q->where('change_type', $request->string('change_type')))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($where) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $where->where('code', 'like', $term)->orWhere('title', 'like', $term);
            }))
            ->orderByDesc('addendum_date');

        return $this->listing($request, $query, SubcontractAddendumResource::class,
            sortable: ['code', 'title', 'addendum_date', 'value_change', 'status'], dateColumn: 'addendum_date');
    }

    public function show(SubcontractAddendum $addendum): JsonResponse
    {
        return $this->ok(SubcontractAddendumResource::make(
            $addendum->load('items', 'subcontract', 'approvals.user:id,name')
        ));
    }

    public function store(AddendumStoreRequest $request): JsonResponse
    {
        try {
            $addendum = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(SubcontractAddendumResource::make($addendum), 'Addendum SPK dibuat.');
    }

    public function update(AddendumUpdateRequest $request, SubcontractAddendum $addendum): JsonResponse
    {
        try {
            $addendum = $this->service->update($addendum, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(SubcontractAddendumResource::make($addendum));
    }

    public function destroy(SubcontractAddendum $addendum): JsonResponse
    {
        try {
            $this->service->delete($addendum);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Addendum dihapus.');
    }

    public function submit(Request $request, SubcontractAddendum $addendum): JsonResponse
    {
        try {
            $addendum->submit($request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        $message = $addendum->needs_director_approval
            ? 'Addendum diajukan; perlu persetujuan direktur (nilai SPK melewati ambang).'
            : 'Addendum diajukan.';

        return $this->ok(SubcontractAddendumResource::make($addendum->load('items', 'subcontract')), $message);
    }

    public function approve(Request $request, SubcontractAddendum $addendum): JsonResponse
    {
        try {
            // Through the service, not the model: the value move, the claimed
            // floor and the director gate all live there.
            $addendum = $this->service->approve($addendum, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(
            SubcontractAddendumResource::make($addendum),
            "Addendum disetujui — nilai SPK diperbarui menjadi {$addendum->subcontract->value}.",
        );
    }

    public function reject(Request $request, SubcontractAddendum $addendum): JsonResponse
    {
        try {
            $addendum = $this->service->reject($addendum, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(SubcontractAddendumResource::make($addendum), 'Addendum ditolak.');
    }
}
