<?php

namespace Modules\Quality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Models\Location;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Engineering\Models\WorkPermitIpp;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Models\Project;
use Modules\Quality\Enums\WitnessParty;

/**
 * Inspeksi mutu — QCI. The Approvable document of the module: the contractor's
 * QC records the sheet, a second qc.approve holder authorises it (house
 * maker-checker). The submit GATE — an open NCR at this location from an earlier
 * stage refuses the submit — lives in InspectionService::submit.
 *
 * `passed` is DERIVED from the result rows, never written from a request.
 *
 * 🧪 NAMED SEAM (persetujuan eksternal): the MK/Owner who witnesses is external
 * and is not a users row (owner decision #6). Today the sheet's approval is the
 * house internal cycle and witness_party is recorded fact; if the external
 * one-time-link decision is ever wired onto the QCI, it maps onto this model the
 * way DrawingSubmittal's seam maps onto recordDecision. Deliberately not wired
 * in the P1-QC lane.
 */
class Inspection extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'qc_inspections';

    public string $documentType = 'QCI';

    protected function casts(): array
    {
        return [
            'inspected_at' => 'date',
            'witness_party' => WitnessParty::class,
            'passed' => 'boolean',
            'status' => DocumentStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function ipp(): BelongsTo
    {
        return $this->belongsTo(WorkPermitIpp::class, 'ipp_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplate::class, 'template_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'inspector_employee_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(InspectionResult::class, 'inspection_id');
    }

    public function ncrs(): HasMany
    {
        return $this->hasMany(Ncr::class, 'inspection_id');
    }
}
