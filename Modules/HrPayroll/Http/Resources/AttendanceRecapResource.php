<?php

namespace Modules\HrPayroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => EmployeeResource::make($this->whenLoaded('employee')),
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'work_days' => $this->work_days,
            'present_days' => $this->present_days,
            'sick_days' => $this->sick_days,
            'leave_days' => $this->leave_days,
            'alpha_days' => $this->alpha_days,
            'overtime_hours' => $this->overtime_hours,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
