<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Core\Models\Location;
use Modules\Estimation\Models\BoqItem;

/**
 * One measured BOQ item on an opname: qty_prev + qty_this = qty_cum, priced at
 * the unit price snapshotted when the line was drafted.
 */
class ProgressMeasurementItem extends BaseModel
{
    protected $table = 'prj_progress_measurement_items';

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'qty_prev' => 'decimal:3',
            'qty_this' => 'decimal:3',
            'qty_cum' => 'decimal:3',
            'amount' => 'decimal:2',
        ];
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(ProgressMeasurement::class, 'progress_measurement_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
