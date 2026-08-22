<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Models\Contract;

class RevenueRecognitionLine extends BaseModel
{
    protected $table = 'fin_revenue_recognition_lines';

    protected function casts(): array
    {
        return [
            'transaction_price' => 'decimal:2',
            'estimated_total_cost' => 'decimal:2',
            'cost_to_date' => 'decimal:2',
            'progress_pct' => 'decimal:4',
            'revenue_cumulative' => 'decimal:2',
            'billed_cumulative' => 'decimal:2',
            'contract_balance' => 'decimal:2',
            'provision_balance' => 'decimal:2',
            'revenue_adjustment' => 'decimal:2',
            'provision_adjustment' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RevenueRecognitionRun::class, 'run_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
