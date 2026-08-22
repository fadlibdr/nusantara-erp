<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\Approval;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Crm\Models\Contract;
use Modules\Estimation\Models\CostBudget;
use Modules\Projects\Enums\BacSource;
use Modules\Projects\Support\PlannedCurve;

/**
 * A baseline is one project, at one instant, frozen.
 *
 * NOT the Approvable trait, and that is a decision rather than an omission.
 * The trait's own registry test (tests/Feature/Core/ApprovalNotificationTest,
 * test_every_approvable_document_type_is_registered) requires every model
 * carrying it to appear in Modules\Core\Support\ApprovableDocuments, and a
 * second test requires that entry to name a resource public/app/js/schema.js
 * declares. Both files belong to other teams. So BaselineService performs the
 * identical lifecycle by hand — the same core_approvals rows, the same
 * SegregationOfDuties::assertNotSubmitter maker-checker, the same
 * DocumentTransitioned event — exactly as Modules\Finance\Services\PaymentService
 * already does for payments. The guarantee is unchanged; only the wiring is.
 */
class ProjectBaseline extends BaseModel
{
    use HasDocumentNumber;

    public const CURVE_SOURCE_WBS = 'wbs';

    protected $table = 'prj_baselines';

    /**
     * config/erp.php deliberately carries NO 'BSL' entry, so codes come from
     * DocumentNumberService's fallback and read BSL/2026/VIII/0001. The entry
     * would be one line, but tests/Unit/Core/DocumentFormatValidationTest pins
     * the shipped document-type count AND requires every type to be an editable
     * setting in Core's registry — both files belong to another team. The
     * fallback loses nothing but two leading zeroes.
     */
    public string $documentType = 'BSL';

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'bac_source' => BacSource::class,
            'revision_no' => 'integer',
            'planned_duration_days' => 'integer',
            'leaf_task_count' => 'integer',
            'effective_date' => 'date',
            'planned_start' => 'date',
            'planned_finish' => 'date',
            'contract_finish' => 'date',
            'bac' => 'decimal:2',
            'contract_value' => 'decimal:2',
            'leaf_weight_total' => 'decimal:4',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(BaselineTask::class, 'baseline_id')->orderBy('sort_order')->orderBy('wbs_code');
    }

    public function leafTasks(): HasMany
    {
        return $this->tasks()->where('is_leaf', true);
    }

    public function points(): HasMany
    {
        return $this->hasMany(BaselinePoint::class, 'baseline_id')->orderBy('seq');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** Cross-module belongsTo (Estimation) — encouraged by CONVENTIONS §3. */
    public function costBudget(): BelongsTo
    {
        return $this->belongsTo(CostBudget::class, 'cost_budget_id');
    }

    /** Cross-module belongsTo (Crm). */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /**
     * The one baseline a report measures against: approved and not yet
     * superseded. A project may hold many approved baselines but only ever one
     * current, which is what makes "the plan" a single answerable thing.
     */
    public function isCurrent(): bool
    {
        return $this->status === DocumentStatus::Approved && $this->superseded_at === null;
    }

    /** Approved means frozen: no edit, no delete, no resnapshot. */
    public function isFrozen(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }

    public function plannedPctAt(string $date): float
    {
        return PlannedCurve::cumulativePct($this->leafTasks()->get(), $date);
    }

    public function plannedValueAt(string $date): float
    {
        return round($this->plannedPctAt($date) / 100 * (float) $this->bac, 2);
    }

    /**
     * Warnings that ride on every report derived from this baseline.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->bac_source === BacSource::RapUnapproved) {
            $warnings[] = "RAP {$this->cost_budget_code} belum disetujui saat baseline dibekukan; BAC dapat berubah.";
        }

        if ($this->bac_source === BacSource::Override) {
            $warnings[] = 'BAC baseline ini ditetapkan manual, bukan diambil dari RAP.';
        }

        // The gap an extension-of-time claim is built from: the WBS says the
        // work ends before (or after) the date the contract obliges it to.
        if ($this->contract_finish !== null && ! $this->contract_finish->isSameDay($this->planned_finish)) {
            $warnings[] = sprintf(
                'Rencana WBS berakhir %s sedangkan kontrak berakhir %s — selisih %d hari.',
                $this->planned_finish->toDateString(),
                $this->contract_finish->toDateString(),
                (int) $this->planned_finish->diffInDays($this->contract_finish, false),
            );
        }

        return $warnings;
    }
}
