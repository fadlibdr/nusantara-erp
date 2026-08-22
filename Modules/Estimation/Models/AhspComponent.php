<?php

namespace Modules\Estimation\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Estimation\Enums\ComponentType;
use Modules\Inventory\Models\Item;

class AhspComponent extends BaseModel
{
    protected $table = 'est_ahsp_components';

    protected $casts = [
        'component_type' => ComponentType::class,
        'coefficient' => 'decimal:6',
        'unit_price' => 'decimal:2',
    ];

    public function ahsp(): BelongsTo
    {
        return $this->belongsTo(Ahsp::class, 'ahsp_id');
    }

    /**
     * Cross-module relation to the Inventory item (inv_items) when the
     * material is stocked. Resolved lazily — safe even before Inventory exists.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function subtotal(): float
    {
        return round((float) $this->coefficient * (float) $this->unit_price, 2);
    }
}
