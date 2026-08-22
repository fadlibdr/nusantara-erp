<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\ManpowerAssignmentStoreRequest;
use Modules\Projects\Http\Requests\ManpowerAssignmentUpdateRequest;
use Modules\Projects\Http\Resources\ManpowerAssignmentResource;
use Modules\Projects\Models\ManpowerAssignment;

class ManpowerAssignmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ManpowerAssignment::query()
            ->with('project')
            ->when($request->filled('q'), fn ($query) => $query->where('role_on_project', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('assigned_from');

        return $this->listing($request, $query, ManpowerAssignmentResource::class,
            sortable: ['role_on_project', 'assigned_from', 'assigned_until']);
    }

    public function store(ManpowerAssignmentStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        $assignment = ManpowerAssignment::query()->create($data);

        return $this->created(ManpowerAssignmentResource::make($assignment));
    }

    public function show(ManpowerAssignment $manpowerAssignment): JsonResponse
    {
        return $this->ok(ManpowerAssignmentResource::make($manpowerAssignment->load('project')));
    }

    public function update(ManpowerAssignmentUpdateRequest $request, ManpowerAssignment $manpowerAssignment): JsonResponse
    {
        $manpowerAssignment->fill($request->validated())->save();

        return $this->ok(ManpowerAssignmentResource::make($manpowerAssignment));
    }

    public function destroy(ManpowerAssignment $manpowerAssignment): JsonResponse
    {
        $manpowerAssignment->delete();

        return $this->ok(null, 'Manpower assignment deleted.');
    }
}
