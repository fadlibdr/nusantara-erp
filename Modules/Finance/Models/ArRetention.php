<?php

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Crm\Models\Contract;
use Modules\Projects\Models\Project;

/**
 * Piutang retensi: withheld by the customer from a termin invoice, receivable
 * after masa pemeliharaan (BAST II).
 */
class ArRetention extends BaseModel
{
    protected $table = 'fin_ar_retentions';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'released' => 'boolean',
            'released_at' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'source_invoice_id');
    }
}
