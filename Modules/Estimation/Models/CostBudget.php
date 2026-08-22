<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\DocumentStatus;
use Modules\Core\Models\BaseModel;
use Modules\Core\Traits\Approvable;
use Modules\Core\Traits\HasDocumentNumber;
use Modules\Projects\Models\Project;

/**
 * RAP — Rencana Anggaran Pelaksanaan (internal execution cost budget).
 */
class CostBudget extends BaseModel
{
    use Approvable;
    use HasDocumentNumber;
    use SoftDeletes;

    public string $documentType = 'RAP';

    protected $table = 'est_cost_budgets';

    protected $casts = [
        'status' => DocumentStatus::class,
        'target_margin_pct' => 'decimal:4',
        'total_budget' => 'decimal:2',
    ];

    public function boq(): BelongsTo
    {
        return $this->belongsTo(Boq::class, 'boq_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CostBudgetItem::class, 'cost_budget_id');
    }

    /**
     * The job this budget is executed against — est_cost_budgets.project_id,
     * which RapService keeps in step with the BOQ's own project.
     *
     * Declared so the printed RAP can put the PROYEK box on its letterhead
     * without the print composer reaching into prj_projects by hand. Nullable:
     * a RAP can be costed against a BOQ before the project is opened.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
