<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class AttendanceRecap extends BaseModel
{
    protected $table = 'hr_attendance_recaps';

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'work_days' => 'integer',
            'present_days' => 'integer',
            'sick_days' => 'integer',
            'leave_days' => 'integer',
            'alpha_days' => 'integer',
            'overtime_hours' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
