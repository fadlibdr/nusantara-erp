<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\WeeklyProgressStoreRequest;
use Modules\Projects\Http\Resources\WeeklyProgressResource;
use Modules\Projects\Models\WeeklyProgress;
use Modules\Projects\Services\ProgressService;

class WeeklyProgressController extends ApiController
{
    public function __construct(private readonly ProgressService $progress) {}

    public function index(Request $request): JsonResponse
    {
        $query = WeeklyProgress::query()
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderBy('project_id')
            ->orderBy('week_no');

        return $this->listing($request, $query, WeeklyProgressResource::class,
            sortable: ['week_no', 'period_start', 'period_end', 'planned_pct', 'actual_pct', 'deviation_pct'],
            dateColumn: 'period_end');
    }

    /**
     * Upsert one kurva-S point (unique per project + week_no); deviation and
     * the project header planned percentage are derived in the service.
     */
    public function store(WeeklyProgressStoreRequest $request): JsonResponse
    {
        // The isOperational guard throws here; uncaught it would be a 500
        // instead of the Indonesian refusal that names the closed project.
        try {
            $row = $this->progress->recordWeekly($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(WeeklyProgressResource::make($row));
    }
}
