<?php

namespace Modules\Quality\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Models\Location;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\InspectionStage;
use Modules\Quality\Enums\NcrStatus;
use Modules\Subcontract\Models\Subcontract;

/**
 * Non-Conformance Report — NCR. HasDocumentNumber ('NCR') but NOT Approvable:
 * status is NcrStatus, its own lifecycle (open → under_correction → verified →
 * closed). `stage` is the hold-point the block compares; the responsible party
 * is an employee XOR a subcontractor (NcrService).
 */
class Ncr extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'qc_ncr';

    public string $documentType = 'NCR';

    protected function casts(): array
    {
        return [
            'stage' => InspectionStage::class,
            'due_date' => 'date',
            'verified_at' => 'date',
            'status' => NcrStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class, 'subcontract_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
