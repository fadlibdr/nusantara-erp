<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

class PurchaseOrderItem extends BaseModel
{
    protected $table = 'prc_purchase_order_items';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'qty_received' => 'decimal:3',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function remainingQty(): float
    {
        return max(0.0, round((float) $this->qty - (float) $this->qty_received, 3));
    }
}
