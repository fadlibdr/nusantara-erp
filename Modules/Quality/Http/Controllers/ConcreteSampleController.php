<?php

namespace Modules\Quality\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Quality\Http\Requests\ConcreteSampleStoreRequest;
use Modules\Quality\Http\Requests\ConcreteSampleUpdateRequest;
use Modules\Quality\Http\Requests\ConcreteTestStoreRequest;
use Modules\Quality\Http\Resources\ConcreteSampleResource;
use Modules\Quality\Models\ConcreteSample;
use Modules\Quality\Services\ConcreteSampleService;

class ConcreteSampleController extends ApiController
{
    public function __construct(private readonly ConcreteSampleService $service) {}

    private const DETAIL = ['project', 'location', 'tests'];

    public function index(Request $request): JsonResponse
    {
        $query = ConcreteSample::query()
            ->with(['project', 'location', 'tests'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('grade', 'like', "%{$q}%")
                        ->orWhere('truck_no', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->orderByDesc('id');

        return $this->listing($request, $query, ConcreteSampleResource::class,
            sortable: ['pour_date', 'grade'],
            dateColumn: 'pour_date');
    }

    public function store(ConcreteSampleStoreRequest $request): JsonResponse
    {
        $sample = $this->service->create($request->validated());

        return $this->created(ConcreteSampleResource::make($sample->load(self::DETAIL)));
    }

    public function show(ConcreteSample $sample): JsonResponse
    {
        return $this->ok(ConcreteSampleResource::make($sample->load(self::DETAIL)));
    }

    public function update(ConcreteSampleUpdateRequest $request, ConcreteSample $sample): JsonResponse
    {
        $updated = $this->service->update($sample, $request->validated());

        return $this->ok(ConcreteSampleResource::make($updated->load(self::DETAIL)));
    }

    public function destroy(ConcreteSample $sample): JsonResponse
    {
        $sample->delete();

        return $this->ok(null, 'Benda uji dihapus.');
    }

    /** Record one break at an age; pass is computed against the grade target. */
    public function addTest(ConcreteTestStoreRequest $request, ConcreteSample $sample): JsonResponse
    {
        $this->service->addTest($sample, $request->validated());

        return $this->created(ConcreteSampleResource::make($sample->fresh()->load(self::DETAIL)));
    }
}
