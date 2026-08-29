<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * Kuantitas satu baris PPK pada satu periode tagihan. work_order_item_id
 * adalah kunci roll-forward plafon (argumen keamanan: migrasi 000868);
 * meter_start/meter_end snapshot pembacaan untuk baris per_jam.
 */
class WorkOrderBillingLine extends BaseModel
{
    protected $table = 'prc_work_order_billing_lines';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'amount' => 'decimal:2',
            'meter_start' => 'decimal:3',
            'meter_end' => 'decimal:3',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(WorkOrderBilling::class, 'work_order_billing_id');
    }

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class, 'work_order_item_id');
    }
}
