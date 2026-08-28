<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;

/**
 * Izin Kerja Lembur — ILB, Form F/IL (P0-C).
 *
 * Header of the per-person overtime sheet. Approval feeds the EMPLOYEE rows'
 * hours into hr_attendance_recaps.overtime_hours through
 * OvertimePermitService::approve → HrPayroll's OvertimeRecapService —
 * forward-only, posted payroll periods skipped and reported, never rewritten.
 */
class OvertimePermit extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_overtime_permits';

    public string $documentType = 'ILB';

    protected function casts(): array
    {
        return [
            'overtime_date' => 'date',
            'status' => DocumentStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(OvertimePermitWorker::class, 'overtime_permit_id');
    }

    /**
     * Lembur melewati tengah malam: end_time < start_time berarti selesai
     * keesokan harinya (22:00 s/d 02:00). Satu tempat yang memutuskannya,
     * dibaca service, resource dan lembar cetak — lihat prosa keputusan di
     * OvertimePermitService::assertTimes.
     */
    public function crossesMidnight(): bool
    {
        return $this->end_time !== null
            && $this->start_time !== null
            && substr((string) $this->end_time, 0, 5) < substr((string) $this->start_time, 0, 5);
    }
}
