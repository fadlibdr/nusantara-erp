<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Assets\Enums\RateBasis;
use Modules\Assets\Models\Asset;
use Modules\Core\Models\BaseModel;

/**
 * Satu baris PPK: alat/uraian x tarif x basis x plafon qty_periods.
 * Baris per_jam wajib menunjuk asset_id (jamnya dibaca dari register alat
 * itu); guard-nya di WorkOrderService::syncItems.
 */
class WorkOrderItem extends BaseModel
{
    protected $table = 'prc_work_order_items';

    protected function casts(): array
    {
        return [
            'rate_basis' => RateBasis::class,
            'rate' => 'decimal:2',
            'qty_periods' => 'decimal:3',
            'amount' => 'decimal:2',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    /** Cross-module read (ast_assets); null untuk baris jasa tanpa alat. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
