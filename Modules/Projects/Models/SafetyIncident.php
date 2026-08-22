<?php

namespace Modules\Projects\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Enums\IncidentCategory;
use Modules\Projects\Enums\IncidentSeverity;
use Modules\Projects\Enums\IncidentStatus;

/**
 * One entry in the register kecelakaan kerja.
 *
 * Not Approvable: an incident is not approved into existence, it happened. Its
 * lifecycle is investigation and close-out, which IncidentStatus describes.
 */
class SafetyIncident extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_safety_incidents';

    public string $documentType = 'K3';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'severity' => IncidentSeverity::class,
            'category' => IncidentCategory::class,
            'status' => IncidentStatus::class,
            'people_involved' => 'integer',
            'lost_days' => 'integer',
            'due_date' => 'date',
            'closed_at' => 'date',
            'is_reportable' => 'boolean',
            'reported_to_authority_at' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A corrective action past its date with nobody having closed it.
     *
     * The single number a site manager is asked for in a safety walk, and the
     * reason due_date exists at all.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->status->isClosed()
            && $this->due_date->isPast();
    }
}
