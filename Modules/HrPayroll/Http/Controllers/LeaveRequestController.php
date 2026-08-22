<?php

namespace Modules\HrPayroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\HrPayroll\Http\Requests\LeaveRequestStoreRequest;
use Modules\HrPayroll\Http\Requests\LeaveRequestUpdateRequest;
use Modules\HrPayroll\Http\Resources\LeaveRequestResource;
use Modules\HrPayroll\Models\LeaveRequest;
use Modules\HrPayroll\Services\LeaveService;

class LeaveRequestController extends ApiController
{
    public function __construct(private readonly LeaveService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = LeaveRequest::query()
            ->with('employee')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhereHas('employee', function ($employee) use ($q): void {
                            $employee->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
                        });
                });
            })
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('leave_type'), fn ($query) => $query->where('leave_type', $request->string('leave_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        return $this->listing($request, $query, LeaveRequestResource::class,
            sortable: ['code', 'leave_type', 'start_date', 'end_date', 'day_count', 'status'], dateColumn: 'start_date');
    }

    public function store(LeaveRequestStoreRequest $request): JsonResponse
    {
        try {
            $leaveRequest = $this->service->create($request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->created(LeaveRequestResource::make($leaveRequest->load('employee')));
    }

    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        return $this->ok(LeaveRequestResource::make($leaveRequest->load(['employee', 'approvals.user'])));
    }

    public function update(LeaveRequestUpdateRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $leaveRequest = $this->service->update($leaveRequest, $request->validated());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LeaveRequestResource::make($leaveRequest->load('employee')));
    }

    public function destroy(LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $this->service->delete($leaveRequest);
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(null, 'Leave request deleted.');
    }

    public function submit(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $leaveRequest = $this->service->submit($leaveRequest, $request->user());
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LeaveRequestResource::make($leaveRequest->load('employee')), 'Leave request submitted.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $result = $this->service->approve($leaveRequest, $request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        $message = 'Leave request approved.';

        // Not silence: the approver is the one person who can still fix the
        // recap of a posted period by other means, so tell them it was left
        // alone rather than let them assume it moved.
        if ($result['skipped_periods'] !== []) {
            $message .= ' Rekap '.implode(', ', $result['skipped_periods'])
                .' tidak diubah — payroll periode itu sudah diposting.';
        }

        return $this->ok(
            LeaveRequestResource::make($result['request']->load('employee')),
            $message,
        );
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        try {
            $leaveRequest->reject($request->user(), $request->input('note'));
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }

        return $this->ok(LeaveRequestResource::make($leaveRequest->load('employee')), 'Leave request rejected.');
    }
}
