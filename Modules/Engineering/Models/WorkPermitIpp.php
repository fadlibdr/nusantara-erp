<?php

namespace Modules\Engineering\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Models\Location;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Engineering\Enums\IppScope;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\WbsTask;

/**
 * Ijin Pelaksanaan Pekerjaan — IPP (FM-10-11 & Master IPP).
 *
 * The ONE Approvable document in this module: the internal approver is real
 * (the PM authorises the start of work), unlike the submittal stamps which
 * belong to the external MK. The submit gate — no drawing line without an
 * approved/approved-as-noted submittal, no material line without an approved
 * one — lives in IppService::submit; controllers and the trait know nothing
 * of it.
 */
class WorkPermitIpp extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'eng_work_permits_ipp';

    public string $documentType = 'IPP';

    protected function casts(): array
    {
        return [
            'scope' => IppScope::class,
            'planned_start' => 'date',
            'status' => DocumentStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * The work package this permit authorises — the value a bon pointing at
     * this IPP inherits (IssueService), and through it the material variance
     * attribution. Cross-module belongsTo with no FK behind it (§3);
     * Engineering → Projects is a declared dependency arrow.
     */
    public function wbsTask(): BelongsTo
    {
        return $this->belongsTo(WbsTask::class, 'wbs_task_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(IppMaterial::class, 'ipp_id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(IppEquipment::class, 'ipp_id');
    }

    public function drawings(): HasMany
    {
        return $this->hasMany(IppDrawing::class, 'ipp_id');
    }

    public function materialApprovals(): HasMany
    {
        return $this->hasMany(IppMaterialApproval::class, 'ipp_id');
    }
}
