<?php

namespace Modules\Subcontract\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Support\Erp;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Procurement\Models\Vendor;
use Modules\Projects\Models\Project;
use Modules\Subcontract\Enums\PphConstructionScheme;

class Subcontract extends BaseModel
{
    use Approvable {
        submit as protected approvableSubmit;
    }
    use HasDocumentNumber;
    use SoftDeletes;

    protected $table = 'scm_subcontracts';

    public string $documentType = 'SPK';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'original_value' => 'decimal:2',
            'ppn_rate' => 'decimal:4',
            'retention_pct' => 'decimal:4',
            'pph_scheme' => PphConstructionScheme::class,
            'pph_rate' => 'decimal:4',
            'start_date' => 'date',
            'end_date' => 'date',
            'defect_liability_until' => 'date',
            'needs_director_approval' => 'boolean',
            'status' => DocumentStatus::class,
        ];
    }

    /**
     * A SPK at or above the configured amount needs a director's approval.
     * The flag is stamped on submit so approvers see it up front.
     */
    public function submit(?User $by = null): static
    {
        $this->forceFill([
            'needs_director_approval' => (float) $this->value >= self::directorApprovalThreshold(),
        ]);

        return $this->approvableSubmit($by);
    }

    /**
     * One reader for approvals.subcontract.threshold_two_level: submit()
     * stamps the flag against it and SubcontractService::approve names it in
     * the refusal, so the number the approver is told can never drift from the
     * number the flag was computed with.
     */
    public static function directorApprovalThreshold(): float
    {
        return Erp::float('approvals.subcontract.threshold_two_level', 200000000);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubcontractItem::class, 'subcontract_id')->orderBy('line_no');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ProgressClaim::class, 'subcontract_id')->orderBy('claim_no');
    }

    public function retentionReleases(): HasMany
    {
        return $this->hasMany(RetentionRelease::class, 'subcontract_id')->orderBy('release_date');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(SubcontractAddendum::class, 'subcontract_id')->orderBy('addendum_date');
    }
}
