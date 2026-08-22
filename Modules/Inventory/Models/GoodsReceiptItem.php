<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Inventory\Enums\StockDocumentStatus;

class GoodsReceiptItem extends BaseModel
{
    protected $table = 'inv_goods_receipt_items';

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * Quantity of this receipt line already sent back to the vendor through
     * POSTED purchase returns. Same shape as IssueItem::qtyReturned(): read
     * from the documents inside the posting transaction, never from a cached
     * counter, so parallel returns cannot both fit under one ceiling.
     */
    public function qtyReturned(): float
    {
        return round((float) PurchaseReturnItem::query()
            ->join('inv_purchase_returns as retur', 'retur.id', '=', 'inv_purchase_return_items.purchase_return_id')
            ->whereNull('retur.deleted_at')
            ->where('retur.status', StockDocumentStatus::Posted->value)
            ->where('inv_purchase_return_items.grn_item_id', $this->id)
            ->sum('inv_purchase_return_items.qty'), 3);
    }
}
