<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\MilestoneStoreRequest;
use Modules\Projects\Http\Requests\MilestoneUpdateRequest;
use Modules\Projects\Http\Resources\MilestoneResource;
use Modules\Projects\Models\Milestone;
use Modules\Projects\Services\MilestoneService;

class MilestoneController extends ApiController
{
    public function __construct(private readonly MilestoneService $milestones) {}

    public function index(Request $request): JsonResponse
    {
        $query = Milestone::query()
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->boolean('pending'), fn ($query) => $query->whereNull('achieved_date'))
            ->orderBy('due_date');

        return $this->listing($request, $query, MilestoneResource::class,
            sortable: ['name', 'due_date', 'achieved_date'], dateColumn: 'due_date');
    }

    public function store(MilestoneStoreRequest $request): JsonResponse
    {
        $milestone = $this->milestones->create($request->validated());

        return $this->created(MilestoneResource::make($milestone));
    }

    public function show(Milestone $milestone): JsonResponse
    {
        return $this->ok(MilestoneResource::make($milestone));
    }

    public function update(MilestoneUpdateRequest $request, Milestone $milestone): JsonResponse
    {
        $milestone = $this->milestones->update($milestone, $request->validated());

        return $this->ok(MilestoneResource::make($milestone));
    }

    public function destroy(Milestone $milestone): JsonResponse
    {
        $this->milestones->delete($milestone);

        return $this->ok(null, 'Milestone deleted.');
    }
}
