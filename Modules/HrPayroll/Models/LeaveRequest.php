<?php

namespace Modules\HrPayroll\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Enums\LeaveType;

class LeaveRequest extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'hr_leave_requests';

    public string $documentType = 'CTI';

    protected function casts(): array
    {
        return [
            'leave_type' => LeaveType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'day_count' => 'integer',
            'status' => DocumentStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
