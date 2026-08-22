<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Enums\AttendanceStatus;
use Modules\Projects\Models\Project;

class Attendance extends BaseModel
{
    protected $table = 'hr_attendances';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
