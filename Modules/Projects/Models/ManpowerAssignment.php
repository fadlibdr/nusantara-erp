<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Models\Employee;

class ManpowerAssignment extends BaseModel
{
    protected $table = 'prj_manpower_assignments';

    protected function casts(): array
    {
        return [
            'assigned_from' => 'date',
            'assigned_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Assigned and within the active window on the given date (default today).
     */
    public function isCurrentOn(?string $date = null): bool
    {
        $date = $date ?? now()->toDateString();

        return $this->is_active
            && $this->assigned_from !== null
            && $this->assigned_from->toDateString() <= $date
            && ($this->assigned_until === null || $this->assigned_until->toDateString() >= $date);
    }
}
