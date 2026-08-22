<?php

namespace Modules\ServiceDesk\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\HrPayroll\Models\Employee;
use Modules\ServiceDesk\Enums\PmFrequency;

class PreventiveSchedule extends BaseModel
{
    use SoftDeletes;

    protected $table = 'svc_preventive_schedules';

    protected function casts(): array
    {
        return [
            'frequency' => PmFrequency::class,
            'next_due_date' => 'date',
            'checklist' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'service_contract_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ContractSite::class, 'site_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }
}
