<?php

namespace Modules\Projects\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\HrPayroll\Models\Employee;
use Modules\Projects\Enums\DefectSeverity;
use Modules\Projects\Enums\DefectSource;
use Modules\Projects\Enums\DefectStatus;
use Modules\Subcontract\Models\Subcontract;

/**
 * One line of the punch list (daftar temuan).
 *
 * Not Approvable, exactly like SafetyIncident: a defect is not approved into
 * existence, it exists because somebody found it. Its lifecycle is repair and
 * verification, which DefectStatus describes.
 *
 * The document type has no entry in config('erp.documents') on purpose — adding
 * one breaks tests/Unit/Core/DocumentFormatValidationTest, which asserts a fixed
 * count of shipped types AND that every one of them is editable through Core's
 * settings registry. DocumentNumberService's documented fallback,
 * strtoupper($type).'/{Y}/{RM}/{N4}', already yields DEF/2026/VIII/0001.
 */
class Defect extends BaseModel
{
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'prj_defects';

    public string $documentType = 'DEF';

    protected function casts(): array
    {
        return [
            'severity' => DefectSeverity::class,
            'source' => DefectSource::class,
            'status' => DefectStatus::class,
            'reported_on' => 'date',
            'due_date' => 'date',
            'fixed_at' => 'date',
            'verified_at' => 'date',
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

    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class, 'subcontract_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * A repair past its target date that nobody has closed out.
     *
     * A waived or verified item is never overdue: the date it was measured
     * against stopped applying the moment the customer signed it off, and a
     * register that keeps counting those reports a backlog nobody can clear.
     *
     * DUE TODAY IS NOT YET OVERDUE — the site has until the end of the day.
     * lt(today()), never isPast(): the `date` cast lands on 00:00:00, so
     * isPast() flipped an item overdue at 00:00:01 on its own target date while
     * the list filter (`due_date < today`) still excluded it — the stat card
     * said "Lewat target perbaikan: 1" above a table reading "Tidak ada baris".
     * One rule, both places: DefectController's overdue filter uses the same
     * strictly-before-today comparison.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->status->isOpen()
            && $this->due_date->lt(today());
    }

    /**
     * How long this item has been on the list.
     *
     * Stops counting at the day it was accepted, not today — otherwise a punch
     * list closed in March keeps ageing and "temuan tertua" always names a
     * finished job.
     */
    public function daysOpen(): ?int
    {
        if ($this->reported_on === null) {
            return null;
        }

        $end = $this->status->isTerminal()
            ? CarbonImmutable::parse($this->verified_at ?? $this->fixed_at ?? $this->updated_at ?? now())
            : CarbonImmutable::now();

        // CarbonImmutable on both sides: `date` casts hand back a MUTABLE Carbon,
        // and startOfDay() on it would quietly rewrite the model's own attribute.
        $start = CarbonImmutable::parse($this->reported_on)->startOfDay();

        return max(0, (int) $start->diffInDays($end->startOfDay(), false));
    }
}
