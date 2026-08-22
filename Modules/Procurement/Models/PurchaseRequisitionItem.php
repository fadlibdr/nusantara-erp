<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Estimation\Models\BoqItem;

class PurchaseRequisitionItem extends BaseModel
{
    protected $table = 'prc_purchase_requisition_items';

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'decimal:3',
            'estimated_price' => 'decimal:2',
        ];
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class, 'boq_item_id');
    }
}
