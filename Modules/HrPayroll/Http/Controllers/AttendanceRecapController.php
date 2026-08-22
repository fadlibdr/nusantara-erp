<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\AttendanceRecapStoreRequest;
use Modules\HrPayroll\Http\Requests\AttendanceRecapUpdateRequest;
use Modules\HrPayroll\Http\Resources\AttendanceRecapResource;
use Modules\HrPayroll\Models\AttendanceRecap;

class AttendanceRecapController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AttendanceRecap::query()
            ->with('employee')
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('period_year'), fn ($query) => $query->where('period_year', $request->integer('period_year')))
            ->when($request->filled('period_month'), fn ($query) => $query->where('period_month', $request->integer('period_month')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->whereHas('employee', function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('employee_id');

        // period_year/period_month dicabut dari whitelist: kolom layarnya
        // komposit 'period', jadi kunci itu tidak pernah menjadi tombol
        // (temuan 10). Kembali saat seam column.sortKey disepakati bersama.
        return $this->listing($request, $query, AttendanceRecapResource::class,
            sortable: ['work_days', 'present_days', 'sick_days', 'leave_days', 'alpha_days', 'overtime_hours']);
    }

    public function store(AttendanceRecapStoreRequest $request): JsonResponse
    {
        $recap = AttendanceRecap::query()->create($request->validated());

        return $this->created(AttendanceRecapResource::make($recap->load('employee')));
    }

    public function show(AttendanceRecap $attendanceRecap): JsonResponse
    {
        return $this->ok(AttendanceRecapResource::make($attendanceRecap->load('employee')));
    }

    public function update(AttendanceRecapUpdateRequest $request, AttendanceRecap $attendanceRecap): JsonResponse
    {
        $attendanceRecap->update($request->validated());

        return $this->ok(AttendanceRecapResource::make($attendanceRecap->load('employee')));
    }

    public function destroy(AttendanceRecap $attendanceRecap): JsonResponse
    {
        $attendanceRecap->delete();

        return $this->ok(null, 'Attendance recap deleted.');
    }
}
