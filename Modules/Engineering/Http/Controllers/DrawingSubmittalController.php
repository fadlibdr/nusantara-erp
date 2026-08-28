<?php

namespace Modules\Engineering\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Engineering\Http\Requests\DrawingSubmittalStoreRequest;
use Modules\Engineering\Http\Requests\DrawingSubmittalUpdateRequest;
use Modules\Engineering\Http\Requests\SubmittalDecisionRequest;
use Modules\Engineering\Http\Resources\DrawingSubmittalResource;
use Modules\Engineering\Models\DrawingSubmittal;
use Modules\Engineering\Services\DrawingSubmittalService;

class DrawingSubmittalController extends ApiController
{
    public function __construct(private readonly DrawingSubmittalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = DrawingSubmittal::query()
            ->with(['drawing.project', 'supersededBy'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhereHas('drawing', fn ($drawing) => $drawing
                            ->where('number', 'like', "%{$q}%")
                            ->orWhere('title', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('drawing_id'), fn ($query) => $query->where('drawing_id', $request->integer('drawing_id')))
            ->when($request->filled('project_id'), fn ($query) => $query
                ->whereHas('drawing', fn ($drawing) => $drawing->where('project_id', $request->integer('project_id'))))
            ->when($request->filled('decision'), fn ($query) => $query->where('decision', $request->string('decision')))
            ->when($request->boolean('current_only'), fn ($query) => $query->whereNull('superseded_at'))
            ->orderByDesc('id');

        return $this->listing($request, $query, DrawingSubmittalResource::class,
            sortable: ['code', 'revision', 'submitted_at', 'decision', 'decided_at'],
            dateColumn: 'submitted_at');
    }

    public function store(DrawingSubmittalStoreRequest $request): JsonResponse
    {
        $submittal = $this->service->create($request->validated(), $request->user());

        return $this->created(DrawingSubmittalResource::make($submittal->load(['drawing', 'createdBy'])));
    }

    public function show(DrawingSubmittal $drawingSubmittal): JsonResponse
    {
        return $this->ok(DrawingSubmittalResource::make(
            $drawingSubmittal->load(['drawing.project', 'createdBy', 'supersededBy']),
        ));
    }

    public function update(DrawingSubmittalUpdateRequest $request, DrawingSubmittal $drawingSubmittal): JsonResponse
    {
        $submittal = $this->service->update($drawingSubmittal, $request->validated());

        return $this->ok(DrawingSubmittalResource::make($submittal->load(['drawing', 'createdBy'])));
    }

    public function destroy(DrawingSubmittal $drawingSubmittal): JsonResponse
    {
        $this->service->delete($drawingSubmittal);

        return $this->ok(null, 'Submittal dihapus.');
    }

    /** The MK's stamp, typed in from the returned sheet. */
    public function decision(SubmittalDecisionRequest $request, DrawingSubmittal $drawingSubmittal): JsonResponse
    {
        $submittal = $this->service->recordDecision($drawingSubmittal, $request->validated(), $request->user());

        return $this->ok(
            DrawingSubmittalResource::make($submittal->load(['drawing', 'createdBy'])),
            'Keputusan MK dicatat.',
        );
    }
}
