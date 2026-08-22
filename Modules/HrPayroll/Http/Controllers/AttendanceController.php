<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\AttendanceBulkRequest;
use Modules\HrPayroll\Http\Requests\AttendanceUpdateRequest;
use Modules\HrPayroll\Http\Resources\AttendanceResource;
use Modules\HrPayroll\Models\Attendance;
use Modules\HrPayroll\Services\AttendanceService;

class AttendanceController extends ApiController
{
    public function __construct(private readonly AttendanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query()
            ->with(['employee', 'project'])
            ->when($request->filled('date'), fn ($query) => $query->whereDate('date', $request->string('date')))
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->whereHas('employee', function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('date')
            ->orderBy('employee_id');

        return $this->listing($request, $query, AttendanceResource::class,
            sortable: ['date', 'status'], dateColumn: 'date');
    }

    /**
     * The site sheet in one POST — see AttendanceService::bulkUpsert for why
     * it upserts instead of inserting.
     */
    public function bulk(AttendanceBulkRequest $request): JsonResponse
    {
        $result = $this->service->bulkUpsert($request->validated(), $request->user()?->id);

        return $this->ok(
            $result,
            sprintf('Absensi tersimpan: %d baru, %d diperbarui.', $result['created'], $result['updated']),
        );
    }

    public function show(Attendance $attendance): JsonResponse
    {
        return $this->ok(AttendanceResource::make($attendance->load(['employee', 'project'])));
    }

    public function update(AttendanceUpdateRequest $request, Attendance $attendance): JsonResponse
    {
        $attendance->update($request->validated());

        return $this->ok(AttendanceResource::make($attendance->load(['employee', 'project'])));
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $attendance->delete();

        return $this->ok(null, 'Attendance deleted.');
    }
}
