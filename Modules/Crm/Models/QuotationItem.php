<?php

namespace Modules\Crm\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class QuotationItem extends BaseModel
{
    protected $table = 'crm_quotation_items';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }
}
