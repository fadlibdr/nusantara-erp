<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\HseDailyStoreRequest;
use Modules\Projects\Http\Requests\HseDailyUpdateRequest;
use Modules\Projects\Http\Resources\HseDailyResource;
use Modules\Projects\Models\HseDaily;
use Modules\Projects\Services\HseDailyService;

/** P6: formulir K3 harian (FM-10-13) — thin; aturan tautan & baris di service. */
class HseDailyController extends ApiController
{
    public function __construct(private readonly HseDailyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = HseDaily::query()
            ->with(['project', 'dailyReport'])
            ->withCount('findings')
            ->when($request->filled('q'), fn ($query) => $query->where(function ($where) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $where->where('code', 'like', $term)
                    ->orWhere('toolbox_topic', 'like', $term);
            }))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('report_date');

        return $this->listing($request, $query, HseDailyResource::class,
            sortable: ['code', 'report_date'], dateColumn: 'report_date');
    }

    public function store(HseDailyStoreRequest $request): JsonResponse
    {
        try {
            $daily = $this->service->create($request->validated(), $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(HseDailyResource::make($daily->load('project')));
    }

    public function show(HseDaily $hseDaily): JsonResponse
    {
        return $this->ok(HseDailyResource::make($hseDaily->load(['project', 'dailyReport', 'apd', 'findings'])));
    }

    public function update(HseDailyUpdateRequest $request, HseDaily $hseDaily): JsonResponse
    {
        try {
            $daily = $this->service->update($hseDaily, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(HseDailyResource::make($daily->load('project')));
    }

    public function destroy(HseDaily $hseDaily): JsonResponse
    {
        $hseDaily->delete();

        return $this->ok(null, 'Formulir K3 harian dihapus.');
    }
}
