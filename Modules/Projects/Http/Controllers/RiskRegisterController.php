<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\RiskRegisterStoreRequest;
use Modules\Projects\Http\Requests\RiskRegisterUpdateRequest;
use Modules\Projects\Http\Resources\RiskRegisterResource;
use Modules\Projects\Models\RiskRegisterEntry;
use Modules\Projects\Services\RiskRegisterService;

/** P6: register IBPRP per proyek — thin; aritmetika skor di service. */
class RiskRegisterController extends ApiController
{
    public function __construct(private readonly RiskRegisterService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = RiskRegisterEntry::query()
            ->with('project')
            ->when($request->filled('q'), fn ($query) => $query->where(function ($where) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $where->where('activity', 'like', $term)
                    ->orWhere('hazard', 'like', $term)
                    ->orWhere('controls', 'like', $term);
            }))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderBy('project_id')->orderBy('sort_order')->orderBy('id');

        return $this->listing($request, $query, RiskRegisterResource::class,
            sortable: ['activity', 'initial_score', 'residual_score', 'sort_order']);
    }

    public function store(RiskRegisterStoreRequest $request): JsonResponse
    {
        $entry = $this->service->create($request->validated(), $request->user());

        return $this->created(RiskRegisterResource::make($entry->load('project')));
    }

    public function show(RiskRegisterEntry $entry): JsonResponse
    {
        return $this->ok(RiskRegisterResource::make($entry->load('project')));
    }

    public function update(RiskRegisterUpdateRequest $request, RiskRegisterEntry $entry): JsonResponse
    {
        $updated = $this->service->update($entry, $request->validated());

        return $this->ok(RiskRegisterResource::make($updated->load('project')));
    }

    public function destroy(RiskRegisterEntry $entry): JsonResponse
    {
        $entry->delete();

        return $this->ok(null, 'Baris register IBPRP dihapus.');
    }
}
