<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Enums\WorkShift;

/**
 * Izin Kerja Lapangan — IKL, Form F/IK (P0-C).
 *
 * One row is one permit for one shift's work. The F/IK sheet used to print as
 * a blank pad; it now prints FROM this row (FormPrintService::izinKerja).
 *
 * Approve = prj.approve, per the spec's own words. 🧪 Seam DITUNAIKAN (P0-F):
 * prj_work_permits terdaftar di ExternalApprovableDocuments mode TRANSISI —
 * MK menyetujui izin kerja berisiko tinggi lewat tautan sekali-pakai, dan
 * keputusannya diterapkan WorkPermitService::applyExternalDecision (adapter
 * service, bukan trait).
 */
class WorkPermit extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_work_permits';

    public string $documentType = 'IKL';

    protected function casts(): array
    {
        return [
            'permit_date' => 'date',
            'shift' => WorkShift::class,
            'ppe_required' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'status' => DocumentStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function wbsTask(): BelongsTo
    {
        return $this->belongsTo(WbsTask::class, 'wbs_task_id');
    }

    /** Pemohon — pelaksana/mandor, an hr_employees row (shared-ID contract). */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function safetyOfficer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'safety_officer_id');
    }
}
