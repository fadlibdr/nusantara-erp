<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Projects\Http\Requests\DailyReportStoreRequest;
use Modules\Projects\Http\Requests\DailyReportUpdateRequest;
use Modules\Projects\Http\Resources\DailyReportResource;
use Modules\Projects\Models\DailyReport;
use Modules\Projects\Services\DailyReportService;

class DailyReportController extends ApiController
{
    public function __construct(private readonly DailyReportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = DailyReport::query()
            ->with(['project', 'materials'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('activities', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->orderByDesc('report_date');

        // The local date_from/date_to whens moved into listing(): identical
        // predicate, same param names, one implementation.
        return $this->listing($request, $query, DailyReportResource::class,
            sortable: ['code', 'report_date', 'manpower_count'], dateColumn: 'report_date');
    }

    public function store(DailyReportStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        // The isOperational guard throws here; uncaught it would be a 500, and
        // a mandor met by "Server Error" retypes the report instead of reading
        // why the closed project refused it.
        try {
            $report = $this->service->create($data);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(DailyReportResource::make($report));
    }

    public function show(DailyReport $dailyReport): JsonResponse
    {
        return $this->ok(DailyReportResource::make($dailyReport->load(['project', 'materials'])));
    }

    public function update(DailyReportUpdateRequest $request, DailyReport $dailyReport): JsonResponse
    {
        try {
            $report = $this->service->update($dailyReport, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(DailyReportResource::make($report));
    }

    public function destroy(DailyReport $dailyReport): JsonResponse
    {
        $this->service->delete($dailyReport);

        return $this->ok(null, 'Daily report deleted.');
    }
}
