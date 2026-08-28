<?php

namespace Modules\Engineering\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Engineering\Http\Requests\MaterialSubmittalStoreRequest;
use Modules\Engineering\Http\Requests\MaterialSubmittalUpdateRequest;
use Modules\Engineering\Http\Requests\SubmittalDecisionRequest;
use Modules\Engineering\Http\Resources\MaterialSubmittalResource;
use Modules\Engineering\Models\MaterialSubmittal;
use Modules\Engineering\Services\MaterialSubmittalService;

class MaterialSubmittalController extends ApiController
{
    public function __construct(private readonly MaterialSubmittalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = MaterialSubmittal::query()
            ->with(['project', 'item'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('material_name', 'like', "%{$q}%")
                        ->orWhere('brand', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('decision'), fn ($query) => $query->where('decision', $request->string('decision')))
            ->orderByDesc('id');

        return $this->listing($request, $query, MaterialSubmittalResource::class,
            sortable: ['code', 'material_name', 'submitted_at', 'decision', 'decided_at'],
            dateColumn: 'submitted_at');
    }

    public function store(MaterialSubmittalStoreRequest $request): JsonResponse
    {
        $submittal = $this->service->create($request->validated(), $request->user());

        return $this->created(MaterialSubmittalResource::make($submittal->load(['project', 'item'])));
    }

    public function show(MaterialSubmittal $materialSubmittal): JsonResponse
    {
        return $this->ok(MaterialSubmittalResource::make(
            $materialSubmittal->load(['project', 'item', 'createdBy']),
        ));
    }

    public function update(MaterialSubmittalUpdateRequest $request, MaterialSubmittal $materialSubmittal): JsonResponse
    {
        $submittal = $this->service->update($materialSubmittal, $request->validated());

        return $this->ok(MaterialSubmittalResource::make($submittal->load(['project', 'item'])));
    }

    public function destroy(MaterialSubmittal $materialSubmittal): JsonResponse
    {
        $this->service->delete($materialSubmittal);

        return $this->ok(null, 'Submittal dihapus.');
    }

    /** The MK's stamp, typed in from the returned sheet. */
    public function decision(SubmittalDecisionRequest $request, MaterialSubmittal $materialSubmittal): JsonResponse
    {
        $submittal = $this->service->recordDecision($materialSubmittal, $request->validated(), $request->user());

        return $this->ok(
            MaterialSubmittalResource::make($submittal->load(['project', 'item'])),
            'Keputusan MK dicatat.',
        );
    }
}
