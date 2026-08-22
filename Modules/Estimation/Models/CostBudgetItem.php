<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Estimation\Enums\CostCategory;

class CostBudgetItem extends BaseModel
{
    protected $table = 'est_cost_budget_items';

    protected $casts = [
        'cost_category' => CostCategory::class,
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function costBudget(): BelongsTo
    {
        return $this->belongsTo(CostBudget::class, 'cost_budget_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }
}
