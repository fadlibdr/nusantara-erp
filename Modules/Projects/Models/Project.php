<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Contract;
use Modules\Crm\Models\Customer;
use Modules\Estimation\Models\Boq;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Enums\DefectStatus;
use Modules\Projects\Enums\ProjectStatus;
use Modules\Projects\Enums\ProjectType;

class Project extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_projects';

    public string $documentType = 'PRJ';

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'start_date' => 'date',
            'end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'contract_value' => 'decimal:2',
            'retention_pct' => 'decimal:4',
            'warranty_months' => 'integer',
            'planned_progress_pct' => 'decimal:4',
            'actual_progress_pct' => 'decimal:4',
            'closed_at' => 'datetime',
            'closure_snapshot' => 'array',
        ];
    }

    /**
     * Refuse site data entry unless the project is operational.
     *
     * ProjectStatus::isOperational() existed from day one and was called by
     * nobody, which is how PRJ-2026-001 could take laporan harian after being
     * closed — and why the period's progress and cost reports could not be
     * trusted. Every field-entry service calls this with the name of what it
     * was about to write, so the refusal reads "Proyek PRJ-2026-001 berstatus
     * Ditutup; laporan harian hanya dapat dientri…" instead of a bare no.
     *
     * @throws LogicException
     */
    public function assertOperational(string $activity): void
    {
        if ($this->status !== null && $this->status->isOperational()) {
            return;
        }

        throw new LogicException(sprintf(
            'Proyek %s berstatus %s; %s hanya dapat dientri pada proyek berstatus Persiapan, Berjalan, atau Finishing.',
            $this->code,
            $this->status?->label() ?? '—',
            $activity,
        ));
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    /**
     * The two people whose names go on a printed form.
     *
     * project_manager_id and site_manager_id have carried hr_employees ids
     * since the first migration and no relation ever read them back — the API
     * returned the bare id and the SPA showed it. A form's signature block
     * needs the NAME and the POSITION, and typing either into a template is how
     * a document ends up signed by somebody who left the company.
     */
    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'project_manager_id');
    }

    public function siteManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'site_manager_id');
    }

    public function wbsTasks(): HasMany
    {
        return $this->hasMany(WbsTask::class, 'project_id');
    }

    public function rootWbsTasks(): HasMany
    {
        return $this->hasMany(WbsTask::class, 'project_id')
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(DailyReport::class, 'project_id');
    }

    public function weeklyProgress(): HasMany
    {
        return $this->hasMany(WeeklyProgress::class, 'project_id')->orderBy('week_no');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class, 'project_id')->orderBy('due_date');
    }

    public function basts(): HasMany
    {
        return $this->hasMany(Bast::class, 'project_id');
    }

    public function manpowerAssignments(): HasMany
    {
        return $this->hasMany(ManpowerAssignment::class, 'project_id');
    }

    /** Register defect (punch list). */
    public function defects(): HasMany
    {
        return $this->hasMany(Defect::class, 'project_id');
    }

    /**
     * Everything still on the punch list.
     *
     * `ready_for_review` is deliberately included: BAST II is the customer's
     * acceptance, so an item that merely claims to be repaired has been accepted
     * by nobody. See DefectStatus::isOpen().
     */
    public function openDefects(): HasMany
    {
        return $this->defects()->whereIn('status', [
            DefectStatus::Open->value,
            DefectStatus::InProgress->value,
            DefectStatus::ReadyForReview->value,
        ]);
    }

    /**
     * Rupiah amount held as retention (retensi) over the whole contract value.
     */
    public function retentionAmount(): float
    {
        return round((float) $this->contract_value * (float) $this->retention_pct / 100, 2);
    }

    /**
     * Schedule deviation of the cumulative progress (actual - planned).
     */
    public function progressDeviation(): float
    {
        return round((float) $this->actual_progress_pct - (float) $this->planned_progress_pct, 4);
    }
}
