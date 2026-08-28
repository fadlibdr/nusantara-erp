<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Models\Employee;

/**
 * One worker's line on an ILB sheet — employee_id XOR worker_name.
 *
 * A non-employee mandor's crew is real: their names print on the sheet and
 * they sign it. They simply have no hr_attendance_recaps row, so only lines
 * carrying employee_id ever reach the payroll recap feed.
 */
class OvertimePermitWorker extends BaseModel
{
    protected $table = 'prj_overtime_permit_workers';

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
        ];
    }

    public function permit(): BelongsTo
    {
        return $this->belongsTo(OvertimePermit::class, 'overtime_permit_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** The name the sheet prints, whichever column carries it. */
    public function displayName(): ?string
    {
        return $this->employee?->name ?? $this->worker_name;
    }
}
