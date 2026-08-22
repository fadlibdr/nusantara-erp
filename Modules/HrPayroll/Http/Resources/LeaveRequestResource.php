<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'code' => $this->employee->code,
                'name' => $this->employee->name,
            ]),
            'leave_type' => $this->leave_type?->value,
            'leave_type_label' => $this->leave_type?->label(),
            'counts_against_balance' => $this->leave_type?->countsAgainstBalance(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'day_count' => $this->day_count,
            'reason' => $this->reason,
            'status' => $this->status?->value,
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval): array => [
                'id' => $approval->id,
                'action' => $approval->action,
                'note' => $approval->note,
                'created_at' => $approval->created_at?->toIso8601String(),
                'user' => $approval->relationLoaded('user') && $approval->user !== null
                    ? ['id' => $approval->user->id, 'name' => $approval->user->name]
                    : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
