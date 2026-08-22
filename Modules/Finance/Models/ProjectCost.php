<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Finance\Enums\CostCategory;
use Modules\Projects\Models\Project;

/**
 * One realisasi row in the project cost ledger. Fed by AP bill approvals,
 * payroll allocations and warehouse material issues.
 */
class ProjectCost extends BaseModel
{
    protected $table = 'fin_project_costs';

    protected function casts(): array
    {
        return [
            'cost_date' => 'date',
            'cost_category' => CostCategory::class,
            'amount' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
