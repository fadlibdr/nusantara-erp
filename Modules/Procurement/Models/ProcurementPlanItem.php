<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Procurement\Enums\ProcurementMethod;

class ProcurementPlanItem extends BaseModel
{
    protected $table = 'prc_procurement_plan_items';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'estimated_amount' => 'decimal:2',
            'target_contract_date' => 'date',
            'method' => ProcurementMethod::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProcurementPlan::class, 'procurement_plan_id');
    }
}
