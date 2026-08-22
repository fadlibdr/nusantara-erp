<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Append-only perpetual stock ledger. Rows are never updated or deleted;
 * corrections happen through new documents (adjustments).
 */
class StockLedgerEntry extends BaseModel
{
    protected $table = 'inv_stock_ledger';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'balance_qty_after' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
